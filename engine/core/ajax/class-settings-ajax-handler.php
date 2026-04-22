<?php

namespace Anibas;

if (! defined('ABSPATH')) exit;

/**
 * AJAX endpoints for plugin settings: general settings save, remote storage
 * connection config get/save, and remote connection testing.
 */
class SettingsAjaxHandler extends AjaxHandler
{
    public function __construct()
    {
        parent::__construct();
        $this->register_actions([
            ANIBAS_FM_SAVE_SETTINGS          => 'save_settings',
            ANIBAS_FM_GET_REMOTE_SETTINGS    => 'get_remote_settings',
            ANIBAS_FM_SAVE_REMOTE_SETTINGS   => 'save_remote_settings',
            ANIBAS_FM_TEST_REMOTE_CONNECTION => 'test_remote_connection',
        ]);
    }

    public function save_settings()
    {
        $this->check_save_settings_privilege();

        $token = anibas_fm_fetch_request_variable('post', 'token', '');
        $password = anibas_fm_fetch_request_variable('post', 'password', '');
        $stored_hash = anibas_fm_get_option('settings_password_hash', '');
        $stored_token = get_transient('anibas_fm_auth_' . get_current_user_id());

        $valid_token = $token && is_string($stored_token) && hash_equals($stored_token, $token);
        $valid_password = ! empty($stored_hash) && wp_check_password($password, $stored_hash);

        if (! empty($stored_hash) && ! $valid_token && ! $valid_password) {
            wp_send_json_error(esc_html__('Invalid authentication', 'anibas-file-manager'), 401);
        }

        $new_password = anibas_fm_fetch_request_variable('post', 'new_password', '');
        $delete_password = anibas_fm_fetch_request_variable('post', 'delete_password', '');
        $excluded_paths = anibas_fm_fetch_request_variable('post', 'excluded_paths', array());
        if (! is_array($excluded_paths)) {
            $excluded_paths = array();
        }
        $excluded_paths = array_map('sanitize_text_field', $excluded_paths);
        $excluded_paths = array_values( array_unique( array_filter( array_map( 'trim', $excluded_paths ) ) ) );

        // Filter out paths that are already in the hardcoded blocked list
        $blocked_paths = anibas_fm_get_blocked_paths();
        $excluded_paths = array_values( array_filter( $excluded_paths, function( $path ) use ( $blocked_paths ) {
            return ! in_array( trim( $path, '/' ), $blocked_paths, true );
        } ) );

        $chunk_size = intval(anibas_fm_fetch_request_variable('post', 'chunk_size', ANIBAS_FM_DEFAULT_CHUNK_SIZE));
        if ($chunk_size < ANIBAS_FM_CHUNK_SIZE_MIN) {
            $chunk_size = ANIBAS_FM_CHUNK_SIZE_MIN;
        }
        if ($chunk_size > ANIBAS_FM_CHUNK_SIZE_MAX) {
            $chunk_size = ANIBAS_FM_CHUNK_SIZE_MAX;
        }

        $updates = array(
            'excluded_paths' => $excluded_paths,
            'chunk_size' => $chunk_size
        );

        $remove_settings_password = anibas_fm_fetch_request_variable('post', 'remove_settings_password', '');
        if (! empty($new_password)) {
            $updates['settings_password_hash'] = wp_hash_password($new_password);
            delete_transient('anibas_fm_auth_' . get_current_user_id());
        } elseif ($remove_settings_password === '1' && ! empty($stored_hash)) {
            // Removing settings password — auth was already validated above
            $updates['settings_password_hash'] = '';
            delete_transient('anibas_fm_auth_' . get_current_user_id());
        }

        $delete_password_isset = isset($_POST['delete_password']);
        if ($delete_password_isset) {
            // Require current delete password when one is already set
            $existing_delete_hash = anibas_fm_get_option('delete_password_hash', '');
            if (! empty($existing_delete_hash)) {
                $current_delete_password = anibas_fm_fetch_request_variable('post', 'current_delete_password', '');
                if (empty($current_delete_password) || ! wp_check_password($current_delete_password, $existing_delete_hash)) {
                    wp_send_json_error(esc_html__('Current delete password is incorrect.', 'anibas-file-manager'));
                }
            }
            $updates['delete_password_hash'] = ! empty($delete_password) ? wp_hash_password($delete_password) : '';
            delete_transient('anibas_fm_delete_auth_' . get_current_user_id());
        }

        // FM page password
        $fm_password_isset = isset($_POST['fm_password']);
        if ($fm_password_isset) {
            // Require current FM password when one is already set
            $existing_fm_hash = anibas_fm_get_option('fm_password_hash', '');
            if (! empty($existing_fm_hash)) {
                $fm_current = anibas_fm_fetch_request_variable('post', 'fm_current_password', '');
                if (empty($fm_current) || ! wp_check_password($fm_current, $existing_fm_hash)) {
                    wp_send_json_error(esc_html__('Current file manager password is incorrect.', 'anibas-file-manager'));
                }
            }
            $fm_password = anibas_fm_fetch_request_variable('post', 'fm_password', '');
            $updates['fm_password_hash'] = ! empty($fm_password) ? wp_hash_password($fm_password) : '';
            // Invalidate all active FM tokens when password changes or is removed
            delete_transient('anibas_fm_fm_token_' . get_current_user_id());
        }

        // FM refresh-required preference (1 = require password every refresh, 0 = use sessionStorage)
        if (isset($_POST['fm_password_refresh_required'])) {
            $updates['fm_password_refresh_required'] = (bool) anibas_fm_fetch_request_variable('post', 'fm_password_refresh_required', true);
        }

        // Trash toggle
        if (isset($_POST['delete_to_trash'])) {
            $updates['delete_to_trash'] = anibas_fm_fetch_request_variable('post', 'delete_to_trash', '0') === '1';
        }

        // Remote per-file backups toggle
        if (isset($_POST['remote_file_backups_enabled'])) {
            $updates['remote_file_backups_enabled'] = anibas_fm_fetch_request_variable('post', 'remote_file_backups_enabled', '0') === '1';
        }

        // Debug mode (only honoured on localhost)
        if (isset($_POST['debug_mode'])) {
            $updates['debug_mode'] = anibas_fm_is_development_site() && anibas_fm_fetch_request_variable('post', 'debug_mode', '0') === '1';
        }

        anibas_fm_update_option($updates);
        wp_send_json_success(array('message' => esc_html__('Settings saved successfully', 'anibas-file-manager')));
    }

