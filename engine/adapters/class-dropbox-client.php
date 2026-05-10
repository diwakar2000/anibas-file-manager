<?php

namespace Anibas;

if ( ! defined( 'ABSPATH' ) ) exit;

class DropboxException extends \RuntimeException {}

class AnibasDropboxClient
{
    private const API_BASE     = 'https://api.dropboxapi.com/2';
    private const CONTENT_BASE = 'https://content.dropboxapi.com/2';
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function rpc(string $endpoint, array $body = [], array $expected = [200]): array
    {
        $response = $this->raw_request(
            'POST',
            self::API_BASE . '/' . ltrim($endpoint, '/'),
            $body === [] ? '{}' : wp_json_encode($body),
            [
                'Authorization' => 'Bearer ' . $this->get_access_token(),
                'Content-Type'  => 'application/json; charset=UTF-8',
            ],
            $expected,
            60
        );

        if ($response['body'] === '') {
            return [];
        }
        $data = json_decode($response['body'], true);
        return is_array($data) ? $data : [];
    }

    public function content(string $endpoint, array $arg, string $body = '', array $headers = [], array $expected = [200]): array
    {
        $headers = array_merge(
            [
                'Authorization'   => 'Bearer ' . $this->get_access_token(),
                'Dropbox-API-Arg' => wp_json_encode($arg),
                'Content-Type'    => 'application/octet-stream',
            ],
            $headers
        );

        return $this->raw_request(
            'POST',
            self::CONTENT_BASE . '/' . ltrim($endpoint, '/'),
            $body,
            $headers,
            $expected,
            25
        );
    }

    public function download(string $path, ?string $range = null): string|false
    {
        $headers = [];
        if ($range !== null) {
            $headers['Range'] = $range;
        }

        try {
            $response = $this->content('/files/download', ['path' => $path], '', $headers, [200, 206]);
            return $response['body'];
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function stream_download(string $path, callable $writer): bool
    {
        if (! function_exists('curl_init')) {
            return false;
        }
        try {
            $ch = curl_init(self::CONTENT_BASE . '/files/download');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $this->get_access_token(),
                'Dropbox-API-Arg: ' . wp_json_encode(['path' => $path]),
                'Content-Type: application/octet-stream',
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 25);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $chunk) use ($writer) {
                $written = $writer($chunk);
                return is_int($written) ? $written : strlen($chunk);
            });
            $result = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            return $result !== false && $error === '' && in_array($status, [200, 206], true);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function upload_small(string $path, string $content): bool
    {
        $this->content('/files/upload', [
            'path'       => $path,
            'mode'       => ['.tag' => 'overwrite'],
            'autorename' => false,
            'mute'       => true,
        ], $content);
        return true;
    }

    public function upload_session_start(string $chunk): string
    {
        $response = $this->content('/files/upload_session/start', ['close' => false], $chunk);
        $data = json_decode((string) ($response['body'] ?? ''), true);
        $session = (string) ($data['session_id'] ?? '');
        if ($session === '') {
            throw new DropboxException('Dropbox did not return an upload session ID.');
        }
        return $session;
    }

    public function upload_session_append(string $session_id, int $offset, string $chunk, bool $close = false): void
    {
        $this->content('/files/upload_session/append_v2', [
            'cursor' => [
                'session_id' => $session_id,
                'offset'     => $offset,
            ],
            'close' => $close,
        ], $chunk);
    }

    public function upload_session_finish(string $session_id, int $offset, string $path, string $chunk): void
    {
        $this->content('/files/upload_session/finish', [
            'cursor' => [
                'session_id' => $session_id,
                'offset'     => $offset,
            ],
            'commit' => [
                'path'       => $path,
                'mode'       => ['.tag' => 'overwrite'],
                'autorename' => false,
                'mute'       => true,
            ],
        ], $chunk);
    }

    public function upload_session_finish_batch(string $session_id, int $offset, string $path): array
    {
        return $this->rpc('/files/upload_session/finish_batch', [
            'entries' => [
                [
                    'cursor' => [
                        'session_id' => $session_id,
                        'offset'     => $offset,
                    ],
                    'commit' => [
                        'path'       => $path,
                        'mode'       => ['.tag' => 'overwrite'],
                        'autorename' => false,
                        'mute'       => true,
                    ],
                ],
            ],
        ]);
    }

    public function upload_session_finish_batch_check(string $async_job_id): array
    {
        return $this->rpc('/files/upload_session/finish_batch/check', [
            'async_job_id' => $async_job_id,
        ]);
    }

    private function raw_request(string $method, string $url, $body, array $headers, array $expected, int $timeout): array
    {
        $response = wp_remote_request($url, [
            'method'      => $method,
            'headers'     => $headers,
            'body'        => $body,
            'timeout'     => $timeout,
            'redirection' => 0,
        ]);

        if (is_wp_error($response)) {
            throw new DropboxException($response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body_string = (string) wp_remote_retrieve_body($response);
        if (! in_array($status, $expected, true)) {
            throw new DropboxException($this->error_message($body_string, $status));
        }

        return [
            'status'  => $status,
            'body'    => $body_string,
            'headers' => $this->response_headers($response),
        ];
    }

    private function get_access_token(): string
    {
        try {
            return (new DropboxOAuthProvider($this->config))->get_access_token();
        } catch (\Throwable $e) {
            throw new DropboxException($e->getMessage());
        }
    }

    private function response_headers($response): array
    {
        $headers = [];
        $raw = wp_remote_retrieve_headers($response);
        if ($raw instanceof \Traversable || is_array($raw)) {
            foreach ($raw as $key => $value) {
                $headers[strtolower((string) $key)] = is_array($value) ? implode(', ', $value) : (string) $value;
            }
        }
        return $headers;
    }

    private function error_message(string $body, int $status): string
    {
        $data = json_decode($body, true);
        if (is_array($data)) {
            if (! empty($data['error_summary'])) {
                return (string) $data['error_summary'];
            }
            if (! empty($data['error_description'])) {
                return (string) $data['error_description'];
            }
            if (! empty($data['error'])) {
                return is_string($data['error']) ? $data['error'] : wp_json_encode($data['error']);
            }
        }
        return 'Dropbox request failed (HTTP ' . $status . ').';
    }
}
