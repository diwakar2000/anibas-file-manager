<?php

namespace Anibas;

if ( ! defined( 'ABSPATH' ) ) exit;




class ListPhase extends OperationPhase
{
    private $is_delete = false;

    public function execute(&$job, &$work_queue, $manager, &$context)
    {
        $fs = $context['fs_adapter'];
        $this->is_delete = ($job['action'] ?? '') === 'delete';

        if ($work_queue['is_single_file']) {
            $source = $job['source_root'];

            if ($this->is_delete) {
                $work_queue['files_to_process'] = [['source' => $source, 'target' => '']];
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

            $work_queue['files_to_process'] = [['source' => $source, 'target' => $target]];
            $job['total_files'] = 1;
            ActivityLogger::get_instance()->log_message('ListPhase: single file -> ' . $source . ' to ' . $target);
            return;
        }

        if (! isset($work_queue['files_to_process'])) {
            $work_queue['files_to_process'] = [];
        }

        if (! isset($work_queue['folders_to_process'])) {
            $work_queue['folders_to_process'] = [];
        }

        $max_items_per_run = 10000;
        $items_processed = 0;

        while (! empty($work_queue['folders']) && ((microtime(true) - $context['start_time']) < $context['time_limit']) && ($items_processed < $max_items_per_run)) {

            $current = &$work_queue['folders'][0];

            if (! isset($current['offset'])) {
                $current['offset'] = 0;
            }
            if (! isset($current['page_cache'])) {
                $current['page_cache'] = null;
            }

            // Load page if not cached
            if ($current['page_cache'] === null) {
                try {
                    if ($fs->is_empty($current['path'])) {
                        $current['page_cache'] = [];
                    } else {
                        $data = $fs->listDirectory($current['path'], $current['page'], 1000);
                        $current['page_cache'] = $data['items'];
                        ActivityLogger::get_instance()->log_message('ListPhase: listDirectory(' . $current['path'] . ') returned ' . count($current['page_cache']) . ' items');
                    }
                } catch (\Exception $e) {
                    array_shift($work_queue['folders']);
                    continue;
                }

                if (empty($current['page_cache'])) {
                    if ($this->is_delete) {
                        // Delete: store the source folder path
                        $work_queue['folders_to_process'][] = $current['path'];
                    } else {
                        // Copy/Move: compute the target folder path
                        // For local storage, current['path'] is a real absolute path.
                        // Same fix as for files: strip the real source root, then re-prefix with dest_root.
                        if ($job['storage'] === 'local' && method_exists($manager, 'frontendPathToReal')) {
                            $abspath = rtrim(ABSPATH, '/\\');
                            if (strpos($job['source_root'], $abspath) === 0) {
                                $real_source_root = rtrim($job['source_root'], DIRECTORY_SEPARATOR);
                            } else {
                                $real_source_root = rtrim($manager->frontendPathToReal($job['source_root']), DIRECTORY_SEPARATOR);
                            }
                            $rel = substr($current['path'], strlen($real_source_root));
                            $folder_target = rtrim($job['dest_root'], '/') . '/' . ltrim(str_replace(DIRECTORY_SEPARATOR, '/', $rel), '/');
                        } else {
                            $folder_target = str_replace($job['source_root'], $job['dest_root'], $current['path']);
                        }
                        $work_queue['folders_to_process'][] = $folder_target;
                    }
                    array_shift($work_queue['folders']);
                    continue;
                }

            }

            // Process items from offset
            $items = array_values($current['page_cache']);
            $total_in_page = count($items);

            for ($i = $current['offset']; $i < $total_in_page; $i++) {
                if (microtime(true) - $context['start_time'] >= $context['time_limit'] || $items_processed >= $max_items_per_run) {
                    $current['offset'] = $i;
                    return;
                }

                $item = $items[$i];

                // For local storage, convert frontend path to real path
                // For remote storage, paths are already in correct format
                if ($job['storage'] === 'local') {
                    $real_path = $manager->frontendPathToReal($item['path']);
                } else {
                    $real_path = $item['path'];
                }

                if ($item['is_folder']) {
                    $work_queue['folders'][] = ['path' => $real_path, 'page' => 1, 'offset' => 0, 'page_cache' => null];
                } elseif ($this->is_delete) {
                    // Delete: just need the source path
                    $work_queue['files_to_process'][] = [
                        'source' => $real_path,
                        'target' => '',
                    ];
                } else {
                    // Compute the target path by stripping the source root from the real_path.
                    //
                    // There are two cases:
                    // 1. Same-storage local jobs: source_root is already a real absolute path
                    //    (e.g. /var/www/html/wp-content/uploads/folder). real_path is also absolute.
                    //    Simple substr works directly.
                    //
                    // 2. Cross-storage local→remote jobs: source_root is a frontend path
                    //    (e.g. /wp-content/uploads/folder) while real_path is absolute.
                    //    We must convert source_root to a real path first so the substr is correct.
                    if ($job['storage'] === 'local' && method_exists($manager, 'frontendPathToReal')) {
                        // Detect if source_root is already an absolute real path (starts with ABSPATH)
                        $abspath = rtrim(ABSPATH, '/\\');
                        if (strpos($job['source_root'], $abspath) === 0) {
                            // source_root is already a real path (same-storage local job)
                            $real_source_root = rtrim($job['source_root'], DIRECTORY_SEPARATOR);
                        } else {
                            // source_root is a frontend path (cross-storage job)
                            $real_source_root = rtrim($manager->frontendPathToReal($job['source_root']), DIRECTORY_SEPARATOR);
                        }
                        $relative_path = substr($real_path, strlen($real_source_root));
                        if (empty($relative_path)) {
                            $relative_path = DIRECTORY_SEPARATOR . basename($real_path);
                        }
                        // Normalize to forward slashes for remote destinations
                        $relative_path = str_replace(DIRECTORY_SEPARATOR, '/', $relative_path);
                    } else {
                        // Remote storage: paths are already in forward-slash format
                        $relative_path = str_replace($job['source_root'], '', $real_path);
                    }

                    $target = rtrim($job['dest_root'], '/') . '/' . ltrim($relative_path, '/');
                    ActivityLogger::get_instance()->log_message('ListPhase: queuing file ' . $real_path . ' -> ' . $target);
                    $work_queue['files_to_process'][] = [
                        'source' => $real_path,
                        'target' => $target,
                        'size'   => $item['filesize'] ?? 0,
                    ];
                }

                $items_processed++;
            }

            // Finished current page
            if ($total_in_page === 1000) {
                $current['page']++;
                $current['offset'] = 0;
                $current['page_cache'] = null;
            } else {
                // Delete: store the source folder path for later deletion
                if ($this->is_delete) {
                    $work_queue['folders_to_process'][] = $current['path'];
                }
                array_shift($work_queue['folders']);
            }
        }
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
