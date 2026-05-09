<?php

namespace Anibas;

if ( ! defined( 'ABSPATH' ) ) exit;

class OneDriveFileSystemAdapter extends FileSystemAdapter
{
    public const COPY_ERROR_CREATING_FILE = 1;
    public const COPY_ERROR_APPENDING_TO_FILE = 2;
    public const COPY_ERROR_DOWNLOADING_CHUNK = 3;
    public const COPY_ERROR_UPLOADING_CHUNK = 4;
    public const COPY_ERROR_SOURCE_NOT_FOUND = 5;
    public const COPY_ERROR_SOURCE_EMPTY = 6;
    public const COPY_ERROR_NO_DATA_RECEIVED = 7;
    public const COPY_ERROR_VERIFICATION_FAILED = 8;
    public const COPY_OPERATION_COMPLETE = 9;
    public const COPY_OPERATION_IN_PROGRESS = 10;

    private AnibasOneDriveClient $client;
    private string $drive_id;
    private string $root_path;
    private int $chunk_size;

    public function __construct(AnibasOneDriveClient $client, string $drive_id = '', string $root_path = '/', int $chunk_size = 10485760)
    {
        $this->client = $client;
        $this->drive_id = trim($drive_id);
        $this->root_path = $this->normalize_path($root_path) ?: '/';
        $this->chunk_size = $this->normalize_chunk_size($chunk_size);
    }

    public function validate_path(string $path): string|false
    {
        return $this->normalize_path($path);
    }

    public function exists(string $path): bool
    {
        return is_array($this->metadata($path));
    }

    public function is_file(string $path): bool
    {
        $meta = $this->metadata($path);
        return is_array($meta) && isset($meta['file']);
    }

    public function is_dir(string $path): bool
    {
        $meta = $this->metadata($path);
        return is_array($meta) && isset($meta['folder']);
    }

    public function mkdir(string $path): bool
    {
        $path = $this->normalize_path($path);
        if ($path === false || $path === '/') {
            return $path === '/';
        }
        $existing = $this->metadata($path);
        if (is_array($existing)) {
            return isset($existing['folder']);
        }

        [$parent, $name] = $this->parent_and_name($path);
        if ($parent === false || $name === '') {
            return false;
        }

        try {
            $this->client->request('POST', $this->children_endpoint($parent), [], [
                'name' => $name,
                'folder' => new \stdClass(),
                '@microsoft.graph.conflictBehavior' => 'fail',
            ]);
            return true;
        } catch (\Throwable $e) {
            ActivityLogger::get_instance()->log_message('OneDrive mkdir failed: ' . $e->getMessage());
            return false;
        }
    }

    public function scandir(string $path): array
    {
        $data = $this->listDirectory($path);
        return array_map(static fn($item) => $item['name'], $data['items'] ?? []);
    }

    public function listDirectory(string $path, int $page = 1, int $pageSize = 100): array
    {
        $page = max(1, $page);
        $pageSize = min(1000, max(1, $pageSize));
        $cursor = null;
        $effective_page = 1;
        $result = ['entries' => [], 'next_cursor' => ['done' => true], 'has_more' => false];
        for ($current = 1; $current <= $page; $current++) {
            $effective_page = $current;
            $result = $this->iterateDirectory($path, $cursor, $pageSize);
            if ($current === $page || empty($result['has_more'])) {
                break;
            }
            $cursor = $result['next_cursor'] ?? null;
        }
        return [
            'items'       => $result['entries'] ?? [],
            'total_items' => (($effective_page - 1) * $pageSize) + count($result['entries'] ?? []) + (! empty($result['has_more']) ? 1 : 0),
            'page'        => $effective_page,
            'page_size'   => $pageSize,
            'has_more'    => ! empty($result['has_more']),
        ];
    }

    public function iterateDirectory(string $path, ?array $cursor = null, int $maxItems = 1000, array $options = []): array
    {
        try {
            if (! empty($cursor['next_link'])) {
                $data = $this->client->request('GET', $cursor['next_link']);
            } else {
                $data = $this->client->request('GET', $this->children_endpoint($path), ['$top' => min(1000, max(1, $maxItems))]);
            }
        } catch (\Throwable $e) {
            return ['entries' => [], 'next_cursor' => ['done' => true], 'has_more' => false];
        }

        $parent = $this->normalize_path($path) ?: '/';
        $entries = [];
        foreach ($data['value'] ?? [] as $entry) {
            $entries[] = $this->format_item($entry, $parent);
        }
        $next = $data['@odata.nextLink'] ?? null;
        return [
            'entries'     => $entries,
            'next_cursor' => $next ? ['next_link' => $next] : ['done' => true],
            'has_more'    => (bool) $next,
        ];
    }

