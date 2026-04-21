<?php

namespace Anibas;

if ( ! defined( 'ABSPATH' ) ) exit;




class WrapupPhase extends OperationPhase
{
    public function execute(&$job, &$work_queue, $manager, &$context)
    {
        $fs = $context['fs_adapter'];
        $start_time = $context['start_time'];
        $time_limit = $context['time_limit'];

        // For move operations, delete the source folder
        if ($job['action'] === 'move' && $fs->is_dir($job['source_root'])) {
            $this->delete_empty_folders_iterative($job, $work_queue, $fs, $start_time, $time_limit);

            // Check if we need more time
            if ((microtime(true) - $start_time) >= $time_limit) {
                return; // Will resume on next request
            }
        }

        // Log activity
        $source_root = is_array($job['source_root']) ? $job['source_root']['path'] ?? '' : $job['source_root'];
        $item_name = basename($source_root);
        $action = match ($job['action']) {
            'move' => 'moved',
            'copy' => 'copied',
            'delete' => 'deleted',
            'unzip' => 'unzipped',
        };
        ActivityLogger::get_instance()->log($action, $item_name, $source_root, $job['dest_root']);

        $job['status'] = 'completed';
        $job['completed_at'] = time();
        delete_option($job['work_queue_id']);
    }

    private function delete_empty_folders_iterative(&$job, &$work_queue, $fs, $start_time, $time_limit)
    {
        // Initialize folder deletion queue if not exists
        if (! isset($work_queue['folders_to_delete'])) {
            $work_queue['folders_to_delete'] = [];
            $work_queue['phase'] = 'discovery'; // Start with discovery phase
            $work_queue['scanned_folders'] = []; // Track scanned folders

            // Start with source root
            $source_root = is_array($job['source_root']) ? $job['source_root']['path'] ?? '' : $job['source_root'];
            $work_queue['folders_to_delete'][] = $source_root;
            ActivityLogger::get_instance()->log_message('Starting discovery phase for: ' . $source_root);
        }

        // Phase 1: Discovery - Build complete folder tree
        if ($work_queue['phase'] === 'discovery') {
            $this->discovery_phase($work_queue, $fs, $start_time, $time_limit);

            // Check if discovery phase is complete (queue empty OR time limit reached)
            if (empty($work_queue['folders_to_delete'])) {
                // Discovery complete - move to deletion phase
                $work_queue['phase'] = 'deletion';
                // Rebuild deletion queue in proper order (deepest first)
                $work_queue['folders_to_delete'] = array_reverse($work_queue['scanned_folders']);
                ActivityLogger::get_instance()->log_message('Discovery complete, starting deletion phase with ' . count($work_queue['folders_to_delete']) . ' folders');
            } elseif ((microtime(true) - $start_time) >= $time_limit) {
                ActivityLogger::get_instance()->log_message('Discovery phase time limit reached, will resume');
                return;
            }
        }

        // Phase 2: Deletion - Delete empty folders
        if ($work_queue['phase'] === 'deletion') {
            $this->deletion_phase($work_queue, $fs, $start_time, $time_limit);
        }

        // Clean up queue when done
        if (empty($work_queue['folders_to_delete'])) {
            unset($work_queue['folders_to_delete']);
            unset($work_queue['phase']);
            unset($work_queue['scanned_folders']);
        }
    }

    private function discovery_phase(&$work_queue, $fs, $start_time, $time_limit)
    {
        while (! empty($work_queue['folders_to_delete']) && ((microtime(true) - $start_time) < $time_limit)) {
            // Get the next folder to scan
            $folder_path = array_pop($work_queue['folders_to_delete']);

            // Ensure it's a valid string path
            if (is_array($folder_path)) {
                $folder_path = $folder_path['path'] ?? '';
            }

            if (empty($folder_path) || ! $fs->is_dir($folder_path)) {
                continue; // Skip invalid folders
            }

            // Skip already-scanned folders to prevent infinite loops
            if (in_array($folder_path, $work_queue['scanned_folders'], true)) {
                continue;
            }

            // Add to scanned folders list
            $work_queue['scanned_folders'][] = $folder_path;

            // Get folder items (scandir returns basenames, not full paths)
            $items = $this->get_folder_items($fs, $folder_path);

            // Build full paths and filter only subfolders
            $folder_items = [];
            foreach ($items as $item_name) {
                $full_path = rtrim($folder_path, '/') . '/' . ltrim($item_name, '/');
                if ($fs->is_dir($full_path)) {
                    $folder_items[] = $full_path;
                }
            }

            // Add subfolders to queue for scanning (only if not already scanned)
            foreach ($folder_items as $subfolder_path) {
                if (! in_array($subfolder_path, $work_queue['scanned_folders'], true)) {
                    $work_queue['folders_to_delete'][] = $subfolder_path;
                    ActivityLogger::get_instance()->log_message('Discovered subfolder: ' . $subfolder_path);
                }
            }

            ActivityLogger::get_instance()->log_message('Scanned folder: ' . $folder_path . ', found ' . count($folder_items) . ' subfolders');
        }
    }

