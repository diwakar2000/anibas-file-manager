<?php

namespace Anibas;

if ( ! defined( 'ABSPATH' ) ) exit;

class GoogleDriveFileSystemAdapter extends FileSystemAdapter
{
    private const FOLDER_MIME = 'application/vnd.google-apps.folder';
    private const INTERNAL_ID_PATH_PREFIX = '/.anibas-drive-id/';

    public const COPY_NO_ERROR = 0;
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

    private AnibasGoogleDriveClient $client;
    private string $root_folder_id;
    private int $chunk_size;
    private array $path_cache = [];

    public function __construct(AnibasGoogleDriveClient $client, string $root_folder_id = 'root', int $chunk_size = 10485760)
    {
        $this->client = $client;
        $this->root_folder_id = trim($root_folder_id) !== '' ? trim($root_folder_id) : 'root';
        $this->chunk_size = max(262144, $this->normalize_chunk_size($chunk_size));
    }

    public function validate_path(string $path): string|false
    {
        return $this->normalize_path($path);
    }

    public function path_from_storage_id(string $storage_id, string $fallback_path = ''): string|false
    {
        $storage_id = trim($storage_id);
        if (! $this->valid_storage_id($storage_id)) {
            return false;
        }

        $fallback = $this->normalize_path($fallback_path);
        if ($fallback === false) {
            return false;
        }

        $parent_path = dirname($fallback);
        if ($parent_path === '.' || $parent_path === '\\') {
            $parent_path = '/';
        }

        $parent = $this->resolve_path($parent_path, 'folder');
        if (! is_array($parent)) {
            return false;
        }

        $meta = $this->get_file_by_id($storage_id);
        if (! is_array($meta) || ! in_array($parent['id'], $meta['parents'] ?? [], true)) {
            return false;
        }
        if ((string) ($meta['name'] ?? '') !== basename($fallback)) {
            return false;
        }

        $parent_id = (string) $parent['id'];
        return self::INTERNAL_ID_PATH_PREFIX
            . rawurlencode($storage_id) . '/'
            . rawurlencode($parent_id) . '/'
            . $this->storage_id_signature($storage_id, $parent_id);
    }

    public function exists(string $path): bool
    {
        return $this->resolve_path($path) !== false;
    }

    public function is_file(string $path): bool
    {
        $meta = $this->resolve_path($path);
        return is_array($meta) && ($meta['mimeType'] ?? '') !== self::FOLDER_MIME;
    }

    public function is_dir(string $path): bool
    {
        $meta = $this->resolve_path($path);
        return is_array($meta) && ($meta['mimeType'] ?? '') === self::FOLDER_MIME;
    }