    public function rmdir(string $path): bool
    {
        return $this->unlink($path);
    }

    public function copy(string $source, string $target): bool
    {
        return $this->copyFileInChunks($source, $target) === self::COPY_OPERATION_COMPLETE;
    }

    public function copyFileInChunks($source, $target, ?int $chunk_size = null, $bytes_copied = 0): int
    {
        $source = $this->normalize_path((string) $source);
        $target = $this->normalize_path((string) $target);
        if ($source === false || $target === false) {
            return self::COPY_ERROR_SOURCE_NOT_FOUND;
        }
        if ($source === $target) {
            return self::COPY_OPERATION_COMPLETE;
        }

        $source_meta = $this->metadata($source);
        if (! is_array($source_meta) || isset($source_meta['folder'])) {
            return self::COPY_ERROR_SOURCE_NOT_FOUND;
        }

        [$parent, $name] = $this->parent_and_name($target);
        $parent_meta = $this->metadata($parent);
        if (! is_array($parent_meta) || $name === '') {
            return self::COPY_ERROR_CREATING_FILE;
        }
        $target_meta = $this->metadata($target);
        if (is_array($target_meta) && isset($target_meta['folder'])) {
            return self::COPY_ERROR_CREATING_FILE;
        }

        $state_key = 'anibas_onedrive_copy_' . md5($source . '|' . $target);
        $state = get_option($state_key, []);
        $size = (int) ($source_meta['size'] ?? 0);

        if ($size === 0) {
            delete_option($state_key);
            return $this->put_contents($target, '')
                ? self::COPY_OPERATION_COMPLETE
                : self::COPY_ERROR_CREATING_FILE;
        }

        try {
            $new_session = false;
            if (empty($state['upload_url']) || (int) ($state['size'] ?? -1) !== $size) {
                $state = [
                    'upload_url' => $this->client->start_upload_session($this->upload_session_endpoint($target), $name),
                    'offset'     => 0,
                    'size'       => $size,
                ];
                update_option($state_key, $state, false);
                $new_session = true;
            }

            $offset = $new_session ? 0 : max((int) $bytes_copied, (int) ($state['offset'] ?? 0));
            if ($offset >= $size) {
                delete_option($state_key);
                return self::COPY_OPERATION_COMPLETE;
            }

            $chunk_size = $this->normalize_chunk_size($chunk_size ?? $this->chunk_size);
            $end = min($offset + $chunk_size - 1, $size - 1);
            $chunk = $this->client->download($this->content_endpoint($source), "bytes={$offset}-{$end}");
            if ($chunk === false || $chunk === '') {
                return self::COPY_ERROR_DOWNLOADING_CHUNK;
            }

            $result = $this->client->upload_session_chunk($state['upload_url'], $chunk, $offset, $offset + strlen($chunk) - 1, $size);
            $state['offset'] = (int) $result['bytes_uploaded'];

            if (! empty($result['complete'])) {
                delete_option($state_key);
                return self::COPY_OPERATION_COMPLETE;
            }

            update_option($state_key, $state, false);
            return self::COPY_OPERATION_IN_PROGRESS;
        } catch (\Throwable $e) {
            ActivityLogger::get_instance()->log_message('OneDrive copy failed: ' . $e->getMessage());
            return self::COPY_ERROR_UPLOADING_CHUNK;
        }
    }

    public function getCopyProgress($source, $target): array
    {
        $size = $this->get_file_size((string) $source);
        $size = $size !== false ? $size : 0;
        $source = $this->normalize_path((string) $source);
        $target = $this->normalize_path((string) $target);
        $state_key = $source !== false && $target !== false ? 'anibas_onedrive_copy_' . md5($source . '|' . $target) : '';
        $state = $state_key !== '' ? get_option($state_key, []) : [];
        $bytes = min($size, max(0, (int) ($state['offset'] ?? $size)));
        return [
            'file_size'         => $size,
            'bytes_copied'      => $bytes,
            'progress_percent'  => $size > 0 ? (int) floor(($bytes / $size) * 100) : 100,
            'is_complete'       => $bytes >= $size,
            'next_bytes_copied' => $bytes,
        ];
    }

