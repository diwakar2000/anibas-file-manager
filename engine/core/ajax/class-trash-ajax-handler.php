<?php

namespace Anibas;

if (! defined('ABSPATH')) exit;

/**
 * AJAX endpoints for the trash bin: list, restore and empty.
 * Local-only — remote storages don't have a trash concept.
 */
class TrashAjaxHandler extends AjaxHandler
{
    public function __construct()
    {
        parent::__construct();
        $this->register_actions([
            ANIBAS_FM_LIST_TRASH    => 'list_trash',
            ANIBAS_FM_RESTORE_TRASH => 'restore_trash',
            ANIBAS_FM_EMPTY_TRASH   => 'empty_trash',
        ]);
    }

    public function list_trash()
    {
        $this->check_privilege();

        $trash_dir = anibas_fm_get_trash_dir();

        if (! is_dir($trash_dir)) {
            wp_send_json_success(array('items' => [], 'total_items' => 0));
        }

        // Read from index.json ledger if it exists
        $index_file = $trash_dir . '/index.json';
        $index = [];
        if (file_exists($index_file)) {
            $content = file_get_contents($index_file);
            if ($content) {
                $index = json_decode($content, true) ?: [];
            }
        }

        $items = [];

        // If index exists and has entries, use it (accurate path tracking)
        if (! empty($index)) {
            foreach ($index as $trash_id => $meta) {
                $trash_path = $trash_dir . '/' . $trash_id;
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

        wp_send_json_success(array(
            'items'       => $items,
            'total_items' => count($items),
        ));
    }

    public function restore_trash()
    {
        $this->check_delete_privilege();

        $trash_name = anibas_fm_fetch_request_variable('post', 'trash_name', '');

        if (empty($trash_name)) {
            wp_send_json_error(array('error' => esc_html__('Trash item name required', 'anibas-file-manager')));
        }

        // Prevent path traversal
        if (strpos($trash_name, '..') !== false || strpos($trash_name, '/') !== false || strpos($trash_name, '\\') !== false) {
            wp_send_json_error(array('error' => esc_html__('Invalid trash item name', 'anibas-file-manager')));
        }

        $trash_dir  = anibas_fm_get_trash_dir();
        $trash_path = $trash_dir . '/' . $trash_name;

        if (! file_exists($trash_path)) {
            wp_send_json_error(array('error' => esc_html__('Trash item not found', 'anibas-file-manager')));
        }

        // Look up original path from index.json
        $index_file = $trash_dir . '/index.json';
        $index = [];
        if (file_exists($index_file)) {
            $raw = file_get_contents($index_file);
            if ($raw) $index = json_decode($raw, true) ?: [];
        }

        if (isset($index[$trash_name]['original_path']) && ! empty($index[$trash_name]['original_path'])) {
            // ── Accurate restore: recreate original location ──
            $original_path = $index[$trash_name]['original_path'];
            $restore_path  = rtrim(ABSPATH, '/') . '/' . ltrim($original_path, '/');
            $restore_dir   = dirname($restore_path);

            // Recreate original directory structure if it no longer exists
            if (! is_dir($restore_dir)) {
                wp_mkdir_p($restore_dir);
            }
        } else {
            // ── Legacy fallback: restore to ABSPATH ──
            $parts = explode('_', $trash_name, 2);
            $original_name = isset($parts[1]) ? $parts[1] : $trash_name;
            $restore_path  = rtrim(ABSPATH, '/') . '/' . $original_name;
        }

        // Handle conflict: if a file already exists at the restore path, rename it
        if (file_exists($restore_path)) {
            $pathinfo = pathinfo($restore_path);
            $base     = $pathinfo['filename'];
            $ext      = isset($pathinfo['extension']) ? '.' . $pathinfo['extension'] : '';
            $dir      = $pathinfo['dirname'];
            $counter  = 1;
            do {
                $restore_path = $dir . '/' . $base . '-restored-' . $counter . $ext;
                $counter++;
            } while (file_exists($restore_path));
        }

        $result = @rename($trash_path, $restore_path);

        if ($result) {
            // Remove entry from index
            if (isset($index[$trash_name])) {
                unset($index[$trash_name]);
                file_put_contents($index_file, wp_json_encode($index), LOCK_EX);
            }

            $restored_display = '/' . ltrim(str_replace(wp_normalize_path(ABSPATH), '', wp_normalize_path($restore_path)), '/');
            ActivityLogger::log('restored', basename($restore_path), 'trash');
            wp_send_json_success(array(
                'message'     => esc_html__('Item restored successfully', 'anibas-file-manager'),
                'restored_to' => $restored_display,
            ));
        } else {
            wp_send_json_error(array('error' => esc_html__('Failed to restore item', 'anibas-file-manager')));
        }
    }

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
                wp_send_json_error(array('error' => 'DeletePasswordRequired'));
            }
        }

        $trash_dir = anibas_fm_get_trash_dir();

        if (! is_dir($trash_dir)) {
            wp_send_json_success(array('message' => esc_html__('Trash is already empty', 'anibas-file-manager')));
        }

        if (! function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        \WP_Filesystem();
        global $wp_filesystem;

        $iterator = new \DirectoryIterator($trash_dir);
        $deleted = 0;

        foreach ($iterator as $item) {
            if ($item->isDot()) continue;
            $name = $item->getFilename();
            if ($name === '.htaccess' || $name === 'index.php') continue;

            $wp_filesystem->delete($item->getPathname(), true);
            $deleted++;
        }

        // Always wipe the index — all items are gone
        $index_file = $trash_dir . '/index.json';
        if (file_exists($index_file)) {
            wp_delete_file($index_file);
        }

        ActivityLogger::log('emptied', $deleted . ' items', 'Trash');
        wp_send_json_success(array('message' => esc_html__('Trash emptied', 'anibas-file-manager'), 'deleted' => $deleted));
    }
}
