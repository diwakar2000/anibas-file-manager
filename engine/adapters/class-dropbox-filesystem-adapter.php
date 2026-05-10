<?php

namespace Anibas;

if ( ! defined( 'ABSPATH' ) ) exit;

class DropboxFileSystemAdapter extends FileSystemAdapter
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

    private AnibasDropboxClient $client;
    private string $root_path;
    private int $chunk_size;

    public function __construct(AnibasDropboxClient $client, string $root_path = '/', int $chunk_size = 10485760)
    {
        $this->client = $client;
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
        return is_array($meta) && ($meta['.tag'] ?? '') === 'file';
    }

    public function is_dir(string $path): bool
    {
        $meta = $this->metadata($path);
        return is_array($meta) && ($meta['.tag'] ?? '') === 'folder';
    }

    public function mkdir(string $path): bool
    {
        $path = $this->normalize_path($path);
        if ($path === false || $path === '/') {
            return $path === '/';
        }
        $existing = $this->metadata($path);
        if (is_array($existing)) {
            return ($existing['.tag'] ?? '') === 'folder';
        }

        try {
            $this->client->rpc('/files/create_folder_v2', [
                'path'       => $this->remote_path($path),
                'autorename' => false,
            ]);
            return true;
        } catch (\Throwable $e) {
            ActivityLogger::get_instance()->log_message('Dropbox mkdir failed: ' . $e->getMessage());
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
            if (! empty($cursor['cursor'])) {
                $data = $this->client->rpc('/files/list_folder/continue', ['cursor' => $cursor['cursor']]);
            } else {
                $data = $this->client->rpc('/files/list_folder', [
                    'path'             => $this->remote_path($path),
                    'recursive'        => false,
                    'include_deleted'  => false,
                    'include_mounted_folders' => true,
                    'limit'            => min(1000, max(1, $maxItems)),
                ]);
            }
        } catch (\Throwable $e) {
            return ['entries' => [], 'next_cursor' => ['done' => true], 'has_more' => false];
        }

        $parent = $this->normalize_path($path) ?: '/';
        $entries = [];
        foreach ($data['entries'] ?? [] as $entry) {
            $item = $this->format_item($entry, $parent);
            if ($item !== null) {
                $entries[] = $item;
            }
        }

        $has_more = ! empty($data['has_more']);
        return [
            'entries'     => $entries,
            'next_cursor' => $has_more ? ['cursor' => (string) ($data['cursor'] ?? '')] : ['done' => true],
            'has_more'    => $has_more,
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
        if ($source === false || $target === false || $source === '/') {
            return self::COPY_ERROR_SOURCE_NOT_FOUND;
        }
        if ($source === $target) {
            return self::COPY_OPERATION_COMPLETE;
        }

        $source_meta = $this->metadata($source);
        if (! is_array($source_meta) || ($source_meta['.tag'] ?? '') !== 'file') {
            return self::COPY_ERROR_SOURCE_NOT_FOUND;
        }
        $existing = $this->metadata($target);
        if (is_array($existing) && ($existing['.tag'] ?? '') === 'folder') {
            return self::COPY_ERROR_CREATING_FILE;
        }
        [$parent, $name] = $this->parent_and_name($target);
        if ($parent === false || $name === '' || ! $this->is_dir($parent)) {
            return self::COPY_ERROR_CREATING_FILE;
        }

        $size = (int) ($source_meta['size'] ?? 0);
        $state_key = 'anibas_dropbox_copy_' . md5($source . '|' . $target);
        if ($size === 0) {
            delete_option($state_key);
            return $this->put_contents($target, '')
                ? self::COPY_OPERATION_COMPLETE
                : self::COPY_ERROR_CREATING_FILE;
        }

        try {
            $result = $this->send_chunked_upload(
                $state_key,
                $this->remote_path($target),
                $size,
                (int) $bytes_copied,
                $chunk_size ?? $this->chunk_size,
                function (int $offset, int $length) use ($source) {
                    $end = $offset + $length - 1;
                    return $this->client->download($this->remote_path($source), "bytes={$offset}-{$end}");
                }
            );
            return $result['status'];
        } catch (\Throwable $e) {
            ActivityLogger::get_instance()->log_message('Dropbox copy failed: ' . $e->getMessage());
            return self::COPY_ERROR_UPLOADING_CHUNK;
        }
    }

    public function getCopyProgress($source, $target): array
    {
        $size = $this->get_file_size((string) $source);
        $size = $size !== false ? $size : 0;
        $source = $this->normalize_path((string) $source);
        $target = $this->normalize_path((string) $target);
        $state_key = $source !== false && $target !== false ? 'anibas_dropbox_copy_' . md5($source . '|' . $target) : '';
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
        if ($source === false || $target === false || $source === '/' || $target === '/') {
            return false;
        }
        if ($source === $target) {
            return true;
        }

        try {
            $this->client->rpc('/files/move_v2', [
                'from_path'  => $this->remote_path($source),
                'to_path'    => $this->remote_path($target),
                'autorename' => false,
            ]);
            return true;
        } catch (\Throwable $e) {
            ActivityLogger::get_instance()->log_message('Dropbox move failed: ' . $e->getMessage());
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
            $this->client->rpc('/files/delete_v2', ['path' => $this->remote_path($path)]);
            return true;
        } catch (\Throwable $e) {
            ActivityLogger::get_instance()->log_message('Dropbox delete failed: ' . $e->getMessage());
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
            $this->client->upload_small($this->remote_path($path), $content);
            return true;
        } catch (\Throwable $e) {
            ActivityLogger::get_instance()->log_message('Dropbox put_contents failed: ' . $e->getMessage());
            return false;
        }
    }

    public function append_contents(string $path, string $content): bool
    {
        return false;
    }

    public function get_contents(string $path): string|false
    {
        return $this->client->download($this->remote_path($path));
    }

    public function get_temporary_link(string $path, int $duration = 3600): string|false
    {
        try {
            $data = $this->client->rpc('/files/get_temporary_link', ['path' => $this->remote_path($path)]);
            return ! empty($data['link']) ? (string) $data['link'] : false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function get_size(string $path): int|false
    {
        return $this->get_file_size($path);
    }

    public function get_file_size(string $path): int|false
    {
        $meta = $this->metadata($path);
        return is_array($meta) && ($meta['.tag'] ?? '') === 'file' && isset($meta['size'])
            ? (int) $meta['size']
            : false;
    }

    public function read_chunk(string $path, int $offset, int $length): string|false
    {
        $end = max($offset, $offset + $length - 1);
        return $this->client->download($this->remote_path($path), "bytes={$offset}-{$end}");
    }

    public function stream_contents(string $path): bool
    {
        return $this->client->stream_download($this->remote_path($path), static function ($chunk) {
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
        $is_dir = ($meta['.tag'] ?? '') === 'folder';
        $name = (string) ($meta['name'] ?? basename($path));
        return [
            'name'             => $name,
            'path'             => $this->normalize_path($path) ?: '/',
            'is_folder'        => $is_dir,
            'size'             => $is_dir ? 0 : (int) ($meta['size'] ?? 0),
            'last_modified'    => ! empty($meta['server_modified']) ? strtotime($meta['server_modified']) : null,
            'created'          => ! empty($meta['client_modified']) ? strtotime($meta['client_modified']) : null,
            'permission'       => null,
            'permission_octal' => null,
            'owner'            => null,
            'group'            => null,
            'extension'        => $is_dir ? '' : pathinfo($name, PATHINFO_EXTENSION),
            'mime_type'        => null,
        ];
    }

    public function is_empty(string $path): bool
    {
        try {
            $data = $this->client->rpc('/files/list_folder', [
                'path'             => $this->remote_path($path),
                'recursive'        => false,
                'include_deleted'  => false,
                'include_mounted_folders' => true,
                'limit'            => 1,
            ]);
            return empty($data['entries']);
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
        $chunk = $this->client->download($this->remote_path($remote_path), "bytes={$offset}-{$end}");
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
        return [
            'status'       => $new_offset >= $size ? self::COPY_OPERATION_COMPLETE : self::COPY_OPERATION_IN_PROGRESS,
            'bytes_copied' => $new_offset,
        ];
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

        $remote_path = $this->normalize_path($remote_path);
        if ($remote_path === false || $remote_path === '/') {
            return ['status' => self::COPY_ERROR_CREATING_FILE, 'bytes_copied' => $offset];
        }
        if ($offset <= 0) {
            [$parent, $name] = $this->parent_and_name($remote_path);
            $existing = $this->metadata($remote_path);
            if ($parent === false || $name === '' || ! $this->is_dir($parent) || (is_array($existing) && ($existing['.tag'] ?? '') === 'folder')) {
                return ['status' => self::COPY_ERROR_CREATING_FILE, 'bytes_copied' => $offset];
            }
        }
        if ((int) $size === 0) {
            return $this->put_contents($remote_path, '')
                ? ['status' => self::COPY_OPERATION_COMPLETE, 'bytes_copied' => 0]
                : ['status' => self::COPY_ERROR_CREATING_FILE, 'bytes_copied' => 0];
        }

        $state_key = 'anibas_dropbox_upload_' . md5($local_path . '|' . $remote_path);
        try {
            return $this->send_chunked_upload(
                $state_key,
                $this->remote_path($remote_path),
                (int) $size,
                $offset,
                $chunk_size,
                function (int $offset, int $length) use ($local_path) {
                    $fp = fopen($local_path, 'rb');
                    if (! $fp) {
                        return false;
                    }
                    fseek($fp, $offset);
                    $chunk = fread($fp, $length);
                    fclose($fp);
                    return $chunk;
                }
            );
        } catch (\Throwable $e) {
            ActivityLogger::get_instance()->log_message('Dropbox upload chunk failed: ' . $e->getMessage());
            return ['status' => self::COPY_ERROR_UPLOADING_CHUNK, 'bytes_copied' => $offset];
        }
    }

    private function send_chunked_upload(string $state_key, string $remote_path, int $size, int $offset, int $chunk_size, callable $reader): array
    {
        $state = get_option($state_key, []);
        $new_session = false;
        if ((int) ($state['size'] ?? -1) !== $size) {
            $state = [];
            $new_session = true;
        }

        $offset = ($new_session || empty($state['session_id']))
            ? 0
            : max($offset, (int) ($state['offset'] ?? 0));
        if ($offset >= $size) {
            return $this->complete_closed_upload_session($state_key, $state, $remote_path, $size);
        }

        $chunk_size = $this->normalize_chunk_size($chunk_size);
        $read_size = min($chunk_size, $size - $offset);
        $chunk = $reader($offset, $read_size);
        if ($chunk === false || $chunk === '') {
            return ['status' => self::COPY_ERROR_NO_DATA_RECEIVED, 'bytes_copied' => $offset];
        }

        $length = strlen($chunk);
        if (empty($state['session_id'])) {
            if ($offset === 0 && $length >= $size) {
                $this->client->upload_small($remote_path, $chunk);
                delete_option($state_key);
                return ['status' => self::COPY_OPERATION_COMPLETE, 'bytes_copied' => $size];
            }
            $state = [
                'session_id' => $this->client->upload_session_start($chunk),
                'offset'     => $offset + $length,
                'size'       => $size,
            ];
            update_option($state_key, $state, false);
            return ['status' => self::COPY_OPERATION_IN_PROGRESS, 'bytes_copied' => $state['offset']];
        }

        if ($offset + $length >= $size) {
            $this->client->upload_session_append((string) $state['session_id'], $offset, $chunk, true);
            $state['offset'] = $size;
            $state['size'] = $size;
            $state['closed'] = true;
            update_option($state_key, $state, false);
            return ['status' => self::COPY_OPERATION_IN_PROGRESS, 'bytes_copied' => $size, 'phase' => 'remote_commit'];
        }

        $this->client->upload_session_append((string) $state['session_id'], $offset, $chunk);
        $state['offset'] = $offset + $length;
        $state['size'] = $size;
        update_option($state_key, $state, false);
        return ['status' => self::COPY_OPERATION_IN_PROGRESS, 'bytes_copied' => $state['offset'], 'phase' => 'remote_upload'];
    }

    private function complete_closed_upload_session(string $state_key, array $state, string $remote_path, int $size): array
    {
        if (empty($state['session_id'])) {
            delete_option($state_key);
            return ['status' => self::COPY_OPERATION_COMPLETE, 'bytes_copied' => $size];
        }

        if (empty($state['finish_batch_id'])) {
            $response = $this->client->upload_session_finish_batch((string) $state['session_id'], $size, $remote_path);
            $result = $this->parse_finish_batch_response($response);
            if ($result === 'complete') {
                delete_option($state_key);
                return ['status' => self::COPY_OPERATION_COMPLETE, 'bytes_copied' => $size];
            }
            $state['finish_batch_id'] = $result;
            $state['offset'] = $size;
            $state['size'] = $size;
            $state['closed'] = true;
            update_option($state_key, $state, false);
            return ['status' => self::COPY_OPERATION_IN_PROGRESS, 'bytes_copied' => $size, 'phase' => 'remote_commit'];
        }

        $response = $this->client->upload_session_finish_batch_check((string) $state['finish_batch_id']);
        $result = $this->parse_finish_batch_response($response);
        if ($result === 'complete') {
            delete_option($state_key);
            return ['status' => self::COPY_OPERATION_COMPLETE, 'bytes_copied' => $size];
        }

        return ['status' => self::COPY_OPERATION_IN_PROGRESS, 'bytes_copied' => $size, 'phase' => 'remote_commit'];
    }

    private function parse_finish_batch_response(array $response): string
    {
        $tag = (string) ($response['.tag'] ?? '');
        if ($tag === 'async_job_id' && ! empty($response['async_job_id'])) {
            return (string) $response['async_job_id'];
        }
        if ($tag === 'in_progress') {
            return 'in_progress';
        }
        if ($tag === 'complete' || $response === []) {
            $this->assert_finish_batch_entries_succeeded($response['entries'] ?? []);
            return 'complete';
        }
        if ($tag === 'failed') {
            throw new DropboxException('Dropbox upload session finish failed: ' . wp_json_encode($response));
        }

        return 'complete';
    }

    private function assert_finish_batch_entries_succeeded(array $entries): void
    {
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $tag = (string) ($entry['.tag'] ?? '');
            if ($tag === 'failure' || $tag === 'failed') {
                throw new DropboxException('Dropbox upload session finish failed: ' . wp_json_encode($entry));
            }
        }
    }

    private function metadata(string $path): array|false
    {
        $normalized = $this->normalize_path($path);
        if ($normalized === false) {
            return false;
        }
        if ($normalized === '/') {
            return ['.tag' => 'folder', 'name' => '', 'path_display' => ''];
        }

        try {
            $data = $this->client->rpc('/files/get_metadata', [
                'path'             => $this->remote_path($normalized),
                'include_deleted'  => false,
                'include_has_explicit_shared_members' => false,
            ]);
            return ($data['.tag'] ?? '') === 'deleted' ? false : $data;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function format_item(array $entry, string $parent): ?array
    {
        $tag = (string) ($entry['.tag'] ?? '');
        if ($tag !== 'file' && $tag !== 'folder') {
            return null;
        }
        $name = (string) ($entry['name'] ?? '');
        $storage_id = (string) ($entry['id'] ?? ($entry['path_lower'] ?? ''));
        $path = $parent === '/' ? '/' . $name : rtrim($parent, '/') . '/' . $name;
        $item = [
            'name'          => $name,
            'path'          => $path,
            'storage_id'    => $storage_id,
            'ui_key'        => $storage_id !== '' ? 'dropbox:' . $storage_id : 'dropbox-path:' . $path,
            'is_folder'     => $tag === 'folder',
            'permission'    => 0,
            'last_modified' => ! empty($entry['server_modified']) ? strtotime($entry['server_modified']) : 0,
            'has_children'  => $tag === 'folder' ? null : false,
            'files'         => [],
        ];
        if ($tag === 'file') {
            $item['filename'] = $name;
            $item['filesize'] = (int) ($entry['size'] ?? 0);
            $item['file_type'] = 'File';
        }
        return $item;
    }

    private function remote_path(string $path): string
    {
        $path = $this->normalize_path($path) ?: '/';
        if ($this->root_path !== '/') {
            $path = $this->normalize_path(rtrim($this->root_path, '/') . '/' . ltrim($path, '/')) ?: '/';
        }
        return $path === '/' ? '' : $path;
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

    private function normalize_chunk_size(int $chunk_size): int
    {
        if ($chunk_size <= 0) $chunk_size = ANIBAS_FM_DEFAULT_CHUNK_SIZE;
        return max(ANIBAS_FM_CHUNK_SIZE_MIN, min(ANIBAS_FM_CHUNK_SIZE_MAX, $chunk_size));
    }
}