    public function move(string $source, string $target): bool
    {
        $source = $this->normalize_path($source);
        $target = $this->normalize_path($target);
        if ($source === false || $target === false) {
            return false;
        }
        if ($source === $target) {
            return true;
        }

        [$parent, $name] = $this->parent_and_name($target);
        $parent_meta = $this->metadata($parent);
        if (! is_array($parent_meta) || $name === '') {
            return false;
        }

        try {
            $this->client->request('PATCH', $this->item_endpoint($source), [], [
                'name' => $name,
                'parentReference' => ['id' => $parent_meta['id']],
            ]);
            return true;
        } catch (\Throwable $e) {
            ActivityLogger::get_instance()->log_message('OneDrive move failed: ' . $e->getMessage());
            return false;
        }
    }

    public function unlink(string $path): bool
    {
        $path = $this->normalize_path($path);
        if ($path === false || $path === '/') {
            return false;
        }
        try {
            $this->client->request('DELETE', $this->item_endpoint($path));
            return true;
        } catch (\Throwable $e) {
            ActivityLogger::get_instance()->log_message('OneDrive delete failed: ' . $e->getMessage());
            return false;
        }
    }

    public function put_contents(string $path, string $content): bool
    {
        $path = $this->normalize_path($path);
        if ($path === false || $path === '/') {
            return false;
        }
        try {
            $this->client->request('PUT', $this->content_endpoint($path), [], $content, ['Content-Type' => 'application/octet-stream']);
            return true;
        } catch (\Throwable $e) {
            ActivityLogger::get_instance()->log_message('OneDrive put_contents failed: ' . $e->getMessage());
            return false;
        }
    }

    public function append_contents(string $path, string $content): bool
    {
        return false;
    }

    public function get_contents(string $path): string|false
    {
        return $this->client->download($this->content_endpoint($path));
    }

    public function get_temporary_link(string $path, int $duration = 3600): string|false
    {
        $meta = $this->metadata($path);
        return is_array($meta) ? ($meta['@microsoft.graph.downloadUrl'] ?? false) : false;
    }

    public function get_size(string $path): int|false
    {
        return $this->get_file_size($path);
    }

    public function get_file_size(string $path): int|false
    {
        $meta = $this->metadata($path);
        return is_array($meta) && isset($meta['size']) ? (int) $meta['size'] : false;
    }

    public function read_chunk(string $path, int $offset, int $length): string|false
    {
        $end = max($offset, $offset + $length - 1);
        return $this->client->download($this->content_endpoint($path), "bytes={$offset}-{$end}");
    }

    public function stream_contents(string $path): bool
    {
        return $this->client->stream_download($this->content_endpoint($path), static function ($chunk) {
            echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary stream
            flush();
            return strlen($chunk);
        });
    }

    public function getDetails(string $path): array|false
    {
        $meta = $this->metadata($path);
        if (! is_array($meta)) {
            return false;
        }
        $is_dir = isset($meta['folder']);
        return [
            'name'             => $meta['name'] ?? basename($path),
            'path'             => $this->normalize_path($path) ?: '/',
            'is_folder'        => $is_dir,
            'size'             => $is_dir ? 0 : (int) ($meta['size'] ?? 0),
            'last_modified'    => ! empty($meta['lastModifiedDateTime']) ? strtotime($meta['lastModifiedDateTime']) : null,
            'created'          => ! empty($meta['createdDateTime']) ? strtotime($meta['createdDateTime']) : null,
            'permission'       => null,
            'permission_octal' => null,
            'owner'            => null,
            'group'            => null,
            'extension'        => $is_dir ? '' : pathinfo((string) ($meta['name'] ?? ''), PATHINFO_EXTENSION),
            'mime_type'        => $meta['file']['mimeType'] ?? null,
        ];
    }

