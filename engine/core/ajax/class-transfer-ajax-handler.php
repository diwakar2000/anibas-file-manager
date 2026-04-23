<?php

namespace Anibas;

if (! defined('ABSPATH')) exit;

/**
 * AJAX endpoints for copy/move/transfer jobs: same-storage, cross-storage,
 * job lifecycle (status / cancel / conflict resolution) and the running-tasks
 * surface used by the UI's resume banner.
 */
class TransferAjaxHandler extends AjaxHandler
{
    public function __construct()
    {
        parent::__construct();
        $this->register_actions([
            ANIBAS_FM_TRANSFER_FILE         => 'transfer_file',
            ANIBAS_FM_DUPLICATE_FILE        => 'duplicate_file',
            ANIBAS_FM_JOB_STATUS            => 'get_job_status',
            ANIBAS_FM_CANCEL_JOB            => 'cancel_job',
            ANIBAS_FM_CHECK_CONFLICT        => 'check_conflict',
            ANIBAS_FM_CHECK_RUNNING_TASKS   => 'check_running_tasks',
            ANIBAS_FM_RESOLVE_SIZE_MISMATCH => 'resolve_size_mismatch',
        ]);
    }

    /**
     * Duplicate a file or folder in place. Computes a fresh, non-colliding
     * destination ("name (Copy).ext", "name (Copy 2).ext", ...) inside the
     * source's parent folder, then runs a copy job through BackgroundProcessor
     * with dest_is_final=true so the resolved name is preserved end-to-end.
     */
    public function duplicate_file()
    {
        $this->check_create_privilege();

        $path    = anibas_fm_fetch_request_variable('post', 'path', '');
        $storage = anibas_fm_fetch_request_variable('post', 'storage', 'local');

        if ($path === '') {
            wp_send_json_error(array('error' => esc_html__('Path required', 'anibas-file-manager')));
        }

        if ($storage === 'local') {
            $source = $this->validate_path($path);
            if (! $source || ! file_exists($source)) {
                wp_send_json_error(array('error' => 'PathInvalid', 'message' => esc_html__('Invalid path', 'anibas-file-manager')));
            }
            $exists_fn = static function ($p) { return file_exists($p); };
            $sep       = DIRECTORY_SEPARATOR;
        } else {
            $adapter = $this->get_storage_adapter($storage);
            if (! $adapter) {
                wp_send_json_error(array('error' => esc_html__('Invalid storage', 'anibas-file-manager')));
            }
            $source = $adapter->validate_path($path);
            if (! $source || ! $adapter->exists($source)) {
                wp_send_json_error(array('error' => 'PathInvalid', 'message' => esc_html__('Invalid path', 'anibas-file-manager')));
            }
            $exists_fn = static function ($p) use ($adapter) { return $adapter->exists($p); };
            $sep       = '/';
        }

        $parent      = rtrim(dirname($source), '/' . DIRECTORY_SEPARATOR);
        $destination = $this->build_duplicate_path($parent . $sep . basename($source), $exists_fn, $sep);

        $job_id = BackgroundProcessor::enqueue_job($source, $destination, 'copy', 'rename', $storage, true);
        if (is_wp_error($job_id)) {
            wp_send_json_error(array(
                'error'   => $job_id->get_error_code(),
                'message' => $job_id->get_error_message(),
            ));
        }

        wp_send_json_success(array(
            'job_id'      => $job_id,
            'destination' => basename($destination),
            /* translators: %s: source file or folder name */
            'message'     => sprintf(esc_html__('Duplicating "%s"...', 'anibas-file-manager'), esc_html(basename($source))),
        ));
    }

