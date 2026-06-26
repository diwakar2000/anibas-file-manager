<?php

namespace Anibas;

if ( ! defined( 'ABSPATH' ) ) exit;

class RemoteOAuthManager
{
    public static function provider(string $provider_id, array $config): ?OAuthProvider
    {
        $providers = self::providers();
        $factory = $providers[$provider_id] ?? null;

        if (is_string($factory) && class_exists($factory) && is_subclass_of($factory, OAuthProvider::class)) {
            return new $factory($config);
        }
        if (is_callable($factory)) {
            $provider = call_user_func($factory, $config);
            return $provider instanceof OAuthProvider ? $provider : null;
        }

        return null;
    }

    public static function providers(): array
    {
        return apply_filters('anibas_fm_oauth_providers', array(
            'gdrive'   => GoogleDriveOAuthProvider::class,
            'onedrive' => OneDriveOAuthProvider::class,
            'dropbox'  => DropboxOAuthProvider::class,
        ));
    }

    public static function manifest(string $provider_id): ?array
    {
        $provider = self::provider($provider_id, array());
        if (! $provider) {
            return null;
        }

        return array(
            'enabled'             => true,
            'startAction'         => ANIBAS_FM_REMOTE_OAUTH_START,
            'revokeAction'        => ANIBAS_FM_REMOTE_OAUTH_REVOKE,
            'redirectUrl'         => $provider->redirect_uri(),
            'buttonLabel'         => sprintf(__('Connect with %s', 'anibas-file-manager'), $provider->label()),
            'connectedLabel'      => __('Connected', 'anibas-file-manager'),
            'requiredFields'      => self::required_fields($provider_id),
            'revocationSupported'  => $provider->supports_token_revocation(),
            'credentialsConfigured' => $provider->has_required_client_credentials(),
        );
    }

    public static function start(string $provider_id): array|\WP_Error
    {
        $settings = anibas_fm_get_remote_settings();
        $config = $settings[$provider_id] ?? array();
        if (! is_array($config)) {
            $config = array();
        }

        $provider = self::provider($provider_id, $config);
        if (! $provider) {
            return new \WP_Error('invalid_provider', __('Invalid OAuth provider.', 'anibas-file-manager'));
        }
        if (! $provider->has_required_client_credentials()) {
            return new \WP_Error(
                'missing_client_credentials',
                sprintf(__('%s OAuth app credentials are not configured.', 'anibas-file-manager'), $provider->label())
            );
        }

        $state = wp_generate_password(48, false, false);
        $verifier = OAuthProvider::code_verifier();
        set_transient(self::state_key($state), array(
            'provider'      => $provider_id,
            'user_id'       => get_current_user_id(),
            'code_verifier' => $verifier,
            'created_at'    => time(),
        ), ANIBAS_FM_OAUTH_STATE_TTL);

        return array(
            'authorize_url' => $provider->authorization_url($state, $verifier),
            'redirect_url'  => $provider->redirect_uri(),
            'provider'      => $provider_id,
        );
    }

    public static function handle_callback(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized', 'anibas-file-manager'), esc_html__('Unauthorized', 'anibas-file-manager'), array('response' => 403));
        }

        $state = sanitize_text_field(wp_unslash($_GET['state'] ?? ''));
        $error = sanitize_text_field(wp_unslash($_GET['error'] ?? ''));
        $code  = sanitize_text_field(wp_unslash($_GET['code'] ?? ''));
        $stored = $state !== '' ? get_transient(self::state_key($state)) : false;

        if (! is_array($stored)) {
            self::redirect_result('general', 'error', __('OAuth session expired. Try connecting again.', 'anibas-file-manager'));
        }

        delete_transient(self::state_key($state));
        $provider_id = sanitize_key((string) ($stored['provider'] ?? ''));
        if ((int) ($stored['user_id'] ?? 0) !== get_current_user_id()) {
            self::redirect_result($provider_id, 'error', __('OAuth session user mismatch.', 'anibas-file-manager'));
        }
        if ($error !== '') {
            self::redirect_result($provider_id, 'error', $error);
        }
        if ($code === '' || empty($stored['code_verifier'])) {
            self::redirect_result($provider_id, 'error', __('OAuth callback did not include an authorization code.', 'anibas-file-manager'));
        }

