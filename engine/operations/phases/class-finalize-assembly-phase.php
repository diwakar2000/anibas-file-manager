<?php

namespace Anibas;

if ( ! defined( 'ABSPATH' ) ) exit;



class FinalizeAssemblyPhase extends OperationPhase
{
    private function is_s3_storage($storage)
    {
        return $storage === 's3' || $storage === 's3_compatible' || strpos($storage, 's3') !== false;
    }

    public function execute(&$job, &$work_queue, $manager, &$context)
    {
        $storage = $job['storage'];
        $log     = ActivityLogger::get_instance();

        if ($storage === 'local') {
            $log->log_message('[Finalize] Moving "' . $job['file_name'] . '" to local destination: ' . $job['destination']);
            $final_path = $this->finalize_local($job);
            $this->verify_file_size($job, $final_path, $storage);
            $this->cleanup_temp($job['temp_dir']);
            $job['s3_upload_done'] = true;
            $log->log_message('[Finalize] Local finalize complete: ' . $final_path);
        } elseif ($this->is_s3_storage($storage)) {
            $done = $this->finalize_s3($job);
            $job['s3_upload_done'] = $done;
            if ($done) {
                $final_path = rtrim($job['destination'], '/') . '/' . $job['file_name'];
                $log->log_message('[Finalize] S3 multipart upload complete for "' . $job['file_name'] . '". Verifying size.');
                $this->verify_file_size($job, $final_path, $storage);
                $this->cleanup_temp($job['temp_dir']);
                $log->log_message('[Finalize] S3 finalize complete: ' . $final_path);
            } else {
                $state_key = 'anibas_s3_multipart_' . md5(($job['temp_dir'] . '/' . $job['file_name']) . $this->get_s3_key($job));
                $state     = get_option($state_key, []);
                $uploaded  = $state['offset'] ?? 0;
                $total     = $state['total_size'] ?? ($job['file_size'] ?? 0);
                $log->log_message(sprintf('[Finalize] S3 upload in progress: %s / %s (part %d)', size_format($uploaded), size_format($total), $state['part_number'] ?? 1));
                return; // caller will invoke again next poll
            }
        } else {
            $log->log_message('[Finalize] Uploading "' . $job['file_name'] . '" to remote storage: ' . $storage);
            $final_path = $this->finalize_remote($job, $storage);
            $this->verify_file_size($job, $final_path, $storage);
            $this->cleanup_temp($job['temp_dir']);
            $job['s3_upload_done'] = true;
            $log->log_message('[Finalize] Remote finalize complete: ' . $final_path);
        }

        // Delete assembly token after successful completion
        $token_key = 'anibas_fm_upload_' . md5($job['file_name'] . $job['file_size'] . $job['user_id']) . '_assembly';
        delete_transient($token_key);
    }

    /** Compute the S3 key for the target file (used for log-only progress state lookup). */
    private function get_s3_key(&$job)
    {
        $remote_path = rtrim($job['destination'], '/') . '/' . $job['file_name'];
        // Mirrors get_key() logic in S3FileSystemAdapter (ltrim + optional prefix)
        return ltrim($remote_path, '/');
    }

    /**
     * Upload the locally-assembled file to S3 using resumable multipart upload.
     * Returns true when the upload is complete, false if more chunks remain.
     */
    private function finalize_s3(&$job)
    {
        $local_file  = $job['temp_dir'] . '/' . $job['file_name'];
        $remote_path = rtrim($job['destination'], '/') . '/' . $job['file_name'];
        $log         = ActivityLogger::get_instance();

        if (! file_exists($local_file)) {
            $log->log_message('[Finalize] Assembled local file missing: ' . $local_file);
            throw new \Exception(esc_html__('Assembled file not found locally: ', 'anibas-file-manager') . esc_html($local_file));
        }

        $local_size = filesize($local_file);
        $log->log_message(sprintf('[Finalize] Starting S3 upload of "%s" (%s) → %s', $job['file_name'], size_format($local_size), $remote_path));

        $adapter = StorageManager::get_instance()->get_adapter($job['storage']);
        $result  = $adapter->upload_file($local_file, $remote_path);

        if ($result === true) {
            $log->log_message('[Finalize] S3 upload_file returned complete (small file / final part).');
        }

        return $result;
    }