    /**
     * Build a "name (Copy).ext" / "name (Copy 2).ext" candidate that doesn't
     * collide. Falls back to a timestamp suffix after 99 attempts.
     */
    private function build_duplicate_path(string $original, callable $exists_fn, string $sep): string
    {
        $info = pathinfo($original);
        $dir  = $info['dirname'];
        $name = $info['filename'];
        $ext  = isset($info['extension']) ? '.' . $info['extension'] : '';

        $candidate = $dir . $sep . $name . ' (Copy)' . $ext;
        if (! $exists_fn($candidate)) {
            return $candidate;
        }

        for ($i = 2; $i <= 99; $i++) {
            $candidate = $dir . $sep . $name . ' (Copy ' . $i . ')' . $ext;
            if (! $exists_fn($candidate)) {
                return $candidate;
            }
        }

        return $dir . $sep . $name . '_' . date('Y-m-d_His') . '_' . mt_rand(100000, 999999) . $ext;
    }

    public function transfer_file()
    {
        $this->check_create_privilege();
        $source = anibas_fm_fetch_request_variable('post', 'source', '');
        $destination = anibas_fm_fetch_request_variable('post', 'destination', '');
        $conflict_mode = anibas_fm_fetch_request_variable('post', 'conflict_mode', 'skip');
        $action = anibas_fm_fetch_request_variable('post', 'action_type', 'copy');
        if (! in_array($action, ['copy', 'move'], true)) {
            $action = 'copy';
        }
        if (empty($source) || empty($destination)) {
            wp_send_json_error(array('error' => esc_html__('Source and destination required', 'anibas-file-manager')));
        }

        // Cross-storage: accept source_storage + dest_storage, fall back to legacy 'storage'
        $source_storage = anibas_fm_fetch_request_variable('post', 'source_storage', '');
        $dest_storage   = anibas_fm_fetch_request_variable('post', 'dest_storage', '');
        if (empty($source_storage) && empty($dest_storage)) {
            $storage = anibas_fm_fetch_request_variable('post', 'storage', 'local');
            $source_storage = $storage;
            $dest_storage   = $storage;
        }

        if ($source_storage !== $dest_storage) {
            // Cross-storage transfer
            $sm = StorageManager::get_instance();
            $validation = $sm->validate_cross_storage_transfer($source_storage, $dest_storage);
            if (is_wp_error($validation)) {
                wp_send_json_error(array('error' => $validation->get_error_message()));
            }
            $this->process_cross_storage_operation($source, $destination, $conflict_mode, $source_storage, $dest_storage, $action);
        } else {
            // Same-storage transfer
            $this->process_transfer_operation($source, $destination, $conflict_mode, $source_storage, $action);
        }
    }

    private function process_transfer_operation($source, $destination, $conflict_mode, $storage, $action = 'copy')
    {
        if ($storage !== 'local') {
            $this->process_remote_transfer($source, $destination, $conflict_mode, $storage, $action);
            return;
        }
        $this->process_local_transfer($source, $destination, $conflict_mode, $action);
    }

