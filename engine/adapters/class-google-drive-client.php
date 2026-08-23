<?php

/**
 * Low-level Google Drive REST API v3 client handling authenticated
 * requests, OAuth token refresh, and resumable/multipart upload sessions.
 *
 * @package Anibas_File_Manager
 */

namespace Anibas;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Exception thrown for Google Drive API errors.
 */
class GoogleDriveException extends \RuntimeException {}

/**
 * Thin wrapper around the Google Drive v3 REST API used by
 * GoogleDriveFileSystemAdapter, handling request signing/authorization,
 * URL building, and file upload/download endpoints.
 */
class AnibasGoogleDriveClient
{
    private const API_BASE    = 'https://www.googleapis.com/drive/v3';
    private const UPLOAD_BASE = 'https://www.googleapis.com/upload/drive/v3';
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function supports_all_drives(): bool
    {
        return ! empty($this->config['supports_all_drives']);
    }

    public function request(string $method, string $path, array $query = [], $body = null, array $headers = []): array
    {
        $url = $this->build_url(self::API_BASE, $path, $query);
        $headers['Authorization'] = 'Bearer ' . $this->get_access_token();
        if (is_array($body)) {
            $body = wp_json_encode($body);
            $headers['Content-Type'] = $headers['Content-Type'] ?? 'application/json; charset=UTF-8';
        }

        $response = $this->raw_request($method, $url, $body, $headers, [200, 201, 204]);
        if ($response['body'] === '') {
            return [];
        }

        $data = json_decode($response['body'], true);
        return is_array($data) ? $data : [];
    }

    public function upload_request(string $method, string $path, array $query = [], $body = null, array $headers = [], array $expected = [200, 201]): array
    {
        $url = $this->build_url(self::UPLOAD_BASE, $path, $query);
        $headers['Authorization'] = 'Bearer ' . $this->get_access_token();
        if (is_array($body)) {
            $body = wp_json_encode($body);
            $headers['Content-Type'] = $headers['Content-Type'] ?? 'application/json; charset=UTF-8';
        }

        return $this->raw_request($method, $url, $body, $headers, $expected);
    }

    public function start_resumable_upload(array $metadata, string $mime_type, int $size, ?string $file_id = null): string
    {
        $path   = $file_id ? '/files/' . rawurlencode($file_id) : '/files';
        $method = $file_id ? 'PATCH' : 'POST';
        $query  = ['uploadType' => 'resumable', 'fields' => 'id,name,size,mimeType,modifiedTime,webContentLink'];
        if ($this->supports_all_drives()) {
            $query['supportsAllDrives'] = 'true';
        }

        $response = $this->upload_request(
            $method,
            $path,
            $query,
            wp_json_encode($metadata),
            [
                'Content-Type'            => 'application/json; charset=UTF-8',
                'X-Upload-Content-Type'   => $mime_type,
                'X-Upload-Content-Length' => (string) $size,
            ],
            [200, 201]
        );

        $location = $response['headers']['location'] ?? '';
        if ($location === '') {
            throw new GoogleDriveException('Google Drive did not return an upload session URL.');
        }

        return $location;
    }

    public function upload_resumable_chunk(string $session_url, string $chunk, int $start, int $end, int $total): array
    {
        $response = $this->raw_request(
            'PUT',
            $session_url,
            $chunk,
            [
                'Authorization'  => 'Bearer ' . $this->get_access_token(),
                'Content-Length' => (string) strlen($chunk),
                'Content-Range'  => "bytes {$start}-{$end}/{$total}",
            ],
            [200, 201, 308],
            25
        );

        if ($response['status'] === 308) {
            $range = $response['headers']['range'] ?? '';
            $next  = $end + 1;
            if (preg_match('/bytes=0-(\d+)/', $range, $matches)) {
                $next = ((int) $matches[1]) + 1;
            }
            return ['complete' => false, 'bytes_uploaded' => $next];
        }

        return ['complete' => true, 'bytes_uploaded' => $total];
    }

