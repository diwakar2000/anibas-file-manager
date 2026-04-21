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

        if (! isset($work_queue['files_to_process'])) {
            $work_queue['files_to_process'] = [];
        }

        if (! isset($work_queue['folders_to_process'])) {
            $work_queue['folders_to_process'] = [];
        }

        while (! empty($work_queue['files_to_process']) && ((microtime(true) - $start_time) < $time_limit)) {

            $file = array_shift($work_queue['files_to_process']);

            // Track current file for progress reporting
            $job['current_file']       = basename($file['source']);
            $job['current_file_bytes'] = $file['bytes_copied'] ?? 0;
            $job['current_file_size']  = $file['size'] ?? 0;

            if (!isset($job['checked_dirs'])) {
                $job['checked_dirs'] = [];
            }

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
            // destination existing is expected (it's our own partial write),
            // so re-running this block would either skip the file ('skip')
            // or scatter chunks across renamed files ('rename').
            $is_first_chunk = empty($file['bytes_copied']);
            if ($is_first_chunk && in_array($job['conflict_mode'], ['skip', 'rename'])) {
                if ($fs->exists($target)) {
                    if ($job['conflict_mode'] === 'skip') {
                        continue;
                    } elseif ($job['conflict_mode'] === 'rename') {
                        // Handle name clash resolution for different storage types
                        if (method_exists($manager, 'resolveNameClash')) {
                            $target = $manager->resolveNameClash($target);
                        } else {
                            // Fallback for remote storage: add timestamp and random digits suffix
                            $path_info = pathinfo($target);
                            $extension = isset($path_info['extension']) ? '.' . $path_info['extension'] : '';
                            $target = $path_info['dirname'] . '/' . $path_info['filename'] . '_' . date('Y-m-d_H-i-s') . '_' . mt_rand(100000, 999999) . $extension;
                        }
                        // Persist the resolved target on the work-queue entry
                        // so subsequent chunks of this file write to the same
                        // path instead of re-resolving from the original name.
                        $file['target'] = $target;
                    }
                }
            }

            try {
                if ($job['action'] === 'copy') {
                    // Use chunked copy for all storage adapters to avoid memory issues
                    if (method_exists($fs, 'copyFileInChunks')) {
                        $bytes_copied = isset($file['bytes_copied']) ? $file['bytes_copied'] : 0;
                        $result = $fs->copyFileInChunks($file['source'], $target, null, $bytes_copied);

                        if ($result === 9 || $result === 0) {
                            $job['processed_count']++;
                        } elseif ($result === 10) {
                            // File is not fully copied yet.
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
                            // Put back at beginning of queue
                            array_unshift($work_queue['files_to_process'], $file);

                            // Exit if time is up, so job manager can resume later
                            if ((microtime(true) - $start_time) >= $time_limit) {
                                return;
                            }
                            continue;
                        } else {
                            $this->cleanup_partial_file($fs, $target);
                            $job['failed_count']++;
                            $job['errors'][] = esc_html(basename($file['source'])) . esc_html__(': Copy operation failed (code ', 'anibas-file-manager') . esc_html($result) . ')';
                        }
                    } else {
                        // Fallback to standard copy if chunked method not available
                        $copy_result = $fs->copy($file['source'], $target);
                        ActivityLogger::get_instance()->log_message('TransferPhase: copy (fallback) result for ' . basename($file['source']) . ' = ' . var_export($copy_result, true));
                        // copyFileInChunks returns int codes (9=complete, 0=no error); treat non-false truthy values as success only for code 9 or true
                        $copy_success = ($copy_result === true || $copy_result === 9 || $copy_result === 0);
                        if ($copy_success) {
                            $job['processed_count']++;
                        } else {
                            $this->cleanup_partial_file($fs, $target);
                            $job['failed_count']++;
                            $job['errors'][] = esc_html(basename($file['source'])) . esc_html__(': Copy operation failed (code ', 'anibas-file-manager') . esc_html(var_export($copy_result, true)) . ')';
                        }
                    }
                } elseif ($job['action'] === 'move') {
                    ActivityLogger::get_instance()->log_message('TransferPhase: moving ' . $file['source'] . ' -> ' . $target);
                    $move_result = $fs->move($file['source'], $target);
                    ActivityLogger::get_instance()->log_message('TransferPhase: move result = ' . var_export($move_result, true));
                    if ($move_result) {
                        $job['processed_count']++;
                    } else {
                        $this->cleanup_partial_file($fs, $target);
                        $job['failed_count']++;
                        $job['errors'][] = esc_html(basename($file['source'])) . esc_html__(': Move operation failed', 'anibas-file-manager');
                        ActivityLogger::get_instance()->log_message('TransferPhase: MOVE FAILED for ' . $file['source'] . '. Errors: ' . implode(', ', $job['errors']));
                    }
                } else {
                    $job['failed_count']++;
                    $job['errors'][] = esc_html(basename($file['source'])) . esc_html__(': Unknown action ', 'anibas-file-manager') . esc_html($job['action']);
                }
            } catch (\Exception $e) {
                $this->cleanup_partial_file($fs, $target);
                ActivityLogger::get_instance()->log_message($e->getMessage());
                $job['failed_count']++;
                $job['errors'][] = basename($file['source']) . ': ' . $e->getMessage();
            }
        }

        foreach ($work_queue['folders_to_process'] as $i => $folder) {
            if ($fs->mkdir($folder)) {
                $job['processed_count']++;
            } else {
                ActivityLogger::get_instance()->log_message('Failed to create folder: ' . $folder);
            }

            unset($work_queue['folders_to_process'][$i]);
            if ((microtime(true) - $start_time) >= $time_limit) {
                return;
            }
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
        return empty($work_queue['files_to_process']) && empty($work_queue['folders_to_process']);
    }

    public function next_phase()
    {
        ActivityLogger::get_instance()->log_message(__CLASS__ . " Complete.");
        return 'wrapup';
    }
}