        try {
            $settings = anibas_fm_get_remote_settings();
            $provider_settings = $settings[$provider_id] ?? array();
            if (! is_array($provider_settings)) {
                $provider_settings = array();
            }

            $provider = self::provider($provider_id, $provider_settings);
            if (! $provider) {
                self::redirect_result($provider_id, 'error', __('Invalid OAuth provider.', 'anibas-file-manager'));
            }

            $tokens = $provider->exchange_authorization_code($code, (string) $stored['code_verifier']);
            $settings[$provider_id] = self::merge_tokens($provider_settings, $tokens);
            update_option('anibas_fm_remote_connections', anibas_fm_sanitize_remote_settings($settings));

            self::redirect_result($provider_id, 'success', sprintf(__('%s connected.', 'anibas-file-manager'), $provider->label()));
        } catch (\Throwable $e) {
            self::redirect_result($provider_id, 'error', $e->getMessage());
        }
    }

    public static function revoke_and_disconnect(string $provider_id): array|\WP_Error
    {
        $provider_id = sanitize_key($provider_id);
        if ($provider_id === '') {
            return new \WP_Error('invalid_provider', __('Invalid OAuth provider.', 'anibas-file-manager'));
        }

        $settings = anibas_fm_get_remote_settings();
        $config = $settings[$provider_id] ?? array();
        if (! is_array($config)) {
            $config = array();
        }

        $provider = self::provider($provider_id, $config);
        if (! $provider) {
            return new \WP_Error('invalid_provider', __('Invalid OAuth provider.', 'anibas-file-manager'));
        }

        $supported = $provider->supports_token_revocation();
        $result = array(
            'revoked' => false,
            'message' => sprintf(__('%s local connection removed.', 'anibas-file-manager'), $provider->label()),
        );

        if ($supported && self::has_saved_oauth_token($config)) {
            try {
                $result = $provider->revoke_tokens($config);
            } catch (\Throwable $e) {
                return new \WP_Error(
                    'oauth_revoke_failed',
                    sprintf(__('Could not revoke %1$s token: %2$s', 'anibas-file-manager'), $provider->label(), $e->getMessage())
                );
            }
        } elseif (! $supported) {
            $result['message'] = sprintf(
                __('%s does not expose app-scoped token revocation here. Local connection removed.', 'anibas-file-manager'),
                $provider->label()
            );
        }

        $settings[$provider_id] = self::clear_tokens($config);
        update_option('anibas_fm_remote_connections', anibas_fm_sanitize_remote_settings($settings));

        $result['provider'] = $provider_id;
        $result['revocation_supported'] = $supported;
        return $result;
    }

    private static function merge_tokens(array $settings, array $tokens): array
    {
        $settings['enabled'] = true;
        if (! empty($tokens['access_token'])) {
            $settings['access_token'] = (string) $tokens['access_token'];
        }
        if (! empty($tokens['refresh_token'])) {
            $settings['refresh_token'] = (string) $tokens['refresh_token'];
        }
        if (! empty($tokens['expires_in'])) {
            $settings['token_expires_at'] = time() + (int) $tokens['expires_in'];
        }
        if (! empty($tokens['scope'])) {
            $settings['token_scope'] = (string) $tokens['scope'];
        }
        $settings['oauth_connected_at'] = time();

        return $settings;
    }

    private static function clear_tokens(array $settings): array
    {
        foreach (array('access_token', 'refresh_token') as $key) {
            $settings[$key] = '';
            $settings[$key . '_set'] = false;
            $settings[$key . '_clear'] = true;
        }

        $settings['token_expires_at'] = 0;
        $settings['token_scope'] = '';
        $settings['oauth_connected_at'] = 0;
        $settings['enabled'] = false;

        return $settings;
    }

    private static function has_saved_oauth_token(array $settings): bool
    {
        return trim((string) ($settings['access_token'] ?? '')) !== ''
            || trim((string) ($settings['refresh_token'] ?? '')) !== '';
    }

    public static function refresh_due_tokens(): void
    {
        $settings = anibas_fm_get_remote_settings();
        if (! is_array($settings)) {
            return;
        }

        $changed = false;
        $now = time();
        foreach (self::providers() as $provider_id => $_factory) {
            $config = $settings[$provider_id] ?? array();
            if (! is_array($config) || empty($config['enabled']) || empty($config['refresh_token'])) {
                continue;
            }
            if ((int) ($config['token_expires_at'] ?? 0) > $now + ANIBAS_FM_OAUTH_REFRESH_WINDOW) {
                continue;
            }

            $provider = self::provider($provider_id, $config);
            if (! $provider || ! $provider->has_required_client_credentials()) {
                continue;
            }

            try {
                $tokens = $provider->refresh_access_token((string) $config['refresh_token']);
                $settings[$provider_id] = self::merge_tokens($config, $tokens);
                $changed = true;
            } catch (\Throwable $e) {
                set_transient('anibas_fm_oauth_refresh_error_' . $provider_id, $e->getMessage(), HOUR_IN_SECONDS);
            }
        }

        if ($changed) {
            update_option('anibas_fm_remote_connections', anibas_fm_sanitize_remote_settings($settings));
        }
    }

    private static function required_fields(string $provider_id): array
    {
        return apply_filters('anibas_fm_oauth_required_fields', array(), $provider_id);
    }

    private static function redirect_result(string $provider_id, string $status, string $message): void
    {
        $provider_id = sanitize_key($provider_id);
        $url = add_query_arg(array(
            'page'                  => 'anibas-file-manager-settings',
            'anibas_oauth_status'   => $status,
            'anibas_oauth_provider' => $provider_id,
            'anibas_oauth_message'  => wp_strip_all_tags($message),
        ), admin_url('admin.php'));

        wp_safe_redirect($url);
        exit;
    }

    private static function state_key(string $state): string
    {
        return 'anibas_fm_oauth_state_' . md5($state);
    }
}
