<?php

namespace Anibas;

if (! defined('ABSPATH')) exit;

/**
 * Base AJAX handler. Holds shared infrastructure used by every domain handler:
 * privilege/nonce checks, path validation, storage adapter lookup, and the
 * action-registration helper. Child handlers extend this class and call
 * register_actions() with their own action map from their constructor.
 */
class AjaxHandler
{
    protected string|false $root_path;

    public function __construct()
    {
        $this->root_path = realpath(ABSPATH);
    }

    /**
     * Wire the given [action => method] map into wp_ajax_*. Each child handler
     * calls this once from its own constructor with its slice of actions.
     */
    protected function register_actions(array $actions)
    {
        foreach ($actions as $action => $method) {
            add_action('wp_ajax_' . $action, [$this, $method]);
        }
    }

    protected function send_json_and_exit($response = null, string $type = 'success', ?int $status_code = null, array $cleanup_transients = []): void
    {
        foreach ($cleanup_transients as $transient) {
            if (is_string($transient) && $transient !== '') {
                delete_transient($transient);
            }
        }

        if ($type === 'error') {
            wp_send_json_error($response, $status_code);
            return;
        }

        wp_send_json_success($response, $status_code);
    }

    protected function send_success($response = null, ?int $status_code = null, array $cleanup_transients = []): void
    {
        $this->send_json_and_exit($response, 'success', $status_code, $cleanup_transients);
    }

    protected function send_error($response = null, ?int $status_code = null, array $cleanup_transients = []): void
    {
        $this->send_json_and_exit($response, 'error', $status_code, $cleanup_transients);
    }

    protected function send_wp_error(\WP_Error $error, ?int $status_code = null, array $cleanup_transients = []): void
    {
        $this->send_error([
            'error'   => $error->get_error_code(),
            'message' => $error->get_error_message(),
        ], $status_code, $cleanup_transients);
    }

    /* =========================================================
       PRIVILEGE / NONCE / TOKEN CHECKS
    ========================================================= */

    protected function check_admin_privilege()
    {
        if (! current_user_can('manage_options')) {
            $this->send_error(esc_html__('Unauthorized', 'anibas-file-manager'), 403);
        }
    }

    protected function check_nonce($nonce = '')
    {
        if (! wp_verify_nonce(anibas_fm_fetch_request_variable('request', 'nonce'), $nonce)) {
            $this->send_error(esc_html__('Invalid nonce.', 'anibas-file-manager'), 401);
        }
    }

    protected function check_privilege()
    {
        $this->check_fm_token();
        $this->check_nonce(ANIBAS_FM_NONCE_LIST);
        $this->check_admin_privilege();
    }

    protected function check_create_privilege()
    {
        $this->check_fm_token();
        $this->check_nonce(ANIBAS_FM_NONCE_CREATE);
        $this->check_admin_privilege();
        $this->block_during_backup();
    }

    protected function check_delete_privilege()
    {
        $this->check_fm_token();
        $this->check_nonce(ANIBAS_FM_NONCE_DELETE);
        $this->check_admin_privilege();
        $this->block_during_backup();
    }

    /**
     * Block destructive file operations while a site backup is in progress.
     * Read-only operations (list, check, status) are not blocked.
     */
    protected function block_during_backup(): void
    {
        // Allow backup's own AJAX endpoints to pass through
        $action = anibas_fm_fetch_request_variable('request', 'action', '');
        $backup_actions = array(
            ANIBAS_FM_BACKUP_START,
            ANIBAS_FM_BACKUP_POLL,
            ANIBAS_FM_BACKUP_CANCEL,
            ANIBAS_FM_BACKUP_STATUS,
        );

        if (in_array($action, $backup_actions, true)) {
            return;
        }

        if (anibas_fm_is_backup_running()) {
            $this->send_error(
                array('error' => 'BackupInProgress', 'message' => esc_html__('A site backup is in progress. Please wait until it completes.', 'anibas-file-manager')),
                423 // HTTP 423 Locked
            );
        }
    }