    public function get_remote_settings()
    {
        $this->check_save_settings_privilege();

        $settings      = anibas_fm_get_remote_settings();
        $secret_fields = anibas_fm_remote_secret_fields();

        // Never return decrypted secrets to the client. Send a presence flag
        // so the UI can show "••••••" instead of leaking the value.
        foreach ($settings as $storage => $conn) {
            if (! is_array($conn)) continue;
            foreach ($secret_fields as $f) {
                if (isset($conn[$f]) && $conn[$f] !== '') {
                    $settings[$storage][$f]         = '';
                    $settings[$storage][$f . '_set'] = true;
                }
            }
        }

        wp_send_json_success($settings);
    }

    public function save_remote_settings()
    {
        $this->check_save_settings_privilege();

        $raw      = json_decode(stripslashes(anibas_fm_fetch_request_variable('post', 'settings', '')), true);
        $sanitized = anibas_fm_sanitize_remote_settings($raw);
        update_option('anibas_fm_remote_connections', $sanitized);
        wp_send_json_success();
    }

    public function test_remote_connection()
    {
        $this->check_nonce(ANIBAS_FM_NONCE_SETTINGS);
        $this->check_admin_privilege();

        $type   = sanitize_text_field(wp_unslash($_POST['type'] ?? ''));
        $config = json_decode(wp_unslash($_POST['config'] ?? ''), true);
        if (! is_array($config)) {
            wp_send_json_error(esc_html__('Invalid config', 'anibas-file-manager'));
        }

        // If a secret field is blank, fall back to the stored (decrypted) value
        // so admins can re-test a saved connection without re-entering creds.
        $stored = anibas_fm_get_remote_settings();
        foreach (anibas_fm_remote_secret_fields() as $f) {
            if ((! isset($config[$f]) || $config[$f] === '') && isset($stored[$type][$f])) {
                $config[$f] = $stored[$type][$f];
            }
        }

        $result = match ($type) {
            'ftp' => RemoteStorageTester::test_ftp($config),
            'sftp' => RemoteStorageTester::test_sftp($config),
            's3' => RemoteStorageTester::test_s3($config),
            's3_compatible' => RemoteStorageTester::test_s3_compatible($config),
            default => ['success' => false, 'message' => esc_html__('Invalid type', 'anibas-file-manager')]
        };

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['message']);
        }
    }
}
