<?php

namespace Anibas;

if ( ! defined( 'ABSPATH' ) ) exit;




class ListPhase extends OperationPhase
{
    private $is_delete = false;
    private array $file_buffer   = [];
    private array $folder_buffer = [];
    private array $source_folder_buffer = [];

    public function execute(&$job, &$work_queue, $manager, &$context)
    {
        $fs = $context['fs_adapter'];
        $this->is_delete = ($job['action'] ?? '') === 'delete';
        $this->file_buffer   = [];
        $this->folder_buffer = [];
        $this->source_folder_buffer = [];

        if ($work_queue['is_single_file']) {
            $source = $job['source_root'];

            if ($this->is_delete) {
                $this->spool_file($job, ['source' => $source, 'target' => '']);
                $this->flush_buffers($job);
                $job['total_files'] = 1;
                ActivityLogger::get_instance()->log_message('ListPhase: single file delete -> ' . $source);
                return;
            }

            // For cross-storage local→remote: source_root may be a frontend path (/wp-content/...)
            // We need the real absolute path for is_file() checks in CrossStorageTransferPhase.
            if ($job['storage'] === 'local' && method_exists($manager, 'frontendPathToReal')) {
                $abspath = rtrim(ABSPATH, '/\\');
                if (strpos($source, $abspath) !== 0) {
                    // It's a frontend path — convert to real path
                    $source = $manager->frontendPathToReal($source);
                }
            }

            // For renames / resolved single-file transfers, dest_root is already the
            // final target path (including the new basename) — don't append again.
            if (! empty($job['dest_is_final'])) {
                $target = $job['dest_root'];
            } else {
                // Use '/' as separator since dest_root may be a remote (S3) path
                $target = rtrim($job['dest_root'], '/') . '/' . basename($source);
            }

            $this->spool_file($job, ['source' => $source, 'target' => $target]);
            $this->flush_buffers($job);
            $job['total_files'] = 1;
            ActivityLogger::get_instance()->log_message('ListPhase: single file -> ' . $source . ' to ' . $target);
            return;
        }

        // Legacy in-memory queue arrays are kept for in-flight jobs only.
        // New entries always go to the JSONL spool via spool_file/spool_folder.
        if (! isset($work_queue['files_to_process'])) {
            $work_queue['files_to_process'] = [];
        }

        if (! isset($work_queue['folders_to_process'])) {
            $work_queue['folders_to_process'] = [];
        }

        $max_items_per_run = 10000;
        $batch_size        = 1000;
        $items_processed   = 0;

        while (! empty($work_queue['folders']) && ((microtime(true) - $context['start_time']) < $context['time_limit']) && ($items_processed < $max_items_per_run)) {

            $current = &$work_queue['folders'][0];

            if (! array_key_exists('cursor', $current)) {
                $current['cursor'] = null;
            }
            if (! array_key_exists('had_entries', $current)) {
                $current['had_entries'] = false;
            }

            $is_first_call = $current['cursor'] === null && ! $current['had_entries'];

            // Pre-flight is_empty check (fast path) — only on the first call for this folder.
            if ($is_first_call) {
                try {
                    if ($fs->is_empty($current['path'])) {
                        $this->finalize_empty_folder($job, $manager, $current['path'], $work_queue);
                        array_shift($work_queue['folders']);
                        continue;
                    }
                } catch (\Exception $e) {
                    // Fall through to iterateDirectory, which will surface the same failure.
                }
            }

            // Drain any entries that didn't fit the previous request's budget
            // before fetching a new batch.
            if (! empty($current['pending_entries'])) {
                while (! empty($current['pending_entries'])) {
                    if (microtime(true) - $context['start_time'] >= $context['time_limit'] || $items_processed >= $max_items_per_run) {
                        $this->flush_buffers($job);
                        return;
                    }
                    $item = array_shift($current['pending_entries']);
                    $this->process_entry($job, $manager, $work_queue, $item);
                    $items_processed++;
                }
                // Loop again so we re-check time/budget before the next iterateDirectory call.
                continue;
            }

            try {
                $page = $fs->iterateDirectory($current['path'], $current['cursor'], $batch_size, $this->iteration_options($job));
            } catch (\Exception $e) {
                ActivityLogger::get_instance()->log_message('ListPhase: iterateDirectory(' . $current['path'] . ') threw: ' . $e->getMessage());
                array_shift($work_queue['folders']);
                continue;
            }

            $entries = $page['entries'] ?? [];
            $current['cursor'] = $page['next_cursor'] ?? null;

            if (! empty($entries)) {
                $current['had_entries'] = true;
            }

            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- formats a boolean for the internal ActivityLogger audit log, not left-over debug output.
            ActivityLogger::get_instance()->log_message('ListPhase: iterateDirectory(' . $current['path'] . ') returned ' . count($entries) . ' items, has_more=' . var_export(! empty($page['has_more']), true));

            foreach ($entries as $idx => $item) {
                if (microtime(true) - $context['start_time'] >= $context['time_limit'] || $items_processed >= $max_items_per_run) {
                    // Stash unconsumed entries for the next request — the cursor
                    // has already advanced past them in the adapter.
                    $current['pending_entries'] = array_slice($entries, $idx);
                    $this->flush_buffers($job);
                    return;
                }

                $this->process_entry($job, $manager, $work_queue, $item);
                $items_processed++;
            }

            if (empty($page['has_more'])) {
                // Folder fully iterated.
                if ($this->is_delete) {
                    if (! $this->should_skip_root($job, $current['path'])) {
                        $this->spool_folder($job, $current['path']);
                    }
                } elseif (! $current['had_entries']) {
                    // Empty source folder under copy/move: queue an explicit mkdir
                    // for its target so the destination tree mirrors the source.
                    $this->finalize_empty_folder($job, $manager, $current['path'], $work_queue);
                } elseif ($this->should_remove_source($job)) {
                    $this->spool_source_folder($job, $current['path']);
                }
                array_shift($work_queue['folders']);
            }
        }

        $this->flush_buffers($job);
    }

