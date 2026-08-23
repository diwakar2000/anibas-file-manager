<?php

/**
 * AJAX handler exposing per-file and full-site backup endpoints.
 *
 * @package Anibas_File_Manager
 */

namespace Anibas;

if (! defined('ABSPATH')) exit;

/**
 * AJAX endpoints for both per-file backups (snapshot-before-edit, restore)
 * and full-site backups (start / poll / cancel / status).
 */
class BackupAjaxHandler extends AjaxHandler
{
    /**
     * Register the per-file and full-site backup/restore AJAX actions.
     */
    public function __construct()
    {
        parent::__construct();
        $this->register_actions([
            ANIBAS_FM_BACKUP_SINGLE_FILE  => 'backup_single_file',
            ANIBAS_FM_LIST_FILE_BACKUPS   => 'list_file_backups',
            ANIBAS_FM_RESTORE_FILE_BACKUP => 'restore_file_backup',
            ANIBAS_FM_DELETE_FILE_BACKUP  => 'delete_file_backup',
            ANIBAS_FM_DELETE_FILE_BACKUP_TREE => 'delete_file_backup_tree',
            ANIBAS_FM_LIST_SITE_BACKUPS   => 'list_site_backups',
            ANIBAS_FM_SITE_BACKUP_PREVIEW => 'site_backup_preview',
            ANIBAS_FM_SITE_BACKUP_INSPECT => 'site_backup_inspect',
            ANIBAS_FM_SITE_BACKUP_DOWNLOAD_FILE => 'site_backup_download_file',
            ANIBAS_FM_DELETE_SITE_BACKUP  => 'delete_site_backup',
            ANIBAS_FM_SEND_SITE_BACKUP_TO_CLOUD => 'send_site_backup_to_cloud',
            ANIBAS_FM_IMPORT_SITE_BACKUP_FROM_CLOUD => 'import_site_backup_from_cloud',
            ANIBAS_FM_SITE_RESTORE_START  => 'site_restore_start',
            ANIBAS_FM_SITE_RESTORE_POLL   => 'site_restore_poll',
            ANIBAS_FM_SITE_RESTORE_CANCEL => 'site_restore_cancel',
            ANIBAS_FM_SITE_RESTORE_FALLBACK_OVERWRITE => 'site_restore_fallback_overwrite',
            ANIBAS_FM_SITE_RESTORE_STATUS => 'site_restore_status',
            ANIBAS_FM_BACKUP_START        => 'backup_start',
            ANIBAS_FM_BACKUP_POLL         => 'backup_poll',
            ANIBAS_FM_BACKUP_CANCEL       => 'backup_cancel',
            ANIBAS_FM_BACKUP_STATUS       => 'backup_status',
        ]);
    }

    // ── Per-file backups (snapshot-before-edit / restore like trash) ──

    /**
     * Snapshot a single file into the file-backup history before it is
     * edited, driven as a resumable chunked-copy job.
     *
     * Requires create privilege. When `job_id` is present, resumes that job
     * instead of starting a new one (see continue_file_backup_job()). Local
     * files are copied directly; remote files are downloaded through a
     * chunked copy job, since a single request may not have time to
     * transfer a large file. The response is either a `running` progress
     * update or a `complete` result, depending on whether the copy finishes
     * within this request's time budget.
     */
    public function backup_single_file()
    {
        $this->check_create_privilege();

        $path    = sanitize_text_field(anibas_fm_fetch_request_variable('post', 'path', ''));
        $storage = sanitize_text_field(anibas_fm_fetch_request_variable('post', 'storage', 'local'));
        $job_id  = sanitize_text_field(anibas_fm_fetch_request_variable('post', 'job_id', ''));

        if (! empty($job_id)) {
            $this->continue_file_backup_job($job_id);
        }

        if (empty($path)) {
            $this->send_error(array('error' => esc_html__('Path required', 'anibas-file-manager')));
        }

        if ($storage === 'local') {
            $full_path = $this->validate_path($path);
            if (! $full_path || ! is_file($full_path)) {
                $this->send_error(array('error' => esc_html__('File not found', 'anibas-file-manager')));
            }
            $dest = anibas_fm_prepare_file_backup_target('local', $full_path);
            if (! $dest) {
                $this->send_error(array('error' => esc_html__('Backup failed', 'anibas-file-manager')));
            }
            $job = $this->create_file_copy_job(array(
                'operation' => 'backup_local',
                'storage'   => 'local',
                'source'    => $full_path,
                'target'    => $dest,
                'tmp'       => dirname($dest) . '/.tmp-' . basename($dest) . '-' . wp_generate_password(6, false),
                'message'   => esc_html__('File backed up', 'anibas-file-manager'),
            ));
            $this->process_file_backup_job($job);
        }

        $adapter = StorageManager::get_instance()->get_adapter($storage);
        if (! $adapter) {
            $this->send_error(array('error' => esc_html__('Invalid storage', 'anibas-file-manager')));
        }
        $full_path = $adapter->validate_path($path);
        if (! $full_path || ! $adapter->is_file($full_path)) {
            $this->send_error(array('error' => esc_html__('File not found', 'anibas-file-manager')));
        }
        $dest = anibas_fm_prepare_file_backup_target($storage, $full_path);
        if (! $dest) {
            $this->send_error(array('error' => esc_html__('Backup failed', 'anibas-file-manager')));
        }
        $job = $this->create_file_copy_job(array(
            'operation' => 'backup_remote',
            'storage'   => $storage,
            'source'    => $full_path,
            'target'    => $dest,
            'tmp'       => dirname($dest) . '/.tmp-' . basename($dest) . '-' . wp_generate_password(6, false),
            'message'   => esc_html__('File backed up', 'anibas-file-manager'),
        ));
        $this->process_file_backup_job($job);
    }

    /**
     * List all per-file backup groups and their available versions.
     *
     * Requires backup privilege. Each backup group lives in its own
     * directory under the file-backups root, identified by a `.source`
     * marker file recording the original storage + path; groups with no
     * remaining versions or a missing/unreadable marker are skipped.
     * Results are sorted newest-first by each group's latest version.
     */
    public function list_file_backups()
    {
        $this->check_backup_privilege();

        $root = anibas_fm_get_file_backups_dir();
        $items = array();

        if (is_dir($root)) {
            foreach (new \DirectoryIterator($root) as $src_dir) {
                if ($src_dir->isDot() || ! $src_dir->isDir()) continue;
                $key      = $src_dir->getFilename();
                $src_path = $src_dir->getPathname();
                $marker   = $src_path . '/.source';
                if (! file_exists($marker)) continue;

                try {
                    $raw = anibas_fm_read_small_file($marker);
                } catch (\Throwable $e) {
                    continue;
                }
                $parts   = explode('|', $raw, 2);
                $storage = $parts[0] ?? 'local';
                $source  = $parts[1] ?? '';

                $versions = array();
                foreach (new \DirectoryIterator($src_path) as $ver) {
                    if ($ver->isDot() || ! $ver->isFile()) continue;
                    if (anibas_fm_is_file_backup_internal_name($ver->getFilename())) continue;
                    $versions[] = array(
                        'name'     => $ver->getFilename(),
                        'mtime'    => $ver->getMTime(),
                        'filesize' => $ver->getSize(),
                    );
                }
                if (empty($versions)) continue;

                usort($versions, function ($a, $b) { return $b['mtime'] <=> $a['mtime']; });

                $items[] = array(
                    'key'      => $key,
                    'storage'  => $storage,
                    'source'   => $source,
                    'basename' => basename($source),
                    'versions' => $versions,
                );
            }
        }

        // Newest-first by latest version mtime
        usort($items, function ($a, $b) { return $b['versions'][0]['mtime'] <=> $a['versions'][0]['mtime']; });

        $this->send_success(array('items' => $items, 'total_items' => count($items)));
    }

