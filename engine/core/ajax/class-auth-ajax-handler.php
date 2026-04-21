<?php

namespace Anibas;

if (! defined('ABSPATH')) exit;

/**
 * AJAX endpoints for authentication: settings password, FM page password,
 * delete confirmation password, plus the silent re-validation checks for each.
 */
class AuthAjaxHandler extends AjaxHandler
{
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

    public function verify_fm_password(): void
    {
        $this->check_nonce(ANIBAS_FM_NONCE_FM);
        $this->check_admin_privilege();

        $user_id  = get_current_user_id();
        $lock_key = 'anibas_fm_fm_pwd_lock_' . $user_id;
        $att_key  = 'anibas_fm_fm_pwd_attempts_' . $user_id;

        if (get_transient($lock_key)) {
            wp_send_json_error(esc_html__('Too many attempts. Please wait 5 minutes.', 'anibas-file-manager'), 429);
        }

        $password  = anibas_fm_fetch_request_variable('post', 'password', '');
        $fm_hash   = anibas_fm_get_option('fm_password_hash', '');

        if (empty($fm_hash) || ! wp_check_password($password, $fm_hash)) {
            $attempts = (int) get_transient($att_key) + 1;
            if ($attempts >= 5) {
                delete_transient($att_key);
                set_transient($lock_key, true, 300);
                sleep(1);
                wp_send_json_error(esc_html__('Too many failed attempts. Locked for 5 minutes.', 'anibas-file-manager'), 429);
            }
            set_transient($att_key, $attempts, 300);
            sleep(1);
            wp_send_json_error(esc_html__('Invalid password', 'anibas-file-manager'), 401);
        }

        // Correct — issue token
        delete_transient($att_key);
        $raw_token   = wp_generate_password(40, false);
        $token_hash  = hash('sha256', $raw_token);
        set_transient('anibas_fm_fm_token_' . $user_id, $token_hash, 12 * HOUR_IN_SECONDS);

        wp_send_json_success(array('token' => $raw_token));
    }

    /* =========================================================
       FM AUTH CHECK — silent re-validation on page load (sessionStorage flow)
    ========================================================= */

    public function check_fm_auth(): void
    {
        $this->check_nonce(ANIBAS_FM_NONCE_FM);
        $this->check_admin_privilege();

        $user_id     = get_current_user_id();
        $lock_key    = 'anibas_fm_fm_auth_lock_' . $user_id;

        if (get_transient($lock_key)) {
            wp_send_json_error(array('error' => 'FMTokenRequired', 'message' => esc_html__('Too many attempts.', 'anibas-file-manager')), 429);
        }

        $raw_token   = anibas_fm_fetch_request_variable('post', 'token', '');
        $stored_hash = get_transient('anibas_fm_fm_token_' . $user_id);

        sleep(1); // timing-safe constant delay

        if ($raw_token && $stored_hash && hash_equals($stored_hash, hash('sha256', $raw_token))) {
            wp_send_json_success();
        } else {
            $retry = (int) get_transient('anibas_fm_fm_auth_retry_' . $user_id);
            if ($retry >= 3) {
                delete_transient('anibas_fm_fm_auth_retry_' . $user_id);
                set_transient($lock_key, true, 300);
            } else {
                set_transient('anibas_fm_fm_auth_retry_' . $user_id, $retry + 1, 300);
            }
            wp_send_json_error(array('error' => 'FMTokenRequired', 'message' => esc_html__('Session expired', 'anibas-file-manager')), 401);
        }
    }