    public function download_media(string $file_id, ?string $range = null): string|false
    {
        $query = ['alt' => 'media'];
        if ($this->supports_all_drives()) {
            $query['supportsAllDrives'] = 'true';
        }

        $headers = [];
        if ($range !== null) {
            $headers['Range'] = $range;
        }

        try {
            $url = $this->build_url(self::API_BASE, '/files/' . rawurlencode($file_id), $query);
            $headers['Authorization'] = 'Bearer ' . $this->get_access_token();
            $response = $this->raw_request('GET', $url, null, $headers, [200, 206], 25);
            return $response['body'];
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function download_media_to_file(string $file_id, string $local_path): bool
    {
        $dir = dirname($local_path);
        if (! is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        $fp = fopen($local_path, 'wb');
        if (! $fp) {
            return false;
        }

        $ok = $this->curl_media($file_id, function ($chunk) use ($fp) {
            return fwrite($fp, $chunk);
        });

        fclose($fp);
        if (! $ok && file_exists($local_path)) {
            wp_delete_file($local_path);
        }
        return $ok;
    }

    public function stream_media(string $file_id): bool
    {
        return $this->curl_media($file_id, function ($chunk) {
            echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary stream
            flush();
            return strlen($chunk);
        });
    }

    private function curl_media(string $file_id, callable $writer): bool
    {
        // Uses cURL rather than wp_remote_request() intentionally: this streams the
        // response body through a CURLOPT_WRITEFUNCTION callback in bounded chunks so
        // large files never get buffered fully in memory. wp_remote_request()/WP_Http
        // has no equivalent streaming-callback API (only 'stream' => true to a fixed
        // file path), so it cannot be substituted here without reading whole files
        // into memory first.
        if (! function_exists('curl_init')) {
            return false;
        }

        $query = ['alt' => 'media'];
        if ($this->supports_all_drives()) {
            $query['supportsAllDrives'] = 'true';
        }
        $url = $this->build_url(self::API_BASE, '/files/' . rawurlencode($file_id), $query);

        try {
            $token = $this->get_access_token();
        } catch (\Throwable $e) {
            return false;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token]);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 25);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $chunk) use ($writer) {
            return $writer($chunk);
        });

        $result = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        return $result !== false && $error === '' && $status >= 200 && $status < 300;
    }

    private function raw_request(string $method, string $url, $body, array $headers, array $expected, int $timeout = 30): array
    {
        $args = [
            'method'      => $method,
            'headers'     => $headers,
            'timeout'     => $timeout,
            'redirection' => 0,
        ];

        if ($body !== null) {
            $args['body'] = $body;
        }

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            throw new GoogleDriveException($response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body_string = (string) wp_remote_retrieve_body($response);
        $headers_out = $this->response_headers($response);

        if (! in_array($status, $expected, true)) {
            throw new GoogleDriveException($this->error_message($body_string, $status));
        }

        return [
            'status'  => $status,
            'body'    => $body_string,
            'headers' => $headers_out,
        ];
    }

    private function get_access_token(): string
    {
        try {
            return (new GoogleDriveOAuthProvider($this->config))->get_access_token();
        } catch (\Throwable $e) {
            throw new GoogleDriveException($e->getMessage());
        }
    }

    private function build_url(string $base, string $path, array $query = []): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            $url = $path;
        } else {
            $url = rtrim($base, '/') . '/' . ltrim($path, '/');
        }

        if (! empty($query)) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return $url;
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
        $location = wp_remote_retrieve_header($response, 'location');
        if ($location !== '') {
            $headers['location'] = $location;
        }
        $range = wp_remote_retrieve_header($response, 'range');
        if ($range !== '') {
            $headers['range'] = $range;
        }
        return $headers;
    }

    private function error_message(string $body, int $status): string
    {
        $data = json_decode($body, true);
        if (is_array($data)) {
            if (! empty($data['error']['message'])) {
                return (string) $data['error']['message'];
            }
            if (! empty($data['error_description'])) {
                return (string) $data['error_description'];
            }
            if (! empty($data['error'])) {
                return is_string($data['error']) ? $data['error'] : wp_json_encode($data['error']);
            }
        }
        return 'Google Drive request failed (HTTP ' . $status . ').';
    }
}
