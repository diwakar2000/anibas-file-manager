<?php

/**
 * AJAX handler exposing local-storage trash bin endpoints (list, restore,
 * empty).
 *
 * @package Anibas_File_Manager
 */

namespace Anibas;

if (! defined('ABSPATH')) exit;

/**
 * AJAX endpoints for the trash bin: list, restore and empty.
 * Local-only — remote storages don't have a trash concept.
 */
class TrashAjaxHandler extends AjaxHandler
{
    /**
     * Register the trash list/restore/empty/delete-item AJAX actions.
     */
    public function __construct()
    {
        parent::__construct();
        $this->register_actions([
            ANIBAS_FM_LIST_TRASH    => 'list_trash',
            ANIBAS_FM_RESTORE_TRASH => 'restore_trash',
            ANIBAS_FM_EMPTY_TRASH   => 'empty_trash',
            'anibas_fm_delete_trash_item' => 'delete_trash_item',
        ]);
    }

    /**
     * List one page of items currently in the trash, newest first.
     *
     * Requires standard privilege. Prefers the trash/index.json ledger for
     * accurate original-path tracking; when the index is missing or empty,
     * falls back to scanning the trash directory and parsing legacy
     * `{timestamp}_{basename}` entry names (whose original path is unknown).
     */
    public function list_trash()
    {
        $this->check_privilege();

        $trash_dir = anibas_fm_get_trash_dir();
        $page      = max(1, absint(anibas_fm_fetch_request_variable('post', 'page', 1)));
        $page_size = min(100, max(1, absint(anibas_fm_fetch_request_variable('post', 'page_size', 50))));
        $offset    = ($page - 1) * $page_size;

        if (! is_dir($trash_dir)) {
            $this->send_success(array(
                'items'       => [],
                'total_items' => 0,
                'page'        => $page,
                'page_size'   => $page_size,
                'has_more'    => false,
            ));
        }

        // Read from index.json ledger if it exists
        $index_file = $trash_dir . '/index.json';
        $index = [];
        if (file_exists($index_file)) {
            try {
                $content = anibas_fm_read_small_file($index_file);
                if ($content) {
                    $index = json_decode($content, true) ?: [];
                }
            } catch (\Throwable $e) {
                $index = [];
            }
        }

        $items = [];

        // If index exists and has entries, use it (accurate path tracking)
        if (! empty($index)) {
            foreach ($index as $trash_id => $meta) {
                $trash_path = $trash_dir . DIRECTORY_SEPARATOR . $trash_id;
                if (! file_exists($trash_path)) continue; // orphaned index entry

                $items[] = array(
                    'name'          => $meta['basename'],
                    'trash_name'    => $trash_id,
                    'original_path' => $meta['original_path'],
                    'is_folder'     => (bool) ($meta['is_dir'] ?? is_dir($trash_path)),
                    'trashed_at'    => $meta['trashed_at'] ?? 0,
                    'last_modified' => filemtime($trash_path),
                    'filesize'      => $meta['filesize'] ?? (is_dir($trash_path) ? 0 : filesize($trash_path)),
                );
            }
        } else {
            // Fallback: scan filesystem for legacy items (no index.json)
            $iterator = new \DirectoryIterator($trash_dir);
            foreach ($iterator as $fileInfo) {
                if ($fileInfo->isDot()) continue;
                $name = $fileInfo->getFilename();
                if ($name === '.htaccess' || $name === 'index.php' || $name === 'index.json') continue;

                // Legacy format: "{timestamp}_{basename}"
                $parts = explode('_', $name, 2);
                $trashed_at = isset($parts[0]) && is_numeric($parts[0]) ? intval($parts[0]) : 0;
                $original_name = isset($parts[1]) ? $parts[1] : $name;

                if ($trashed_at <= 0) {
                    $trashed_at = $fileInfo->getMTime();
                }

                $items[] = array(
                    'name'          => $original_name,
                    'trash_name'    => $name,
                    'original_path' => null, // unknown for legacy items
                    'is_folder'     => $fileInfo->isDir(),
                    'trashed_at'    => $trashed_at,
                    'last_modified' => $fileInfo->getMTime(),
                    'filesize'      => $fileInfo->isDir() ? 0 : $fileInfo->getSize(),
                );
            }
        }

        // Sort by trashed_at descending (newest first)
        usort($items, function ($a, $b) {
            return $b['trashed_at'] <=> $a['trashed_at'];
        });

        $total_items = count($items);

        $this->send_success(array(
            'items'       => array_slice($items, $offset, $page_size),
            'total_items' => $total_items,
            'page'        => $page,
            'page_size'   => $page_size,
            'has_more'    => ($offset + $page_size) < $total_items,
        ));
    }

