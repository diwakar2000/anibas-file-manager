<?php

namespace Anibas;

if (! defined('ABSPATH')) exit;




class TransferPhase extends OperationPhase
{
    public function execute(&$job, &$work_queue, $manager, &$context)
    {
        $start_time = $context['start_time'];
        $time_limit = $context['time_limit'];
        $fs = $context['fs_adapter'];
        $remove_source = ! empty($job['remove_source']) || $job['action'] === 'move';
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

        // Drain legacy in-memory queue first (jobs that were enqueued before the spool refactor).
        while (! empty($work_queue['files_to_process']) && ((microtime(true) - $start_time) < $time_limit)) {
            $file = array_shift($work_queue['files_to_process']);
            $action = $this->process_file($file, $fs, $manager, $job, $remove_source, $start_time, $time_limit);
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

        // Read from the JSONL spool. On in-progress chunks we keep the cursor
        // pinned to the start of the current line and persist per-file chunk
        // state in $work_queue['current_file_state'] until the file completes.
        if ($job_id !== null) {
            while (! JobQueueSpool::is_eof($job_id, 'files', $work_queue['files_cursor'])
                && ((microtime(true) - $start_time) < $time_limit)) {

                $peek = JobQueueSpool::peek($job_id, 'files', $work_queue['files_cursor']);
                $line_end = $peek['next_cursor'];
                $file = $peek['item'];

                if ($file === null) {
                    // Malformed line — skip and advance.
                    $work_queue['files_cursor'] = $line_end;
                    continue;
                }

                // Overlay any per-file chunk state stashed from a prior request.
                if (! empty($work_queue['current_file_state'])) {
                    $file = array_merge($file, $work_queue['current_file_state']);
                }

                $action = $this->process_file($file, $fs, $manager, $job, $remove_source, $start_time, $time_limit);

                if ($action === 'advance') {
                    $work_queue['files_cursor'] = $line_end;
                    unset($work_queue['current_file_state']);
                } elseif ($action === 'requeue') {
                    // Keep cursor pinned; persist updated chunk state for next tick.
                    $work_queue['current_file_state'] = [
                        'bytes_copied' => $file['bytes_copied'] ?? 0,
                        'target'       => $file['target'],
                    ];
                    if ((microtime(true) - $start_time) >= $time_limit) {
                        return;
                    }
                }
            }
        }

        // mkdir folders queued by the list phase (target dirs for empty source folders, etc.).
        // Drain legacy in-memory list first, then read from the folders spool.
        while (! empty($work_queue['folders_to_process']) && ((microtime(true) - $start_time) < $time_limit)) {
            $folder = array_shift($work_queue['folders_to_process']);
            $folder_path = is_array($folder) ? ($folder['path'] ?? '') : $folder;
            $this->mkdir_one($folder_path, $fs, $job);
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
                if ($entry === null) {
                    continue;
                }
                $this->mkdir_one($entry['path'] ?? '', $fs, $job);
            }
        }
    }

