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
        $job_id = $job['id'] ?? null;
        $work_queue['_job_id'] = $job_id;
        $trash_mode = ! empty($job['trash_mode']) && method_exists($fs, 'moveToTrash');

        if (! isset($work_queue['files_to_process'])) {
            $work_queue['files_to_process'] = [];
        }
        if (! isset($work_queue['folders_to_process'])) {
            $work_queue['folders_to_process'] = [];
        }
        if (! isset($work_queue['files_cursor'])) {
            $work_queue['files_cursor'] = 0;
        }

        // Phase 1: Delete files. Drain legacy in-memory entries first, then read from spool.
        while (! empty($work_queue['files_to_process']) && ((microtime(true) - $start_time) < $time_limit)) {
            $file = array_shift($work_queue['files_to_process']);
            $entry = is_array($file) ? $file : ['source' => $file];
            $this->process_entry($entry, $fs, $job, $trash_mode);
        }
        if (! empty($work_queue['files_to_process'])) {
            return;
        }

        if ($job_id !== null) {
            while (! JobQueueSpool::is_eof($job_id, 'files', $work_queue['files_cursor'])
                && ((microtime(true) - $start_time) < $time_limit)) {

                $peek = JobQueueSpool::peek($job_id, 'files', $work_queue['files_cursor']);
                $work_queue['files_cursor'] = $peek['next_cursor'];
                $entry = $peek['item'];
                if ($entry === null) {
                    continue;
                }
                $this->process_entry($entry, $fs, $job, $trash_mode);
            }
            if (! JobQueueSpool::is_eof($job_id, 'files', $work_queue['files_cursor'])) {
                return;
            }
        }

        // Trash mode operates on direct children only (list_depth=1) — no
        // separate folder-rmdir pass: moveToTrash handles directories itself.
        if ($trash_mode) {
            return;
        }

        // Phase 2: Delete folders deepest-first. Legacy in-memory folders are
        // reversed once; the JSONL folder spool is read backward by byte cursor
        // so directory-heavy trees never become a giant PHP array.
        if (empty($work_queue['folders_loaded'])) {
            $work_queue['folders_to_process'] = array_reverse($work_queue['folders_to_process']);
            $work_queue['folders_loaded'] = true;
            if ($job_id !== null && ! isset($work_queue['folders_reverse_cursor'])) {
                $work_queue['folders_reverse_cursor'] = JobQueueSpool::size($job_id, 'folders');
            }
        }

        while (! empty($work_queue['folders_to_process']) && ((microtime(true) - $start_time) < $time_limit)) {
            $path = array_shift($work_queue['folders_to_process']);
            $this->delete_folder($path, $fs, $job);
        }
        if (! empty($work_queue['folders_to_process'])) {
            return;
        }

        if ($job_id !== null) {
            while (! empty($work_queue['folders_reverse_cursor']) && ((microtime(true) - $start_time) < $time_limit)) {
                $peek = JobQueueSpool::previous($job_id, 'folders', (int) $work_queue['folders_reverse_cursor']);
                $work_queue['folders_reverse_cursor'] = $peek['previous_cursor'];
                $entry = $peek['item'];
                if ($entry === null || empty($entry['path'])) {
                    continue;
                }
                $this->delete_folder($entry['path'], $fs, $job);
            }
        }
    }

    private function process_entry(array $entry, $fs, array &$job, bool $trash_mode): void
    {
        $path = $entry['source'] ?? '';
        if ($path === '') {
            return;
        }
        $job['current_file'] = basename($path);

        if ($trash_mode) {
            try {
                $r = method_exists($fs, 'moveQueuedItemToTrash')
                    ? $fs->moveQueuedItemToTrash($path, (string) ($job['source_root'] ?? ''))
                    : $fs->moveToTrash($path);
                // moveToTrash returns:
                //   true   — atomic rename succeeded (item is in trash now).
                //   string — child move was enqueued as a separate background job.
                //   false  — failed.
                if ($r === false) {
                    $job['failed_count']++;
                    $job['errors'][] = basename($path) . esc_html__(': Trash move failed', 'anibas-file-manager');
                    ActivityLogger::get_instance()->log_message('DeletePhase: moveToTrash failed for ' . $path);
                    return;
                }
                $job['processed_count']++;
                if (is_string($r)) {
                    if (! isset($job['child_jobs'])) {
                        $job['child_jobs'] = [];
                    }
                    $job['child_jobs'][] = $r;
                    $this->annotate_child_job($r, $job);
                }
            } catch (\Exception $e) {
                $job['failed_count']++;
                $job['errors'][] = basename($path) . ': ' . $e->getMessage();
                ActivityLogger::get_instance()->log_message('DeletePhase: trash ' . $e->getMessage());
            }
            return;
        }

        try {
            // Trust the spool's classification: list-phase tags folders with
            // is_folder=true; everything else is a file. Calling $fs->is_dir()
            // here would re-run assertAllowed() per entry and slow large jobs
            // by 5–10× without changing behaviour.
            $is_folder = ! empty($entry['is_folder']);
            $result = $is_folder
                ? (method_exists($fs, 'queuedRmdir')
                    ? $fs->queuedRmdir($path, (string) ($job['source_root'] ?? ''))
                    : $fs->rmdir($path))
                : (method_exists($fs, 'queuedUnlink')
                    ? $fs->queuedUnlink($path, (string) ($job['source_root'] ?? ''))
                    : $fs->unlink($path));
            if ($result === false) {
                $job['failed_count']++;
                $job['errors'][] = basename($path) . esc_html__(': Delete failed', 'anibas-file-manager');
                ActivityLogger::get_instance()->log_message('DeletePhase: ' . ($is_folder ? 'rmdir' : 'unlink') . ' failed for ' . $path);
            } else {
                $job['processed_count']++;
            }
        } catch (\Exception $e) {
            $job['failed_count']++;
            $job['errors'][] = basename($path) . ': ' . $e->getMessage();
            ActivityLogger::get_instance()->log_message('DeletePhase: ' . $e->getMessage());
        }
    }

    private function annotate_child_job(string $job_id, array $parent_job): void
    {
        $meta = [];
        foreach (['ui_group_id', 'ui_group_action', 'ui_group_mode', 'ui_group_label', 'ui_group_source'] as $key) {
            if (array_key_exists($key, $parent_job)) {
                $meta[$key] = $parent_job[$key];
            }
        }
        if (! empty($meta) && class_exists(__NAMESPACE__ . '\\BackgroundProcessor')) {
            BackgroundProcessor::annotate_jobs([$job_id], $meta);
        }
    }

    private function delete_folder($path, $fs, array &$job): void
    {
        if (is_array($path)) {
            $path = $path['path'] ?? '';
        }
        if ($path === '') {
            return;
        }
        $job['current_file'] = basename($path) . '/';
        try {
            $result = method_exists($fs, 'queuedRmdir')
                ? $fs->queuedRmdir($path, (string) ($job['source_root'] ?? ''))
                : $fs->rmdir($path);
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

    public function is_complete($work_queue)
    {
        if (! empty($work_queue['files_to_process']) || ! empty($work_queue['folders_to_process'])) {
            return false;
        }
        $job_id = $work_queue['_job_id'] ?? null;
        if ($job_id !== null) {
            if (! JobQueueSpool::is_eof($job_id, 'files', $work_queue['files_cursor'] ?? 0)) {
                return false;
            }
            if (! empty($work_queue['folders_reverse_cursor'])) {
                return false;
            }
            if (empty($work_queue['folders_loaded']) && JobQueueSpool::exists($job_id, 'folders')) {
                return false;
            }
        }
        return true;
    }

    public function next_phase()
    {
        ActivityLogger::get_instance()->log_message(__CLASS__ . " Complete.");
        return null; // No wrapup needed — done
    }
}
