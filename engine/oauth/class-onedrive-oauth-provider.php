<?php

namespace Anibas;

if ( ! defined( 'ABSPATH' ) ) exit;

class OneDriveOAuthProvider extends OAuthProvider
{
    public function id(): string
    {
        return 'onedrive';
    }

    public function label(): string
    {
        return 'OneDrive';
    }

    protected function authorization_endpoint(): string
    {
        return 'https://login.microsoftonline.com/' . rawurlencode($this->tenant()) . '/oauth2/v2.0/authorize';
    }

    protected function token_endpoint(): string
    {
        return 'https://login.microsoftonline.com/' . rawurlencode($this->tenant()) . '/oauth2/v2.0/token';
    }

    protected function default_scopes(): array
    {
        return array('offline_access', 'Files.ReadWrite.All');
    }

    protected function extra_authorization_params(): array
    {
        return array('response_mode' => 'query');
    }

    protected function extra_token_params(string $grant_type): array
    {
        return array('scope' => implode(' ', $this->default_scopes()));
    }

    protected function default_client_credentials(): array
    {
        return array(
            'client_id'     => defined('ANIBAS_FM_ONEDRIVE_CLIENT_ID') ? (string) ANIBAS_FM_ONEDRIVE_CLIENT_ID : '',
            'client_secret' => defined('ANIBAS_FM_ONEDRIVE_CLIENT_SECRET') ? (string) ANIBAS_FM_ONEDRIVE_CLIENT_SECRET : '',
            'tenant'        => defined('ANIBAS_FM_ONEDRIVE_TENANT') ? (string) ANIBAS_FM_ONEDRIVE_TENANT : 'common',
        );
    }

    private function tenant(): string
    {
        $credentials = $this->configured_client_credentials();
        $tenant = trim((string) ($this->config['tenant'] ?? $credentials['tenant'] ?? 'common'));
        return $tenant !== '' ? $tenant : 'common';
    }
}
