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
    protected $root_path;

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

    /* =========================================================
       PRIVILEGE / NONCE / TOKEN CHECKS
    ========================================================= */

    protected function check_admin_privilege()
    {
        if (! current_user_can('manage_options')) {
            wp_send_json_error(esc_html__('Unauthorized', 'anibas-file-manager'), 403);
        }
    }

    protected function check_nonce($nonce = '')
    {
        if (! wp_verify_nonce(anibas_fm_fetch_request_variable('request', 'nonce'), $nonce)) {
            wp_send_json_error(esc_html__('Invalid nonce.', 'anibas-file-manager'), 401);
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
            wp_send_json_error(
                array('error' => 'BackupInProgress', 'message' => esc_html__('A site backup is in progress. Please wait until it completes.', 'anibas-file-manager')),
                423 // HTTP 423 Locked
            );
        }
    }

    protected function check_backup_privilege()
    {
        $this->check_nonce(ANIBAS_FM_NONCE_CREATE);
        $this->check_admin_privilege();
    }

    protected function check_save_settings_privilege()
    {
        $this->check_nonce(ANIBAS_FM_NONCE_SETTINGS);
        $this->check_admin_privilege();
    }

    /**
     * Verify the file manager session token on every FM request.
     * Only enforced when a FM password has been configured.
     * On failure, returns FMTokenRequired so the frontend can re-show the gate.
     */
    protected function check_fm_token(): void
    {
        $fm_hash = anibas_fm_get_option('fm_password_hash', '');
        if (empty($fm_hash)) {
            return; // FM password not configured — no gate
        }

        $user_id   = get_current_user_id();
        $raw_token = anibas_fm_fetch_request_variable('request', 'fm_token', '');

        if (empty($raw_token)) {
            wp_send_json_error(array('error' => 'FMTokenRequired', 'message' => esc_html__('File manager authentication required', 'anibas-file-manager')), 401);
        }

        $stored_hash = get_transient('anibas_fm_fm_token_' . $user_id);

        if (! $stored_hash || ! hash_equals($stored_hash, hash('sha256', $raw_token))) {
            wp_send_json_error(array('error' => 'FMTokenRequired', 'message' => esc_html__('File manager session expired. Please re-enter your password.', 'anibas-file-manager')), 401);
        }
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

    protected function get_storage_adapter($storage)
    {
        try {
            $adapter = StorageManager::get_instance()->get_adapter($storage);
        } catch (\Throwable $e) {
            wp_send_json_error(array(
                'error'   => 'StorageConnectionFailed',
                'message' => esc_html($e->getMessage()),
            ));
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
