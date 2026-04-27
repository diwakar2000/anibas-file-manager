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

        if (empty($path)) {
            $this->send_error(array('error' => esc_html__('Path required', 'anibas-file-manager')));
        }

        if ($storage === 'local') {
            $full_path = $this->validate_path($path);
            if (! $full_path || ! is_file($full_path)) {
                $this->send_error(array('error' => esc_html__('File not found', 'anibas-file-manager')));
            }
            $dest = anibas_fm_prepare_file_backup_target('local', $full_path);
            if (! $dest || ! @copy($full_path, $dest)) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
                $this->send_error(array('error' => esc_html__('Backup failed', 'anibas-file-manager')));
            }
            $this->send_success(array('message' => esc_html__('File backed up', 'anibas-file-manager')));
        }

        $adapter = StorageManager::get_instance()->get_adapter($storage);
        if (! $adapter) {
            $this->send_error(array('error' => esc_html__('Invalid storage', 'anibas-file-manager')));
        }
        $full_path = $adapter->validate_path($path);
        if (! $full_path || ! $adapter->is_file($full_path)) {
            $this->send_error(array('error' => esc_html__('File not found', 'anibas-file-manager')));
        }
        $content = $adapter->get_contents($full_path);
        if ($content === false) {
            $this->send_error(array('error' => esc_html__('Failed to read remote file', 'anibas-file-manager')));
        }
        $dest = anibas_fm_prepare_file_backup_target($storage, $full_path);
        if (! $dest || @file_put_contents($dest, $content) === false) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents
            $this->send_error(array('error' => esc_html__('Backup failed', 'anibas-file-manager')));
        }
        $this->send_success(array('message' => esc_html__('File backed up', 'anibas-file-manager')));
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

        // Conflict handling: if target exists, rename existing file to -old-N suffix.
        // Sibling names are pre-fetched once so the candidate-name search is an in-memory
        // lookup — avoids a per-iteration remote existence call (which on S3 = 2 requests).
        $rename_existing = function (string $path, array $sibling_names, callable $rename): ?string {
            $target_name = basename($path);
            if (! in_array($target_name, $sibling_names, true)) return null; // No conflict
            $info = pathinfo($path);
            $base = $info['filename'];
            $ext  = isset($info['extension']) ? '.' . $info['extension'] : '';
            $dir  = $info['dirname'];
            $n    = 1;
            $max  = 1000; // Safety bound in case the sibling list is incomplete
            do {
                $candidate_name = $base . '-old-' . $n . $ext;
                $candidate      = $dir . '/' . $candidate_name;
                $n++;
            } while (in_array($candidate_name, $sibling_names, true) && $n <= $max);
            if (in_array($candidate_name, $sibling_names, true)) return null;
            return $rename($path, $candidate) ? $candidate : null;
        };

        if ($storage === 'local') {
            $target = $this->validate_local_restore_target($target);
            if (! $target) {
                $this->send_error(array('error' => esc_html__('Target path is outside the site', 'anibas-file-manager')));
            }
            $restore_dir = dirname($target);
            if (! is_dir($restore_dir)) {
                wp_mkdir_p($restore_dir);
            }

            // If target exists, rename existing file to -old-N suffix
            $sibling_names = is_dir($restore_dir)
                ? array_values(array_diff(@scandir($restore_dir) ?: [], array('.', '..')))
                : array();
            $renamed_path = $rename_existing($target, $sibling_names, function ($from, $to) {
                return @rename($from, $to);
            });

            // Restore backup to original target path
            if (! @copy($backup, $target)) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
                // Attempt to restore original file if rename happened
                if ($renamed_path) {
                    @rename($renamed_path, $target);
                }
                $this->send_error(array('error' => esc_html__('Failed to restore backup', 'anibas-file-manager')));
            }
            $display = '/' . ltrim(str_replace(wp_normalize_path(ABSPATH), '', wp_normalize_path($target)), '/');
            ActivityLogger::log('restored_file_backup', basename($target), 'file-backup');
            $this->send_success(array(
                'message'     => esc_html__('Backup restored', 'anibas-file-manager'),
                'restored_to' => $display,
                'renamed_existing' => $renamed_path ? basename($renamed_path) : null,
            ));
        }

        $adapter = StorageManager::get_instance()->get_adapter($storage);
        if (! $adapter) {
            $this->send_error(array('error' => esc_html__('Invalid storage', 'anibas-file-manager')));
        }

        // If target exists on remote, rename existing file to -old-N suffix.
        // Fetch the target's sibling names in one remote call (scandir) and reuse
        // the list for conflict checks, instead of calling is_file() per candidate.
        $remote_dir = rtrim(dirname($target), '/');
        try {
            $sibling_names = $adapter->listDirectory($remote_dir)['items'] ?? [];
            if (! is_array($sibling_names)) $sibling_names = array();
        } catch (\Exception) {
            $sibling_names = array();
        }
        $renamed_path = $rename_existing($target, $sibling_names, function ($from, $to) use ($adapter) {
            return $adapter->move($from, $to);
        });

        // Restore backup to original target path
        $content = @file_get_contents($backup);
        if ($content === false || ! $adapter->put_contents($target, $content)) {
            // Attempt to restore original file if rename happened
            if ($renamed_path) {
                $adapter->move($renamed_path, $target);
            }
            $this->send_error(array('error' => esc_html__('Failed to restore backup to remote storage', 'anibas-file-manager')));
        }
        ActivityLogger::log('restored_file_backup', basename($target), 'file-backup');
        $this->send_success(array(
            'message'     => esc_html__('Backup restored', 'anibas-file-manager'),
            'restored_to' => $target,
            'storage'     => $storage,
            'renamed_existing' => $renamed_path ? basename($renamed_path) : null,
        ));
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

        if (! anibas_fm_recursive_rmdir($src_dir)) {
            $this->send_error(array('error' => esc_html__('Failed to delete backup history', 'anibas-file-manager')));
        }

        ActivityLogger::log('deleted_file_backup_tree', $key, 'file-backup');
        $this->send_success(array('message' => esc_html__('Backup history deleted', 'anibas-file-manager')));
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