    protected function check_backup_privilege()
    {
        $nonce = anibas_fm_fetch_request_variable('request', 'nonce');

        if (wp_verify_nonce($nonce, ANIBAS_FM_NONCE_SETTINGS)) {
            $this->check_admin_privilege();
            $this->check_settings_auth();
            return;
        }

        $this->check_nonce(ANIBAS_FM_NONCE_CREATE);
        $this->check_admin_privilege();
        $this->check_fm_token();
    }

    protected function check_database_view_privilege(): void
    {
        $this->check_database_view_enabled();

        $this->check_nonce(ANIBAS_FM_NONCE_DATABASE);
        $this->check_admin_privilege();
        $this->check_fm_token();
        $this->check_database_token();
    }

    protected function check_database_edit_privilege(): void
    {
        $this->check_database_view_privilege();

        if (! anibas_fm_database_edit_constant_enabled()) {
            $this->send_error(array(
                'error' => 'DatabaseEditDisabled',
                'message' => esc_html__('Database editing is disabled. Add ANIBAS_FM_ENABLE_DATABASE_EDIT to wp-config.php to allow it.', 'anibas-file-manager'),
            ), 403);
        }

        if (! anibas_fm_database_edit_enabled()) {
            $this->send_error(array(
                'error' => 'DatabaseEditDisabled',
                'message' => esc_html__('Database editing is disabled in settings.', 'anibas-file-manager'),
            ), 403);
        }
    }

    protected function check_database_view_enabled(): void
    {
        if (! anibas_fm_database_view_constant_enabled()) {
            $this->send_error(array(
                'error' => 'DatabaseViewDisabled',
                'message' => esc_html__('Database browsing is disabled. Add ANIBAS_FM_ENABLE_DATABASE_VIEW to wp-config.php to allow it.', 'anibas-file-manager'),
            ), 403);
        }

        if (! anibas_fm_database_view_enabled()) {
            $this->send_error(array(
                'error' => 'DatabaseViewDisabled',
                'message' => esc_html__('Database browsing is disabled in settings.', 'anibas-file-manager'),
            ), 403);
        }
    }

    protected function check_database_token(): void
    {
        if (! anibas_fm_database_password_required()) {
            return;
        }

        if (! anibas_fm_fetch_request_variable('request', 'db_token', '')) {
            $this->send_error(array('error' => 'DBTokenRequired', 'message' => esc_html__('Database password required', 'anibas-file-manager')), 401);
        }

        if (! $this->has_valid_database_token()) {
            $this->send_error(array('error' => 'DBTokenRequired', 'message' => esc_html__('Database session expired. Please re-enter the database password.', 'anibas-file-manager')), 401);
        }
    }

    protected function has_valid_database_token(): bool
    {
        if (! anibas_fm_database_password_required()) {
            return true;
        }

        $user_id     = get_current_user_id();
        $raw_token   = anibas_fm_fetch_request_variable('request', 'db_token', '');
        $stored_hash = get_transient('anibas_fm_db_token_' . $user_id);
        $password_hash = anibas_fm_get_option('database_password_hash', '');
        $expected_hash = $raw_token && $password_hash ? hash('sha256', $raw_token . '|' . $password_hash) : '';

        return $raw_token && $stored_hash && $expected_hash && hash_equals($stored_hash, $expected_hash);
    }

    protected function check_save_settings_privilege()
    {
        $this->check_nonce(ANIBAS_FM_NONCE_SETTINGS);
        $this->check_admin_privilege();
    }

    protected function check_settings_auth(): void
    {
        if (! $this->has_valid_settings_auth()) {
            $this->send_error(esc_html__('Invalid authentication', 'anibas-file-manager'), 401);
        }
    }

    protected function has_valid_settings_auth(): bool
    {
        $settings_hash = anibas_fm_get_option('settings_password_hash', '');
        if (empty($settings_hash)) {
            return true;
        }

        $user_id      = get_current_user_id();
        $token        = anibas_fm_fetch_request_variable('request', 'token', '');
        $stored_token = get_transient('anibas_fm_auth_' . $user_id);

        return $token && is_string($stored_token) && hash_equals($stored_token, $token);
    }