    private function process_remote_transfer($source, $destination, $conflict_mode, $storage, $action)
    {
        $adapter = $this->get_storage_adapter($storage);
        if (! $adapter) {
            wp_send_json_error(array('error' => esc_html__('Invalid storage', 'anibas-file-manager')));
        }
        try {
            if (! $adapter->exists($source)) {
                wp_send_json_error(array('error' => esc_html__('Source not found', 'anibas-file-manager'), 'error_code' => 5));
            }

            $dest_dir      = rtrim($destination, '/');
            $is_dir        = $adapter->is_dir($source);
            $dest_is_final = false;

            if ($is_dir) {
                $final_dest = $dest_dir;
            } else {
                $final_dest = $dest_dir . '/' . basename($source);

                if ($source === $final_dest) {
                    if ($action === 'move') {
                        wp_send_json_success(array('message' => esc_html__('File moved successfully', 'anibas-file-manager'), 'response' => 9, 'status' => 'complete'));
                    }
                    if ($action === 'copy' && $conflict_mode !== 'rename') {
                        $conflict_mode = 'rename';
                    }
                }

                if ($adapter->exists($final_dest)) {
                    if ($conflict_mode === 'rename') {
                        if (method_exists($adapter, 'resolveNameClash')) {
                            $final_dest = $adapter->resolveNameClash($final_dest);
                        } else {
                            $path_info = pathinfo($final_dest);
                            $extension = isset($path_info['extension']) ? '.' . $path_info['extension'] : '';
                            $final_dest = $path_info['dirname'] . '/' . $path_info['filename'] . '_' . date('Y-m-d_H-i-s') . '_' . mt_rand(100000, 999999) . $extension;
                        }
                    } elseif ($conflict_mode === 'skip') {
                        wp_send_json_success(array('message' => esc_html__('File skipped', 'anibas-file-manager'), 'response' => 9, 'status' => 'complete', 'skipped' => true));
                    }
                }
                $dest_is_final = true;
            }

            $job_id = BackgroundProcessor::enqueue_job($source, $final_dest, $action, $conflict_mode, $storage, $dest_is_final);
            if (is_wp_error($job_id)) {
                wp_send_json_error(array(
                    'error'      => $job_id->get_error_code(),
                    'message'    => $job_id->get_error_message(),
                    'error_code' => 1,
                ));
            }
            wp_send_json_success(array('job_id' => $job_id, /* translators: %s: action name */
            'message' => sprintf(esc_html__('%s job started', 'anibas-file-manager'), esc_html(ucfirst($action)))));
        } catch (\Exception $e) {
            /* translators: 1: action name e.g. 'copy', 2: error message */
            wp_send_json_error(array('error' => sprintf(esc_html__('Failed to start %1$s job: %2$s', 'anibas-file-manager'), esc_html($action), esc_html($e->getMessage()))));
        }
    }

    private function process_local_transfer($source, $destination, $conflict_mode, $action)
    {
        $action_label = ucfirst($action);
        $source_path = $this->validate_path($source);
        $dest_path = $this->validate_path($destination);
        if (! $source_path) {
            wp_send_json_error(array('error' => esc_html__('Invalid source path', 'anibas-file-manager')));
        }
        if (! $dest_path) {
            wp_send_json_error(array('error' => esc_html__('Invalid destination path', 'anibas-file-manager')));
        }
        if (! file_exists($source_path)) {
            wp_send_json_error(array('error' => esc_html__('Source not found', 'anibas-file-manager'), 'error_code' => 5));
        }
        if (! is_dir($dest_path)) {
            wp_send_json_error(array('error' => esc_html__('Destination must be a directory', 'anibas-file-manager'), 'error_code' => 1));
        }

        $fm            = new LocalFileSystemAdapter();
        $is_dir        = is_dir($source_path);
        $dest_is_final = false;

        if ($is_dir) {
            $final_dest = $dest_path;
        } else {
            $final_dest = $dest_path . DIRECTORY_SEPARATOR . basename($source_path);

            if ($source_path === $final_dest) {
                if ($action === 'move') {
                    wp_send_json_success(array('message' => esc_html__('File moved successfully', 'anibas-file-manager'), 'response' => 9, 'status' => 'complete'));
                }
                if ($action === 'copy' && $conflict_mode !== 'rename') {
                    $conflict_mode = 'rename';
                }
            }

            if (file_exists($final_dest)) {
                if ($conflict_mode === 'rename') {
                    $final_dest = $fm->resolveNameClash($final_dest);
                } elseif ($conflict_mode === 'skip') {
                    wp_send_json_success(array('message' => esc_html__('File skipped', 'anibas-file-manager'), 'response' => 9, 'status' => 'complete', 'skipped' => true));
                }
            }
            $dest_is_final = true;
        }

        $job_id = BackgroundProcessor::enqueue_job($source_path, $final_dest, $action, $conflict_mode, 'local', $dest_is_final);
        if (is_wp_error($job_id)) {
            wp_send_json_error(array(
                'error'      => $job_id->get_error_code(),
                'message'    => $job_id->get_error_message(),
                'error_code' => 1,
            ));
        }
        wp_send_json_success(array('job_id' => $job_id, /* translators: %s: action label */
        'message' => sprintf(esc_html__('%s job started', 'anibas-file-manager'), esc_html($action_label))));
    }

