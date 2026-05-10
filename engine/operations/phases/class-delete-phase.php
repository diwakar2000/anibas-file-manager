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
        $allow_trash_root = ! empty($job['allow_trash_root']);
        if (! empty($job['recreate_trash_root'])) {
            $work_queue['must_recreate_trash_root'] = true;
        }
        if ($this->should_recreate_keep_root($job)) {
            $work_queue['must_recreate_keep_root'] = true;
        }

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
            $this->process_entry($entry, $fs, $job, $trash_mode, $allow_trash_root);
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
                $this->process_entry($entry, $fs, $job, $trash_mode, $allow_trash_root);
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
            if (! empty($work_queue['folders_reverse_cursor'])) {
                return;
            }
        }

        $this->recreate_trash_root_if_needed($job, $work_queue);
        $this->recreate_kept_root_if_needed($job, $work_queue, $fs);
    }

    private function process_entry(array $entry, $fs, array &$job, bool $trash_mode, bool $allow_trash_root): void
    {
        $path = $entry['source'] ?? '';
        if ($path === '') {
            return;
        }
        if ($this->should_keep_root($job, $path)) {
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
                    ? $fs->queuedRmdir($path, (string) ($job['source_root'] ?? ''), $allow_trash_root)
                    : $fs->rmdir($path))
                : (method_exists($fs, 'queuedUnlink')
                    ? $fs->queuedUnlink($path, (string) ($job['source_root'] ?? ''), $allow_trash_root)
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
        if ($this->should_keep_root($job, $path)) {
            return;
        }
        $job['current_file'] = basename($path) . '/';
        try {
            $result = method_exists($fs, 'queuedRmdir')
                ? $fs->queuedRmdir($path, (string) ($job['source_root'] ?? ''), ! empty($job['allow_trash_root']))
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
        if (! empty($work_queue['must_recreate_trash_root']) && empty($work_queue['trash_root_recreated'])) {
            return false;
        }
        if (! empty($work_queue['must_recreate_keep_root']) && empty($work_queue['keep_root_recreated'])) {
            return false;
        }
        return true;
    }

    private function should_keep_root(array $job, string $path): bool
    {
        if (empty($job['keep_root'])) {
            return false;
        }

        $root = rtrim((string) ($job['source_root'] ?? ''), "/\\" . DIRECTORY_SEPARATOR);
        $here = rtrim($path, "/\\" . DIRECTORY_SEPARATOR);
        return $root !== '' && $root === $here;
    }

    private function should_recreate_keep_root(array $job): bool
    {
        if (empty($job['keep_root'])) {
            return false;
        }

        return ! empty($job['recreate_keep_root']) || (string) ($job['storage'] ?? 'local') !== 'local';
    }

    private function recreate_trash_root_if_needed(array &$job, array &$work_queue): void
    {
        if (empty($job['recreate_trash_root']) || ! empty($work_queue['trash_root_recreated'])) {
            return;
        }

        try {
            $trash_dir = function_exists('anibas_fm_get_trash_dir')
                ? anibas_fm_get_trash_dir()
                : (string) ($job['source_root'] ?? '');

            if (! is_dir($trash_dir) && ! wp_mkdir_p($trash_dir)) {
                throw new \RuntimeException(esc_html__('Unable to create trash folder.', 'anibas-file-manager'));
            }
            if (function_exists('anibas_fm_protect_dir')) {
                anibas_fm_protect_dir($trash_dir);
            }

            $index_file = rtrim($trash_dir, '/\\') . DIRECTORY_SEPARATOR . 'index.json';
            if (! file_exists($index_file)) {
                $wrote = @file_put_contents($index_file, wp_json_encode([]), LOCK_EX); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents
                if ($wrote === false) {
                    throw new \RuntimeException(esc_html__('Unable to initialize trash index.', 'anibas-file-manager'));
                }
            }

            ActivityLogger::get_instance()->log_message('DeletePhase: trash root recreated');
        } catch (\Throwable $e) {
            $job['failed_count']++;
            $job['errors'][] = esc_html__('Trash folder could not be recreated: ', 'anibas-file-manager') . $e->getMessage();
            ActivityLogger::get_instance()->log_message('DeletePhase: trash root recreate failed: ' . $e->getMessage());
        }

        $work_queue['trash_root_recreated'] = true;
    }

    private function recreate_kept_root_if_needed(array &$job, array &$work_queue, $fs): void
    {
        if (! $this->should_recreate_keep_root($job) || ! empty($work_queue['keep_root_recreated'])) {
            return;
        }

        $root = (string) ($job['source_root'] ?? '');
        if ($root === '') {
            $work_queue['keep_root_recreated'] = true;
            return;
        }

        try {
            $exists = method_exists($fs, 'is_dir') ? $fs->is_dir($root) : false;
            if (! $exists && method_exists($fs, 'mkdir') && ! $fs->mkdir($root)) {
                throw new \RuntimeException(esc_html__('Unable to recreate emptied folder.', 'anibas-file-manager'));
            }

            ActivityLogger::get_instance()->log_message('DeletePhase: kept root recreated for ' . $root);
        } catch (\Throwable $e) {
            $job['failed_count']++;
            $job['errors'][] = esc_html__('Emptied folder could not be recreated: ', 'anibas-file-manager') . $e->getMessage();
            ActivityLogger::get_instance()->log_message('DeletePhase: kept root recreate failed: ' . $e->getMessage());
        }

        $work_queue['keep_root_recreated'] = true;
    }

    public function next_phase()
    {
        ActivityLogger::get_instance()->log_message(__CLASS__ . " Complete.");
        return null; // No wrapup needed — done
    }
}