    /**
     * Push a file entry to the spool buffer (flushed at end of execute() or
     * before returning from a budget-exhausted iteration).
     */
    private function spool_file(array $job, array $entry): void
    {
        $this->file_buffer[] = $entry;
        if (count($this->file_buffer) >= 500) {
            JobQueueSpool::append($job['id'], 'files', $this->file_buffer);
            $this->file_buffer = [];
        }
    }

    private function spool_folder(array $job, string $path): void
    {
        $this->folder_buffer[] = ['path' => $path];
        if (count($this->folder_buffer) >= 500) {
            JobQueueSpool::append($job['id'], 'folders', $this->folder_buffer);
            $this->folder_buffer = [];
        }
    }

    /**
     * Append a source-side folder path to the 'sources' stream — read by the
     * wrapup phase to rmdir the now-emptied source tree after a move job
     * without needing a second filesystem walk.
     */
    private function spool_source_folder(array $job, string $path): void
    {
        $this->source_folder_buffer[] = ['path' => $path];
        if (count($this->source_folder_buffer) >= 500) {
            JobQueueSpool::append($job['id'], 'sources', $this->source_folder_buffer);
            $this->source_folder_buffer = [];
        }
    }

    private function flush_buffers(array $job): void
    {
        if (! empty($this->file_buffer)) {
            JobQueueSpool::append($job['id'], 'files', $this->file_buffer);
            $this->file_buffer = [];
        }
        if (! empty($this->folder_buffer)) {
            JobQueueSpool::append($job['id'], 'folders', $this->folder_buffer);
            $this->folder_buffer = [];
        }
        if (! empty($this->source_folder_buffer)) {
            JobQueueSpool::append($job['id'], 'sources', $this->source_folder_buffer);
            $this->source_folder_buffer = [];
        }
    }

    /**
     * True when this is a move-style job whose source folders should be
     * rmdir'd after the transfer phase finishes. Triggers the list phase to
     * append source folder paths to the 'sources' spool so a later phase can
     * walk them in reverse without rescanning the filesystem.
     */
    private function should_remove_source(array $job): bool
    {
        return ($job['action'] ?? '') === 'move' || ! empty($job['remove_source']);
    }

    /**
     * True when iterateDirectory may use the cheaper "recursive job"
     * authorization path (validate the root once, then check only protected
     * subtree membership for descendants). Always true here — list phase is
     * only ever invoked from a background job that's already been authorized
     * by the caller-facing AJAX handler.
     */
    private function uses_recursive_job_paths(array $job): bool
    {
        return true;
    }

    private function iteration_options(array $job): array
    {
        if (! $this->uses_recursive_job_paths($job)) {
            return [];
        }

        return [
            'recursive_root'    => (string) ($job['source_root'] ?? ''),
            'allow_trash_root'  => ! empty($job['allow_trash_root']),
        ];
    }