    /**
     * Handle cross-storage file transfer (local ↔ remote).
     *
     * ALL cross-storage transfers — both files and directories — are routed through
     * BackgroundProcessor so they use chunked I/O and are resumable across page refreshes.
     */
    private function process_cross_storage_operation($source, $destination, $conflict_mode, $source_storage, $dest_storage, $action)
    {
        $sm             = StorageManager::get_instance();
        $source_adapter = $sm->get_adapter($source_storage);
        $dest_adapter   = $sm->get_adapter($dest_storage);

        if (! $source_adapter || ! $dest_adapter) {
            wp_send_json_error(array('error' => esc_html__('Invalid storage adapter.', 'anibas-file-manager')));
        }

        // Always enqueue as a background job — this ensures chunked I/O for large files
        // and resumability across page refreshes / PHP time limits.
        $job_id = BackgroundProcessor::enqueue_cross_storage_job(
            $source,
            $destination,
            $action,
            $conflict_mode,
            $source_storage,
            $dest_storage
        );

        if (is_wp_error($job_id)) {
            wp_send_json_error(array(
                'error'   => $job_id->get_error_code(),
                'message' => $job_id->get_error_message(),
            ));
        }
        wp_send_json_success(array('job_id' => $job_id, 'message' => esc_html__('Transfer started', 'anibas-file-manager')));
    }

    public function get_job_status()
    {
        $this->check_privilege();

        $job_id = anibas_fm_fetch_request_variable('get', 'job_id', '');

        if (empty($job_id)) {
            wp_send_json_error(array('error' => esc_html__('Job ID required', 'anibas-file-manager')));
        }

        $job = BackgroundProcessor::get_job_status($job_id);

        if ($job) {
            // Fallback safety net: if the job is active but the worker lock is free,
            // the async HTTP loopback likely failed (e.g., due to firewall or basic auth).
            // This polling request kickstarts the worker again.
            if (in_array($job['status'], ['pending', 'processing', 'retrying'], true)) {
                if (! BackgroundProcessor::is_worker_locked()) {
                    require_once __DIR__ . '/../../handlers/class-async-worker-dispatcher.php';
                    AsyncWorkerDispatcher::dispatch();
                }
            }

            wp_send_json_success($job);
        } else {
            wp_send_json_error(array('error' => esc_html__('Job not found', 'anibas-file-manager')));
        }
    }

    public function cancel_job()
    {
        $this->check_privilege();

        $job_id = anibas_fm_fetch_request_variable('post', 'job_id', '');

        if (empty($job_id)) {
            wp_send_json_error(array('error' => esc_html__('Job ID required', 'anibas-file-manager')));
        }

        $result = BackgroundProcessor::cancel_job($job_id);

        if ($result) {
            wp_send_json_success(array('message' => esc_html__('Job cancelled', 'anibas-file-manager')));
        } else {
            wp_send_json_error(array('error' => esc_html__('Job not found', 'anibas-file-manager')));
        }
    }

    public function check_conflict()
    {
        $this->check_privilege();

        $source = anibas_fm_fetch_request_variable('get', 'source', '');
        $destination = anibas_fm_fetch_request_variable('get', 'destination', '');
        $storage = anibas_fm_fetch_request_variable('get', 'storage', 'local');

        if (empty($source) || empty($destination)) {
            wp_send_json_error(array('error' => esc_html__('Source and destination required', 'anibas-file-manager')));
        }

        // Handle different storage types
        if ('local' === $storage) {
            $source_path = $this->validate_path($source);
            $dest_path = $this->validate_path($destination);

            if (! $source_path || ! $dest_path) {
                wp_send_json_error(array('error' => 'PathInvalid', 'message' => esc_html__('Invalid path', 'anibas-file-manager')));
            }

            $basename = basename($source_path);
            $target = $dest_path . DIRECTORY_SEPARATOR . $basename;
            $has_conflict = file_exists($target);
        } else {
            // Remote storage - use adapter
            $adapter = $this->get_storage_adapter($storage);
            if (! $adapter) {
                wp_send_json_error(array('error' => esc_html__('Invalid storage', 'anibas-file-manager')));
            }

            try {
                // Check if source exists
                if (! $adapter->exists($source)) {
                    wp_send_json_error(array('error' => esc_html__('Source not found', 'anibas-file-manager')));
                }

                // Check for conflict at destination
                $basename = basename($source);
                $target = rtrim($destination, '/') . '/' . $basename;
                $has_conflict = $adapter->exists($target);
            } catch (\Exception $e) {
                wp_send_json_error(array('error' => esc_html($e->getMessage())));
            }
        }

        wp_send_json_success(array('has_conflict' => $has_conflict));
    }