    /**
     * Process a single file entry. Returns one of:
     *   'advance' — file processed (success or terminal failure); caller should advance.
     *   'requeue' — chunk in progress; caller should keep cursor and re-feed this file.
     *
     * On 'requeue', $file is mutated with updated bytes_copied and (possibly) target.
     */
    private function process_file(array &$file, $fs, $manager, array &$job, bool $remove_source, float $start_time, float $time_limit): string
    {
        $job['current_file']       = basename($file['source']);
        $job['current_file_bytes'] = $file['bytes_copied'] ?? 0;
        $job['current_file_size']  = $file['size'] ?? 0;

        $target_dir = dirname($file['target']);
        if (! isset($job['checked_dirs'][$target_dir])) {
            if (! $fs->exists($target_dir)) {
                ActivityLogger::get_instance()->log_message('TransferPhase: creating target dir ' . $target_dir);
                $mkdir_result = $fs->mkdir($target_dir);
                ActivityLogger::get_instance()->log_message('TransferPhase: mkdir result = ' . var_export($mkdir_result, true));
            }
            $job['checked_dirs'][$target_dir] = true;
        }

        $target = $file['target'];
        // Only resolve conflicts on the first chunk. On chunks 2+, the
        // destination existing is expected (it's our own partial write).
        $is_first_chunk = empty($file['bytes_copied']);
        if ($is_first_chunk && in_array($job['conflict_mode'], ['skip', 'rename'])) {
            if ($fs->exists($target)) {
                if ($job['conflict_mode'] === 'skip') {
                    return 'advance';
                } elseif ($job['conflict_mode'] === 'rename') {
                    if (method_exists($manager, 'resolveNameClash')) {
                        $target = $manager->resolveNameClash($target);
                    } else {
                        $path_info = pathinfo($target);
                        $extension = isset($path_info['extension']) ? '.' . $path_info['extension'] : '';
                        $target = $path_info['dirname'] . '/' . $path_info['filename'] . '_' . gmdate('Y-m-d_H-i-s') . '_' . wp_rand(100000, 999999) . $extension;
                    }
                    $file['target'] = $target;
                }
            }
        }

        try {
            if ($job['action'] !== 'copy' && $job['action'] !== 'move') {
                $job['failed_count']++;
                $job['errors'][] = esc_html(basename($file['source'])) . esc_html__(': Unknown action ', 'anibas-file-manager') . esc_html($job['action']);
                return 'advance';
            }
            if (! method_exists($fs, 'copyFileInChunks')) {
                $job['failed_count']++;
                $job['errors'][] = esc_html(basename($file['source'])) . esc_html__(': Chunked transfer is not supported for this storage adapter', 'anibas-file-manager');
                return 'advance';
            }

            $bytes_copied = isset($file['bytes_copied']) ? $file['bytes_copied'] : 0;
            $result = $fs->copyFileInChunks($file['source'], $target, null, $bytes_copied);

            if ($result === 9 || $result === 0) {
                $removed = ! $remove_source
                    || (method_exists($fs, 'queuedUnlink')
                        ? $fs->queuedUnlink($file['source'], (string) ($job['source_root'] ?? ''), ! empty($job['allow_trash_root']))
                        : $fs->unlink($file['source']));
                if (! $removed) {
                    $this->cleanup_partial_file($fs, $target);
                    $job['failed_count']++;
                    $job['errors'][] = esc_html(basename($file['source'])) . esc_html__(': Move cleanup failed', 'anibas-file-manager');
                } else {
                    $job['processed_count']++;
                }
                return 'advance';
            } elseif ($result === 10) {
                if (method_exists($fs, 'getCopyProgress')) {
                    $progress = $fs->getCopyProgress($file['source'], $target);
                    $file['bytes_copied'] = $progress['next_bytes_copied'];
                    $job['current_file_bytes'] = $progress['next_bytes_copied'];
                    if (isset($progress['file_size'])) {
                        $job['current_file_size'] = $progress['file_size'];
                    }
                } else {
                    $file['bytes_copied'] = $bytes_copied;
                }
                return 'requeue';
            } else {
                $this->cleanup_partial_file($fs, $target);
                $job['failed_count']++;
                $job['errors'][] = esc_html(basename($file['source'])) . esc_html__(': Transfer operation failed (code ', 'anibas-file-manager') . esc_html($result) . ')';
                return 'advance';
            }
        } catch (\Exception $e) {
            $this->cleanup_partial_file($fs, $target);
            ActivityLogger::get_instance()->log_message($e->getMessage());
            $job['failed_count']++;
            $job['errors'][] = basename($file['source']) . ': ' . $e->getMessage();
            return 'advance';
        }
    }

    private function mkdir_one(string $folder, $fs, array &$job): void
    {
        if ($folder === '') {
            return;
        }
        if ($fs->mkdir($folder)) {
            $job['processed_count']++;
        } else {
            ActivityLogger::get_instance()->log_message('Failed to create folder: ' . $folder);
        }
    }

    private function cleanup_partial_file($fs, $target)
    {
        try {
            if ($fs->exists($target)) {
                $fs->unlink($target);
                ActivityLogger::get_instance()->log_message("Cleaned up partial file: {$target}");
            }
        } catch (\Exception $e) {
            ActivityLogger::get_instance()->log_message("Failed to clean up partial file: " . $e->getMessage());
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
        ActivityLogger::get_instance()->log_message(__CLASS__ . " Complete.");
        return 'wrapup';
    }
}