    private function verify_file_size(&$job, $final_path, $storage)
    {
        $expected_size = 0;

        // Calculate expected size from original file_size in job
        if (isset($job['file_size'])) {
            $expected_size = $job['file_size'];
        } else {
            // Fallback: sum chunk sizes (shouldn't happen)
            for ($i = 0; $i < $job['total_chunks']; $i++) {
                $chunk_file = $job['temp_dir'] . '/chunk_' . $i;
                if (file_exists($chunk_file)) {
                    $expected_size += filesize($chunk_file);
                }
            }
        }

        $actual_size = 0;

        if ($storage === 'local') {
            if (file_exists($final_path)) {
                $actual_size = filesize($final_path);
            }
        } elseif ($this->is_s3_storage($storage)) {
            // Use headObject for direct, consistent size check — faster and more reliable than listDirectory
            $adapter = StorageManager::get_instance()->get_adapter($storage);
            if (method_exists($adapter, 'get_file_size')) {
                $size = $adapter->get_file_size($final_path);
                $actual_size = $size !== false ? (int) $size : 0;
            }
            if ($actual_size === 0) {
            }
        } else {
            $adapter = StorageManager::get_instance()->get_adapter($storage);
            $dir_path = dirname($final_path);
            $file_name = basename($final_path);
            $items = $adapter->listDirectory($dir_path);

            foreach ($items['items'] as $item) {
                if ($item['name'] === $file_name) {
                    $actual_size = $item['filesize'] ?? 0;
                    break;
                }
            }

            if ($actual_size === 0) {
            }
        }

        if ($actual_size !== $expected_size) {
            $job['error_code'] = 'FileSizeMismatch';
            $job['error_details'] = [
                'expected' => $expected_size,
                'actual' => $actual_size,
                'file_path' => $final_path,
            ];
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- both args are integers used with %d
            throw new \Exception(
                /* translators: 1: expected size in bytes, 2: actual size in bytes */
                sprintf(
                    esc_html__('File size mismatch: expected %1$d bytes, got %2$d bytes', 'anibas-file-manager'),
                    $expected_size,
                    $actual_size
                )
            );
        }
    }

    private function finalize_local(&$job)
    {
        if (! function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        WP_Filesystem();
        global $wp_filesystem;

        $temp_file = $job['temp_dir'] . '/' . $job['file_name'];

        // Use the adapter's path resolution to avoid trailing-slash / OS issues
        // that plague raw string concatenation with realpath(ABSPATH).
        $fm = new LocalFileSystemAdapter();
        $dest_path = $fm->frontendPathToReal($job['destination']);

        // Security: ensure the resolved destination is within the allowed root
        $validated = $fm->validate_path($job['destination']);
        if (! $validated || ! is_dir($validated)) {
            throw new \Exception(esc_html__('Destination directory does not exist or is outside allowed root: ', 'anibas-file-manager') . esc_html($job['destination']));
        }
        $dest_path = $validated;

        $target = $dest_path . DIRECTORY_SEPARATOR . $job['file_name'];

        if (! file_exists($temp_file)) {
            throw new \Exception(esc_html__('Assembled file not found: ', 'anibas-file-manager') . esc_html($temp_file));
        }

        if (! is_dir($dest_path)) {
            throw new \Exception(esc_html__('Destination directory does not exist: ', 'anibas-file-manager') . esc_html($dest_path));
        }

        if (! is_writable($dest_path)) {
            throw new \Exception(esc_html__('Destination directory is not writable: ', 'anibas-file-manager') . esc_html($dest_path));
        }

        if (file_exists($target)) {
            throw new \Exception(esc_html__('File already exists at destination: ', 'anibas-file-manager') . esc_html($job['file_name']));
        }

        if (! $wp_filesystem->move($temp_file, $target, true)) {
            $error = error_get_last();
            throw new \Exception(esc_html__('Failed to move file: ', 'anibas-file-manager') . esc_html($error['message'] ?? esc_html__('Unknown error', 'anibas-file-manager')));
        }

        return $target;
    }

    private function finalize_remote(&$job, $storage)
    {
        $adapter = StorageManager::get_instance()->get_adapter($storage);
        $temp_file = rtrim($job['destination'], '/') . '/' . $job['file_name'] . '.tmp';
        $final_file = rtrim($job['destination'], '/') . '/' . $job['file_name'];

        if (! $adapter->move($temp_file, $final_file)) {
            throw new \Exception(esc_html__('Failed to rename temp file', 'anibas-file-manager'));
        }

        return $final_file;
    }

    private function cleanup_temp($temp_dir)
    {
        if (! function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        WP_Filesystem();
        global $wp_filesystem;

        if ($wp_filesystem->is_dir($temp_dir)) {
            $wp_filesystem->delete($temp_dir, true);
        }
    }

    public function is_complete($work_queue)
    {
        ActivityLogger::get_instance()->log_message(__CLASS__ . " Complete.");
        return true;
    }

    public function next_phase()
    {
        return null;
    }
}
