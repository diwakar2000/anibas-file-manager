<?php

namespace Anibas;

if ( ! defined( 'ABSPATH' ) ) exit;

abstract class OAuthProvider
{
    protected array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    abstract public function id(): string;
    abstract public function label(): string;
    abstract protected function authorization_endpoint(): string;
    abstract protected function token_endpoint(): string;
    abstract protected function default_scopes(): array;

    protected function client_id_key(): string
    {
        return 'client_id';
    }

    protected function client_secret_key(): string
    {
        return 'client_secret';
    }

    protected function requires_client_secret(): bool
    {
        return true;
    }

    protected function token_client_credentials(): array
    {
        $credentials = array(
            'client_id' => $this->client_id(),
        );

        if ($this->requires_client_secret()) {
            $credentials['client_secret'] = $this->client_secret();
        }

        return $credentials;
    }

    protected function extra_authorization_params(): array
    {
        return array();
    }

    protected function extra_token_params(string $grant_type): array
    {
        return array();
    }

    public function redirect_uri(): string
    {
        return admin_url('admin-post.php?action=' . ANIBAS_FM_REMOTE_OAUTH_CALLBACK);
    }

    public function client_id(): string
    {
        $key = $this->client_id_key();
        $credentials = $this->configured_client_credentials();
        return $this->first_configured_value(
            $this->config[$key] ?? '',
            $this->config['client_id'] ?? '',
            $credentials[$key] ?? '',
            $credentials['client_id'] ?? ''
        );
    }

    public function client_secret(): string
    {
        $key = $this->client_secret_key();
        $credentials = $this->configured_client_credentials();
        return $this->first_configured_value(
            $this->config[$key] ?? '',
            $this->config['client_secret'] ?? '',
            $credentials[$key] ?? '',
            $credentials['client_secret'] ?? ''
        );
    }

    public function has_required_client_credentials(): bool
    {
        return $this->client_id() !== ''
            && (! $this->requires_client_secret() || $this->client_secret() !== '');
    }

    public function authorization_url(string $state, string $code_verifier): string
    {
        $params = array_merge(
            array(
                'client_id'             => $this->client_id(),
                'redirect_uri'          => $this->redirect_uri(),
                'response_type'         => 'code',
                'scope'                 => implode(' ', $this->default_scopes()),
                'state'                 => $state,
                'code_challenge'        => $this->code_challenge($code_verifier),
                'code_challenge_method' => 'S256',
            ),
            $this->extra_authorization_params()
        );

        return $this->authorization_endpoint() . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    public function exchange_authorization_code(string $code, string $code_verifier): array
    {
        return $this->token_request(array_merge(
            $this->token_client_credentials(),
            array(
                'code'          => $code,
                'code_verifier' => $code_verifier,
                'grant_type'    => 'authorization_code',
                'redirect_uri'  => $this->redirect_uri(),
            ),
            $this->extra_token_params('authorization_code')
        ));
    }

    public function refresh_access_token(string $refresh_token): array
    {
        return $this->token_request(array_merge(
            $this->token_client_credentials(),
            array(
                'grant_type'     => 'refresh_token',
                'refresh_token'  => $refresh_token,
            ),
            $this->extra_token_params('refresh_token')
        ));
    }

    public function get_access_token(): string
    {
        $refresh_token = trim((string) ($this->config['refresh_token'] ?? ''));
        $access_token  = trim((string) ($this->config['access_token'] ?? ''));
        $expires_at    = (int) ($this->config['token_expires_at'] ?? 0);

        if ($refresh_token === '') {
            if ($access_token !== '') {
                return $access_token;
            }
            throw new \RuntimeException(sprintf('%s access token or refresh token is required.', $this->label()));
        }
        if ($access_token !== '' && $expires_at > time() + 60) {
            return $access_token;
        }
        if (! $this->has_required_client_credentials()) {
            throw new \RuntimeException(sprintf('%s client credentials are required.', $this->label()));
        }

        $cache_key = 'anibas_fm_oauth_token_' . $this->id() . '_' . md5($this->client_id() . '|' . $refresh_token);
        $cached = get_transient($cache_key);
        if (is_array($cached) && ! empty($cached['access_token']) && (int) ($cached['expires_at'] ?? 0) > time() + 60) {
            return (string) $cached['access_token'];
        }

        $data = $this->refresh_access_token($refresh_token);
        if (empty($data['access_token'])) {
            throw new \RuntimeException(sprintf('%s did not return an access token.', $this->label()));
        }

        $ttl = max(60, (int) ($data['expires_in'] ?? 3600) - 60);
        set_transient($cache_key, array(
            'access_token' => (string) $data['access_token'],
            'expires_at'   => time() + $ttl,
        ), $ttl);

        return (string) $data['access_token'];
    }

    public function supports_token_revocation(): bool
    {
        return false;
    }

    public function revoke_tokens(array $settings): array
    {
        return array(
            'revoked' => false,
            'message' => sprintf(__('%s does not support app-scoped token revocation here.', 'anibas-file-manager'), $this->label()),
        );
    }

    public static function code_verifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
    }

    protected function code_challenge(string $code_verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $code_verifier, true)), '+/', '-_'), '=');
    }

    protected function token_request(array $body): array
    {
        $response = wp_remote_post($this->token_endpoint(), array(
            'timeout' => 30,
            'body'    => $body,
        ));

        if (is_wp_error($response)) {
            throw new \RuntimeException($response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        $data = json_decode($raw, true);
        if ($status < 200 || $status >= 300 || ! is_array($data)) {
            throw new \RuntimeException($this->error_message($raw, $status));
        }

        return $data;
    }

    protected function error_message(string $body, int $status): string
    {
        $data = json_decode($body, true);
        if (is_array($data)) {
            if (! empty($data['error_description'])) {
                return (string) $data['error_description'];
            }
            if (! empty($data['error']['message'])) {
                return (string) $data['error']['message'];
            }
            if (! empty($data['error_summary'])) {
                return (string) $data['error_summary'];
            }
            if (! empty($data['error'])) {
                return is_string($data['error']) ? $data['error'] : wp_json_encode($data['error']);
            }
        }

        return sprintf('%s OAuth request failed (HTTP %d).', $this->label(), $status);
    }

    protected function default_client_credentials(): array
    {
        return array();
    }

    protected function configured_client_credentials(): array
    {
        $credentials = apply_filters(
            'anibas_fm_oauth_client_credentials',
            $this->default_client_credentials(),
            $this->id(),
            $this->config
        );

        return is_array($credentials) ? $credentials : array();
    }

    private function first_configured_value(...$values): string
    {
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    protected function token_looks_invalid(string $body): bool
    {
        $data = json_decode($body, true);
        $values = array();
        if (is_array($data)) {
            foreach (array('error', 'error_description', 'error_summary') as $key) {
                if (! empty($data[$key])) {
                    $values[] = is_string($data[$key]) ? $data[$key] : wp_json_encode($data[$key]);
                }
            }
        }

        $text = strtolower(implode(' ', $values) . ' ' . $body);
        return str_contains($text, 'invalid_token')
            || str_contains($text, 'invalid access token')
            || str_contains($text, 'expired_access_token')
            || str_contains($text, 'invalid_grant');
    }
}
