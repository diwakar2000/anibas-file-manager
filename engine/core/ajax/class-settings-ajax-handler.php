<?php

namespace Anibas;

if (! defined('ABSPATH')) exit;

/**
 * AJAX endpoints for plugin settings and remote-storage connection management.
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
            ANIBAS_FM_REMOTE_OAUTH_START     => 'start_remote_oauth',
            ANIBAS_FM_REMOTE_OAUTH_REVOKE    => 'revoke_remote_oauth',
        ]);
        add_action('admin_post_' . ANIBAS_FM_REMOTE_OAUTH_CALLBACK, array($this, 'remote_oauth_callback'));
    }

    public function save_settings()
    {
        $this->check_save_settings_privilege();

        $token = anibas_fm_fetch_request_variable('post', 'token', '');
        $password = anibas_fm_fetch_request_variable('post', 'password', '');
        $stored_hash = anibas_fm_get_option('settings_password_hash', '');
        $stored_token = get_transient('anibas_fm_auth_' . get_current_user_id());
        $new_password = anibas_fm_fetch_request_variable('post', 'new_password', '');
        $remove_settings_password = anibas_fm_fetch_request_variable('post', 'remove_settings_password', '');

        $valid_token = $token && is_string($stored_token) && hash_equals($stored_token, $token);
        $valid_password = ! empty($stored_hash) && wp_check_password($password, $stored_hash);

        if (! empty($stored_hash) && ! $valid_token && ! $valid_password) {
            $this->send_error(esc_html__('Invalid authentication', 'anibas-file-manager'), 401);
        }

        $is_changing_settings_password = ! empty($new_password) || ($remove_settings_password === '1' && ! empty($stored_hash));
        if ($is_changing_settings_password && ! empty($stored_hash) && ! $valid_password) {
            $this->send_error(esc_html__('Current settings password is required to change or remove password protection.', 'anibas-file-manager'), 401);
        }

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

        if (! empty($new_password)) {
            $updates['settings_password_hash'] = wp_hash_password($new_password);
            delete_transient('anibas_fm_auth_' . get_current_user_id());
        } elseif ($remove_settings_password === '1' && ! empty($stored_hash)) {
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
                    $this->send_error(esc_html__('Current delete password is incorrect.', 'anibas-file-manager'));
                }
            }
            $updates['delete_password_hash'] = ! empty($delete_password) ? wp_hash_password($delete_password) : '';
            delete_transient('anibas_fm_delete_auth_' . get_current_user_id());
        }

        $fm_password = anibas_fm_fetch_request_variable('post', 'fm_password', '');
        $remove_fm_password = anibas_fm_fetch_request_variable('post', 'remove_fm_password', '');
        $is_changing_fm_password = $fm_password !== '' || $remove_fm_password === '1';
        if ($is_changing_fm_password) {
            $existing_fm_hash = anibas_fm_get_option('fm_password_hash', '');
            if (! empty($existing_fm_hash)) {
                $fm_current = anibas_fm_fetch_request_variable('post', 'fm_current_password', '');
                if (empty($fm_current) || ! wp_check_password($fm_current, $existing_fm_hash)) {
                    $this->send_error(esc_html__('Current file manager password is incorrect.', 'anibas-file-manager'));
                }
            }
            $updates['fm_password_hash'] = $remove_fm_password === '1' ? '' : wp_hash_password($fm_password);
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

        if (anibas_fm_database_view_constant_enabled()) {
            if (isset($_POST['database_view_enabled'])) {
                $updates['database_view_enabled'] = anibas_fm_fetch_request_variable('post', 'database_view_enabled', '0') === '1';
            }

            if (anibas_fm_database_edit_constant_enabled() && isset($_POST['database_edit_enabled'])) {
                $updates['database_edit_enabled'] = anibas_fm_fetch_request_variable('post', 'database_edit_enabled', '0') === '1';
            } elseif (! anibas_fm_database_edit_constant_enabled()) {
                $updates['database_edit_enabled'] = false;
            }

            $database_password = anibas_fm_fetch_request_variable('post', 'database_password', '');
            $remove_database_password = anibas_fm_fetch_request_variable('post', 'remove_database_password', '');
            if (! empty($database_password)) {
                $updates['database_password_hash'] = wp_hash_password($database_password);
                delete_transient('anibas_fm_db_token_' . get_current_user_id());
            } elseif ($remove_database_password === '1') {
                $updates['database_password_hash'] = '';
                delete_transient('anibas_fm_db_token_' . get_current_user_id());
            }
        } else {
            $updates['database_view_enabled'] = false;
            $updates['database_edit_enabled'] = false;
        }

        if (array_key_exists('database_view_enabled', $updates) && ! $updates['database_view_enabled']) {
            $updates['database_edit_enabled'] = false;
        }

        anibas_fm_update_option($updates);
        $this->send_success(array('message' => esc_html__('Settings saved successfully', 'anibas-file-manager')));
    }

    public function get_remote_settings()
    {
        $nonce = anibas_fm_fetch_request_variable('request', 'nonce');

        if (wp_verify_nonce($nonce, ANIBAS_FM_NONCE_SETTINGS)) {
            $this->check_admin_privilege();
            $this->check_settings_auth();

            $settings = anibas_fm_get_remote_settings();
            if (anibas_fm_fetch_request_variable('request', 'summary', '') === '1') {
                $this->send_success($this->remote_settings_availability_summary($settings));
            }

            $providers = anibas_fm_remote_storage_providers();

            foreach ($settings as $storage => $conn) {
                if (! is_array($conn)) continue;
                if (isset($providers[$storage])) {
                    $settings[$storage] = array_intersect_key($conn, anibas_fm_remote_storage_fields($providers[$storage]));
                    $conn = $settings[$storage];
                }
                if (! empty($providers[$storage]['oauth']) && anibas_fm_remote_connection_has_oauth_token($conn)) {
                    foreach (anibas_fm_remote_oauth_credential_fields($storage) as $credential_key) {
                        unset($settings[$storage][$credential_key], $conn[$credential_key]);
                    }
                }
                foreach (anibas_fm_remote_secret_fields($storage) as $f) {
                    if (isset($conn[$f]) && $conn[$f] !== '') {
                        $settings[$storage][$f]         = '';
                        $settings[$storage][$f . '_set'] = true;
                    }
                }
            }

            $this->send_success($settings);
        }

        if (wp_verify_nonce($nonce, ANIBAS_FM_NONCE_LIST)) {
            $this->check_admin_privilege();
            $this->check_fm_token();

            $settings = anibas_fm_get_remote_settings();
            $this->send_success($this->remote_settings_availability_summary($settings));
        }

        $this->send_error(esc_html__('Invalid nonce.', 'anibas-file-manager'), 401);
    }

    private function remote_settings_availability_summary(array $settings): array
    {
        $summary = array();

        foreach (anibas_fm_remote_storage_providers() as $storage => $provider) {
            $factory = $provider['adapter_factory'] ?? null;
            if (! $factory || ! is_callable($factory)) {
                continue;
            }

            $connection = is_array($settings[$storage] ?? null) ? $settings[$storage] : array();
            $is_enabled = ! empty($settings[$storage]['enabled']);
            $connection_status = $is_enabled
                ? $this->saved_remote_connection_status($storage, $connection)
                : array('success' => false, 'message' => '');
            $is_available = $is_enabled && ! empty($connection_status['success']);

            $summary[$storage] = array(
                'enabled'   => $is_enabled,
                'available' => $is_available,
                'label'     => $provider['label'] ?? $storage,
                'message'   => (string) ($connection_status['message'] ?? ''),
            );
        }

        return $summary;
    }

    private function saved_remote_connection_status(string $storage, array $config): array
    {
        try {
            $result = $this->test_remote_storage($storage, $config);
        } catch (\Throwable $e) {
            $result = array('success' => false, 'message' => $e->getMessage());
        }

        $status = array(
            'success' => ! empty($result['success']),
            'message' => (string) ($result['message'] ?? ''),
        );

        return $status;
    }

    public function save_remote_settings()
    {
        $this->check_save_settings_privilege();
        $this->check_settings_auth();

        $raw      = json_decode(stripslashes(anibas_fm_fetch_request_variable('post', 'settings', '')), true);
        $sanitized = anibas_fm_sanitize_remote_settings($raw);
        $availability = $this->disable_new_unavailable_remote_connections($sanitized);
        $sanitized = $availability['settings'];
        update_option('anibas_fm_remote_connections', $sanitized);

        $message = empty($availability['disabled'])
            ? esc_html__('Remote settings saved successfully.', 'anibas-file-manager')
            : esc_html__('Remote settings saved. Unavailable storage was left disabled.', 'anibas-file-manager');
        $this->send_success(array(
            'message'  => $message,
            'disabled' => $availability['disabled'],
        ));
    }

    public function test_remote_connection()
    {
        $this->check_save_settings_privilege();
        $this->check_settings_auth();

        $type   = sanitize_text_field(wp_unslash($_POST['type'] ?? ''));
        $config = json_decode(wp_unslash($_POST['config'] ?? ''), true);
        if (! is_array($config)) {
            $this->send_error(esc_html__('Invalid config', 'anibas-file-manager'));
        }

        // If a secret field is blank, fall back to the stored (decrypted) value
        // so admins can re-test a saved connection without re-entering creds.
        $stored = anibas_fm_get_remote_settings();
        foreach (anibas_fm_remote_secret_fields($type) as $f) {
            if ((! isset($config[$f]) || $config[$f] === '') && isset($stored[$type][$f])) {
                $config[$f] = $stored[$type][$f];
            }
        }
        foreach (anibas_fm_remote_oauth_credential_fields($type) as $f) {
            if ((! isset($config[$f]) || $config[$f] === '') && isset($stored[$type][$f])) {
                $config[$f] = $stored[$type][$f];
            }
        }

        $result = $this->test_remote_storage($type, $config);

        if ($result['success']) {
            $this->send_success($result);
        } else {
            $this->send_error($result['message'] ?? esc_html__('Connection test failed', 'anibas-file-manager'));
        }
    }

    public function start_remote_oauth()
    {
        $this->check_save_settings_privilege();
        $this->check_settings_auth();

        $provider = sanitize_key(wp_unslash($_POST['provider'] ?? ''));
        $result = RemoteOAuthManager::start($provider);
        if (is_wp_error($result)) {
            $this->send_wp_error($result, 400);
        }

        $this->send_success($result);
    }

    public function remote_oauth_callback()
    {
        RemoteOAuthManager::handle_callback();
    }

    public function revoke_remote_oauth()
    {
        $this->check_save_settings_privilege();
        $this->check_settings_auth();

        $provider = sanitize_key(wp_unslash($_POST['provider'] ?? ''));
        $result = RemoteOAuthManager::revoke_and_disconnect($provider);
        if (is_wp_error($result)) {
            $this->send_wp_error($result, 400);
        }

        $this->send_success($result);
    }

    private function test_remote_storage(string $type, array $config): array
    {
        $providers = anibas_fm_remote_storage_providers();
        $tester = $providers[$type]['tester'] ?? null;

        if (! $tester || ! is_callable($tester)) {
            return ['success' => false, 'message' => esc_html__('Invalid type', 'anibas-file-manager')];
        }

        $result = call_user_func($tester, $config);
        return is_array($result) ? $result : ['success' => false, 'message' => esc_html__('Connection test failed', 'anibas-file-manager')];
    }

    private function disable_new_unavailable_remote_connections(array $settings): array
    {
        $next = anibas_fm_decrypt_remote_settings($settings);
        if (! is_array($next)) {
            return array('settings' => $settings, 'disabled' => array());
        }

        $existing = anibas_fm_get_remote_settings();
        $providers = anibas_fm_remote_storage_providers();
        $disabled = array();

        foreach ($next as $storage => $config) {
            if (! is_array($config) || empty($config['enabled'])) {
                continue;
            }

            $previous = is_array($existing[$storage] ?? null) ? $existing[$storage] : array();
            $was_enabled = ! empty($previous['enabled']);
            if ($was_enabled && ! $this->remote_connection_config_changed($config, $previous)) {
                continue;
            }

            $status = $this->saved_remote_connection_status($storage, $config);
            if (! empty($status['success'])) {
                continue;
            }

            $next[$storage]['enabled'] = false;
            $disabled[$storage] = array(
                'label'   => $providers[$storage]['label'] ?? $storage,
                'message' => (string) ($status['message'] ?? ''),
            );
        }

        return array(
            'settings' => empty($disabled) ? $settings : anibas_fm_encrypt_remote_settings($next),
            'disabled' => $disabled,
        );
    }

    private function remote_connection_config_changed(array $next, array $previous): bool
    {
        unset($next['enabled'], $previous['enabled']);
        ksort($next);
        ksort($previous);

        return wp_json_encode($next) !== wp_json_encode($previous);
    }
}