    /**
     * Queue the work needed when a source folder turns out to be empty.
     * For delete jobs, store the folder for later rmdir. For copy/move jobs,
     * store the computed target path so the transfer phase explicitly mkdirs it.
     */
    private function finalize_empty_folder(array $job, $manager, string $folder_path, array &$work_queue): void
    {
        if ($this->is_delete) {
            if (! $this->should_skip_root($job, $folder_path)) {
                $this->spool_folder($job, $folder_path);
            }
            return;
        }

        if ($this->should_remove_source($job)) {
            $this->spool_source_folder($job, $folder_path);
        }

        if ($job['storage'] === 'local' && method_exists($manager, 'frontendPathToReal')) {
            $abspath = rtrim(ABSPATH, '/\\');
            if (strpos($job['source_root'], $abspath) === 0) {
                $real_source_root = rtrim($job['source_root'], DIRECTORY_SEPARATOR);
            } else {
                $real_source_root = rtrim($manager->frontendPathToReal($job['source_root']), DIRECTORY_SEPARATOR);
            }
            $rel = substr($folder_path, strlen($real_source_root));
            $folder_target = rtrim($job['dest_root'], '/') . '/' . ltrim(str_replace(DIRECTORY_SEPARATOR, '/', $rel), '/');
        } else {
            $folder_target = str_replace($job['source_root'], $job['dest_root'], $folder_path);
        }
        $this->spool_folder($job, $folder_target);
    }

    /**
     * Convert a single iterator entry into the right work_queue entry.
     */
    private function process_entry(array $job, $manager, array &$work_queue, array $item): void
    {
        // For local storage, convert frontend path to real path.
        // For remote storage, paths are already in correct format.
        if ($job['storage'] === 'local' && method_exists($manager, 'frontendPathToReal')) {
            $real_path = $manager->frontendPathToReal($item['path']);
        } else {
            $real_path = $item['path'];
        }

        if (! empty($item['is_folder'])) {
            // list_depth=1 — treat each subfolder as a terminal queue entry
            // instead of descending into it. Used by jobs that need to act on
            // direct children only (e.g. cross-FS empty-folder-to-trash).
            if (! empty($job['list_depth']) && (int) $job['list_depth'] === 1) {
                $this->spool_file($job, [
                    'source'    => $real_path,
                    'target'    => '',
                    'is_folder' => true,
                ]);
                return;
            }
            $work_queue['folders'][] = ['path' => $real_path, 'cursor' => null, 'had_entries' => false];
            return;
        }

        if ($this->is_delete) {
            $this->spool_file($job, [
                'source' => $real_path,
                'target' => '',
            ]);
            return;
        }

        // Compute the target path by stripping the source root from real_path.
        // Same-storage local jobs: source_root is already an absolute real path.
        // Cross-storage local→remote jobs: source_root is a frontend path that
        // must be converted to a real path before substring removal.
        if ($job['storage'] === 'local' && method_exists($manager, 'frontendPathToReal')) {
            $abspath = rtrim(ABSPATH, '/\\');
            if (strpos($job['source_root'], $abspath) === 0) {
                $real_source_root = rtrim($job['source_root'], DIRECTORY_SEPARATOR);
            } else {
                $real_source_root = rtrim($manager->frontendPathToReal($job['source_root']), DIRECTORY_SEPARATOR);
            }
            $relative_path = substr($real_path, strlen($real_source_root));
            if (empty($relative_path)) {
                $relative_path = DIRECTORY_SEPARATOR . basename($real_path);
            }
            $relative_path = str_replace(DIRECTORY_SEPARATOR, '/', $relative_path);
        } else {
            $relative_path = str_replace($job['source_root'], '', $real_path);
        }

        $target = rtrim($job['dest_root'], '/') . '/' . ltrim($relative_path, '/');
        $this->spool_file($job, [
            'source' => $real_path,
            'target' => $target,
            'size'   => $item['filesize'] ?? 0,
        ]);
    }

    /**
     * True when this is a keep_root delete job and the given folder is the job's
     * source root — i.e. the folder we want to preserve after emptying.
     */
    private function should_skip_root(array $job, string $folder_path): bool
    {
        if (empty($job['keep_root'])) {
            return false;
        }
        $root = rtrim($job['source_root'] ?? '', "/\\" . DIRECTORY_SEPARATOR);
        $here = rtrim($folder_path, "/\\" . DIRECTORY_SEPARATOR);
        return $root !== '' && $root === $here;
    }

    public function is_complete($work_queue)
    {
        return empty($work_queue['folders']);
    }

    public function next_phase()
    {
        ActivityLogger::get_instance()->log_message(__CLASS__ . " Complete.");
        return $this->is_delete ? 'delete' : 'transfer';
    }
}
