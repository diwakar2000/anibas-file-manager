<?php

namespace Anibas;

if ( ! defined( 'ABSPATH' ) ) exit;


class DeletePhase extends OperationPhase
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

        // Reverse folders so we delete deepest-first (only once)
        if (empty($work_queue['folders_reversed'])) {
            $work_queue['folders_to_process'] = array_reverse($work_queue['folders_to_process']);
            $work_queue['folders_reversed'] = true;
        }

        // Phase 1: Delete files
        while (! empty($work_queue['files_to_process']) && ((microtime(true) - $start_time) < $time_limit)) {
            $file = array_shift($work_queue['files_to_process']);
            $path = $file['source'];

            $job['current_file'] = basename($path);

            try {
                $result = $fs->unlink($path);
                if ($result === false) {
                    $job['failed_count']++;
                    $job['errors'][] = basename($path) . esc_html__(': Delete failed', 'anibas-file-manager');
                    ActivityLogger::get_instance()->log_message('DeletePhase: unlink failed for ' . $path);
                } else {
                    $job['processed_count']++;
                }
            } catch (\Exception $e) {
                $job['failed_count']++;
                $job['errors'][] = basename($path) . ': ' . $e->getMessage();
                ActivityLogger::get_instance()->log_message('DeletePhase: ' . $e->getMessage());
            }
        }

        // If files remain, time is up — resume next tick
        if (! empty($work_queue['files_to_process'])) {
            return;
        }

        // Phase 2: Delete folders (deepest-first)
        while (! empty($work_queue['folders_to_process']) && ((microtime(true) - $start_time) < $time_limit)) {
            $path = array_shift($work_queue['folders_to_process']);

            $job['current_file'] = basename($path) . '/';

            try {
                // rmdir on remote adapters may delete marker objects; contents already removed above
                $result = $fs->rmdir($path);
                if ($result === false) {
                    $job['failed_count']++;
                    $job['errors'][] = basename($path) . '/: ' . esc_html__('Folder delete failed', 'anibas-file-manager');
                    ActivityLogger::get_instance()->log_message('DeletePhase: rmdir failed for ' . $path);
                } else {
                    $job['processed_count']++;
                }
            } catch (\Exception $e) {
                $job['failed_count']++;
                $job['errors'][] = basename($path) . '/: ' . $e->getMessage();
                ActivityLogger::get_instance()->log_message('DeletePhase: ' . $e->getMessage());
            }
        }
    }

    public function is_complete($work_queue)
    {
        return empty($work_queue['files_to_process']) && empty($work_queue['folders_to_process']);
    }

    public function next_phase()
    {
        ActivityLogger::get_instance()->log_message(__CLASS__ . " Complete.");
        return null; // No wrapup needed — done
    }
}