    /**
     * Restore a trashed item back to its original location (or, for legacy
     * entries with no recorded original path, to the WordPress root).
     *
     * Requires delete privilege. Rejects trash names containing path
     * traversal sequences and re-confirms containment within the trash
     * directory via realpath(). If the restore target already exists, the
     * restored item is renamed with a `-restored-N` suffix instead of
     * overwriting. The actual move uses anibas_fm_safe_move(), which
     * renames when trash and target share a filesystem or falls back to a
     * chunked background move job otherwise — the response carries a
     * `job_id` in the async case.
     */
    public function restore_trash()
    {
        $this->check_delete_privilege();

        $trash_name = anibas_fm_fetch_request_variable('post', 'trash_name', '');

        if (empty($trash_name)) {
            $this->send_error(array('error' => esc_html__('Trash item name required', 'anibas-file-manager')));
        }

        // Prevent path traversal
        if (strpos($trash_name, '..') !== false || strpos($trash_name, DIRECTORY_SEPARATOR) !== false || strpos($trash_name, '\\') !== false) {
            $this->send_error(array('error' => esc_html__('Invalid trash item name', 'anibas-file-manager')));
        }

        $trash_dir  = anibas_fm_get_trash_dir();
        $trash_path = $trash_dir . DIRECTORY_SEPARATOR . $trash_name;

        // Containment check
        $real_trash = realpath($trash_path);
        $real_dir   = realpath($trash_dir);
        if (! $real_trash || ! $real_dir || strpos($real_trash, rtrim($real_dir, '/\\') . DIRECTORY_SEPARATOR) !== 0) {
            $this->send_error(array('error' => esc_html__('Trash item not found', 'anibas-file-manager')));
        }

        // Look up original path from index.json
        $index_file = $trash_dir . DIRECTORY_SEPARATOR . 'index.json';
        $index = [];
        if (file_exists($index_file)) {
            $fp = @fopen($index_file, 'r');
            if ($fp) {
                if (flock($fp, LOCK_SH)) {
                    $raw = stream_get_contents($fp);
                    if ($raw) $index = json_decode($raw, true) ?: [];
                    flock($fp, LOCK_UN);
                }
                fclose($fp);
            }
        }

        if (isset($index[$trash_name]['original_path']) && ! empty($index[$trash_name]['original_path'])) {
            // ── Accurate restore: recreate original location ──
            $original_path = $index[$trash_name]['original_path'];
            $restore_path  = untrailingslashit(ABSPATH) . DIRECTORY_SEPARATOR . ltrim($original_path, DIRECTORY_SEPARATOR);
            $restore_dir   = dirname($restore_path);

            // Recreate original directory structure if it no longer exists
            if (! is_dir($restore_dir)) {
                wp_mkdir_p($restore_dir);
            }
        } else {
            $parts = explode('_', $trash_name, 3);
            $original_name = $parts[2] ?? ($parts[1] ?? $trash_name);
            $restore_path  = untrailingslashit(ABSPATH) . DIRECTORY_SEPARATOR . $original_name;
        }

        // Handle conflict: if a file already exists at the restore path, rename it
        if (file_exists($restore_path)) {
            $pathinfo = pathinfo($restore_path);
            $base     = $pathinfo['filename'];
            $ext      = isset($pathinfo['extension']) ? '.' . $pathinfo['extension'] : '';
            $dir      = $pathinfo['dirname'];
            $counter  = 1;
            do {
                $restore_path = $dir . DIRECTORY_SEPARATOR . $base . '-restored-' . $counter . $ext;
                $counter++;
            } while (file_exists($restore_path));
        }

        // safe_move: rename when same-FS, enqueue a chunked BackgroundProcessor
        // 'move' job otherwise (typical for Docker bind-mounts where /trash
        // and the restore target live on different filesystems). Returns
        // true on sync success, a job_id string when async, false on failure.
        $result = anibas_fm_safe_move($trash_path, $restore_path);

        if ($result === true) {
            self::remove_trash_index_entry($trash_dir, $trash_name);

            $restored_display = DIRECTORY_SEPARATOR . ltrim(str_replace(wp_normalize_path(ABSPATH), '', wp_normalize_path($restore_path)), DIRECTORY_SEPARATOR);
            ActivityLogger::log('restored', basename($restore_path), 'trash');
            $this->send_success(array(
                'message'     => esc_html__('Item restored successfully', 'anibas-file-manager'),
                'restored_to' => $restored_display,
            ));
        } elseif (is_string($result)) {
            // Detach the index entry up front; the background job will move
            // the actual bytes. The frontend polls $job_id to surface
            // completion.
            self::remove_trash_index_entry($trash_dir, $trash_name);
            $restored_display = DIRECTORY_SEPARATOR . ltrim(str_replace(wp_normalize_path(ABSPATH), '', wp_normalize_path($restore_path)), DIRECTORY_SEPARATOR);
            ActivityLogger::log('restored', basename($restore_path), 'trash');
            $this->send_success(array(
                'message'     => esc_html__('Restoring item in the background…', 'anibas-file-manager'),
                'restored_to' => $restored_display,
                'job_id'      => $result,
            ));
        } else {
            $this->send_error(array('error' => esc_html__('Failed to restore item', 'anibas-file-manager')));
        }
    }