    private function deletion_phase(&$work_queue, $fs, $start_time, $time_limit)
    {
        $previous_skipped_count = -1;

        while (! empty($work_queue['folders_to_delete']) && (microtime(true) - $start_time) < $time_limit) {
            // Get one folder to process (shallowest first to match our queue order)
            $folder_path = array_shift($work_queue['folders_to_delete']);

            // Ensure it's a valid string path
            if (is_array($folder_path)) {
                $folder_path = $folder_path['path'] ?? '';
            }

            if (empty($folder_path) || ! $fs->is_dir($folder_path)) {
                continue;
            }

            // Get folder items (reused method)
            $actual_items = $this->get_folder_items($fs, $folder_path);

            if (empty($actual_items)) {
                try {
                    ActivityLogger::get_instance()->log_message('Deleting empty folder: ' . $folder_path);
                    $rm = $fs->rmdir($folder_path);
                    if ($rm === false) {
                        // Surface rmdir failure so the job doesn't silently report success
                        // when the source folder of a move couldn't be removed.
                        $job['errors'][] = basename($folder_path) . esc_html__('/: Failed to remove source folder after move', 'anibas-file-manager');
                        ActivityLogger::get_instance()->log_message('rmdir returned false for: ' . $folder_path);
                    } else {
                        ActivityLogger::get_instance()->log_message('Successfully deleted folder: ' . $folder_path);
                    }
                } catch (\Exception $e) {
                    $job['errors'][] = basename($folder_path) . '/: ' . $e->getMessage();
                    ActivityLogger::get_instance()->log_message('Failed to delete folder: ' . $folder_path . ' - ' . $e->getMessage());
                }
            } else {
                // Skip folder and put it back at the end for next iteration
                $work_queue['folders_to_delete'][] = $folder_path;
                ActivityLogger::get_instance()->log_message('Skipping folder - not empty: ' . $folder_path);
            }

            // If no progress made (same number of skips), break to prevent infinite loop
            if ($previous_skipped_count === count($work_queue['folders_to_delete'])) {
                ActivityLogger::get_instance()->log_message('No progress made, breaking to prevent infinite loop');
                break;
            }
            $previous_skipped_count = count($work_queue['folders_to_delete']);
        }

        // Reset to discovery phase if folders to delete is not empty and count equals skipped count
        if (! empty($work_queue['folders_to_delete']) && $previous_skipped_count === count($work_queue['folders_to_delete'])) {
            ActivityLogger::get_instance()->log_message('No progress made, resetting to discovery phase');
            $work_queue['phase'] = 'discovery';
            $work_queue['scanned_folders'] = [];
        }
    }

    private function get_folder_items($fs, $folder_path)
    {
        $items = $fs->scandir($folder_path);

        // Handle different scandir return formats and filter out . and ..
        $actual_items = [];
        if (is_array($items) && isset($items['items'])) {
            // Remote storage format
            foreach ($items['items'] as $item_path => $item_data) {
                $item_name = basename($item_path);
                if ($item_name !== '.' && $item_name !== '..') {
                    $actual_items[] = $item_path;
                }
            }
        } else {
            // Local storage format
            foreach ($items as $item) {
                if ($item !== '.' && $item !== '..') {
                    $actual_items[] = $item;
                }
            }
        }

        return $actual_items;
    }

    public function is_complete($work_queue)
    {
        return empty($work_queue['folders_to_delete']);
    }

    public function next_phase()
    {
        ActivityLogger::get_instance()->log_message(__CLASS__ . " Complete.");
        return null;
    }
}
