<?php

namespace Anibas;

if (! defined('ABSPATH')) exit;

/**
 * AJAX endpoints for both per-file backups (snapshot-before-edit, restore)
 * and full-site backups (start / poll / cancel / status).
 */
class BackupAjaxHandler extends AjaxHandler
{
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
            ANIBAS_FM_DELETE_SITE_BACKUP  => 'delete_site_backup',
            ANIBAS_FM_BACKUP_START        => 'backup_start',
            ANIBAS_FM_BACKUP_POLL         => 'backup_poll',
            ANIBAS_FM_BACKUP_CANCEL       => 'backup_cancel',
            ANIBAS_FM_BACKUP_STATUS       => 'backup_status',
        ]);
    }

    // ── Per-file backups (snapshot-before-edit / restore like trash) ──

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

                $raw     = (string) @file_get_contents($marker);
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

        $raw     = (string) @file_get_contents($marker);
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
        if ($chunk_size < ANIBAS_FM_CHUNK_SIZE_MIN) $chunk_size = ANIBAS_FM_CHUNK_SIZE_MIN;
        if ($chunk_size > ANIBAS_FM_CHUNK_SIZE_MAX) $chunk_size = ANIBAS_FM_CHUNK_SIZE_MAX;
        return $chunk_size;
    }

    private function file_backup_time_budget(): int
    {
        $max_time = (int) ini_get('max_execution_time');
        return $max_time > 0 ? max(1, (int) floor($max_time * 0.6)) : 20;
    }

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
                );
            }
        }

        usort($items, function ($a, $b) { return $b['mtime'] <=> $a['mtime']; });

        $this->send_success(array('items' => $items, 'total_items' => count($items)));
    }

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

        if (! @unlink($real_path)) {
            $this->send_error(array('error' => esc_html__('Failed to delete site backup', 'anibas-file-manager')));
        }

        ActivityLogger::log('deleted_site_backup', basename($real_path), 'site-backup');
        $this->send_success(array('message' => esc_html__('Backup deleted', 'anibas-file-manager')));
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