    public function mkdir(string $path): bool
    {
        $normalized = $this->normalize_path($path);
        if ($normalized === false || $normalized === '/') {
            return $normalized === '/';
        }

        $existing = $this->resolve_path($normalized);
        if (is_array($existing)) {
            return ($existing['mimeType'] ?? '') === self::FOLDER_MIME;
        }

        [$parent_id, $name] = $this->parent_id_and_name($normalized);
        if ($parent_id === false || $name === '') {
            return false;
        }

        $created = $this->client->request('POST', '/files', $this->drive_query(['fields' => $this->fields()]), [
            'name'     => $name,
            'mimeType' => self::FOLDER_MIME,
            'parents'  => [$parent_id],
        ], ['Content-Type' => 'application/json; charset=UTF-8']);

        $this->path_cache[$normalized] = $created;
        return ! empty($created['id']);
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
        $normalized = $this->normalize_path($path);
        $folder = $this->resolve_path($normalized ?: '/', 'folder');
        if (! is_array($folder)) {
            return ['entries' => [], 'next_cursor' => ['done' => true], 'has_more' => false];
        }

        $page_token = $cursor['page_token'] ?? null;
        $page = $this->list_children_page($folder['id'], $page_token, min(1000, max(1, $maxItems)));
        $entries = [];
        foreach ($page['files'] ?? [] as $file) {
            $entries[] = $this->format_item($file, $normalized ?: '/');
        }

        $next = $page['nextPageToken'] ?? null;
        return [
            'entries'     => $entries,
            'next_cursor' => $next ? ['page_token' => $next] : ['done' => true],
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
        $normalized_source = $this->normalize_path((string) $source);
        $normalized_target = $this->normalize_path((string) $target);
        if ($normalized_source !== false && $normalized_source === $normalized_target) {
            return self::COPY_OPERATION_COMPLETE;
        }

        $source_meta = $this->resolve_path((string) $source, 'file');
        if (! is_array($source_meta)) {
            return self::COPY_ERROR_SOURCE_NOT_FOUND;
        }

        $existing = $this->resolve_path((string) $target);
        if (is_array($existing)) {
            if (($existing['mimeType'] ?? '') === self::FOLDER_MIME) {
                return self::COPY_ERROR_CREATING_FILE;
            }
            if (! $this->unlink((string) $target)) {
                return self::COPY_ERROR_CREATING_FILE;
            }
        }

        [$parent_id, $name] = $this->parent_id_and_name((string) $target);
        if ($parent_id === false || $name === '') {
            return self::COPY_ERROR_CREATING_FILE;
        }

        try {
            $this->client->request(
                'POST',
                '/files/' . rawurlencode($source_meta['id']) . '/copy',
                $this->drive_query(['fields' => $this->fields()]),
                ['name' => $name, 'parents' => [$parent_id]],
                ['Content-Type' => 'application/json; charset=UTF-8']
            );
            return self::COPY_OPERATION_COMPLETE;
        } catch (\Throwable $e) {
            ActivityLogger::get_instance()->log_message('Google Drive copy failed: ' . $e->getMessage());
            return self::COPY_ERROR_DOWNLOADING_CHUNK;
        }
    }

    public function getCopyProgress($source, $target): array
    {
        $size = $this->get_file_size((string) $source);
        $size = $size !== false ? $size : 0;
        return [
            'file_size'          => $size,
            'bytes_copied'       => $size,
            'progress_percent'   => 100,
            'is_complete'        => true,
            'next_bytes_copied'  => $size,
        ];
    }

    public function move(string $source, string $target): bool
    {
        $normalized_source = $this->normalize_path($source);
        $normalized_target = $this->normalize_path($target);
        if ($normalized_source !== false && $normalized_source === $normalized_target) {
            return true;
        }

        $source_meta = $this->resolve_path($source);
        if (! is_array($source_meta)) {
            return false;
        }

        [$parent_id, $name] = $this->parent_id_and_name($target);
        if ($parent_id === false || $name === '') {
            return false;
        }

        $current_parents = $source_meta['parents'] ?? [];
        $query = ['fields' => $this->fields(), 'addParents' => $parent_id];
        if (! empty($current_parents)) {
            $query['removeParents'] = implode(',', $current_parents);
        }

        try {
            $this->client->request(
                'PATCH',
                '/files/' . rawurlencode($source_meta['id']),
                $this->drive_query($query),
                ['name' => $name],
                ['Content-Type' => 'application/json; charset=UTF-8']
            );
            unset($this->path_cache[$source], $this->path_cache[$target]);
            return true;
        } catch (\Throwable $e) {
            ActivityLogger::get_instance()->log_message('Google Drive move failed: ' . $e->getMessage());
            return false;
        }
    }

    public function unlink(string $path): bool
    {
        $meta = $this->resolve_path($path);
        if (! is_array($meta) || ($meta['id'] ?? '') === $this->root_folder_id) {
            return false;
        }

        try {
            $this->client->request('DELETE', '/files/' . rawurlencode($meta['id']), $this->drive_query());
            unset($this->path_cache[$path]);
            return true;
        } catch (\Throwable $e) {
            ActivityLogger::get_instance()->log_message('Google Drive delete failed: ' . $e->getMessage());
            return false;
        }
    }

    public function put_contents(string $path, string $content): bool
    {
        $normalized = $this->normalize_path($path);
        if ($normalized === false || $normalized === '/') {
            return false;
        }

        $mime = $this->detect_mime_from_string($content);
        $existing = $this->resolve_path($normalized);
        try {
            if (is_array($existing) && ($existing['mimeType'] ?? '') === self::FOLDER_MIME) {
                return false;
            }
            if (is_array($existing)) {
                $this->client->upload_request(
                    'PATCH',
                    '/files/' . rawurlencode($existing['id']),
                    $this->drive_query(['uploadType' => 'media', 'fields' => $this->fields()]),
                    $content,
                    ['Content-Type' => $mime],
                    [200]
                );
                return true;
            }

            [$parent_id, $name] = $this->parent_id_and_name($normalized);
            if ($parent_id === false || $name === '') {
                return false;
            }
            $this->multipart_create($parent_id, $name, $content, $mime);
            return true;
        } catch (\Throwable $e) {
            ActivityLogger::get_instance()->log_message('Google Drive put_contents failed: ' . $e->getMessage());
            return false;
        }
    }

    public function append_contents(string $path, string $content): bool
    {
        return false;
    }

    public function get_contents(string $path): string|false
    {
        $meta = $this->resolve_path($path, 'file');
        if (! is_array($meta) || $this->is_google_workspace_file($meta)) {
            return false;
        }

        return $this->client->download_media($meta['id']);
    }

    public function stream_contents(string $path): bool
    {
        $meta = $this->resolve_path($path, 'file');
        if (! is_array($meta) || $this->is_google_workspace_file($meta)) {
            return false;
        }

        return $this->client->stream_media($meta['id']);
    }

    public function get_temporary_link(string $path, int $duration = 3600): string|false
    {
        return false;
    }

    public function get_size(string $path): int|false
    {
        return $this->get_file_size($path);
    }

    public function get_file_size(string $path): int|false
    {
        $meta = $this->resolve_path($path, 'file');
        if (! is_array($meta)) {
            return false;
        }
        return isset($meta['size']) ? (int) $meta['size'] : false;
    }

    public function read_chunk(string $path, int $offset, int $length): string|false
    {
        $meta = $this->resolve_path($path, 'file');
        if (! is_array($meta) || $this->is_google_workspace_file($meta)) {
            return false;
        }

        $end = max($offset, $offset + $length - 1);
        return $this->client->download_media($meta['id'], "bytes={$offset}-{$end}");
    }

    public function getDetails(string $path): array|false
    {
        $meta = $this->resolve_path($path);
        if (! is_array($meta)) {
            return false;
        }

        $is_dir = ($meta['mimeType'] ?? '') === self::FOLDER_MIME;
        return [
            'name'             => $meta['name'] ?? basename($path),
            'path'             => $this->normalize_path($path) ?: '/',
            'is_folder'        => $is_dir,
            'size'             => $is_dir ? 0 : (isset($meta['size']) ? (int) $meta['size'] : null),
            'last_modified'    => ! empty($meta['modifiedTime']) ? strtotime($meta['modifiedTime']) : null,
            'created'          => ! empty($meta['createdTime']) ? strtotime($meta['createdTime']) : null,
            'permission'       => null,
            'permission_octal' => null,
            'owner'            => null,
            'group'            => null,
            'extension'        => $is_dir ? '' : pathinfo((string) ($meta['name'] ?? ''), PATHINFO_EXTENSION),
            'mime_type'        => $meta['mimeType'] ?? null,
        ];
    }

    public function is_empty(string $path): bool
    {
        $meta = $this->resolve_path($path, 'folder');
        if (! is_array($meta)) {
            return false;
        }
        $page = $this->list_children_page($meta['id'], null, 1);
        return empty($page['files']);
    }

    public function download_to_local(string $remote_path, string $local_path): bool
    {
        $meta = $this->resolve_path($remote_path, 'file');
        if (! is_array($meta) || $this->is_google_workspace_file($meta)) {
            return false;
        }
        return $this->client->download_media_to_file($meta['id'], $local_path);
    }

    public function upload_from_local(string $local_path, string $remote_path): bool
    {
        $result = $this->upload_from_local_chunked($local_path, $remote_path, 0, $this->chunk_size);
        return $result['status'] === self::COPY_OPERATION_COMPLETE;
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
        $meta = $this->resolve_path($remote_path, 'file');
        if (! is_array($meta) || $this->is_google_workspace_file($meta)) {
            return ['status' => self::COPY_ERROR_SOURCE_NOT_FOUND, 'bytes_copied' => $offset];
        }

        $file_size = isset($meta['size']) ? (int) $meta['size'] : 0;
        if ($file_size === 0) {
            $dir = dirname($local_path);
            if (! is_dir($dir)) {
                wp_mkdir_p($dir);
            }
            return @touch($local_path)
                ? ['status' => self::COPY_OPERATION_COMPLETE, 'bytes_copied' => 0]
                : ['status' => self::COPY_ERROR_CREATING_FILE, 'bytes_copied' => 0];
        }
        if ($offset >= $file_size) {
            return ['status' => self::COPY_OPERATION_COMPLETE, 'bytes_copied' => $file_size];
        }

        $chunk_size = $this->normalize_chunk_size($chunk_size);
        $end = min($offset + $chunk_size - 1, $file_size - 1);
        $chunk = $this->client->download_media($meta['id'], "bytes={$offset}-{$end}");
        if ($chunk === false || $chunk === '') {
            return ['status' => self::COPY_ERROR_DOWNLOADING_CHUNK, 'bytes_copied' => $offset];
        }

        $dir = dirname($local_path);
        if (! is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        $fp = fopen($local_path, $offset === 0 ? 'wb' : 'ab');
        if (! $fp) {
            return ['status' => self::COPY_ERROR_CREATING_FILE, 'bytes_copied' => $offset];
        }
        fwrite($fp, $chunk);
        fclose($fp);

        $new_offset = $offset + strlen($chunk);
        return [
            'status'       => $new_offset >= $file_size ? self::COPY_OPERATION_COMPLETE : self::COPY_OPERATION_IN_PROGRESS,
            'bytes_copied' => $new_offset,
        ];
    }

    public function upload_from_local_chunked(string $local_path, string $remote_path, int $offset = 0, int $chunk_size = 2097152): array
    {
        if (! is_file($local_path)) {
            return ['status' => self::COPY_ERROR_SOURCE_NOT_FOUND, 'bytes_copied' => 0];
        }

        $file_size = filesize($local_path);
        if ($file_size === false) {
            return ['status' => self::COPY_ERROR_SOURCE_NOT_FOUND, 'bytes_copied' => 0];
        }
        if ((int) $file_size === 0) {
            return $this->put_contents($remote_path, '')
                ? ['status' => self::COPY_OPERATION_COMPLETE, 'bytes_copied' => 0]
                : ['status' => self::COPY_ERROR_CREATING_FILE, 'bytes_copied' => 0];
        }

        $normalized = $this->normalize_path($remote_path);
        if ($normalized === false || $normalized === '/') {
            return ['status' => self::COPY_ERROR_CREATING_FILE, 'bytes_copied' => $offset];
        }

        [$parent_id, $name] = $this->parent_id_and_name($normalized);
        if ($parent_id === false || $name === '') {
            return ['status' => self::COPY_ERROR_CREATING_FILE, 'bytes_copied' => $offset];
        }

        $state_key = 'anibas_gdrive_upload_' . md5($local_path . '|' . $normalized);
        $state = get_option($state_key, []);
        $existing = $this->resolve_path($normalized);
        if (is_array($existing) && ($existing['mimeType'] ?? '') === self::FOLDER_MIME) {
            return ['status' => self::COPY_ERROR_CREATING_FILE, 'bytes_copied' => $offset];
        }

        try {
            $new_session = false;
            if (empty($state['session_url']) || (int) ($state['file_size'] ?? -1) !== (int) $file_size) {
                $mime = $this->detect_mime_from_file($local_path);
                $metadata = ['name' => $name];
                if (! is_array($existing)) {
                    $metadata['parents'] = [$parent_id];
                }
                $state = [
                    'session_url' => $this->client->start_resumable_upload($metadata, $mime, (int) $file_size, is_array($existing) ? $existing['id'] : null),
                    'offset'      => 0,
                    'file_size'   => (int) $file_size,
                ];
                update_option($state_key, $state, false);
                $new_session = true;
            }

            $offset = $new_session ? 0 : max((int) $offset, (int) ($state['offset'] ?? 0));
            if ($offset >= $file_size) {
                delete_option($state_key);
                return ['status' => self::COPY_OPERATION_COMPLETE, 'bytes_copied' => $file_size];
            }

            $chunk_size = $this->normalize_chunk_size($chunk_size);
            $read_size = min($chunk_size, $file_size - $offset);
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
            $result = $this->client->upload_resumable_chunk($state['session_url'], $chunk, $offset, $end, (int) $file_size);
            $state['offset'] = (int) $result['bytes_uploaded'];

            if (! empty($result['complete'])) {
                delete_option($state_key);
                unset($this->path_cache[$normalized]);
                return ['status' => self::COPY_OPERATION_COMPLETE, 'bytes_copied' => $file_size];
            }

            update_option($state_key, $state, false);
            return ['status' => self::COPY_OPERATION_IN_PROGRESS, 'bytes_copied' => $state['offset']];
        } catch (\Throwable $e) {
            ActivityLogger::get_instance()->log_message('Google Drive upload chunk failed: ' . $e->getMessage());
            return ['status' => self::COPY_ERROR_UPLOADING_CHUNK, 'bytes_copied' => $offset];
        }
    }

    private function resolve_path(string $path, ?string $expected = null): array|false
    {
        $normalized = $this->normalize_path($path);
        if ($normalized === false) {
            return false;
        }

        $by_id = $this->resolve_internal_id_path($normalized, $expected);
        if ($by_id !== null) {
            return $by_id;
        }

        if ($normalized === '/') {
            return [
                'id'       => $this->root_folder_id,
                'name'     => '',
                'mimeType' => self::FOLDER_MIME,
                'parents'  => [],
            ];
        }

        $cache_key = $normalized . '|' . (string) $expected;
        if (isset($this->path_cache[$cache_key])) {
            return $this->path_cache[$cache_key];
        }

        $parent_id = $this->root_folder_id;
        $segments = array_values(array_filter(explode('/', trim($normalized, '/')), 'strlen'));
        $meta = false;

        foreach ($segments as $index => $segment) {
            $is_last = $index === count($segments) - 1;
            $wanted = $is_last ? $expected : 'folder';
            $meta = $this->find_child($parent_id, $segment, $wanted);
            if (! is_array($meta)) {
                return false;
            }
            $parent_id = $meta['id'];
        }

        $this->path_cache[$cache_key] = $meta;
        return $meta;
    }

    private function resolve_internal_id_path(string $normalized, ?string $expected): array|false|null
    {
        if (! str_starts_with($normalized, self::INTERNAL_ID_PATH_PREFIX)) {
            return null;
        }

        $parts = explode('/', trim(substr($normalized, strlen(self::INTERNAL_ID_PATH_PREFIX)), '/'));
        if (count($parts) !== 3) {
            return false;
        }

        [$encoded_id, $encoded_parent, $signature] = $parts;
        $storage_id = rawurldecode($encoded_id);
        $parent_id  = rawurldecode($encoded_parent);
        if (! $this->valid_storage_id($storage_id) || ! $this->valid_storage_id($parent_id)) {
            return false;
        }
        if (! hash_equals($this->storage_id_signature($storage_id, $parent_id), $signature)) {
            return false;
        }

        $meta = $this->get_file_by_id($storage_id);
        if (! is_array($meta) || ! in_array($parent_id, $meta['parents'] ?? [], true)) {
            return false;
        }

        $is_folder = ($meta['mimeType'] ?? '') === self::FOLDER_MIME;
        if ($expected === 'folder' && ! $is_folder) {
            return false;
        }
        if ($expected === 'file' && $is_folder) {
            return false;
        }

        return $meta;
    }

    private function get_file_by_id(string $storage_id): array|false
    {
        if (! $this->valid_storage_id($storage_id)) {
            return false;
        }

        try {
            $meta = $this->client->request('GET', '/files/' . rawurlencode($storage_id), $this->drive_query([
                'fields' => $this->fields(),
            ]));
        } catch (\Throwable $e) {
            return false;
        }

        if (! is_array($meta) || ! empty($meta['trashed'])) {
            return false;
        }

        return $meta;
    }

    private function valid_storage_id(string $storage_id): bool
    {
        return $storage_id !== '' && strlen($storage_id) <= 256 && preg_match('/^[A-Za-z0-9_-]+$/', $storage_id) === 1;
    }

    private function storage_id_signature(string $storage_id, string $parent_id): string
    {
        return substr(hash_hmac('sha256', $storage_id . '|' . $parent_id, wp_salt('auth')), 0, 16);
    }

    private function find_child(string $parent_id, string $name, ?string $expected = null): array|false
    {
        $q = sprintf("'%s' in parents and name = '%s' and trashed = false", $this->query_value($parent_id), $this->query_value($name));
        $page = $this->client->request('GET', '/files', $this->drive_query([
            'q'        => $q,
            'fields'   => 'files(' . $this->fields() . ')',
            'pageSize' => 10,
        ]));

        foreach ($page['files'] ?? [] as $file) {
            $is_folder = ($file['mimeType'] ?? '') === self::FOLDER_MIME;
            if ($expected === 'folder' && ! $is_folder) {
                continue;
            }
            if ($expected === 'file' && $is_folder) {
                continue;
            }
            return $file;
        }

        return false;
    }

    private function list_children_page(string $folder_id, ?string $page_token, int $page_size): array
    {
        $query = [
            'q'        => sprintf("'%s' in parents and trashed = false", $this->query_value($folder_id)),
            'fields'   => 'nextPageToken,files(' . $this->fields() . ')',
            'pageSize' => $page_size,
        ];
        if ($page_token) {
            $query['pageToken'] = $page_token;
        }

        return $this->client->request('GET', '/files', $this->drive_query($query));
    }

    private function parent_id_and_name(string $path): array
    {
        $normalized = $this->normalize_path($path);
        if ($normalized === false || $normalized === '/') {
            return [false, ''];
        }

        $parent_path = dirname($normalized);
        if ($parent_path === '.' || $parent_path === '\\') {
            $parent_path = '/';
        }
        $parent = $this->resolve_path($parent_path, 'folder');
        if (! is_array($parent)) {
            return [false, ''];
        }

        return [$parent['id'], basename($normalized)];
    }

    private function format_item(array $file, string $parent_path): array
    {
        $is_folder = ($file['mimeType'] ?? '') === self::FOLDER_MIME;
        $name = (string) ($file['name'] ?? '');
        $storage_id = (string) ($file['id'] ?? '');
        $path = rtrim($parent_path, '/') . '/' . $name;
        if ($parent_path === '/') {
            $path = '/' . $name;
        }

        $item = [
            'name'          => $name,
            'path'          => $path,
            'storage_id'    => $storage_id,
            'ui_key'        => $storage_id !== '' ? 'gdrive:' . $storage_id : 'gdrive-path:' . $path,
            'is_folder'     => $is_folder,
            'permission'    => 0,
            'last_modified' => ! empty($file['modifiedTime']) ? strtotime($file['modifiedTime']) : 0,
            'has_children'  => $is_folder ? null : false,
            'files'         => [],
        ];

        if (! $is_folder) {
            $item['filename']  = $name;
            $item['filesize']  = isset($file['size']) ? (int) $file['size'] : 0;
            $item['file_type'] = $this->is_google_workspace_file($file) ? 'Google Workspace' : 'File';
        }

        return $item;
    }

    private function multipart_create(string $parent_id, string $name, string $content, string $mime): void
    {
        $boundary = 'anibas_' . wp_generate_password(24, false, false);
        $metadata = wp_json_encode(['name' => $name, 'parents' => [$parent_id]]);
        $body = "--{$boundary}\r\n"
            . "Content-Type: application/json; charset=UTF-8\r\n\r\n"
            . $metadata . "\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: {$mime}\r\n\r\n"
            . $content . "\r\n"
            . "--{$boundary}--";

        $this->client->upload_request(
            'POST',
            '/files',
            $this->drive_query(['uploadType' => 'multipart', 'fields' => $this->fields()]),
            $body,
            ['Content-Type' => 'multipart/related; boundary=' . $boundary],
            [200, 201]
        );
    }

    private function drive_query(array $query = []): array
    {
        if ($this->client->supports_all_drives()) {
            $query['supportsAllDrives'] = $query['supportsAllDrives'] ?? 'true';
            if (isset($query['q']) || isset($query['pageSize'])) {
                $query['includeItemsFromAllDrives'] = $query['includeItemsFromAllDrives'] ?? 'true';
            }
        }
        return $query;
    }

    private function fields(): string
    {
        return 'id,name,mimeType,size,modifiedTime,createdTime,parents,webContentLink,trashed';
    }

    private function normalize_path(string $path): string|false
    {
        $path = str_replace(["\0", '\\'], ['', '/'], $path);
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if (empty($segments)) {
                    return false;
                }
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return '/' . implode('/', $segments);
    }

    private function query_value(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }

    private function is_google_workspace_file(array $meta): bool
    {
        return str_starts_with((string) ($meta['mimeType'] ?? ''), 'application/vnd.google-apps.')
            && ($meta['mimeType'] ?? '') !== self::FOLDER_MIME;
    }

    private function detect_mime_from_file(string $path): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = finfo_file($finfo, $path);
                finfo_close($finfo);
                if ($mime) {
                    return $mime;
                }
            }
        }
        return 'application/octet-stream';
    }

    private function detect_mime_from_string(string $content): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = finfo_buffer($finfo, $content);
                finfo_close($finfo);
                if ($mime) {
                    return $mime;
                }
            }
        }
        return 'application/octet-stream';
    }

    private function normalize_chunk_size(int $chunk_size): int
    {
        if ($chunk_size <= 0) {
            $chunk_size = isset($this->chunk_size) ? $this->chunk_size : ANIBAS_FM_DEFAULT_CHUNK_SIZE;
        }
        $chunk_size = max(ANIBAS_FM_CHUNK_SIZE_MIN, min(ANIBAS_FM_CHUNK_SIZE_MAX, $chunk_size));
        $multiple = 262144;
        return max($multiple, (int) (floor($chunk_size / $multiple) * $multiple));
    }
}
