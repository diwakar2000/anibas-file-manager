<?php

/**
 * AJAX handler exposing password authentication and re-validation endpoints.
 *
 * @package Anibas_File_Manager
 */

namespace Anibas;

if (! defined('ABSPATH')) exit;

/**
 * AJAX endpoints for authentication: settings password, FM page password,
 * delete confirmation password, plus the silent re-validation checks for each.
 */
class AuthAjaxHandler extends AjaxHandler
{
    /**
     * Register the settings/FM/delete password verification and
     * re-validation AJAX actions.
     */
    public function __construct()
    {
        parent::__construct();
        $this->register_actions([
            ANIBAS_FM_VERIFY_PASSWORD        => 'verify_password',
            ANIBAS_FM_CHECK_AUTH             => 'check_auth',
            ANIBAS_FM_VERIFY_FM_PASSWORD     => 'verify_fm_password',
            ANIBAS_FM_CHECK_FM_AUTH          => 'check_fm_auth',
            ANIBAS_FM_VERIFY_DELETE_PASSWORD => 'verify_delete_password',
            ANIBAS_FM_REQUEST_DELETE_TOKEN   => 'request_delete_token',
        ]);
    }

    /* =========================================================
       FM PASSWORD VERIFY — gate the file manager page itself
    ========================================================= */

    /**
     * Verify the file manager page password and, on success, issue a
     * session token used to gate access to the FM UI.
     *
     * Requires the FM nonce and admin privilege. Locks out further attempts
     * for 5 minutes after 5 failed tries for this user, with a 1-second
     * delay on each failure to slow brute-force attempts.
     */
    public function verify_fm_password(): void
    {
        $this->check_nonce(ANIBAS_FM_NONCE_FM);
        $this->check_admin_privilege();

        $user_id  = get_current_user_id();
        $lock_key = 'anibas_fm_fm_pwd_lock_' . $user_id;
        $att_key  = 'anibas_fm_fm_pwd_attempts_' . $user_id;

        if (get_transient($lock_key)) {
            $this->send_error(esc_html__('Too many attempts. Please wait 5 minutes.', 'anibas-file-manager'), 429);
        }

        $password  = anibas_fm_fetch_request_variable('post', 'password', '');
        $fm_hash   = anibas_fm_get_option('fm_password_hash', '');

        if (empty($fm_hash) || ! wp_check_password($password, $fm_hash)) {
            $attempts = (int) get_transient($att_key) + 1;
            if ($attempts >= 5) {
                delete_transient($att_key);
                set_transient($lock_key, true, 300);
                sleep(1);
                $this->send_error(esc_html__('Too many failed attempts. Locked for 5 minutes.', 'anibas-file-manager'), 429);
            }
            set_transient($att_key, $attempts, 300);
            sleep(1);
            $this->send_error(esc_html__('Invalid password', 'anibas-file-manager'), 401);
        }

        // Correct — issue token
        delete_transient($att_key);
        $raw_token   = wp_generate_password(40, false);
        $token_hash  = hash('sha256', $raw_token);
        set_transient('anibas_fm_fm_token_' . $user_id, $token_hash, 12 * HOUR_IN_SECONDS);

        $this->send_success(array('token' => $raw_token));
    }

    /* =========================================================
       FM AUTH CHECK — silent re-validation on page load (sessionStorage flow)
    ========================================================= */

    /**
     * Silently re-validate a previously issued FM session token (e.g. on
     * page load, restoring the sessionStorage-backed session).
     *
     * Requires the FM nonce and admin privilege. Applies a constant
     * 1-second delay on every check for timing-safety, and locks out
     * further attempts for 5 minutes after 3 failed retries.
     */
    public function check_fm_auth(): void
    {
        $this->check_nonce(ANIBAS_FM_NONCE_FM);
        $this->check_admin_privilege();

        $user_id     = get_current_user_id();
        $lock_key    = 'anibas_fm_fm_auth_lock_' . $user_id;

        if (get_transient($lock_key)) {
            $this->send_error(array('error' => 'FMTokenRequired', 'message' => esc_html__('Too many attempts.', 'anibas-file-manager')), 429);
        }

        $raw_token   = anibas_fm_fetch_request_variable('post', 'token', '');
        $stored_hash = get_transient('anibas_fm_fm_token_' . $user_id);

        sleep(1); // timing-safe constant delay

        if ($raw_token && $stored_hash && hash_equals($stored_hash, hash('sha256', $raw_token))) {
            $this->send_success();
        } else {
            $retry = (int) get_transient('anibas_fm_fm_auth_retry_' . $user_id);
            if ($retry >= 3) {
                delete_transient('anibas_fm_fm_auth_retry_' . $user_id);
                set_transient($lock_key, true, 300);
            } else {
                set_transient('anibas_fm_fm_auth_retry_' . $user_id, $retry + 1, 300);
            }
            $this->send_error(array('error' => 'FMTokenRequired', 'message' => esc_html__('Session expired', 'anibas-file-manager')), 401);
        }
    }

