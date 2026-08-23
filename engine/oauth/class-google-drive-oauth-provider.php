<?php

/**
 * OAuth provider configuration for connecting Google Drive as a storage
 * backend.
 *
 * @package Anibas_File_Manager
 */

namespace Anibas;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * OAuthProvider implementation supplying Google's authorization and token
 * endpoints and default Drive API scopes.
 */
class GoogleDriveOAuthProvider extends OAuthProvider
{
    public function id(): string
    {
        return 'gdrive';
    }

    public function label(): string
    {
        return 'Google Drive';
    }

    protected function authorization_endpoint(): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth';
    }

    protected function token_endpoint(): string
    {
        return 'https://oauth2.googleapis.com/token';
    }

    protected function default_scopes(): array
    {
        return array('https://www.googleapis.com/auth/drive');
    }

    protected function extra_authorization_params(): array
    {
        return array(
            'access_type'            => 'offline',
            'include_granted_scopes' => 'true',
            'prompt'                 => 'consent',
        );
    }

    protected function default_client_credentials(): array
    {
        return array(
            'client_id'     => defined('ANIBAS_FM_GOOGLE_DRIVE_CLIENT_ID') ? (string) ANIBAS_FM_GOOGLE_DRIVE_CLIENT_ID : '',
            'client_secret' => defined('ANIBAS_FM_GOOGLE_DRIVE_CLIENT_SECRET') ? (string) ANIBAS_FM_GOOGLE_DRIVE_CLIENT_SECRET : '',
        );
    }

    public function supports_token_revocation(): bool
    {
        return true;
    }

    public function revoke_tokens(array $settings): array
    {
        $token = trim((string) ($settings['refresh_token'] ?? ''));
        if ($token === '') {
            $token = trim((string) ($settings['access_token'] ?? ''));
        }
        if ($token === '') {
            return array(
                'revoked' => false,
                'message' => __('No saved Google Drive OAuth token was found.', 'anibas-file-manager'),
            );
        }

        $response = wp_remote_post('https://oauth2.googleapis.com/revoke', array(
            'timeout' => 20,
            'headers' => array('Content-Type' => 'application/x-www-form-urlencoded'),
            'body'    => array('token' => $token),
        ));

        if (is_wp_error($response)) {
            throw new \RuntimeException($response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        if ($status === 200) {
            return array(
                'revoked' => true,
                'message' => __('Google Drive token revoked and local connection removed.', 'anibas-file-manager'),
            );
        }
        if ($status === 400 && $this->token_looks_invalid($body)) {
            return array(
                'revoked' => true,
                'message' => __('Google Drive token was already invalid; local connection removed.', 'anibas-file-manager'),
            );
        }

        throw new \RuntimeException($this->error_message($body, $status));
    }
}