    /**
     * Verify the file manager session token on every FM request.
     * Only enforced when a FM password has been configured.
     * On failure, returns FMTokenRequired so the frontend can re-show the gate.
     */
    protected function check_fm_token(): void
    {
        if (! $this->fm_password_is_configured()) {
            return;
        }

        if (! anibas_fm_fetch_request_variable('request', 'fm_token', '')) {
            $this->send_error(array('error' => 'FMTokenRequired', 'message' => esc_html__('File manager authentication required', 'anibas-file-manager')), 401);
        }

        if (! $this->has_valid_fm_token()) {
            $this->send_error(array('error' => 'FMTokenRequired', 'message' => esc_html__('File manager session expired. Please re-enter your password.', 'anibas-file-manager')), 401);
        }
    }

    protected function fm_password_is_configured(): bool
    {
        return ! empty(anibas_fm_get_option('fm_password_hash', ''));
    }

    protected function has_valid_fm_token(): bool
    {
        if (! $this->fm_password_is_configured()) {
            return true;
        }

        $user_id     = get_current_user_id();
        $raw_token   = anibas_fm_fetch_request_variable('request', 'fm_token', '');
        $stored_hash = get_transient('anibas_fm_fm_token_' . $user_id);

        return $raw_token && $stored_hash && hash_equals($stored_hash, hash('sha256', $raw_token));
    }

    /* =========================================================
       PATH / STORAGE HELPERS
    ========================================================= */

    protected function validate_path($path)
    {
        $path = str_replace(chr(0), '', $path);
        $path = ltrim($path, '/\\');
        $full_path = $path ? realpath($this->root_path . DIRECTORY_SEPARATOR . $path) : $this->root_path;

        // Must exist and be within WordPress root (with directory separator check)
        $root_with_sep = trailingslashit($this->root_path);
        if (! $full_path || (0 !== strpos(trailingslashit($full_path), $root_with_sep) && $full_path !== untrailingslashit($this->root_path))) {
            return false;
        }

        // Check against excluded paths
        foreach (anibas_fm_exclude_paths() as $blocked) {
            $blocked_path = trailingslashit($this->root_path . DIRECTORY_SEPARATOR . $blocked);
            if (0 === strpos(trailingslashit($full_path), $blocked_path)) {
                return false;
            }
        }

        // Check against hardcoded blocked paths
        foreach (anibas_fm_get_blocked_paths() as $blocked) {
            // Handle wildcards
            if (strpos($blocked, '*') !== false) {
                $pattern = str_replace('*', '.*', preg_quote($blocked, '/'));
                if (preg_match('/' . $pattern . '$/i', $full_path)) {
                    return false;
                }
            } else {
                $blocked_path = $this->root_path . DIRECTORY_SEPARATOR . $blocked;
                $blocked_real = realpath($blocked_path);

                // Check exact match or prefix match for directories
                if ($blocked_real && $full_path === $blocked_real) {
                    return false;
                }
                if ($blocked_real && is_dir($blocked_real)) {
                    $blocked_with_sep = trailingslashit($blocked_real);
                    if (0 === strpos(trailingslashit($full_path), $blocked_with_sep)) {
                        return false;
                    }
                }
            }
        }

        return $full_path;
    }

    protected function get_storage_adapter($storage, array $cleanup_transients = [])
    {
        try {
            $adapter = StorageManager::get_instance()->get_adapter($storage);
        } catch (\Throwable $e) {
            $this->send_error(array(
                'error'   => 'StorageConnectionFailed',
                'message' => esc_html($e->getMessage()),
            ), null, $cleanup_transients);
        }
        return $adapter;
    }

    /**
     * Fetch the active archive job registry, pruning entries older than 2 hours.
     * Shared between ArchiveAjaxHandler (registers/cancels jobs) and
     * TransferAjaxHandler (surfaces them in check_running_tasks).
     */
    protected function get_archive_jobs(): array
    {
        $jobs   = anibas_fm_get_option('anibas_fm_archive_jobs', []);
        $cutoff = time() - 7200; // 2 hours
        return array_filter($jobs, fn($j) => isset($j['started_at']) && $j['started_at'] > $cutoff);
    }
}