    /**
     * Verify the delete confirmation password and, on success, issue a
     * short-lived delete-auth token.
     *
     * Requires delete privilege. If no delete password is configured, any
     * submission is treated as valid (nothing to gate). Locks out further
     * attempts for 5 minutes after 5 failures, with a 1-second delay on
     * each failure.
     */
    public function verify_delete_password()
    {
        $this->check_delete_privilege();

        $user_id = get_current_user_id();
        $lock_key = 'anibas_fm_delete_pwd_lock_' . $user_id;
        $attempts_key = 'anibas_fm_delete_pwd_attempts_' . $user_id;

        if (get_transient($lock_key)) {
            $this->send_error(esc_html__('Too many attempts. Please wait.', 'anibas-file-manager'), 429);
        }

        $password = anibas_fm_fetch_request_variable('post', 'password', '');
        $stored_hash = anibas_fm_get_option('delete_password_hash', '');

        if (empty($stored_hash) || wp_check_password($password, $stored_hash)) {
            delete_transient($attempts_key);
            $token = wp_generate_password(32, false);
            set_transient('anibas_fm_delete_auth_' . $user_id, $token, 60);
            $this->send_success(array('token' => $token));
        } else {
            $attempts = (int) get_transient($attempts_key);
            $attempts++;
            set_transient($attempts_key, $attempts, 300);

            if ($attempts >= 5) {
                set_transient($lock_key, true, 300);
                delete_transient($attempts_key);
                $this->send_error(esc_html__('Too many failed attempts. Locked for 5 minutes.', 'anibas-file-manager'), 429);
            }

            sleep(1);
            $this->send_error(esc_html__('Invalid password', 'anibas-file-manager'), 401);
        }
    }

    /**
     * Verify the settings-area password and, on success, issue a session
     * token used by has_valid_settings_auth().
     *
     * Requires the settings-save privilege. If no settings password is
     * configured, any submission is treated as valid. Locks out further
     * attempts for 5 minutes after 5 failures, with a 1-second delay on
     * each failure.
     */
    public function verify_password()
    {
        $this->check_save_settings_privilege();

        $user_id = get_current_user_id();
        $lock_key = 'anibas_fm_settings_pwd_lock_' . $user_id;
        $attempts_key = 'anibas_fm_settings_pwd_attempts_' . $user_id;

        if (get_transient($lock_key)) {
            $this->send_error(esc_html__('Too many attempts. Please wait.', 'anibas-file-manager'), 429);
        }

        $password = anibas_fm_fetch_request_variable('post', 'password', '');
        $stored_hash = anibas_fm_get_option('settings_password_hash', '');

        if (empty($stored_hash) || wp_check_password($password, $stored_hash)) {
            delete_transient($attempts_key);
            $token = wp_generate_password(32, false);
            set_transient('anibas_fm_auth_' . $user_id, $token, HOUR_IN_SECONDS);
            $this->send_success(array('token' => $token));
        } else {
            $attempts = (int) get_transient($attempts_key);
            $attempts++;
            set_transient($attempts_key, $attempts, 300);

            if ($attempts >= 5) {
                set_transient($lock_key, true, 300);
                delete_transient($attempts_key);
                $this->send_error(esc_html__('Too many failed attempts. Locked for 5 minutes.', 'anibas-file-manager'), 429);
            }

            sleep(1);
            $this->send_error(esc_html__('Invalid password', 'anibas-file-manager'), 401);
        }
    }

    /**
     * Silently re-validate a previously issued settings session token.
     *
     * Checks a per-user lockout transient before even running the
     * settings-save privilege check, so a locked-out user's requests fail
     * fast. On success, refreshes the stored token's expiry; on failure,
     * applies a 1-second delay and locks out further attempts for 5
     * minutes after 3 failed retries.
     */
    public function check_auth()
    {
        if (get_transient('anibas_fm_auth_' . get_current_user_id() . '_lock')) {
            $this->send_error(esc_html__('Too many attempts. Please try again later.', 'anibas-file-manager'), 429);
        }

        $this->check_save_settings_privilege();

        $token = anibas_fm_fetch_request_variable('post', 'token', '');
        $stored_token = get_transient('anibas_fm_auth_' . get_current_user_id());

        sleep(1);

        if ($token && is_string($stored_token) && hash_equals($stored_token, $token)) {
            delete_transient('anibas_fm_auth_' . get_current_user_id() . '_retry');
            set_transient('anibas_fm_auth_' . get_current_user_id(), $token, HOUR_IN_SECONDS);
            $this->send_success();
        } else {
            $retry = (int) get_transient('anibas_fm_auth_' . get_current_user_id() . '_retry');
            if ($retry < 3) {
                set_transient('anibas_fm_auth_' . get_current_user_id() . '_retry', $retry + 1, 300);
            } else {
                delete_transient('anibas_fm_auth_' . get_current_user_id() . '_retry');
                set_transient('anibas_fm_auth_' . get_current_user_id() . '_lock', true, 300);
            }
            $this->send_error(esc_html__('Invalid token', 'anibas-file-manager'), 401);
        }
    }

    /**
     * Issue a one-time delete confirmation token scoped to a specific
     * storage + path, consumed by FileCrudAjaxHandler::delete_file().
     *
     * Requires delete privilege. The token is stored for 60 seconds keyed
     * by user, storage, and an md5 hash of the path, so it can only confirm
     * deletion of the exact item it was requested for.
     */
    public function request_delete_token()
    {
        $this->check_delete_privilege();

        $path    = anibas_fm_fetch_request_variable('post', 'path', '');
        $storage = sanitize_text_field(anibas_fm_fetch_request_variable('post', 'storage', 'local'));
        if ($storage === '') {
            $storage = 'local';
        }

        if (empty($path)) {
            $this->send_error(array('error' => esc_html__('Path required', 'anibas-file-manager')));
        }

        $user_id = get_current_user_id();
        $token = wp_generate_password(32, false);
        $token_key = 'anibas_fm_delete_token_' . $user_id . '_' . md5($storage . '|' . $path);

        // Store token for 1 minute
        set_transient($token_key, $token, 60);

        $this->send_success(array('delete_token' => $token));
    }
}
