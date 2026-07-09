<?php

namespace Anibas;

if ( ! defined( 'ABSPATH' ) ) exit;


/**
 * AJAX handlers for the file editor.
 *
 * generate_editor_token — called from the file manager UI; creates a one-time token.
 * get_file_chunk        — reads a byte range of a file (chunked streaming read).
 * save_file             — writes content back to the file via the appropriate adapter.
 */
class EditorAjax extends AjaxHandler
{

    public function __construct()
    {
        parent::__construct();

        $actions = [
            ANIBAS_FM_INIT_EDITOR_SESSION   => 'init_editor_session',
            ANIBAS_FM_GET_FILE_CHUNK        => 'get_file_chunk',
            ANIBAS_FM_SAVE_FILE             => 'save_file',
        ];

        foreach ($actions as $action => $method) {
            add_action('wp_ajax_' . $action, [$this, $method]);
        }
    }



    public function init_editor_session(): void
    {
        $this->check_editor_privilege();

        $path     = sanitize_text_field(anibas_fm_fetch_request_variable('post', 'path', ''));
        $storage  = sanitize_text_field(anibas_fm_fetch_request_variable('post', 'storage', 'local'));
        $can_edit = (bool) anibas_fm_fetch_request_variable('post', 'can_edit', false);

        if (empty($path)) {
            $this->send_error(['error' => esc_html__('Path required', 'anibas-file-manager')]);
        }

        if (! EditorPage::is_editable_file($path)) {
            $this->send_error(['error' => 'UnsupportedFileType', 'message' => esc_html__('This file type cannot be opened in the editor.', 'anibas-file-manager')]);
        }

        $user_id     = get_current_user_id();
        $session_key = EditorPage::session_key($user_id, $path, $storage);
        set_transient($session_key, ['can_edit' => $can_edit], ANIBAS_FM_EDITOR_SESSION_TTL);

        $this->send_success(['session' => 'created']);
    }

    // ── Chunked read ─────────────────────────────────────────────────────────