    public function check_running_tasks()
    {
        $this->check_privilege();

        // Copy/move jobs
        $queue         = anibas_fm_get_option('anibas_fm_job_queue_v2', []);
        $running_tasks = array_filter($queue, function ($job) {
            return in_array($job['status'], ['pending', 'processing']);
        });
        $sanitized_tasks = array_map('anibas_fm_convert_paths_in_job_data', array_values($running_tasks));

        // Archive jobs — return only display-safe fields (no absolute paths)
        $archive_jobs       = $this->get_archive_jobs();
        $sanitized_archives = array_map(function ($job) {
            return [
                'id'         => $job['id'],
                'source'     => basename($job['source_abs']),
                'output'     => basename($job['output_abs']),
                'format'     => $job['format'],
                'started_at' => $job['started_at'],
            ];
        }, array_values($archive_jobs));

        // Backup status
        $backup_lock = anibas_fm_get_backup_lock();

        wp_send_json_success([
            'tasks'        => $sanitized_tasks,
            'archive_jobs' => $sanitized_archives,
            'backup'       => $backup_lock ? [
                'running'    => true,
                'job_id'     => $backup_lock['job_id'],
                'format'     => $backup_lock['format'],
                'output'     => $backup_lock['output'],
                'started_at' => $backup_lock['started_at'],
            ] : [ 'running' => false ],
        ]);
    }

    public function resolve_size_mismatch()
    {
        $this->check_privilege();

        $job_id = anibas_fm_fetch_request_variable('post', 'job_id', '');
        $action = anibas_fm_fetch_request_variable('post', 'action_type', '');

        if (empty($job_id) || ! in_array($action, ['keep', 'delete'])) {
            wp_send_json_error(array('error' => esc_html__('Invalid request', 'anibas-file-manager')));
        }

        $queue = anibas_fm_get_option('anibas_fm_job_queue_v2', []);
        $job = null;
        $job_index = null;

        // Find job by ID
        foreach ($queue as $index => $q) {
            if ($q['id'] === $job_id) {
                $job = $q;
                $job_index = $index;
                break;
            }
        }

        if (! $job) {
            wp_send_json_error(array('error' => esc_html__('Job not found', 'anibas-file-manager')));
        }

        // Delete assembly token
        $token_key = 'anibas_fm_upload_' . md5($job['file_name'] . $job['file_size'] . $job['user_id']) . '_assembly';
        delete_transient($token_key);

        if ($action === 'delete') {
            $storage = $job['storage'];
            $file_path = $job['error_details']['file_path'] ?? null;

            if ($file_path) {
                if ($storage === 'local') {
                    wp_delete_file($file_path);
                } else {
                    $adapter = $this->get_storage_adapter($storage);
                    if ($adapter) {
                        $adapter->unlink($file_path);
                    }
                }
            }

            $job['status'] = 'failed';
            $job['user_action'] = 'deleted';
        } else {
            $job['status'] = 'completed';
            $job['user_action'] = 'kept';
            $job['completed_at'] = time();
        }

        $queue[$job_index] = $job;
        anibas_fm_update_option('anibas_fm_job_queue_v2', $queue);

        wp_send_json_success(array('message' => esc_html__('Action completed', 'anibas-file-manager')));
    }
}
