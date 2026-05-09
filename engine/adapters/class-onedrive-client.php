<?php

namespace Anibas;

if ( ! defined( 'ABSPATH' ) ) exit;

class OneDriveException extends \RuntimeException {}

class AnibasOneDriveClient
{
    private const GRAPH_BASE = 'https://graph.microsoft.com/v1.0';

    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function request(string $method, string $path, array $query = [], $body = null, array $headers = [], array $expected = [200, 201, 204]): array
    {
        $response = $this->request_raw($method, $path, $query, $body, $headers, $expected);
        if ($response['body'] === '') {
            return [];
        }
        $data = json_decode($response['body'], true);
        return is_array($data) ? $data : [];
    }

    public function request_raw(string $method, string $path, array $query = [], $body = null, array $headers = [], array $expected = [200, 201, 204]): array
    {
        $url = $this->build_url(self::GRAPH_BASE, $path, $query);
        $headers['Authorization'] = 'Bearer ' . $this->get_access_token();
        if (is_array($body)) {
            $body = wp_json_encode($body);
            $headers['Content-Type'] = $headers['Content-Type'] ?? 'application/json; charset=UTF-8';
        }

        return $this->raw_request($method, $url, $body, $headers, $expected, 60, 0);
    }

    public function download(string $path, ?string $range = null): string|false
    {
        $headers = [];
        if ($range !== null) {
            $headers['Range'] = $range;
        }
        try {
            $url = $this->build_url(self::GRAPH_BASE, $path);
            $headers['Authorization'] = 'Bearer ' . $this->get_access_token();
            $response = $this->raw_request('GET', $url, null, $headers, [200, 206], 25, 5);
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
            $url = $this->build_url(self::GRAPH_BASE, $path);
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $this->get_access_token()]);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
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
            return $result !== false && $error === '' && $status >= 200 && $status < 300;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function start_upload_session(string $path, string $name): string
    {
        $data = $this->request('POST', $path, [], [
            'item' => [
                '@microsoft.graph.conflictBehavior' => 'replace',
                'name' => $name,
            ],
        ]);
        $url = $data['uploadUrl'] ?? '';
        if ($url === '') {
            throw new OneDriveException('OneDrive did not return an upload session URL.');
        }
        return $url;
    }

    public function upload_session_chunk(string $upload_url, string $chunk, int $start, int $end, int $total): array
    {
        $response = $this->raw_request(
            'PUT',
            $upload_url,
            $chunk,
            [
                'Content-Length' => (string) strlen($chunk),
                'Content-Range'  => "bytes {$start}-{$end}/{$total}",
            ],
            [200, 201, 202],
            25,
            0
        );

        if ($response['status'] === 202) {
            $data = json_decode($response['body'], true);
            $next = $end + 1;
            if (is_array($data) && ! empty($data['nextExpectedRanges'][0])) {
                $range = (string) $data['nextExpectedRanges'][0];
                $next = (int) strtok($range, '-');
            }
            return ['complete' => false, 'bytes_uploaded' => $next];
        }

        return ['complete' => true, 'bytes_uploaded' => $total];
    }

    private function raw_request(string $method, string $url, $body, array $headers, array $expected, int $timeout, int $redirection): array
    {
        $args = [
            'method'      => $method,
            'headers'     => $headers,
            'timeout'     => $timeout,
            'redirection' => $redirection,
        ];
        if ($body !== null) {
            $args['body'] = $body;
        }

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            throw new OneDriveException($response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body_string = (string) wp_remote_retrieve_body($response);
        $headers_out = $this->response_headers($response);
        if (! in_array($status, $expected, true)) {
            throw new OneDriveException($this->error_message($body_string, $status));
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
            return (new OneDriveOAuthProvider($this->config))->get_access_token();
        } catch (\Throwable $e) {
            throw new OneDriveException($e->getMessage());
        }
    }

    private function build_url(string $base, string $path, array $query = []): string
    {
        $url = str_starts_with($path, 'http://') || str_starts_with($path, 'https://')
            ? $path
            : rtrim($base, '/') . '/' . ltrim($path, '/');
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
        }
        return 'OneDrive request failed (HTTP ' . $status . ').';
    }
}