    // Every base64_encode() call below transports a raw binary file chunk to the
    // editor over JSON (which cannot carry arbitrary binary safely); none of it is
    // logic obfuscation.
    public function get_file_chunk(): void
    {
        $this->check_editor_privilege();

        $path    = sanitize_text_field(anibas_fm_fetch_request_variable('post', 'path', ''));
        $storage = sanitize_text_field(anibas_fm_fetch_request_variable('post', 'storage', 'local'));
        $offset  = max(0, (int) anibas_fm_fetch_request_variable('post', 'offset', 0));

        if (empty($path)) {
            $this->send_error(['error' => esc_html__('Path required', 'anibas-file-manager')]);
        }

        $user_id     = get_current_user_id();
        $session_key = EditorPage::session_key($user_id, $path, $storage);
        $session     = get_transient($session_key);

        if (! $session) {
            $this->send_error(['error' => 'SessionExpired', 'message' => esc_html__('Editor session expired. Please close and reopen the file.', 'anibas-file-manager')], 403);
        }

        $chunk_size = ANIBAS_FM_EDITOR_CHUNK_BYTES;

        if ($storage === 'local') {
            $full_path = $this->validate_local_path($path);
            if (! $full_path || ! is_file($full_path)) {
                $this->send_error(['error' => 'NotFound']);
            }

            $file_size = filesize($full_path);
            if ($file_size > ANIBAS_FM_EDITOR_MAX_BYTES) {
                $this->send_error(['error' => 'FileTooLarge', 'message' => esc_html__('File exceeds the 10 MB editor limit.', 'anibas-file-manager')]);
            }

            $handle = @fopen($full_path, 'rb');
            if (! $handle || fseek($handle, $offset) !== 0) {
                if ($handle) {
                    fclose($handle);
                }
                $this->send_error(['error' => 'ReadFailed']);
            }
            $chunk = fread($handle, $chunk_size);
            fclose($handle);
            if ($chunk === false) {
                $this->send_error(['error' => 'ReadFailed']);
            }

            $this->send_success([
                'chunk'     => base64_encode($chunk), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
                'offset'    => $offset,
                'length'    => strlen($chunk),
                'file_size' => $file_size,
                'done'      => ($offset + strlen($chunk)) >= $file_size,
            ]);
        }

        // Remote storage
        $adapter = StorageManager::get_instance()->get_adapter($storage);
        if (! $adapter) {
            $this->send_error(['error' => 'InvalidStorage']);
        }

        $full_path = $adapter->validate_path($path);
        if (! $full_path || ! $adapter->is_file($full_path)) {
            $this->send_error(['error' => 'NotFound']);
        }

        $file_size = method_exists($adapter, 'get_file_size') ? $adapter->get_file_size($full_path) : false;

        if ($file_size !== false && $file_size > ANIBAS_FM_EDITOR_MAX_BYTES) {
            $this->send_error(['error' => 'FileTooLarge', 'message' => esc_html__('File exceeds the 10 MB editor limit.', 'anibas-file-manager')]);
        }

        // FTP has no range-read support — fetch whole file, check size
        if ($storage === 'ftp') {
            if ($offset > 0) {
                // Chunks beyond first are not supported for FTP — should not happen
                // since front-end will see done=true on first chunk for small files
                $this->send_error(['error' => 'UnsupportedOperation', 'message' => esc_html__('FTP does not support partial reads.', 'anibas-file-manager')]);
            }
            $content = $adapter->get_contents($full_path);
            if ($content === false) {
                $this->send_error(['error' => 'ReadFailed']);
            }
            if (strlen($content) > ANIBAS_FM_EDITOR_MAX_BYTES) {
                $this->send_error(['error' => 'FileTooLarge', 'message' => esc_html__('File exceeds the 10 MB editor limit.', 'anibas-file-manager')]);
            }
            $this->send_success([
                'chunk'     => base64_encode($content), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
                'offset'    => 0,
                'length'    => strlen($content),
                'file_size' => strlen($content),
                'done'      => true,
            ]);
        }

        if (method_exists($adapter, 'read_chunk')) {
            $chunk = $adapter->read_chunk($full_path, $offset, $chunk_size);
            if ($chunk === false) {
                $this->send_error(['error' => 'ReadFailed']);
            }
            $done = $file_size !== false
                ? ($offset + strlen($chunk)) >= $file_size
                : strlen($chunk) < $chunk_size;
            $this->send_success([
                'chunk'     => base64_encode($chunk), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
                'offset'    => $offset,
                'length'    => strlen($chunk),
                'file_size' => $file_size !== false ? $file_size : -1,
                'done'      => $done,
            ]);
        }

        // S3 / S3-compatible — use get_contents (full fetch, size already gated above)
        $content = $adapter->get_contents($full_path);
        if ($content === false) {
            $this->send_error(['error' => 'ReadFailed']);
        }

        $total = strlen($content);
        $chunk = substr($content, $offset, $chunk_size);

        $this->send_success([
            'chunk'     => base64_encode($chunk), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
            'offset'    => $offset,
            'length'    => strlen($chunk),
            'file_size' => $total,
            'done'      => ($offset + strlen($chunk)) >= $total,
        ]);
    }

    // ── Save ─────────────────────────────────────────────────────────────────

