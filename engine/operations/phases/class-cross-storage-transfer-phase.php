<?php

namespace Anibas;

if (! defined('ABSPATH')) exit;

/**
 * Transfer phase for cross-storage (local ↔ remote) directory operations.
 *
 * Replaces the standard TransferPhase when source and destination
 * live on different storage adapters. Uses chunked download_to_local /
 * upload_from_local to stream files through the local disk with
 * resumability across AJAX time windows.
 */
class CrossStorageTransferPhase extends OperationPhase
{
    public function execute(&$job, &$work_queue, $manager, &$context)
    {
        $start_time = $context['start_time'];
        $time_limit = $context['time_limit'];

        $source_adapter  = $context['source_adapter'];
        $dest_adapter    = $context['dest_adapter'];
        $source_is_local = $source_adapter->is_local_storage();

        $temp_dir = StorageManager::get_instance()->get_cross_storage_temp_dir();

        if (! isset($work_queue['files_to_process'])) {
            $work_queue['files_to_process'] = [];
        }

        if (! isset($work_queue['folders_to_process'])) {
            $work_queue['folders_to_process'] = [];
        }

        if (! isset($job['checked_dirs'])) {
            $job['checked_dirs'] = [];
        }

        while (! empty($work_queue['files_to_process']) && ((microtime(true) - $start_time) < $time_limit)) {

            $file = array_shift($work_queue['files_to_process']);

            $target = $file['target'];

            // Track current file for progress reporting
            $job['current_file']       = basename($file['source']);
            $job['current_file_bytes'] = $file['upload_offset'] ?? $file['download_offset'] ?? 0;
            $job['current_file_size']  = $file['size'] ?? 0;

            // Handle conflict on destination (only check once per file, not on resume)
            if (empty($file['conflict_resolved'])) {
                if (in_array($job['conflict_mode'], ['skip', 'rename'])) {
                    if ($dest_adapter->exists($target)) {
                        if ($job['conflict_mode'] === 'skip') {
                            continue;
                        } elseif ($job['conflict_mode'] === 'rename') {
                            $pathinfo  = pathinfo($target);
                            $extension = isset($pathinfo['extension']) ? '.' . $pathinfo['extension'] : '';
                            $target    = $pathinfo['dirname'] . '/' . $pathinfo['filename'] . '_' . date('Y-m-d_H-i-s') . '_' . mt_rand(100000, 999999) . $extension;
                            $file['target'] = $target;
                        }
                    }
                }
                $file['conflict_resolved'] = true;
            }

            try {
                // Ensure destination directory exists (cached)
                $target_dir = dirname($target);
                if (! isset($job['checked_dirs'][$target_dir])) {
                    if (! $dest_adapter->exists($target_dir)) {
                        $dest_adapter->mkdir($target_dir);
                    }
                    $job['checked_dirs'][$target_dir] = true;
                }

                if ($source_is_local) {
                    // Local → Remote: chunked upload
                    $this->transfer_local_to_remote($file, $target, $source_adapter, $dest_adapter, $job, $work_queue, $start_time, $time_limit);
                } else {
                    // Remote → Local: chunked download (possibly via temp staging)
                    $this->transfer_remote_to_local($file, $target, $source_adapter, $dest_adapter, $job, $work_queue, $temp_dir, $start_time, $time_limit);
                }
            } catch (\Throwable $e) {
                ActivityLogger::get_instance()->log_message('CrossStorageTransfer error: ' . $e->getMessage());
                $job['failed_count']++;
                $job['errors'][] = basename($file['source']) . ': ' . $e->getMessage();
                // Clean up temp file if exists
                if (! empty($file['temp_file']) && file_exists($file['temp_file'])) {
                    @unlink($file['temp_file']);
                }
            }
        }

        // Create destination folders
        foreach ($work_queue['folders_to_process'] as $i => $folder) {
            $dest_adapter->mkdir($folder);
            $job['processed_count']++;
            unset($work_queue['folders_to_process'][$i]);

            if ((microtime(true) - $start_time) >= $time_limit) {
                return;
            }
        }
    }

    /**
     * Local → Remote: upload using chunked method.
     */
    private function transfer_local_to_remote(array &$file, string $target, $source_adapter, $dest_adapter, array &$job, array &$work_queue, float $start_time, float $time_limit): void
    {
        $local_path = $file['source'];
        if (! is_file($local_path)) {
            $job['failed_count']++;
            $job['errors'][] = basename($local_path) . esc_html__(': Source file not found', 'anibas-file-manager');
            return;
        }

        // Cache file size for progress tracking
        if (empty($file['size'])) {
            $file['size'] = filesize($local_path) ?: 0;
        }

        $offset = $file['upload_offset'] ?? 0;
        $result = $dest_adapter->upload_from_local_chunked($local_path, $target, $offset);

        if ($result['status'] === 9) {
            // Complete
            $job['current_file_bytes'] = $result['bytes_copied'];
            $job['current_file_size']  = $result['bytes_copied'];
            $job['processed_count']++;
            if ($job['action'] === 'move') {
                $this->try_delete_source($source_adapter, $file['source'], $job);
            }
        } elseif ($result['status'] === 10) {
            // In progress — re-queue with updated offset
            $file['upload_offset'] = $result['bytes_copied'];
            $job['current_file_bytes'] = $result['bytes_copied'];
            $job['current_file_size']  = $file['size'];
            array_unshift($work_queue['files_to_process'], $file);
        } else {
            // Error — drop the partial remote file so resumed jobs don't append to corrupt state
            try {
                if ($dest_adapter->exists($target)) {
                    $dest_adapter->unlink($target);
                }
            } catch (\Throwable $e) {
                ActivityLogger::get_instance()->log_message('CrossStorage upload cleanup failed for ' . $target . ': ' . $e->getMessage());
            }
            $job['failed_count']++;
            $job['errors'][] = esc_html(basename($local_path)) . esc_html__(': Upload failed (code ', 'anibas-file-manager') . esc_html($result['status']) . ')';
        }
    }