    public function is_empty(string $path): bool
    {
        try {
            $data = $this->client->request('GET', $this->children_endpoint($path), ['$top' => 1]);
            return empty($data['value']);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function download_to_local(string $remote_path, string $local_path): bool
    {
        return $this->download_to_local_chunked($remote_path, $local_path, 0, ANIBAS_FM_CHUNK_SIZE_MAX)['status'] === self::COPY_OPERATION_COMPLETE;
    }

    public function upload_from_local(string $local_path, string $remote_path): bool
    {
        return $this->upload_from_local_chunked($local_path, $remote_path, 0, $this->chunk_size)['status'] === self::COPY_OPERATION_COMPLETE;
    }

    public function supports_chunked_transfer(): bool
    {
        return true;
    }

    public function requires_local_upload_assembly(): bool
    {
        return true;
    }

    public function download_to_local_chunked(string $remote_path, string $local_path, int $offset = 0, int $chunk_size = 2097152): array
    {
        $size = $this->get_file_size($remote_path);
        if ($size === false) {
            return ['status' => self::COPY_ERROR_SOURCE_NOT_FOUND, 'bytes_copied' => $offset];
        }
        if ($size === 0) {
            $dir = dirname($local_path);
            if (! is_dir($dir)) wp_mkdir_p($dir);
            return @touch($local_path)
                ? ['status' => self::COPY_OPERATION_COMPLETE, 'bytes_copied' => 0]
                : ['status' => self::COPY_ERROR_CREATING_FILE, 'bytes_copied' => 0];
        }
        if ($offset >= $size) {
            return ['status' => self::COPY_OPERATION_COMPLETE, 'bytes_copied' => $size];
        }

        $chunk_size = $this->normalize_chunk_size($chunk_size);
        $end = min($offset + $chunk_size - 1, $size - 1);
        $chunk = $this->client->download($this->content_endpoint($remote_path), "bytes={$offset}-{$end}");
        if ($chunk === false || $chunk === '') {
            return ['status' => self::COPY_ERROR_DOWNLOADING_CHUNK, 'bytes_copied' => $offset];
        }
        $dir = dirname($local_path);
        if (! is_dir($dir)) wp_mkdir_p($dir);
        $fp = fopen($local_path, $offset === 0 ? 'wb' : 'ab');
        if (! $fp) {
            return ['status' => self::COPY_ERROR_CREATING_FILE, 'bytes_copied' => $offset];
        }
        fwrite($fp, $chunk);
        fclose($fp);
        $new_offset = $offset + strlen($chunk);
        return ['status' => $new_offset >= $size ? self::COPY_OPERATION_COMPLETE : self::COPY_OPERATION_IN_PROGRESS, 'bytes_copied' => $new_offset];
    }

    public function upload_from_local_chunked(string $local_path, string $remote_path, int $offset = 0, int $chunk_size = 2097152): array
    {
        if (! is_file($local_path)) {
            return ['status' => self::COPY_ERROR_SOURCE_NOT_FOUND, 'bytes_copied' => 0];
        }
        $size = filesize($local_path);
        if ($size === false) {
            return ['status' => self::COPY_ERROR_SOURCE_NOT_FOUND, 'bytes_copied' => 0];
        }
        if ((int) $size === 0) {
            return $this->put_contents($remote_path, '')
                ? ['status' => self::COPY_OPERATION_COMPLETE, 'bytes_copied' => 0]
                : ['status' => self::COPY_ERROR_CREATING_FILE, 'bytes_copied' => 0];
        }

        $remote_path = $this->normalize_path($remote_path);
        if ($remote_path === false || $remote_path === '/') {
            return ['status' => self::COPY_ERROR_CREATING_FILE, 'bytes_copied' => $offset];
        }
        if ($offset <= 0) {
            [$parent, $name] = $this->parent_and_name($remote_path);
            $parent_meta = $this->metadata($parent);
            $target_meta = $this->metadata($remote_path);
            if (! is_array($parent_meta) || $name === '' || (is_array($target_meta) && isset($target_meta['folder']))) {
                return ['status' => self::COPY_ERROR_CREATING_FILE, 'bytes_copied' => $offset];
            }
        }
        $state_key = 'anibas_onedrive_upload_' . md5($local_path . '|' . $remote_path);
        $state = get_option($state_key, []);

        try {
            $new_session = false;
            if (empty($state['upload_url']) || (int) ($state['size'] ?? -1) !== (int) $size) {
                $state = [
                    'upload_url' => $this->client->start_upload_session($this->upload_session_endpoint($remote_path), basename($remote_path)),
                    'offset'     => 0,
                    'size'       => (int) $size,
                ];
                update_option($state_key, $state, false);
                $new_session = true;
            }

            $offset = $new_session ? 0 : max((int) $offset, (int) ($state['offset'] ?? 0));
            $chunk_size = $this->normalize_chunk_size($chunk_size);
            $read_size = min($chunk_size, $size - $offset);
            $fp = fopen($local_path, 'rb');
            if (! $fp) {
                return ['status' => self::COPY_ERROR_DOWNLOADING_CHUNK, 'bytes_copied' => $offset];
            }
            fseek($fp, $offset);
            $chunk = fread($fp, $read_size);
            fclose($fp);
            if ($chunk === false || $chunk === '') {
                return ['status' => self::COPY_ERROR_NO_DATA_RECEIVED, 'bytes_copied' => $offset];
            }
            $end = $offset + strlen($chunk) - 1;
            $result = $this->client->upload_session_chunk($state['upload_url'], $chunk, $offset, $end, (int) $size);
            $state['offset'] = (int) $result['bytes_uploaded'];
            if (! empty($result['complete'])) {
                delete_option($state_key);
                return ['status' => self::COPY_OPERATION_COMPLETE, 'bytes_copied' => $size];
            }
            update_option($state_key, $state, false);
            return ['status' => self::COPY_OPERATION_IN_PROGRESS, 'bytes_copied' => $state['offset']];
        } catch (\Throwable $e) {
            ActivityLogger::get_instance()->log_message('OneDrive upload chunk failed: ' . $e->getMessage());
            return ['status' => self::COPY_ERROR_UPLOADING_CHUNK, 'bytes_copied' => $offset];
        }
    }

    private function metadata(string $path): array|false
    {
        try {
            return $this->client->request('GET', $this->item_endpoint($path));
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function format_item(array $entry, string $parent): array
    {
        $is_dir = isset($entry['folder']);
        $name = (string) ($entry['name'] ?? '');
        $storage_id = (string) ($entry['id'] ?? '');
        $path = $parent === '/' ? '/' . $name : rtrim($parent, '/') . '/' . $name;
        $item = [
            'name'          => $name,
            'path'          => $path,
            'storage_id'    => $storage_id,
            'ui_key'        => $storage_id !== '' ? 'onedrive:' . $storage_id : 'onedrive-path:' . $path,
            'is_folder'     => $is_dir,
            'permission'    => 0,
            'last_modified' => ! empty($entry['lastModifiedDateTime']) ? strtotime($entry['lastModifiedDateTime']) : 0,
            'has_children'  => $is_dir ? null : false,
            'files'         => [],
        ];
        if (! $is_dir) {
            $item['filename'] = $name;
            $item['filesize'] = (int) ($entry['size'] ?? 0);
            $item['file_type'] = 'File';
        }
        return $item;
    }

    private function item_endpoint(string $path): string
    {
        $remote = $this->remote_path($path);
        $base = $this->drive_base();
        return $remote === '/' ? $base . '/root' : $base . '/root:/' . $this->encode_path($remote);
    }

    private function children_endpoint(string $path): string
    {
        $remote = $this->remote_path($path);
        $base = $this->drive_base();
        return $remote === '/' ? $base . '/root/children' : $base . '/root:/' . $this->encode_path($remote) . ':/children';
    }

    private function content_endpoint(string $path): string
    {
        return $this->item_endpoint($path) . ':/content';
    }

    private function upload_session_endpoint(string $path): string
    {
        return $this->item_endpoint($path) . ':/createUploadSession';
    }

    private function drive_base(): string
    {
        return $this->drive_id !== '' ? '/drives/' . rawurlencode($this->drive_id) : '/me/drive';
    }

    private function remote_path(string $path): string
    {
        $path = $this->normalize_path($path) ?: '/';
        if ($this->root_path === '/') {
            return $path;
        }
        return $this->normalize_path(rtrim($this->root_path, '/') . '/' . ltrim($path, '/')) ?: '/';
    }

    private function parent_and_name(string $path): array
    {
        $path = $this->normalize_path($path);
        if ($path === false || $path === '/') {
            return [false, ''];
        }
        $parent = dirname($path);
        return [$parent === '.' ? '/' : $parent, basename($path)];
    }

    private function normalize_path(string $path): string|false
    {
        $path = str_replace(["\0", '\\'], ['', '/'], $path);
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') continue;
            if ($segment === '..') {
                if (empty($segments)) return false;
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }
        return '/' . implode('/', $segments);
    }

    private function encode_path(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));
    }

    private function normalize_chunk_size(int $chunk_size): int
    {
        if ($chunk_size <= 0) $chunk_size = ANIBAS_FM_DEFAULT_CHUNK_SIZE;
        $graph_unit = 327680;
        $chunk_size = max(ANIBAS_FM_CHUNK_SIZE_MIN, min(ANIBAS_FM_CHUNK_SIZE_MAX, $chunk_size));
        $aligned = (int) (ceil($chunk_size / $graph_unit) * $graph_unit);
        if ($aligned > ANIBAS_FM_CHUNK_SIZE_MAX) {
            $aligned = intdiv(ANIBAS_FM_CHUNK_SIZE_MAX, $graph_unit) * $graph_unit;
        }
        return max($graph_unit, $aligned);
    }
}