    public function save_file(): void
    {
        $this->check_editor_privilege();

        if (anibas_fm_is_backup_running()) {
            $this->send_error(['error' => 'BackupInProgress', 'message' => esc_html__('A site backup is in progress. Please wait until it completes.', 'anibas-file-manager')], 423);
        }

        $path    = sanitize_text_field(anibas_fm_fetch_request_variable('post', 'path', ''));
        $storage = sanitize_text_field(anibas_fm_fetch_request_variable('post', 'storage', 'local'));
        // Content arrives base64-encoded to safely transport arbitrary text
        $content_b64 = anibas_fm_fetch_request_variable('post', 'content', '');

        if (empty($path) || $content_b64 === '') {
            $this->send_error(['error' => 'MissingParams']);
        }

        if (! EditorPage::is_editable_file($path)) {
            $this->send_error(['error' => 'UnsupportedFileType', 'message' => esc_html__('This file type cannot be saved through the editor.', 'anibas-file-manager')], 403);
        }

        $user_id     = get_current_user_id();
        $session_key = EditorPage::session_key($user_id, $path, $storage);
        $session     = get_transient($session_key);

        if (! $session) {
            $this->send_error(['error' => 'SessionExpired', 'message' => esc_html__('Editor session expired. Please close and reopen the file.', 'anibas-file-manager')], 403);
        }

        if (empty($session['can_edit'])) {
            $this->send_error(['error' => 'ReadOnly', 'message' => esc_html__('You do not have permission to edit this file.', 'anibas-file-manager')], 403);
        }

        $content = base64_decode($content_b64, true);
        if ($content === false) {
            $this->send_error(['error' => 'InvalidContent']);
        }

        if (strlen($content) > ANIBAS_FM_EDITOR_MAX_BYTES) {
            $this->send_error(['error' => 'FileTooLarge', 'message' => esc_html__('Content exceeds the 10 MB save limit.', 'anibas-file-manager')]);
        }

        if ($storage === 'local') {
            $full_path = $this->validate_local_path($path);
            if (! $full_path) {
                $this->send_error(['error' => 'PathInvalid']);
            }

            $this->backup_local_file_before_save($full_path);

            if (! function_exists('WP_Filesystem')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }

            WP_Filesystem();

            global $wp_filesystem;

            $result = $wp_filesystem->put_contents($full_path, $content);

            if ($result === false) {
                $this->send_error(['error' => 'WriteFailed', 'message' => esc_html__('Failed to write file.', 'anibas-file-manager')]);
            }
            $this->send_success(['message' => esc_html__('Saved successfully.', 'anibas-file-manager')]);
        }

        $adapter = StorageManager::get_instance()->get_adapter($storage);
        if (! $adapter) {
            $this->send_error(['error' => 'InvalidStorage']);
        }

        $full_path = $adapter->validate_path($path);
        if (! $full_path) {
            $this->send_error(['error' => 'PathInvalid']);
        }

        if (anibas_fm_get_option('remote_file_backups_enabled', false)) {
            $this->backup_remote_file_before_save($adapter, $storage, $full_path);
        }

        $result = $adapter->put_contents($full_path, $content);
        if (! $result) {
            $this->send_error(['error' => 'WriteFailed', 'message' => esc_html__('Failed to write to remote storage.', 'anibas-file-manager')]);
        }

        $this->send_success(['message' => esc_html__('Saved successfully.', 'anibas-file-manager')]);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function check_editor_privilege(): void
    {
        check_ajax_referer(ANIBAS_FM_NONCE_EDITOR, 'nonce');

        if (! current_user_can('manage_options')) {
            $this->send_error(['error' => 'Forbidden'], 403);
        }

        $this->check_fm_token();
    }

    private function validate_local_path(string $path): string|false
    {
        $adapter = StorageManager::get_instance()->get_adapter('local');
        if (! $adapter) {
            return false;
        }
        $full = $adapter->validate_path($path);
        return $full ?: false;
    }

    private function backup_local_file_before_save(string $full_path): void
    {
        if (! file_exists($full_path) || ! is_file($full_path)) {
            return;
        }
        if (anibas_fm_has_recent_file_backup('local', $full_path)) {
            return;
        }

        try {
            $dest = $this->prepare_file_backup_target('local', $full_path);
            if ($dest) {
                copy($full_path, $dest); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
            }
        } catch (\Throwable $e) {
            // Best-effort — don't block the save if backup fails
        }
    }

    private function backup_remote_file_before_save($adapter, string $storage, string $full_path): void
    {
        if (anibas_fm_has_recent_file_backup($storage, $full_path)) {
            return;
        }
        try {
            if (! $adapter->is_file($full_path)) {
                return;
            }
            $content = $adapter->get_contents($full_path);
            if ($content === false) {
                return;
            }
            $dest = $this->prepare_file_backup_target($storage, $full_path);
            if ($dest) {
                file_put_contents($dest, $content); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents
            }
        } catch (\Throwable $e) {
            // Best-effort — don't block the save if backup fails
        }
    }

    private function prepare_file_backup_target(string $storage, string $source_path): ?string
    {
        return anibas_fm_prepare_file_backup_target($storage, $source_path);
    }
}