    /**
     * Permanently delete everything in the trash.
     *
     * Requires delete privilege and, if a delete password is configured, a
     * valid delete-auth token. Always runs as a background delete job (via
     * BackgroundProcessor) rather than synchronously, since trash contents
     * can be arbitrarily large; the trash root itself is recreated after
     * deletion so it stays a valid mount point for future trashing.
     */
    public function empty_trash()
    {
        $this->check_delete_privilege();

        $token = anibas_fm_fetch_request_variable('post', 'token', '');

        // Enforce delete password if configured
        $user_id = get_current_user_id();
        $delete_password_hash = anibas_fm_get_option('delete_password_hash', '');
        if (! empty($delete_password_hash)) {
            $stored_token = get_transient('anibas_fm_delete_auth_' . $user_id);
            if (! $token || ! $stored_token || ! hash_equals($stored_token, $token)) {
                $this->send_error(array('error' => 'DeletePasswordRequired'));
            }
        }

        $trash_dir = anibas_fm_get_trash_dir();
        $real_trash_dir = realpath($trash_dir);

        if (! $real_trash_dir || ! is_dir($real_trash_dir)) {
            $this->send_error(array('error' => esc_html__('Trash folder could not be prepared', 'anibas-file-manager')));
        }

        $job_id = BackgroundProcessor::enqueue_delete_job($real_trash_dir, 'local', false, [
            'allow_trash_root'    => true,
            'recreate_trash_root' => true,
        ]);

        if (is_wp_error($job_id)) {
            $this->send_error(array('error' => $job_id->get_error_message()));
        }

        ActivityLogger::log('emptied', 'Trash (background job)', 'Trash');
        $this->send_success(array(
            'message' => esc_html__('Emptying trash in the background…', 'anibas-file-manager'),
            'job_id'  => $job_id,
            'job_ids' => [$job_id],
            'deleted' => 0,
        ));
    }