    /**
     * Restore a specific version of a per-file backup to its original
     * location, driven as a resumable chunked-copy job.
     *
     * Requires backup privilege. When `job_id` is present, resumes that job
     * instead of starting a new one. `key` must be a 32-hex-char backup
     * group id and `version` must not contain path separators or `..`
     * (guards against traversal via the stored filename). The original
     * target path is read from the group's `.source` marker; for local
     * targets it is re-validated against the site root and blocked-path
     * rules before restoring. The response is a `running` progress update
     * or a `complete` result depending on whether the copy finishes within
     * this request's time budget.
     */
    public function restore_file_backup()
    {
        $this->check_backup_privilege();

        $job_id  = sanitize_text_field(anibas_fm_fetch_request_variable('post', 'job_id', ''));
        if (! empty($job_id)) {
            $this->continue_file_backup_job($job_id);
        }

        $key     = sanitize_text_field(anibas_fm_fetch_request_variable('post', 'key', ''));
        $version = sanitize_text_field(anibas_fm_fetch_request_variable('post', 'version', ''));

        // Hardened path-segment checks — keys are md5, versions are timestamp__basename
        if (! preg_match('/^[a-f0-9]{32}$/', $key)) {
            $this->send_error(array('error' => esc_html__('Invalid backup key', 'anibas-file-manager')));
        }
        if (empty($version) || strpos($version, '/') !== false || strpos($version, '\\') !== false || strpos($version, '..') !== false) {
            $this->send_error(array('error' => esc_html__('Invalid version', 'anibas-file-manager')));
        }
        if (anibas_fm_is_file_backup_internal_name($version)) {
            $this->send_error(array('error' => esc_html__('Invalid version', 'anibas-file-manager')));
        }

        $src_dir = anibas_fm_get_file_backups_dir() . '/' . $key;
        $backup  = $src_dir . '/' . $version;
        $marker  = $src_dir . '/.source';

        if (! is_file($backup) || ! file_exists($marker)) {
            $this->send_error(array('error' => esc_html__('Backup not found', 'anibas-file-manager')));
        }

        try {
            $raw = anibas_fm_read_small_file($marker);
        } catch (\Throwable $e) {
            $this->send_error(array('error' => esc_html__('Backup metadata is corrupt', 'anibas-file-manager')));
        }
        $parts   = explode('|', $raw, 2);
        $storage = $parts[0] ?? '';
        $target  = $parts[1] ?? '';
        if (empty($storage) || empty($target)) {
            $this->send_error(array('error' => esc_html__('Backup metadata is corrupt', 'anibas-file-manager')));
        }

        if ($storage === 'local') {
            $target = $this->validate_local_restore_target($target);
            if (! $target) {
                $this->send_error(array('error' => esc_html__('Target path is outside the site', 'anibas-file-manager')));
            }
            $restore_dir = dirname($target);
            if (! is_dir($restore_dir)) {
                wp_mkdir_p($restore_dir);
            }

            $job = $this->create_file_copy_job(array(
                'operation' => 'restore_local',
                'storage'   => 'local',
                'source'    => $backup,
                'target'    => $target,
                'tmp'       => $restore_dir . '/.anfm-restore-' . wp_generate_password(8, false) . '-' . basename($target),
                'message'   => esc_html__('Backup restored', 'anibas-file-manager'),
            ));
            $this->process_file_backup_job($job);
        }

        $adapter = StorageManager::get_instance()->get_adapter($storage);
        if (! $adapter) {
            $this->send_error(array('error' => esc_html__('Invalid storage', 'anibas-file-manager')));
        }

        $remote_dir = rtrim(dirname($target), '/');
        $remote_tmp = $remote_dir . '/.anfm-restore-' . wp_generate_password(8, false) . '-' . basename($target);
        $job = $this->create_file_copy_job(array(
            'operation' => 'restore_remote',
            'storage'   => $storage,
            'source'    => $backup,
            'target'    => $target,
            'tmp'       => $remote_tmp,
            'message'   => esc_html__('Backup restored', 'anibas-file-manager'),
        ));
        $this->process_file_backup_job($job);
    }

    private function validate_local_restore_target(string $target): string|false
    {
        $root = realpath(ABSPATH);
        if (! $root) {
            return false;
        }

        $target = $this->normalize_absolute_path($target);
        $root   = untrailingslashit(wp_normalize_path($root));
        if (! $this->path_is_inside($target, $root)) {
            return false;
        }

        $relative = ltrim(substr($target, strlen($root)), '/');
        if ($relative === '') {
            return false;
        }

        if (file_exists($target)) {
            return $this->validate_path($relative) ?: false;
        }

        if (! $this->local_restore_parent_is_allowed($target, $root)) {
            return false;
        }

        foreach (anibas_fm_exclude_paths() as $blocked) {
            $blocked_path = $root . '/' . trim(wp_normalize_path($blocked), '/');
            if ($this->path_is_inside($target, $blocked_path)) {
                return false;
            }
        }

        foreach (anibas_fm_get_blocked_paths() as $blocked) {
            $blocked_path = $root . '/' . trim(wp_normalize_path($blocked), '/');
            if (strpos($blocked, '*') !== false) {
                $pattern = str_replace('*', '.*', preg_quote($blocked_path, '/'));
                if (preg_match('/' . $pattern . '$/i', $target)) {
                    return false;
                }
            } elseif ($target === $blocked_path || $this->path_is_inside($target, $blocked_path)) {
                return false;
            }
        }

        return $target;
    }

    private function local_restore_parent_is_allowed(string $target, string $root): bool
    {
        $check = dirname($target);
        while (! is_dir($check) && $check !== $root && dirname($check) !== $check) {
            $check = dirname($check);
        }

        $real = realpath($check);
        if (! $real) {
            return false;
        }

        $real = untrailingslashit(wp_normalize_path($real));
        if (! $this->path_is_inside($real, $root)) {
            return false;
        }

        if ($real === $root) {
            return true;
        }

        $relative = ltrim(substr($real, strlen($root)), '/');
        return (bool) $this->validate_path($relative);
    }

    private function normalize_absolute_path(string $path): string
    {
        $path = str_replace(chr(0), '', wp_normalize_path($path));
        $prefix = str_starts_with($path, '/') ? '/' : '';
        if (preg_match('/^[A-Za-z]:\//', $path, $m)) {
            $prefix = $m[0];
            $path = substr($path, strlen($prefix));
        } else {
            $path = ltrim($path, '/');
        }

        $parts = array();
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }

        return untrailingslashit($prefix . implode('/', $parts));
    }

    private function path_is_inside(string $path, string $root): bool
    {
        $path = untrailingslashit(wp_normalize_path($path));
        $root = untrailingslashit(wp_normalize_path($root));
        return $path === $root || str_starts_with($path . '/', trailingslashit($root));
    }

    private function create_file_copy_job(array $data): array
    {
        $job_id = 'file_' . wp_generate_password(12, false);
        $job = array_merge(array(
            'job_id'       => $job_id,
            'user_id'      => get_current_user_id(),
            'operation'    => '',
            'storage'      => 'local',
            'source'       => '',
            'target'       => '',
            'tmp'          => '',
            'offset'       => 0,
            'total_size'   => 0,
            'message'      => '',
            'created_at'   => time(),
            'stack'        => array(),
        ), $data);

        $job['job_id'] = $job_id;
        $size_info = $this->file_backup_job_size_info($job);
        $job['total_size'] = $size_info['size'];
        $job['total_size_known'] = $size_info['known'];
        set_transient($this->file_backup_job_key($job_id), $job, 2 * HOUR_IN_SECONDS);
        return $job;
    }

    private function continue_file_backup_job(string $job_id): void
    {
        $job = $this->load_file_backup_job($job_id);
        if (! $job) {
            $this->send_error(array('error' => esc_html__('Backup job not found or expired', 'anibas-file-manager')));
        }
        $this->process_file_backup_job($job);
    }

    private function load_file_backup_job(string $job_id): array|false
    {
        if (! preg_match('/^file_[A-Za-z0-9]{12}$/', $job_id)) {
            return false;
        }
        $job = get_transient($this->file_backup_job_key($job_id));
        if (! is_array($job) || (int) ($job['user_id'] ?? 0) !== get_current_user_id()) {
            return false;
        }
        return $job;
    }

    private function save_file_backup_job(array $job): void
    {
        set_transient($this->file_backup_job_key((string) $job['job_id']), $job, 2 * HOUR_IN_SECONDS);
    }

    private function delete_file_backup_job(array $job): void
    {
        delete_transient($this->file_backup_job_key((string) $job['job_id']));
    }

    private function file_backup_job_key(string $job_id): string
    {
        return 'anibas_fm_file_backup_job_' . $job_id;
    }

    private function process_file_backup_job(array $job): void
    {
        $operation = (string) ($job['operation'] ?? '');
        if ($operation === 'backup_remote') {
            $done = $this->process_remote_download_job($job);
        } elseif ($operation === 'restore_remote') {
            $done = $this->process_remote_upload_job($job);
        } elseif ($operation === 'delete_tree') {
            $done = $this->process_delete_tree_job($job);
        } else {
            $done = $this->process_local_copy_job($job);
        }

        if (! $done) {
            $this->save_file_backup_job($job);
            $this->send_success(array(
                'status'  => 'running',
                'job_id'  => $job['job_id'],
                'progress' => $this->file_backup_job_progress($job),
            ));
        }

        $result = $this->finalize_file_backup_job($job);
        $this->delete_file_backup_job($job);
        $this->send_success(array_merge(array(
            'status'  => 'complete',
            'job_id'  => $job['job_id'],
            'message' => $job['message'],
        ), $result));
    }

    private function process_local_copy_job(array &$job): bool
    {
        $source = (string) ($job['source'] ?? '');
        $tmp    = (string) ($job['tmp'] ?? '');
        if (! is_file($source) || $tmp === '') {
            $this->send_error(array('error' => esc_html__('Backup source is missing', 'anibas-file-manager')));
        }

        $dir = dirname($tmp);
        if (! is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        $total = (int) @filesize($source);
        $job['total_size'] = max((int) ($job['total_size'] ?? 0), $total);
        if ($total === 0) {
            if (@file_put_contents($tmp, '') === false) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents
                $this->send_error(array('error' => esc_html__('Failed to create backup file', 'anibas-file-manager')));
            }
            $job['offset'] = 0;
            return true;
        }

        $offset = max(0, (int) ($job['offset'] ?? 0));
        $src = @fopen($source, 'rb');
        $dst = @fopen($tmp, $offset > 0 ? 'c+b' : 'wb');
        if (! $src || ! $dst) {
            if ($src) fclose($src);
            if ($dst) fclose($dst);
            $this->send_error(array('error' => esc_html__('Failed to open backup file', 'anibas-file-manager')));
        }
        if ($offset > 0) {
            fseek($src, $offset);
            fseek($dst, $offset);
        }

        $started = microtime(true);
        $chunk_size = $this->file_backup_chunk_size();
        while (! feof($src)) {
            $chunk = fread($src, $chunk_size);
            if ($chunk === false) {
                fclose($src);
                fclose($dst);
                $this->send_error(array('error' => esc_html__('Failed to read backup file', 'anibas-file-manager')));
            }
            if ($chunk === '') {
                break;
            }
            $written = fwrite($dst, $chunk);
            if ($written === false || $written !== strlen($chunk)) {
                fclose($src);
                fclose($dst);
                $this->send_error(array('error' => esc_html__('Failed to write backup file', 'anibas-file-manager')));
            }
            $offset += $written;
            $job['offset'] = $offset;
            if ((microtime(true) - $started) >= $this->file_backup_time_budget()) {
                fclose($src);
                fclose($dst);
                return false;
            }
        }

        fclose($src);
        fclose($dst);
        return $offset >= $total;
    }

    private function process_remote_download_job(array &$job): bool
    {
        $adapter = StorageManager::get_instance()->get_adapter((string) $job['storage']);
        if (! $adapter) {
            $this->send_error(array('error' => esc_html__('Invalid storage', 'anibas-file-manager')));
        }
        $tmp = (string) $job['tmp'];
        $dir = dirname($tmp);
        if (! is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        $result = $adapter->download_to_local_chunked((string) $job['source'], $tmp, (int) $job['offset'], $this->file_backup_chunk_size());
        $job['offset'] = (int) ($result['bytes_copied'] ?? $job['offset']);
        $status = (int) ($result['status'] ?? 0);
        if ($status === 9) {
            return true;
        }
        if ($status === 10) {
            return false;
        }
        if ((int) ($job['offset'] ?? 0) === 0 && ! empty($job['total_size_known']) && (int) ($job['total_size'] ?? 0) === 0) {
            if (@file_put_contents($tmp, '') !== false) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents
                return true;
            }
        }
        $this->send_error(array('error' => esc_html__('Failed to read remote file', 'anibas-file-manager')));
    }

    private function process_remote_upload_job(array &$job): bool
    {
        $adapter = StorageManager::get_instance()->get_adapter((string) $job['storage']);
        if (! $adapter) {
            $this->send_error(array('error' => esc_html__('Invalid storage', 'anibas-file-manager')));
        }
        $result = $adapter->upload_from_local_chunked((string) $job['source'], (string) $job['tmp'], (int) $job['offset'], $this->file_backup_chunk_size());
        $job['offset'] = (int) ($result['bytes_copied'] ?? $job['offset']);
        $status = (int) ($result['status'] ?? 0);
        if ($status === 9) {
            return true;
        }
        if ($status === 10) {
            return false;
        }
        $this->send_error(array('error' => esc_html__('Failed to restore backup to remote storage', 'anibas-file-manager')));
    }

    private function process_delete_tree_job(array &$job): bool
    {
        $root = (string) ($job['source'] ?? '');
        $stack = ! empty($job['stack']) && is_array($job['stack']) ? $job['stack'] : array($root);
        $started = microtime(true);
        $deleted = 0;

        while (! empty($stack)) {
            $dir = end($stack);
            if (! is_string($dir) || ! $this->path_is_inside($dir, $root)) {
                $this->send_error(array('error' => esc_html__('Invalid backup delete path', 'anibas-file-manager')));
            }
            if (! is_dir($dir)) {
                array_pop($stack);
                continue;
            }

            $handle = @opendir($dir);
            if (! $handle) {
                $this->send_error(array('error' => esc_html__('Failed to delete backup history', 'anibas-file-manager')));
            }

            $descended = false;
            $paused = false;
            $found = false;
            while (($name = readdir($handle)) !== false) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $found = true;
                $full = wp_normalize_path($dir . '/' . $name);
                if (! $this->path_is_inside($full, $root)) {
                    closedir($handle);
                    $this->send_error(array('error' => esc_html__('Invalid backup delete path', 'anibas-file-manager')));
                }
                if (is_dir($full) && ! is_link($full)) {
                    $stack[] = $full;
                    $descended = true;
                    break;
                }
                if (! @unlink($full)) {
                    closedir($handle);
                    $this->send_error(array('error' => esc_html__('Failed to delete backup history', 'anibas-file-manager')));
                }
                $deleted++;
                if ($deleted >= 1000 || (microtime(true) - $started) >= $this->file_backup_time_budget()) {
                    $paused = true;
                    break;
                }
            }
            closedir($handle);

            if ($paused || $descended) {
                $job['stack'] = $stack;
                return false;
            }
            if (! $found) {
                if (! @rmdir($dir)) {
                    $this->send_error(array('error' => esc_html__('Failed to delete backup history', 'anibas-file-manager')));
                }
                array_pop($stack);
            }
        }

        $job['stack'] = array();
        return true;
    }

    private function finalize_file_backup_job(array $job): array
    {
        $operation = (string) ($job['operation'] ?? '');
        if ($operation === 'delete_tree') {
            ActivityLogger::log('deleted_file_backup_tree', (string) ($job['key'] ?? basename((string) $job['source'])), 'file-backup');
            return array();
        }

        if ($operation === 'backup_local' || $operation === 'backup_remote') {
            if (! @rename((string) $job['tmp'], (string) $job['target'])) {
                $this->send_error(array('error' => esc_html__('Backup failed', 'anibas-file-manager')));
            }
            return array();
        }

        if ($operation === 'restore_local') {
            return $this->finalize_local_restore_job($job);
        }

        if ($operation === 'restore_remote') {
            return $this->finalize_remote_restore_job($job);
        }

        return array();
    }

    private function finalize_local_restore_job(array $job): array
    {
        $target = (string) $job['target'];
        $renamed_path = $this->rename_existing_local_target($target);
        if ($renamed_path === false) {
            $this->send_error(array('error' => esc_html__('Failed to rename existing file', 'anibas-file-manager')));
        }
        if (! @rename((string) $job['tmp'], $target)) {
            if (is_string($renamed_path)) {
                @rename($renamed_path, $target);
            }
            $this->send_error(array('error' => esc_html__('Failed to restore backup', 'anibas-file-manager')));
        }
        $display = '/' . ltrim(str_replace(wp_normalize_path(ABSPATH), '', wp_normalize_path($target)), '/');
        ActivityLogger::log('restored_file_backup', basename($target), 'file-backup');
        return array(
            'restored_to'      => $display,
            'renamed_existing' => is_string($renamed_path) ? basename($renamed_path) : null,
        );
    }

    private function finalize_remote_restore_job(array $job): array
    {
        $adapter = StorageManager::get_instance()->get_adapter((string) $job['storage']);
        if (! $adapter) {
            $this->send_error(array('error' => esc_html__('Invalid storage', 'anibas-file-manager')));
        }

        $target = (string) $job['target'];
        $renamed_path = $this->rename_existing_remote_target($adapter, $target);
        if ($renamed_path === false) {
            $this->send_error(array('error' => esc_html__('Failed to rename existing remote file', 'anibas-file-manager')));
        }
        if (! $adapter->move((string) $job['tmp'], $target)) {
            if (is_string($renamed_path)) {
                $adapter->move($renamed_path, $target);
            }
            $this->send_error(array('error' => esc_html__('Failed to restore backup to remote storage', 'anibas-file-manager')));
        }
        ActivityLogger::log('restored_file_backup', basename($target), 'file-backup');
        return array(
            'restored_to'      => $target,
            'storage'          => (string) $job['storage'],
            'renamed_existing' => is_string($renamed_path) ? basename($renamed_path) : null,
        );
    }

    private function rename_existing_local_target(string $target): string|false|null
    {
        if (! file_exists($target)) {
            return null;
        }

        $candidate = $this->next_available_local_old_name($target);
        return $candidate !== null && @rename($target, $candidate) ? $candidate : false;
    }

    private function next_available_local_old_name(string $path): ?string
    {
        $info = pathinfo($path);
        $base = $info['filename'];
        $ext  = isset($info['extension']) ? '.' . $info['extension'] : '';
        $dir  = $info['dirname'];

        for ($n = 1; $n <= 1000; $n++) {
            $candidate = $dir . '/' . $base . '-old-' . $n . $ext;
            if (! file_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function rename_existing_remote_target($adapter, string $target): string|false|null
    {
        if (! $adapter->exists($target)) {
            return null;
        }

        $candidate = $this->next_available_remote_old_name($adapter, $target);
        return $candidate !== null && $adapter->move($target, $candidate) ? $candidate : false;
    }

    private function next_available_remote_old_name($adapter, string $path): ?string
    {
        $info = pathinfo($path);
        $base = $info['filename'];
        $ext  = isset($info['extension']) ? '.' . $info['extension'] : '';
        $dir  = $info['dirname'];

        for ($n = 1; $n <= 1000; $n++) {
            $candidate = $dir . '/' . $base . '-old-' . $n . $ext;
            if (! $adapter->exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function file_backup_job_size_info(array $job): array
    {
        $operation = (string) ($job['operation'] ?? '');
        if ($operation === 'backup_remote') {
            $adapter = StorageManager::get_instance()->get_adapter((string) $job['storage']);
            if ($adapter) {
                $size = $adapter->get_size((string) $job['source']);
                if ($size !== false) {
                    return array('size' => (int) $size, 'known' => true);
                }
                $details = $adapter->getDetails((string) $job['source']);
                if (is_array($details)) {
                    $detail_size = $details['size'] ?? $details['filesize'] ?? null;
                    if (is_numeric($detail_size)) {
                        return array('size' => (int) $detail_size, 'known' => true);
                    }
                }
            }
            return array('size' => 0, 'known' => false);
        }
        return is_file((string) $job['source'])
            ? array('size' => (int) @filesize((string) $job['source']), 'known' => true)
            : array('size' => 0, 'known' => false);
    }

    private function file_backup_job_progress(array $job): array
    {
        $total = (int) ($job['total_size'] ?? 0);
        $offset = (int) ($job['offset'] ?? 0);
        return array(
            'bytes_processed' => $offset,
            'total_size'      => $total,
            'percent'         => $total > 0 ? round(min(100, ($offset / $total) * 100), 2) : 0,
        );
    }

    private function file_backup_chunk_size(): int
    {
        $chunk_size = intval(anibas_fm_get_option('chunk_size', ANIBAS_FM_DEFAULT_CHUNK_SIZE));
        return function_exists('anibas_fm_safe_chunk_size')
            ? anibas_fm_safe_chunk_size($chunk_size)
            : max(ANIBAS_FM_CHUNK_SIZE_MIN, min(ANIBAS_FM_CHUNK_SIZE_MAX, $chunk_size));
    }

    private function file_backup_time_budget(): int
    {
        return function_exists('anibas_fm_safe_time_budget')
            ? anibas_fm_safe_time_budget(20, 0.6)
            : 20;
    }

    /**
     * Delete a single version of a per-file backup.
     *
     * Requires backup privilege. `key` must be a 32-hex-char backup group
     * id and `version` must not contain path separators or `..`. If this
     * was the last remaining version in the group, the whole group
     * directory (including its `.source` marker) is removed.
     */
    public function delete_file_backup()
    {
        $this->check_backup_privilege();

        $key     = sanitize_text_field(anibas_fm_fetch_request_variable('post', 'key', ''));
        $version = sanitize_text_field(anibas_fm_fetch_request_variable('post', 'version', ''));

        if (! preg_match('/^[a-f0-9]{32}$/', $key)) {
            $this->send_error(array('error' => esc_html__('Invalid backup key', 'anibas-file-manager')));
        }
        if (empty($version) || strpos($version, '/') !== false || strpos($version, '\\') !== false || strpos($version, '..') !== false) {
            $this->send_error(array('error' => esc_html__('Invalid version', 'anibas-file-manager')));
        }
        if (anibas_fm_is_file_backup_internal_name($version)) {
            $this->send_error(array('error' => esc_html__('Invalid version', 'anibas-file-manager')));
        }

        $root   = anibas_fm_get_file_backups_dir();
        $src_dir = $root . '/' . $key;
        $backup = $src_dir . '/' . $version;

        if (! is_file($backup)) {
            $this->send_error(array('error' => esc_html__('Backup not found', 'anibas-file-manager')));
        }

        if (! @unlink($backup)) {
            $this->send_error(array('error' => esc_html__('Failed to delete backup', 'anibas-file-manager')));
        }

        $remaining_versions = array();
        if (is_dir($src_dir)) {
            foreach (new \DirectoryIterator($src_dir) as $item) {
                if ($item->isDot() || ! $item->isFile()) continue;
                if (anibas_fm_is_file_backup_internal_name($item->getFilename())) continue;
                $remaining_versions[] = $item->getFilename();
            }
        }

        if (empty($remaining_versions) && is_dir($src_dir)) {
            foreach (array('.source', '.htaccess', 'index.php') as $internal) {
                $internal_path = $src_dir . '/' . $internal;
                if (is_file($internal_path)) {
                    @unlink($internal_path);
                }
            }
            @rmdir($src_dir);
        }

        ActivityLogger::log('deleted_file_backup', $version, 'file-backup');
        $this->send_success(array('message' => esc_html__('Backup deleted', 'anibas-file-manager')));
    }

    /**
     * Delete an entire per-file backup group (all versions and its
     * `.source` marker), driven as a resumable job.
     *
     * Requires backup privilege. When `job_id` is present, resumes that
     * job. `key` must be a 32-hex-char backup group id. Deletion walks the
     * group directory iteratively (not recursively) so it can pause and
     * resume within the request's time/entry budget for very large
     * histories.
     */
    public function delete_file_backup_tree()
    {
        $this->check_backup_privilege();

        $job_id = sanitize_text_field(anibas_fm_fetch_request_variable('post', 'job_id', ''));
        if (! empty($job_id)) {
            $this->continue_file_backup_job($job_id);
        }

        $key = sanitize_text_field(anibas_fm_fetch_request_variable('post', 'key', ''));

        if (! preg_match('/^[a-f0-9]{32}$/', $key)) {
            $this->send_error(array('error' => esc_html__('Invalid backup key', 'anibas-file-manager')));
        }

        $root    = anibas_fm_get_file_backups_dir();
        $src_dir = $root . '/' . $key;
        $marker  = $src_dir . '/.source';

        if (! is_dir($src_dir) || ! is_file($marker)) {
            $this->send_error(array('error' => esc_html__('Backup group not found', 'anibas-file-manager')));
        }

        $job = $this->create_file_copy_job(array(
            'operation' => 'delete_tree',
            'source'    => $src_dir,
            'target'    => $src_dir,
            'tmp'       => '',
            'key'       => $key,
            'message'   => esc_html__('Backup history deleted', 'anibas-file-manager'),
            'stack'     => array($src_dir),
        ));
        $this->process_file_backup_job($job);
    }

    /**
     * List full-site backup archives (.tar and .anfm) in the backup
     * directory, newest first.
     *
     * Requires backup privilege. Only .anfm backups are marked
     * restore_supported, and restorable is additionally gated on the
     * ANIBAS_FM_ENABLE_SITE_RESTORE constant.
     */
    public function list_site_backups()
    {
        $this->check_backup_privilege();

        $backup_dir = anibas_fm_get_backup_dir();
        $items = array();

        if (is_dir($backup_dir)) {
            foreach (new \DirectoryIterator($backup_dir) as $item) {
                if ($item->isDot() || ! $item->isFile()) continue;
                $name = $item->getFilename();
                if ($name === '.htaccess' || $name === 'index.php') continue;
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (! in_array($ext, array('tar', 'anfm'), true)) continue;

                $items[] = array(
                    'name'     => $name,
                    'format'   => $ext,
                    'mtime'    => $item->getMTime(),
                    'filesize' => $item->getSize(),
                    'restore_supported' => $ext === 'anfm',
                    'restore_enabled' => (bool) ANIBAS_FM_ENABLE_SITE_RESTORE,
                    'restorable' => $ext === 'anfm' && (bool) ANIBAS_FM_ENABLE_SITE_RESTORE,
                );
            }
        }

        usort($items, function ($a, $b) { return $b['mtime'] <=> $a['mtime']; });

        $this->send_success(array('items' => $items, 'total_items' => count($items)));
    }

    /**
     * Permanently delete a full-site backup archive.
     *
     * Requires backup privilege. `name` is restricted to a bare filename
     * (no path separators or `..`) with a .tar/.anfm extension, and the
     * resolved real path is re-confirmed to live inside the backup
     * directory. Refuses to delete a backup that is currently being
     * created or is currently being restored (checked against the active
     * backup/restore locks).
     */
    public function delete_site_backup()
    {
        $this->check_backup_privilege();

        $name = sanitize_file_name(anibas_fm_fetch_request_variable('post', 'name', ''));
        if (empty($name) || strpos($name, '..') !== false || strpos($name, '/') !== false || strpos($name, '\\') !== false) {
            $this->send_error(array('error' => esc_html__('Invalid backup name', 'anibas-file-manager')));
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (! in_array($ext, array('tar', 'anfm'), true)) {
            $this->send_error(array('error' => esc_html__('Invalid backup format', 'anibas-file-manager')));
        }

        $backup_dir = anibas_fm_get_backup_dir();
        $full_path  = $backup_dir . '/' . $name;
        $real_dir   = realpath($backup_dir);
        $real_path  = realpath($full_path);

        if (! $real_dir || ! $real_path || strpos($real_path, trailingslashit($real_dir)) !== 0 || ! is_file($real_path)) {
            $this->send_error(array('error' => esc_html__('Backup not found', 'anibas-file-manager')));
        }

        $lock = anibas_fm_get_backup_lock();
        if ($lock && ! empty($lock['output']) && basename((string) $lock['output']) === basename($real_path)) {
            $this->send_error(array('error' => esc_html__('Cannot delete a backup that is currently being created', 'anibas-file-manager')));
        }

        $restore_lock = SiteRestoreEngine::get_lock();
        if ($restore_lock && ! empty($restore_lock['archive']) && basename((string) $restore_lock['archive']) === basename($real_path)) {
            $this->send_error(array('error' => esc_html__('Cannot delete a backup that is currently being restored', 'anibas-file-manager')));
        }

        if (! @unlink($real_path)) {
            $this->send_error(array('error' => esc_html__('Failed to delete site backup', 'anibas-file-manager')));
        }

        ActivityLogger::log('deleted_site_backup', basename($real_path), 'site-backup');
        $this->send_success(array('message' => esc_html__('Backup deleted', 'anibas-file-manager')));
    }

    /**
     * Start (or poll) uploading a full-site backup archive to a remote
     * storage backend.
     *
     * Requires backup privilege. When `job_id` is present, delegates to
     * send_site_backup_cloud_status() to report progress instead of
     * starting a new upload. Otherwise resolves and validates the backup
     * file, refuses local storage as a destination, checks the storage
     * pair is a valid cross-storage transfer, creates (or reuses) a
     * dedicated backup folder on the destination, and enqueues the upload
     * as a background cross-storage copy job.
     */
    public function send_site_backup_to_cloud()
    {
        $this->check_backup_privilege();

        $job_id = sanitize_text_field(anibas_fm_fetch_request_variable('post', 'job_id', ''));
        if ($job_id !== '') {
            $this->send_site_backup_cloud_status($job_id);
            return;
        }

        $name = sanitize_file_name(anibas_fm_fetch_request_variable('post', 'name', ''));
        $dest_storage = sanitize_key(anibas_fm_fetch_request_variable('post', 'storage', ''));
        $destination = '/';

        if ($dest_storage === '' || $dest_storage === 'local') {
            $this->send_error(array('error' => esc_html__('Choose a cloud storage destination.', 'anibas-file-manager')));
        }

        try {
            $source_path = $this->resolve_site_backup_path($name, false);
            $this->assert_site_backup_not_busy($source_path);

            $sm = StorageManager::get_instance();
            $validation = $sm->validate_cross_storage_transfer('local', $dest_storage);
            if (is_wp_error($validation)) {
                $this->send_error(array('error' => $validation->get_error_message()));
            }

            $adapter = $this->get_storage_adapter($dest_storage);
            if (! $adapter) {
                $this->send_error(array('error' => esc_html__('Invalid storage', 'anibas-file-manager')));
            }

            $folder_name = anibas_fm_get_site_backup_cloud_folder_name();
            $dest_dir = $this->resolve_site_backup_cloud_destination($adapter, $destination, $folder_name);

            $job_id = BackgroundProcessor::enqueue_cross_storage_job(
                $source_path,
                $dest_dir,
                'copy',
                'rename',
                'local',
                $dest_storage,
                array(
                    'allow_private_backup_source' => true,
                    'ui_group_action' => 'copy',
                    'ui_group_mode' => 'site_backup_cloud_upload',
                    'ui_group_label' => sprintf(
                        /* translators: 1: backup file name, 2: remote folder name. */
                        esc_html__('Upload backup %1$s to %2$s', 'anibas-file-manager'),
                        basename($source_path),
                        $folder_name
                    ),
                    'ui_group_source' => basename($source_path),
                    'ui_group_destination' => $dest_dir,
                )
            );

            if (is_wp_error($job_id)) {
                $this->send_error(array(
                    'error' => $job_id->get_error_code(),
                    'message' => $job_id->get_error_message(),
                ));
            }

            ActivityLogger::log('started_site_backup_cloud_upload', basename($source_path), $dest_storage);
            set_transient($this->site_backup_cloud_job_key($job_id), array(
                'name' => basename($source_path),
                'storage' => $dest_storage,
                'destination' => $dest_dir,
                'folder' => $folder_name,
                'started_at' => time(),
            ), 2 * HOUR_IN_SECONDS);

            $this->send_success(array(
                'status' => 'running',
                'job_id' => $job_id,
                'storage' => $dest_storage,
                'destination' => $dest_dir,
                'folder' => $folder_name,
                'message' => esc_html__('Backup upload started', 'anibas-file-manager'),
            ));
        } catch (\Throwable $e) {
            $this->send_error(array('error' => esc_html($e->getMessage())));
        }
    }

    /**
     * Import a full-site .anfm backup archive from a remote storage
     * backend into the local backup directory.
     *
     * Requires backup privilege. Refuses local storage as a source, checks
     * the storage pair is a valid cross-storage transfer, and requires the
     * source file to live in an Anibas site-backup cloud folder with a
     * .anfm extension (guards against importing arbitrary remote files as
     * if they were trusted backups). The copy is enqueued as a background
     * cross-storage job; the response carries a `job_id` for polling.
     */
    public function import_site_backup_from_cloud()
    {
        $this->check_backup_privilege();

        $source = anibas_fm_fetch_request_variable('post', 'source', '');
        $source_storage = sanitize_key(anibas_fm_fetch_request_variable('post', 'storage', ''));

        if ($source_storage === '' || $source_storage === 'local') {
            $this->send_error(array('error' => esc_html__('Choose a cloud backup file.', 'anibas-file-manager')));
        }

        try {
            $sm = StorageManager::get_instance();
            $validation = $sm->validate_cross_storage_transfer($source_storage, 'local');
            if (is_wp_error($validation)) {
                $this->send_error(array('error' => $validation->get_error_message()));
            }

            $adapter = $this->get_storage_adapter($source_storage);
            if (! $adapter) {
                $this->send_error(array('error' => esc_html__('Invalid storage', 'anibas-file-manager')));
            }

            $source_path = $adapter->validate_path($source);
            if ($source_path === false || ! $adapter->is_file($source_path)) {
                $this->send_error(array('error' => esc_html__('Cloud backup file not found.', 'anibas-file-manager')));
            }

            if (! anibas_fm_is_site_backup_cloud_path($source_path) || strtolower(pathinfo($source_path, PATHINFO_EXTENSION)) !== 'anfm') {
                $this->send_error(array('error' => esc_html__('Only ANFM full-site backups from an Anibas backup folder can be imported.', 'anibas-file-manager')));
            }

            $backup_name = sanitize_file_name(basename($source_path));
            if ($backup_name === '' || strtolower(pathinfo($backup_name, PATHINFO_EXTENSION)) !== 'anfm') {
                $backup_name = 'cloud-site-backup-' . gmdate('Ymd-His') . '.anfm';
            }

            $backup_dir = anibas_fm_get_backup_dir();
            $destination = trailingslashit($backup_dir) . $backup_name;

            $job_id = BackgroundProcessor::enqueue_cross_storage_job(
                $source_path,
                $destination,
                'copy',
                'rename',
                $source_storage,
                'local',
                array(
                    'dest_is_final' => true,
                    'allow_private_backup_destination' => true,
                    'ui_group_mode' => 'site_backup_cloud_import',
                    'ui_group_label' => sprintf(
                        /* translators: %s: backup file name. */
                        esc_html__('Import cloud backup %s', 'anibas-file-manager'),
                        $backup_name
                    ),
                    'ui_group_source' => basename($source_path),
                )
            );

            if (is_wp_error($job_id)) {
                $this->send_error(array(
                    'error' => $job_id->get_error_code(),
                    'message' => $job_id->get_error_message(),
                ));
            }

            ActivityLogger::log('started_site_backup_cloud_import', $backup_name, $source_storage);
            $this->send_success(array(
                'status' => 'running',
                'job_id' => $job_id,
                'source' => $source_path,
                'destination' => esc_html__('Local Backups', 'anibas-file-manager'),
                'message' => esc_html__('Backup import started', 'anibas-file-manager'),
            ));
        } catch (\Throwable $e) {
            $this->send_error(array('error' => esc_html($e->getMessage())));
        }
    }

    /**
     * Validate a full-site backup archive and return a shallow manifest
     * preview (up to `limit` entries) for the pre-restore confirmation UI.
     *
     * Requires backup privilege. If the archive is password-protected and
     * no `password` was supplied, reports password_required instead of
     * reading the manifest. The restore engine instance used to read the
     * manifest is always cleaned up afterward, even on error.
     */
    public function site_backup_preview()
    {
        $this->check_backup_privilege();

        $name     = sanitize_file_name(anibas_fm_fetch_request_variable('post', 'name', ''));
        $password = anibas_fm_fetch_request_variable('post', 'password', '');
        $limit    = (int) anibas_fm_fetch_request_variable('post', 'limit', 80);

        try {
            $path = $this->resolve_site_backup_path($name, true);
            $validator = new SiteBackupPackageValidator();
            $package = $validator->validate($path);
            $package_public = array(
                'size'               => (int) ($package['size'] ?? 0),
                'version'            => (int) ($package['version'] ?? 0),
                'password_protected' => ! empty($package['password_protected']),
                'manifest_size'      => (int) ($package['manifest_size'] ?? 0),
            );

            if (! empty($package['password_protected']) && $password === '') {
                $this->send_success(array(
                    'password_required' => true,
                    'package'           => $package_public,
                    'manifest'          => null,
                ));
            }

            $engine = ArchiveRestoreEngine::get_instance($path, anibas_fm_get_backup_dir());
            try {
                $manifest = $engine->preview_manifest($password !== '' ? (string) $password : null, $limit, false);
            } finally {
                $engine->cleanup();
            }

            $this->send_success(array(
                'password_required' => false,
                'package'           => $package_public,
                'manifest'          => $manifest,
            ));
        } catch (\Throwable $e) {
            $this->send_error(array('error' => esc_html($e->getMessage())));
        }
    }

    /**
     * Multi-mode inspector for a full-site backup archive's contents:
     * `prepare` builds/reads the cached manifest, `browse` lists a
     * directory within it, and `search` queries entries by name.
     *
     * Requires backup privilege. If the archive is password-protected and
     * no `password` was supplied, reports password_required without
     * touching the manifest. `prepare` reports the manifest build's own
     * progress if it isn't complete yet, regardless of the requested mode,
     * so callers must poll `prepare` to completion before `browse`/`search`
     * can return real results. An unrecognized `mode` is rejected with a
     * 400 InvalidInspectMode error.
     */
    public function site_backup_inspect()
    {
        $this->check_backup_privilege();

        $name      = sanitize_file_name(anibas_fm_fetch_request_variable('post', 'name', ''));
        $password  = anibas_fm_fetch_request_variable('post', 'password', '');
        $mode      = sanitize_key(anibas_fm_fetch_request_variable('post', 'mode', 'prepare'));
        $directory = (string) anibas_fm_fetch_request_variable('post', 'directory', '');
        $query     = (string) anibas_fm_fetch_request_variable('post', 'query', '');
        $cursor    = max(0, (int) anibas_fm_fetch_request_variable('post', 'cursor', 0));
        $limit     = max(0, (int) anibas_fm_fetch_request_variable('post', 'limit', 0));

        try {
            $path = $this->resolve_site_backup_path($name, true);
            $validator = new SiteBackupPackageValidator();
            $package = $validator->validate($path);
            $package_public = $this->site_backup_package_public($package);

            if (! empty($package['password_protected']) && $password === '') {
                $this->send_success(array(
                    'password_required' => true,
                    'package'           => $package_public,
                    'inspect'           => null,
                ));
            }

            $engine = ArchiveRestoreEngine::get_instance($path, anibas_fm_get_backup_dir());
            $inspect = $engine->prepare_manifest_cache_step($password !== '' ? (string) $password : null);

            if (empty($inspect['complete']) || $mode === 'prepare') {
                $this->send_success(array(
                    'password_required' => false,
                    'package'           => $package_public,
                    'inspect'           => $inspect,
                ));
            }

            if ($mode === 'browse') {
                $this->send_success(array(
                    'password_required' => false,
                    'package'           => $package_public,
                    'inspect'           => $inspect,
                    'tree'              => $engine->browse_manifest($directory, $cursor, $limit),
                ));
            }

            if ($mode === 'search') {
                $this->send_success(array(
                    'password_required' => false,
                    'package'           => $package_public,
                    'inspect'           => $inspect,
                    'search'            => $engine->search_manifest($query, $cursor, $limit),
                ));
            }

            $this->send_error(array('error' => 'InvalidInspectMode', 'message' => esc_html__('Invalid backup inspection mode.', 'anibas-file-manager')), 400);
        } catch (\Throwable $e) {
            $this->send_error(array('error' => esc_html($e->getMessage())));
        }
    }

    /**
     * Stream a single file out of a full-site backup archive's manifest,
     * and exit — this is not a JSON AJAX endpoint.
     *
     * Requires backup privilege. Calls wp_die() (not send_error()) on any
     * failure, since the response is a raw file stream. Requires the
     * archive's manifest cache to already be built via site_backup_inspect()
     * — dies with a 409 if it isn't — and requires the `password` field if
     * the archive is password-protected.
     */
    public function site_backup_download_file()
    {
        $this->check_backup_privilege();

        $name     = sanitize_file_name(anibas_fm_fetch_request_variable('post', 'name', ''));
        $password = anibas_fm_fetch_request_variable('post', 'password', '');
        $entry    = (string) anibas_fm_fetch_request_variable('post', 'entry', '');

        try {
            $path = $this->resolve_site_backup_path($name, true);
            $validator = new SiteBackupPackageValidator();
            $package = $validator->validate($path);
            if (! empty($package['password_protected']) && $password === '') {
                wp_die(esc_html__('Backup password is required.', 'anibas-file-manager'), esc_html__('Error', 'anibas-file-manager'), array('response' => 401));
            }

            $engine = ArchiveRestoreEngine::get_instance($path, anibas_fm_get_backup_dir());
            if (! $engine->manifest_cache_ready()) {
                wp_die(esc_html__('Inspect the backup before downloading individual files.', 'anibas-file-manager'), esc_html__('Error', 'anibas-file-manager'), array('response' => 409));
            }

            $engine->stream_manifest_file($entry, $password !== '' ? (string) $password : null);
        } catch (\Throwable $e) {
            wp_die(esc_html($e->getMessage()), esc_html__('Error', 'anibas-file-manager'), array('response' => 400));
        }
    }

    private function site_backup_package_public(array $package): array
    {
        return array(
            'size'               => (int) ($package['size'] ?? 0),
            'version'            => (int) ($package['version'] ?? 0),
            'password_protected' => ! empty($package['password_protected']),
            'manifest_size'      => (int) ($package['manifest_size'] ?? 0),
        );
    }

    /**
     * Start a full-site restore from a backup archive.
     *
     * Requires backup privilege and site restore to be enabled via the
     * ANIBAS_FM_ENABLE_SITE_RESTORE constant. Delegates setup (validating
     * the archive, staging, and locking) to SiteRestoreEngine::start(),
     * passing through the chosen `db_mode` and whether to preserve old data
     * during the swap.
     */
    public function site_restore_start()
    {
        $this->check_backup_privilege();
        $this->check_site_restore_enabled();

        $name     = sanitize_file_name(anibas_fm_fetch_request_variable('post', 'name', ''));
        $password = anibas_fm_fetch_request_variable('post', 'password', '');
        $db_mode  = sanitize_text_field(anibas_fm_fetch_request_variable('post', 'db_mode', SiteRestoreEngine::DB_MODE_STAGING_SWAP));
        $preserve_old_data = filter_var(anibas_fm_fetch_request_variable('post', 'preserve_old_data', false), FILTER_VALIDATE_BOOLEAN);

        try {
            $path = $this->resolve_site_backup_path($name, true);
            $result = SiteRestoreEngine::start($path, $password !== '' ? (string) $password : null, $db_mode, $preserve_old_data);
            $this->send_success($result);
        } catch (\Throwable $e) {
            $this->send_error(array('error' => esc_html($e->getMessage())));
        }
    }

    /**
     * Advance one step of an in-progress full-site restore job.
     *
     * Requires backup privilege and site restore to be enabled. Requires
     * `job_id`; `password` is passed through for archives that need it
     * re-supplied mid-restore. The response's `done` flag reflects whether
     * the step reported the restore as complete.
     */
    public function site_restore_poll()
    {
        $this->check_backup_privilege();
        $this->check_site_restore_enabled();

        $job_id   = sanitize_text_field(anibas_fm_fetch_request_variable('post', 'job_id', ''));
        $password = anibas_fm_fetch_request_variable('post', 'password', '');
        if ($job_id === '') {
            $this->send_error(array('error' => esc_html__('Missing restore job id', 'anibas-file-manager')));
        }

        try {
            $engine = new SiteRestoreEngine($job_id);
            $progress = $engine->run_step($password !== '' ? (string) $password : null);
            $this->send_success(array(
                'done' => ! empty($progress['complete']),
                'progress' => $progress,
            ));
        } catch (\Throwable $e) {
            $this->send_error(array('error' => esc_html($e->getMessage())));
        }
    }

    /**
     * Cancel an in-progress full-site restore job.
     *
     * Requires backup privilege and site restore to be enabled. Requires
     * `job_id`. If the engine rejects the cancellation (e.g. the restore
     * has passed a point of no return), responds with a 409
     * SiteRestoreCancelRejected error carrying the engine's message.
     */
    public function site_restore_cancel()
    {
        $this->check_backup_privilege();
        $this->check_site_restore_enabled();

        $job_id = sanitize_text_field(anibas_fm_fetch_request_variable('post', 'job_id', ''));
        if ($job_id === '') {
            $this->send_error(array('error' => esc_html__('Missing restore job id', 'anibas-file-manager')));
        }

        try {
            $engine = new SiteRestoreEngine($job_id);
            $engine->cancel();
            $this->send_success(array('cancelled' => true));
        } catch (\Throwable $e) {
            $this->send_error(array(
                'error' => 'SiteRestoreCancelRejected',
                'message' => esc_html($e->getMessage()),
            ), 409);
        }
    }

    /**
     * Switch an in-progress full-site restore job from its current database
     * mode to a direct-overwrite fallback.
     *
     * Requires backup privilege and site restore to be enabled. Requires
     * `job_id`. Used when the primary db_mode (e.g. staging-swap) can't
     * proceed and the restore needs to fall back to overwriting the live
     * database directly.
     */
    public function site_restore_fallback_overwrite()
    {
        $this->check_backup_privilege();
        $this->check_site_restore_enabled();

        $job_id = sanitize_text_field(anibas_fm_fetch_request_variable('post', 'job_id', ''));
        if ($job_id === '') {
            $this->send_error(array('error' => esc_html__('Missing restore job id', 'anibas-file-manager')));
        }

        try {
            $engine = new SiteRestoreEngine($job_id);
            $progress = $engine->fallback_to_overwrite();
            $this->send_success(array(
                'progress' => $progress,
            ));
        } catch (\Throwable $e) {
            $this->send_error(array('error' => esc_html($e->getMessage())));
        }
    }

    /**
     * Report whether a full-site restore is currently running, for polling
     * on page load without needing a known `job_id`.
     *
     * Requires backup privilege and site restore to be enabled. Reads the
     * active restore lock rather than request input; if the locked job can
     * no longer be resumed (e.g. its state expired), clears the stale lock
     * and reports not running instead of erroring.
     */
    public function site_restore_status()
    {
        $this->check_backup_privilege();
        $this->check_site_restore_enabled();

        $lock = SiteRestoreEngine::get_lock();
        if (! $lock) {
            $this->send_success(array('running' => false));
            return;
        }

        try {
            $engine = new SiteRestoreEngine((string) $lock['job_id']);
            $this->send_success(array(
                'running' => true,
                'job_id' => (string) $lock['job_id'],
                'archive' => (string) ($lock['archive'] ?? ''),
                'started_at' => (int) ($lock['started_at'] ?? 0),
                'progress' => $engine->current_progress(),
            ));
        } catch (\Throwable $e) {
            SiteRestoreEngine::clear_lock();
            $this->send_success(array('running' => false));
        }
    }

    private function check_site_restore_enabled(): void
    {
        if (! (bool) ANIBAS_FM_ENABLE_SITE_RESTORE) {
            $this->send_error(array(
                'error' => 'SiteRestoreDisabled',
                'message' => esc_html__('Site restore is disabled. Add ANIBAS_FM_ENABLE_SITE_RESTORE to wp-config.php to enable it.', 'anibas-file-manager'),
            ), 403);
        }
    }

    private function resolve_site_backup_path(string $name, bool $require_anfm): string
    {
        if (empty($name) || strpos($name, '..') !== false || strpos($name, '/') !== false || strpos($name, '\\') !== false) {
            throw new \RuntimeException(esc_html__('Invalid backup name', 'anibas-file-manager'));
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($require_anfm && $ext !== 'anfm') {
            throw new \RuntimeException(esc_html__('Site restore only accepts ANFM backups.', 'anibas-file-manager'));
        }
        if (! $require_anfm && ! in_array($ext, array('tar', 'anfm'), true)) {
            throw new \RuntimeException(esc_html__('Invalid backup format', 'anibas-file-manager'));
        }

        $backup_dir = anibas_fm_get_backup_dir();
        $full_path  = $backup_dir . '/' . $name;
        $real_dir   = realpath($backup_dir);
        $real_path  = realpath($full_path);

        if (! $real_dir || ! $real_path || strpos($real_path, trailingslashit($real_dir)) !== 0 || ! is_file($real_path)) {
            throw new \RuntimeException(esc_html__('Backup not found', 'anibas-file-manager'));
        }

        return wp_normalize_path($real_path);
    }

    private function assert_site_backup_not_busy(string $real_path): void
    {
        $lock = anibas_fm_get_backup_lock();
        if ($lock && ! empty($lock['output']) && basename((string) $lock['output']) === basename($real_path)) {
            throw new \RuntimeException(esc_html__('Cannot upload a backup that is still being created.', 'anibas-file-manager'));
        }

        $restore_lock = SiteRestoreEngine::get_lock();
        if ($restore_lock && ! empty($restore_lock['archive']) && basename((string) $restore_lock['archive']) === basename($real_path)) {
            throw new \RuntimeException(esc_html__('Cannot upload a backup that is currently being restored.', 'anibas-file-manager'));
        }
    }

    private function send_site_backup_cloud_status(string $job_id): void
    {
        $registered = get_transient($this->site_backup_cloud_job_key($job_id));
        if (! is_array($registered)) {
            $this->send_error(array('error' => esc_html__('Upload job not found', 'anibas-file-manager')));
        }

        $job = BackgroundProcessor::get_job_status($job_id);

        if (! $job) {
            $this->send_error(array('error' => esc_html__('Upload job not found', 'anibas-file-manager')));
        }

        $status = (string) ($job['status'] ?? '');
        if (in_array($status, array('completed', 'failed', 'cancelled'), true)) {
            delete_transient($this->site_backup_cloud_job_key($job_id));
        }

        $this->send_success(array(
            'status' => $status,
            'job_id' => $job_id,
            'done' => in_array($status, array('completed', 'failed', 'cancelled'), true),
            'storage' => $registered['storage'] ?? '',
            'destination' => $registered['destination'] ?? '',
            'folder' => $registered['folder'] ?? '',
            'job' => $job,
        ));
    }

    private function resolve_site_backup_cloud_destination(FileSystemAdapter $adapter, string $destination, string $folder_name): string
    {
        $base_dir = $adapter->validate_path($destination);
        if ($base_dir === false) {
            throw new \RuntimeException(esc_html__('Invalid destination path', 'anibas-file-manager'));
        }

        $dest_dir = $adapter->validate_path($this->join_remote_path($base_dir, $folder_name));
        if ($dest_dir === false) {
            throw new \RuntimeException(esc_html__('Invalid backup destination path', 'anibas-file-manager'));
        }

        if ($adapter->exists($dest_dir)) {
            if (! $adapter->is_dir($dest_dir)) {
                throw new \RuntimeException(esc_html__('A file already exists with the backup folder name.', 'anibas-file-manager'));
            }
            return $dest_dir;
        }

        if (! $adapter->mkdir($dest_dir) || ! $adapter->is_dir($dest_dir)) {
            throw new \RuntimeException(esc_html__('Backup folder is not accessible on the selected cloud storage.', 'anibas-file-manager'));
        }

        return $dest_dir;
    }

    private function join_remote_path(string $base_dir, string $name): string
    {
        $base_dir = trim(str_replace('\\', '/', $base_dir));
        if ($base_dir === '' || $base_dir === '/') {
            return '/' . trim($name, '/');
        }
        return rtrim($base_dir, '/') . '/' . trim($name, '/');
    }

    private function site_backup_cloud_job_key(string $job_id): string
    {
        return 'anibas_fm_site_backup_cloud_job_' . preg_replace('/[^A-Za-z0-9_-]/', '', $job_id);
    }

    /* =========================================================
       SITE BACKUP — start / poll / cancel / status
    ========================================================= */

    /**
     * Start a new site backup.
     *
     * POST params: format (tar|anfm), password (optional, anfm only).
     */
    public function backup_start()
    {
        $this->check_backup_privilege();

        $format   = anibas_fm_fetch_request_variable('post', 'format', 'tar');
        $password = anibas_fm_fetch_request_variable('post', 'password', '');

        try {
            $result = BackupEngine::start($format, $password ?: null);
            $this->send_success($result);
        } catch (\Exception $e) {
            $this->send_error(array('error' => esc_html($e->getMessage())));
        }
    }

    /**
     * Poll / advance an in-progress backup.
     *
     * POST params: job_id, password (optional, anfm only).
     */
    public function backup_poll()
    {
        $this->check_backup_privilege();

        $job_id   = anibas_fm_fetch_request_variable('post', 'job_id', '');
        $password = anibas_fm_fetch_request_variable('post', 'password', '');

        if (empty($job_id)) {
            $this->send_error(array('error' => esc_html__('Missing job_id', 'anibas-file-manager')));
        }

        try {
            $engine = BackupEngine::resume($job_id, $password ?: null);
            $more   = $engine->run_step();

            $this->send_success(array(
                'done'     => ! $more,
                'progress' => $engine->progress(),
            ));
        } catch (\Exception $e) {
            $this->send_error(array('error' => esc_html($e->getMessage())));
        }
    }

    /**
     * Cancel a running backup and clean up temp files.
     *
     * POST params: job_id.
     */
    public function backup_cancel()
    {
        $this->check_backup_privilege();

        $job_id = anibas_fm_fetch_request_variable('post', 'job_id', '');

        if (empty($job_id)) {
            $this->send_error(array('error' => esc_html__('Missing job_id', 'anibas-file-manager')));
        }

        try {
            $engine = BackupEngine::resume($job_id);
            $engine->cancel();
            $this->send_success(array('cancelled' => true));
        } catch (\Exception $e) {
            // Even if resume fails, clear the lock so the user isn't stuck
            anibas_fm_clear_backup_lock();
            $this->send_success(array('cancelled' => true, 'note' => esc_html($e->getMessage())));
        }
    }

    /**
     * Check whether a backup is currently running (lightweight status check).
     */
    public function backup_status()
    {
        $this->check_backup_privilege();

        $lock = anibas_fm_get_backup_lock();

        if (! $lock) {
            $this->send_success(array('running' => false));
            return;
        }

        // Try to get progress from the running job
        $progress = null;
        try {
            $engine   = BackupEngine::resume($lock['job_id']);
            $progress = $engine->progress();
        } catch (\Exception $e) {
            // Job may have expired — clear stale lock
            anibas_fm_clear_backup_lock();
            $this->send_success(array('running' => false));
            return;
        }

        $this->send_success(array(
            'running'    => true,
            'job_id'     => $lock['job_id'],
            'format'     => $lock['format'],
            'output'     => $lock['output'],
            'started_at' => $lock['started_at'],
            'progress'   => $progress,
        ));
    }
}
