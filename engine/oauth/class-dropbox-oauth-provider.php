<?php

namespace Anibas;

if ( ! defined( 'ABSPATH' ) ) exit;

class DropboxOAuthProvider extends OAuthProvider
{
    public function id(): string
    {
        return 'dropbox';
    }

    public function label(): string
    {
        return 'Dropbox';
    }

    protected function client_id_key(): string
    {
        return 'app_key';
    }

    protected function requires_client_secret(): bool
    {
        return false;
    }

    protected function authorization_endpoint(): string
    {
        return 'https://www.dropbox.com/oauth2/authorize';
    }

    protected function token_endpoint(): string
    {
        return 'https://api.dropboxapi.com/oauth2/token';
    }

    protected function default_scopes(): array
    {
        return array(
            'files.metadata.read',
            'files.metadata.write',
            'files.content.read',
            'files.content.write',
        );
    }

    protected function extra_authorization_params(): array
    {
        return array('token_access_type' => 'offline');
    }

    protected function default_client_credentials(): array
    {
        return array(
            'app_key' => defined('ANIBAS_FM_DROPBOX_APP_KEY') ? (string) ANIBAS_FM_DROPBOX_APP_KEY : '',
        );
    }

    public function supports_token_revocation(): bool
    {
        return true;
    }

    public function revoke_tokens(array $settings): array
    {
        $access_token = '';
        if (trim((string) ($settings['refresh_token'] ?? '')) !== '') {
            try {
                $access_token = $this->get_access_token();
            } catch (\Throwable $e) {
                if ($this->token_looks_invalid($e->getMessage())) {
                    return array(
                        'revoked' => true,
                        'message' => __('Dropbox token was already invalid; local connection removed.', 'anibas-file-manager'),
                    );
                }
                throw $e;
            }
        } else {
            $access_token = trim((string) ($settings['access_token'] ?? ''));
        }
        if ($access_token === '') {
            return array(
                'revoked' => false,
                'message' => __('No saved Dropbox OAuth token was found.', 'anibas-file-manager'),
            );
        }

        $response = wp_remote_post('https://api.dropboxapi.com/2/auth/token/revoke', array(
            'timeout' => 20,
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/json',
            ),
            'body' => '{}',
        ));

        if (is_wp_error($response)) {
            throw new \RuntimeException($response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        if ($status === 200) {
            return array(
                'revoked' => true,
                'message' => __('Dropbox token revoked and local connection removed.', 'anibas-file-manager'),
            );
        }
        if ($this->token_looks_invalid($body)) {
            return array(
                'revoked' => true,
                'message' => __('Dropbox token was already invalid; local connection removed.', 'anibas-file-manager'),
            );
        }

        throw new \RuntimeException($this->error_message($body, $status));
    }
}