    /**
     * Permanently delete a single item from the trash.
     *
     * Requires delete privilege, rejects trash names with path traversal
     * sequences, re-confirms containment within the trash directory via
     * realpath() (catches symlink tricks the string check can't), and
     * enforces the delete-password token when one is configured. The index
     * entry is removed up front under an exclusive lock so concurrent trash
     * operations can't race it. Directories are deleted via a background
     * job (a trashed folder can be an arbitrarily large subtree); a single
     * file or symlink is unlinked synchronously.
     */
    public function delete_trash_item()
    {
        $this->check_delete_privilege();

        $trash_name = anibas_fm_fetch_request_variable('post', 'trash_name', '');
        $token      = anibas_fm_fetch_request_variable('post', 'token', '');

        if (empty($trash_name)) {
            $this->send_error(array('error' => esc_html__('Trash item name required', 'anibas-file-manager')));
        }

        // Fast-fail on obvious traversal attempts.
        if (strpos($trash_name, '..') !== false || strpos($trash_name, '/') !== false || strpos($trash_name, '\\') !== false) {
            $this->send_error(array('error' => esc_html__('Invalid trash item name', 'anibas-file-manager')));
        }

        // Enforce the delete-password token when configured — matches
        // empty_trash() / empty_folder() so one-off deletes aren't a
        // backdoor around the same protection.
        $user_id = get_current_user_id();
        $delete_password_hash = anibas_fm_get_option('delete_password_hash', '');
        if (! empty($delete_password_hash)) {
            $stored_token = get_transient('anibas_fm_delete_auth_' . $user_id);
            if (! $token || ! $stored_token || ! hash_equals($stored_token, $token)) {
                $this->send_error(array('error' => 'DeletePasswordRequired'));
            }
        }

        $trash_dir  = anibas_fm_get_trash_dir();
        $trash_path = $trash_dir . '/' . $trash_name;

        // Containment: the resolved real path must still live under trash_dir.
        // Catches cases the string check above can't (symlinks pointing outside,
        // directory-canonicalization edge cases on case-insensitive filesystems).
        $real_trash = realpath($trash_path);
        $real_dir   = realpath($trash_dir);
        if (! $real_trash || ! $real_dir || strpos($real_trash, rtrim($real_dir, '/\\') . DIRECTORY_SEPARATOR) !== 0) {
            $this->send_error(array('error' => esc_html__('Trash item not found', 'anibas-file-manager')));
        }

        // Detach the index entry up-front under an exclusive file lock so a
        // concurrent restore/delete/moveToTrash can't stomp our write window.
        // Even if the actual delete below fails or is deferred to a background
        // job, the ledger reflects user intent immediately.
        self::remove_trash_index_entry($trash_dir, $trash_name);

        // Directories: hand off to BackgroundProcessor so deleting a deep tree
        // stays chunked. A trash entry is a single atomic rename on the way in,
        // but can represent an arbitrarily large subtree on the way out — a
        // sync recursive delete here would time out on big folders.
        if (is_dir($real_trash) && ! is_link($real_trash)) {
            $job_id = BackgroundProcessor::enqueue_delete_job($real_trash, 'local', false, ['allow_trash_root' => true]);
            if (is_wp_error($job_id)) {
                $this->send_error(array('error' => $job_id->get_error_message()));
            }
            ActivityLogger::log('deleted', $trash_name, 'trash');
            $this->send_success(array(
                'job_id'  => $job_id,
                'message' => esc_html__('Deleting trash item in the background…', 'anibas-file-manager'),
            ));
        }

        // Single file (or symlink): one syscall, no background job needed.
        if (@unlink($real_trash)) {
            ActivityLogger::log('deleted', $trash_name, 'trash');
            $this->send_success(array(
                'message' => esc_html__('Item permanently deleted', 'anibas-file-manager'),
            ));
        }

        $this->send_error(array('error' => esc_html__('Failed to delete item', 'anibas-file-manager')));
    }

    /**
     * Remove a single entry from trash/index.json atomically.
     *
     * Uses fopen('c+') + flock(LOCK_EX) so the whole read-modify-write window
     * is serialized — fixes the race the previous inline code had against
     * concurrent trash operations (moveToTrash, restore, delete). No-ops
     * when the index file doesn't exist or the entry isn't present.
     */
    private static function remove_trash_index_entry(string $trash_dir, string $trash_name): void
    {
        $index_file = $trash_dir . DIRECTORY_SEPARATOR . 'index.json';
        if (! file_exists($index_file)) {
            return;
        }
        $fp = @fopen($index_file, 'c+');
        if (! $fp) {
            return;
        }
        if (flock($fp, LOCK_EX)) {
            $raw   = stream_get_contents($fp);
            $index = $raw ? (json_decode($raw, true) ?: []) : [];
            if (isset($index[$trash_name])) {
                unset($index[$trash_name]);
                rewind($fp);
                ftruncate($fp, 0);
                fwrite($fp, wp_json_encode($index));
                fflush($fp);
            }
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }
}