    public function verify_delete_password()
    {
        $this->check_delete_privilege();

        $user_id = get_current_user_id();
        $lock_key = 'anibas_fm_delete_pwd_lock_' . $user_id;
        $attempts_key = 'anibas_fm_delete_pwd_attempts_' . $user_id;

        if (get_transient($lock_key)) {
            wp_send_json_error(esc_html__('Too many attempts. Please wait.', 'anibas-file-manager'), 429);
        }

        $password = anibas_fm_fetch_request_variable('post', 'password', '');
        $stored_hash = anibas_fm_get_option('delete_password_hash', '');

        if (empty($stored_hash) || wp_check_password($password, $stored_hash)) {
            delete_transient($attempts_key);
            $token = wp_generate_password(32, false);
            set_transient('anibas_fm_delete_auth_' . $user_id, $token, 60);
            wp_send_json_success(array('token' => $token));
        } else {
            $attempts = (int) get_transient($attempts_key);
            $attempts++;
            set_transient($attempts_key, $attempts, 300);

            if ($attempts >= 5) {
                set_transient($lock_key, true, 300);
                delete_transient($attempts_key);
                wp_send_json_error(esc_html__('Too many failed attempts. Locked for 5 minutes.', 'anibas-file-manager'), 429);
            }

            sleep(1);
            wp_send_json_error(esc_html__('Invalid password', 'anibas-file-manager'), 401);
        }
    }

    public function verify_password()
    {
        $this->check_save_settings_privilege();

        $user_id = get_current_user_id();
        $lock_key = 'anibas_fm_settings_pwd_lock_' . $user_id;
        $attempts_key = 'anibas_fm_settings_pwd_attempts_' . $user_id;

        if (get_transient($lock_key)) {
            wp_send_json_error(esc_html__('Too many attempts. Please wait.', 'anibas-file-manager'), 429);
        }

        $password = anibas_fm_fetch_request_variable('post', 'password', '');
        $stored_hash = anibas_fm_get_option('settings_password_hash', '');

        if (empty($stored_hash) || wp_check_password($password, $stored_hash)) {
            delete_transient($attempts_key);
            $token = wp_generate_password(32, false);
            set_transient('anibas_fm_auth_' . $user_id, $token, HOUR_IN_SECONDS);
            wp_send_json_success(array('token' => $token));
        } else {
            $attempts = (int) get_transient($attempts_key);
            $attempts++;
            set_transient($attempts_key, $attempts, 300);

            if ($attempts >= 5) {
                set_transient($lock_key, true, 300);
                delete_transient($attempts_key);
                wp_send_json_error(esc_html__('Too many failed attempts. Locked for 5 minutes.', 'anibas-file-manager'), 429);
            }

            sleep(1);
            wp_send_json_error(esc_html__('Invalid password', 'anibas-file-manager'), 401);
        }
    }

    public function check_auth()
    {
        if (get_transient('anibas_fm_auth_' . get_current_user_id() . '_lock')) {
            wp_send_json_error(esc_html__('Too many attempts. Please try again later.', 'anibas-file-manager'), 429);
        }

        $this->check_save_settings_privilege();

        $token = anibas_fm_fetch_request_variable('post', 'token', '');
        $stored_token = get_transient('anibas_fm_auth_' . get_current_user_id());

        sleep(1);

        if ($token && is_string($stored_token) && hash_equals($stored_token, $token)) {
            delete_transient('anibas_fm_auth_' . get_current_user_id() . '_retry');
            set_transient('anibas_fm_auth_' . get_current_user_id(), $token, HOUR_IN_SECONDS);
            wp_send_json_success();
        } else {
            $retry = (int) get_transient('anibas_fm_auth_' . get_current_user_id() . '_retry');
            if ($retry < 3) {
                set_transient('anibas_fm_auth_' . get_current_user_id() . '_retry', $retry + 1, 300);
            } else {
                delete_transient('anibas_fm_auth_' . get_current_user_id() . '_retry');
                set_transient('anibas_fm_auth_' . get_current_user_id() . '_lock', true, 300);
            }
            wp_send_json_error(esc_html__('Invalid token', 'anibas-file-manager'), 401);
        }
    }

    public function request_delete_token()
    {
        $this->check_delete_privilege();

        $path = anibas_fm_fetch_request_variable('post', 'path', '');

        if (empty($path)) {
            wp_send_json_error(array('error' => esc_html__('Path required', 'anibas-file-manager')));
        }

        $user_id = get_current_user_id();
        $token = wp_generate_password(32, false);
        $token_key = 'anibas_fm_delete_token_' . $user_id . '_' . md5($path);

        // Store token for 1 minute
        set_transient($token_key, $token, 60);

        wp_send_json_success(array('delete_token' => $token));
    }
}