    /**
     * Remote → Local: download using chunked method.
     */
    private function transfer_remote_to_local(array &$file, string $target, $source_adapter, $dest_adapter, array &$job, array &$work_queue, string $temp_dir, float $start_time, float $time_limit): void
    {
        $dest_is_local = $dest_adapter->is_local_storage();
        $file_size = $file['size'] ?? 0;

        if ($dest_is_local) {
            // Resolve the real local path for the target
            if (method_exists($dest_adapter, 'frontendPathToReal')) {
                $local_target = $dest_adapter->frontendPathToReal($target);
            } else {
                $local_target = $target;
            }

            $dir = dirname($local_target);
            if (! is_dir($dir)) {
                wp_mkdir_p($dir);
            }

            // Use a temp file for download, rename on completion
            $temp_file = $file['temp_file'] ?? $temp_dir . '/' . md5($file['source'] . $target) . '.tmp';
            $offset    = $file['download_offset'] ?? 0;

            $result = $source_adapter->download_to_local_chunked($file['source'], $temp_file, $offset);

            if ($result['status'] === 9) {
                // Download complete — move temp to final destination
                $job['current_file_bytes'] = $result['bytes_copied'];
                $job['current_file_size']  = $result['bytes_copied'];

                if (file_exists($local_target) && $job['conflict_mode'] === 'overwrite') {
                    @unlink($local_target);
                }
                $moved = @rename($temp_file, $local_target);
                if (! $moved) {
                    // rename across filesystems — fallback to copy+delete
                    $moved = @copy($temp_file, $local_target);
                    @unlink($temp_file);
                }

                if ($moved) {
                    $job['processed_count']++;
                    if ($job['action'] === 'move') {
                        $this->try_delete_source($source_adapter, $file['source'], $job);
                    }
                } else {
                    // Both rename and copy failed — copy may have left a partial target
                    @unlink($temp_file);
                    if (file_exists($local_target)) {
                        @unlink($local_target);
                    }
                    $job['failed_count']++;
                    $job['errors'][] = basename($file['source']) . esc_html__(': Failed to move temp file to destination', 'anibas-file-manager');
                }
            } elseif ($result['status'] === 10) {
                // In progress — re-queue
                $file['temp_file']       = $temp_file;
                $file['download_offset'] = $result['bytes_copied'];
                $job['current_file_bytes'] = $result['bytes_copied'];
                $job['current_file_size']  = $file_size;
                array_unshift($work_queue['files_to_process'], $file);
            } else {
                @unlink($temp_file);
                $job['failed_count']++;
                $job['errors'][] = esc_html(basename($file['source'])) . esc_html__(': Download failed (code ', 'anibas-file-manager') . esc_html($result['status']) . ')';
            }
        } else {
            // Remote-to-remote fallback (shouldn't happen): temp staging
            $temp_file = $file['temp_file'] ?? $temp_dir . '/' . md5($file['source'] . $target) . '.tmp';
            $d_offset  = $file['download_offset'] ?? 0;
            $u_offset  = $file['upload_offset'] ?? 0;

            // Phase 1: download to temp
            if (empty($file['download_done'])) {
                $result = $source_adapter->download_to_local_chunked($file['source'], $temp_file, $d_offset);
                if ($result['status'] === 9) {
                    $file['download_done'] = true;
                    $file['temp_file']     = $temp_file;
                } elseif ($result['status'] === 10) {
                    $file['temp_file']       = $temp_file;
                    $file['download_offset'] = $result['bytes_copied'];
                    $job['current_file_bytes'] = $result['bytes_copied'];
                    $job['current_file_size']  = $file_size;
                    array_unshift($work_queue['files_to_process'], $file);
                    return;
                } else {
                    @unlink($temp_file);
                    $job['failed_count']++;
                    $job['errors'][] = basename($file['source']) . esc_html__(': Download failed', 'anibas-file-manager');
                    return;
                }
            }

            // Phase 2: upload from temp
            $result = $dest_adapter->upload_from_local_chunked($temp_file, $target, $u_offset);
            if ($result['status'] === 9) {
                @unlink($temp_file);
                $job['current_file_bytes'] = $result['bytes_copied'];
                $job['current_file_size']  = $result['bytes_copied'];
                $job['processed_count']++;
                if ($job['action'] === 'move') {
                    $this->try_delete_source($source_adapter, $file['source'], $job);
                }
            } elseif ($result['status'] === 10) {
                $file['upload_offset'] = $result['bytes_copied'];
                $job['current_file_bytes'] = $result['bytes_copied'];
                $job['current_file_size']  = $file_size;
                array_unshift($work_queue['files_to_process'], $file);
            } else {
                @unlink($temp_file);
                $job['failed_count']++;
                $job['errors'][] = basename($file['source']) . esc_html__(': Upload failed', 'anibas-file-manager');
            }
        }
    }

    private function try_delete_source($adapter, string $path, array &$job): void
    {
        try {
            $adapter->unlink($path);
        } catch (\Throwable $e) {
            $job['errors'][] = basename($path) . esc_html__(': Transferred but failed to delete source', 'anibas-file-manager');
        }
    }

    public function is_complete($work_queue)
    {
        return empty($work_queue['files_to_process']) && empty($work_queue['folders_to_process']);
    }

    public function next_phase()
    {
        ActivityLogger::get_instance()->log_message(__CLASS__ . ' Complete.');
        return 'wrapup';
    }
}
