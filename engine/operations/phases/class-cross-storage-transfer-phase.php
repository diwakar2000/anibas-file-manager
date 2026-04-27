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
        $job_id = $job['id'] ?? null;
        $work_queue['_job_id'] = $job_id;

        if (! isset($work_queue['files_to_process'])) {
            $work_queue['files_to_process'] = [];
        }
        if (! isset($work_queue['folders_to_process'])) {
            $work_queue['folders_to_process'] = [];
        }
        if (! isset($work_queue['files_cursor'])) {
            $work_queue['files_cursor'] = 0;
        }
        if (! isset($work_queue['folders_cursor'])) {
            $work_queue['folders_cursor'] = 0;
        }
        if (! isset($job['checked_dirs'])) {
            $job['checked_dirs'] = [];
        }

        // Drain legacy in-memory queue first.
        while (! empty($work_queue['files_to_process']) && ((microtime(true) - $start_time) < $time_limit)) {
            $file = array_shift($work_queue['files_to_process']);
            $action = $this->process_file($file, $source_adapter, $dest_adapter, $source_is_local, $temp_dir, $job, $work_queue, $start_time, $time_limit);
            if ($action === 'requeue') {
                array_unshift($work_queue['files_to_process'], $file);
                if ((microtime(true) - $start_time) >= $time_limit) {
                    return;
                }
            }
        }
        if (! empty($work_queue['files_to_process'])) {
            return;
        }

        // Read from JSONL spool. Per-file state (offsets, temp_file, conflict_resolved)
        // is overlaid from $work_queue['current_file_state'] until the file completes.
        if ($job_id !== null) {
            while (! JobQueueSpool::is_eof($job_id, 'files', $work_queue['files_cursor'])
                && ((microtime(true) - $start_time) < $time_limit)) {

                $peek = JobQueueSpool::peek($job_id, 'files', $work_queue['files_cursor']);
                $line_end = $peek['next_cursor'];
                $file = $peek['item'];
                if ($file === null) {
                    $work_queue['files_cursor'] = $line_end;
                    continue;
                }
                if (! empty($work_queue['current_file_state'])) {
                    $file = array_merge($file, $work_queue['current_file_state']);
                }

                $action = $this->process_file($file, $source_adapter, $dest_adapter, $source_is_local, $temp_dir, $job, $work_queue, $start_time, $time_limit);

                if ($action === 'advance') {
                    $work_queue['files_cursor'] = $line_end;
                    unset($work_queue['current_file_state']);
                } elseif ($action === 'requeue') {
                    // Persist all the per-file state fields the chunk handlers may have set.
                    $state = [];
                    foreach (['target', 'conflict_resolved', 'size', 'upload_offset', 'download_offset', 'download_done', 'temp_file', 'local_target_prepared'] as $k) {
                        if (array_key_exists($k, $file)) {
                            $state[$k] = $file[$k];
                        }
                    }
                    $work_queue['current_file_state'] = $state;
                    if ((microtime(true) - $start_time) >= $time_limit) {
                        return;
                    }
                }
            }
        }

        // Folder mkdir on destination — drain legacy then spool.
        while (! empty($work_queue['folders_to_process']) && ((microtime(true) - $start_time) < $time_limit)) {
            $folder = array_shift($work_queue['folders_to_process']);
            $folder_path = is_array($folder) ? ($folder['path'] ?? '') : $folder;
            if ($folder_path !== '') {
                $dest_adapter->mkdir($folder_path);
                $job['processed_count']++;
            }
        }
        if (! empty($work_queue['folders_to_process'])) {
            return;
        }

        if ($job_id !== null) {
            while (! JobQueueSpool::is_eof($job_id, 'folders', $work_queue['folders_cursor'])
                && ((microtime(true) - $start_time) < $time_limit)) {

                $peek = JobQueueSpool::peek($job_id, 'folders', $work_queue['folders_cursor']);
                $work_queue['folders_cursor'] = $peek['next_cursor'];
                $entry = $peek['item'];
                if ($entry === null || empty($entry['path'])) {
                    continue;
                }
                $dest_adapter->mkdir($entry['path']);
                $job['processed_count']++;
            }
        }
    }

    /**
     * Process one file entry. Returns 'advance' (move to next) or 'requeue'
     * (chunk in flight; caller should keep cursor and persist updated $file state).
     * On 'requeue', $file is mutated to reflect new offsets/state.
     */
    private function process_file(array &$file, $source_adapter, $dest_adapter, bool $source_is_local, string $temp_dir, array &$job, array &$work_queue, float $start_time, float $time_limit): string
    {
        $target = $file['target'];

        $job['current_file']       = basename($file['source']);
        $job['current_file_bytes'] = $file['upload_offset'] ?? $file['download_offset'] ?? 0;
        $job['current_file_size']  = $file['size'] ?? 0;

        // Handle conflict on destination (only check once per file, not on resume).
        if (empty($file['conflict_resolved'])) {
            if (in_array($job['conflict_mode'], ['skip', 'rename'])) {
                if ($dest_adapter->exists($target)) {
                    if ($job['conflict_mode'] === 'skip') {
                        return 'advance';
                    } elseif ($job['conflict_mode'] === 'rename') {
                        $pathinfo  = pathinfo($target);
                        $extension = isset($pathinfo['extension']) ? '.' . $pathinfo['extension'] : '';
                        $target    = $pathinfo['dirname'] . '/' . $pathinfo['filename'] . '_' . gmdate('Y-m-d_H-i-s') . '_' . wp_rand(100000, 999999) . $extension;
                        $file['target'] = $target;
                    }
                }
            }
            $file['conflict_resolved'] = true;
        }

        try {
            // Ensure destination directory exists (cached).
            $target_dir = dirname($target);
            if (! isset($job['checked_dirs'][$target_dir])) {
                if (! $dest_adapter->exists($target_dir)) {
                    $dest_adapter->mkdir($target_dir);
                }
                $job['checked_dirs'][$target_dir] = true;
            }

            if ($source_is_local) {
                return $this->transfer_local_to_remote($file, $target, $source_adapter, $dest_adapter, $job);
            } else {
                return $this->transfer_remote_to_local($file, $target, $source_adapter, $dest_adapter, $job, $temp_dir);
            }
        } catch (\Throwable $e) {
            ActivityLogger::get_instance()->log_message('CrossStorageTransfer error: ' . $e->getMessage());
            $job['failed_count']++;
            $job['errors'][] = basename($file['source']) . ': ' . $e->getMessage();
            if (! empty($file['temp_file']) && file_exists($file['temp_file'])) {
                @unlink($file['temp_file']);
            }
            return 'advance';
        }
    }

    /**
     * Local → Remote: upload using chunked method.
     * Returns 'advance' or 'requeue'.
     */
    private function transfer_local_to_remote(array &$file, string $target, $source_adapter, $dest_adapter, array &$job): string
    {
        $local_path = $file['source'];
        if (! is_file($local_path)) {
            $job['failed_count']++;
            $job['errors'][] = basename($local_path) . esc_html__(': Source file not found', 'anibas-file-manager');
            return 'advance';
        }

        if (empty($file['size'])) {
            $file['size'] = filesize($local_path) ?: 0;
        }

        $offset = $file['upload_offset'] ?? 0;
        $result = $dest_adapter->upload_from_local_chunked($local_path, $target, $offset);

        if ($result['status'] === 9) {
            $job['current_file_bytes'] = $result['bytes_copied'];
            $job['current_file_size']  = $result['bytes_copied'];
            $job['processed_count']++;
            if ($job['action'] === 'move') {
                $this->try_delete_source($source_adapter, $file['source'], $job);
            }
            return 'advance';
        } elseif ($result['status'] === 10) {
            $file['upload_offset'] = $result['bytes_copied'];
            $job['current_file_bytes'] = $result['bytes_copied'];
            $job['current_file_size']  = $file['size'];
            return 'requeue';
        } else {
            try {
                if ($dest_adapter->exists($target)) {
                    $dest_adapter->unlink($target);
                }
            } catch (\Throwable $e) {
                ActivityLogger::get_instance()->log_message('CrossStorage upload cleanup failed for ' . $target . ': ' . $e->getMessage());
            }
            $job['failed_count']++;
            $job['errors'][] = esc_html(basename($local_path)) . esc_html__(': Upload failed (code ', 'anibas-file-manager') . esc_html($result['status']) . ')';
            return 'advance';
        }
    }

    /**
     * Remote → Local: download using chunked method.
     * Returns 'advance' or 'requeue'.
     */
    private function transfer_remote_to_local(array &$file, string $target, $source_adapter, $dest_adapter, array &$job, string $temp_dir): string
    {
        $dest_is_local = $dest_adapter->is_local_storage();
        $file_size = $file['size'] ?? 0;

        if ($dest_is_local) {
            if (method_exists($dest_adapter, 'frontendPathToReal')) {
                $local_target = $dest_adapter->frontendPathToReal($target);
            } else {
                $local_target = $target;
            }

            $dir = dirname($local_target);
            if (! is_dir($dir)) {
                wp_mkdir_p($dir);
            }

            $offset = $file['download_offset'] ?? 0;
            if ($offset === 0 && empty($file['local_target_prepared'])) {
                if (file_exists($local_target) && $job['conflict_mode'] === 'overwrite') {
                    @unlink($local_target);
                }
                $file['local_target_prepared'] = true;
            }

            $result = $source_adapter->download_to_local_chunked($file['source'], $local_target, $offset);

            if ($result['status'] === 9) {
                $job['current_file_bytes'] = $result['bytes_copied'];
                $job['current_file_size']  = $result['bytes_copied'];
                $job['processed_count']++;
                if ($job['action'] === 'move') {
                    $this->try_delete_source($source_adapter, $file['source'], $job);
                }
                return 'advance';
            } elseif ($result['status'] === 10) {
                $file['download_offset'] = $result['bytes_copied'];
                $job['current_file_bytes'] = $result['bytes_copied'];
                $job['current_file_size']  = $file_size;
                return 'requeue';
            } else {
                if (file_exists($local_target)) {
                    @unlink($local_target);
                }
                $job['failed_count']++;
                $job['errors'][] = esc_html(basename($file['source'])) . esc_html__(': Download failed (code ', 'anibas-file-manager') . esc_html($result['status']) . ')';
                return 'advance';
            }
        }

        // Remote-to-remote fallback (shouldn't happen): temp staging.
        $temp_file = $file['temp_file'] ?? $temp_dir . '/' . md5($file['source'] . $target) . '.tmp';
        $d_offset  = $file['download_offset'] ?? 0;
        $u_offset  = $file['upload_offset'] ?? 0;

        if (empty($file['download_done'])) {
            $result = $source_adapter->download_to_local_chunked($file['source'], $temp_file, $d_offset);
            if ($result['status'] === 9) {
                $file['download_done'] = true;
                $file['temp_file']     = $temp_file;
                // fall through to upload step below
            } elseif ($result['status'] === 10) {
                $file['temp_file']       = $temp_file;
                $file['download_offset'] = $result['bytes_copied'];
                $job['current_file_bytes'] = $result['bytes_copied'];
                $job['current_file_size']  = $file_size;
                return 'requeue';
            } else {
                @unlink($temp_file);
                $job['failed_count']++;
                $job['errors'][] = basename($file['source']) . esc_html__(': Download failed', 'anibas-file-manager');
                return 'advance';
            }
        }

        $result = $dest_adapter->upload_from_local_chunked($temp_file, $target, $u_offset);
        if ($result['status'] === 9) {
            @unlink($temp_file);
            $job['current_file_bytes'] = $result['bytes_copied'];
            $job['current_file_size']  = $result['bytes_copied'];
            $job['processed_count']++;
            if ($job['action'] === 'move') {
                $this->try_delete_source($source_adapter, $file['source'], $job);
            }
            return 'advance';
        } elseif ($result['status'] === 10) {
            $file['upload_offset'] = $result['bytes_copied'];
            $job['current_file_bytes'] = $result['bytes_copied'];
            $job['current_file_size']  = $file_size;
            return 'requeue';
        } else {
            @unlink($temp_file);
            $job['failed_count']++;
            $job['errors'][] = basename($file['source']) . esc_html__(': Upload failed', 'anibas-file-manager');
            return 'advance';
        }
    }

    private function try_delete_source($adapter, string $path, array &$job): void
    {
        try {
            $deleted = method_exists($adapter, 'queuedUnlink')
                ? $adapter->queuedUnlink($path, (string) ($job['source_root'] ?? ''))
                : $adapter->unlink($path);
            if (! $deleted) {
                $job['failed_count']++;
                $job['errors'][] = basename($path) . esc_html__(': Transferred but failed to delete source', 'anibas-file-manager');
            }
        } catch (\Throwable $e) {
            $job['failed_count']++;
            $job['errors'][] = basename($path) . esc_html__(': Transferred but failed to delete source', 'anibas-file-manager');
        }
    }

    public function is_complete($work_queue)
    {
        if (! empty($work_queue['files_to_process']) || ! empty($work_queue['folders_to_process'])) {
            return false;
        }
        if (! empty($work_queue['current_file_state'])) {
            return false;
        }
        $job_id = $work_queue['_job_id'] ?? null;
        if ($job_id !== null) {
            if (! JobQueueSpool::is_eof($job_id, 'files', $work_queue['files_cursor'] ?? 0)) {
                return false;
            }
            if (! JobQueueSpool::is_eof($job_id, 'folders', $work_queue['folders_cursor'] ?? 0)) {
                return false;
            }
        }
        return true;
    }

    public function next_phase()
    {
        ActivityLogger::get_instance()->log_message(__CLASS__ . ' Complete.');
        return 'wrapup';
    }
}
