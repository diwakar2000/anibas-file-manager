<?php

namespace Anibas;

if ( ! defined( 'ABSPATH' ) ) exit;


require_once ANIBAS_FILE_MANAGER_PLUGIN_DIR . DIRECTORY_SEPARATOR . 'vendor/autoload.php';

/**
 * SFTP adapter - uses phpseclib if available, falls back to cURL
 * Install phpseclib: composer require phpseclib/phpseclib
 */
class SFTPFileSystemAdapter extends FileSystemAdapter
{
    const COPY_ERROR_CREATING_FILE = 1;
    const COPY_ERROR_APPENDING_TO_FILE = 2;
    const COPY_ERROR_DOWNLOADING_CHUNK = 3;
    const COPY_ERROR_UPLOADING_CHUNK = 4;
    const COPY_ERROR_SOURCE_NOT_FOUND = 5;
    const COPY_ERROR_SOURCE_EMPTY = 6;
    const COPY_ERROR_NO_DATA_RECEIVED = 7;
    const COPY_ERROR_VERIFICATION_FAILED = 8;

    private $backend;
    private $base_path;
    private $host;
    private $username;
    private $password;
    private $private_key;
    private $port;
    private $backend_type;

    public function __construct($host, $username, $password = null, $private_key = null, $base_path = DIRECTORY_SEPARATOR, $port = 22)
    {
        $this->base_path = is_string($base_path) && $base_path !== '' ? $base_path : DIRECTORY_SEPARATOR;
        $this->host = $host;
        $this->username = $username;
        $this->password = $password;
        $this->private_key = $private_key;
        $this->port = $port;

        // Prefer cURL when SFTP is available because it can stream LIST output
        // and stop at the current page budget. phpseclib rawlist() materializes
        // the whole directory, so keep it as the compatibility fallback.
        if (self::curl_supports_sftp()) {
            $this->backend_type = 'curl';
        } elseif (class_exists('\phpseclib3\Net\SFTP')) {
            $this->backend_type = 'phpseclib';
        } else {
            $this->backend_type = 'curl';
        }
        $this->backend = $this->create_backend($this->backend_type);
    }

    private static function curl_supports_sftp(): bool
    {
        if (! function_exists('curl_version')) {
            return false;
        }
        $version = curl_version();
        return in_array('sftp', $version['protocols'] ?? [], true);
    }

    private static function phpseclib_available(): bool
    {
        return class_exists('\phpseclib3\Net\SFTP');
    }

    private function create_backend(string $type)
    {
        if ($type === 'phpseclib') {
            return new SFTPPhpseclibBackend($this->host, $this->username, $this->password, $this->private_key, $this->port);
        }

        return new SFTPCurlBackend($this->host, $this->username, $this->password, $this->private_key, $this->port);
    }

    private function call_backend(string $method, array $args = [])
    {
        try {
            return $this->backend->{$method}(...$args);
        } catch (\Throwable $e) {
            if ($this->backend_type !== 'curl' || ! self::phpseclib_available()) {
                throw $e;
            }

            ActivityLogger::get_instance()->log_message(
                'SFTP cURL backend failed for ' . $method . '; retrying with phpseclib: ' . $e->getMessage()
            );

            $this->backend_type = 'phpseclib';
            $this->backend = $this->create_backend('phpseclib');

            return $this->backend->{$method}(...$args);
        }
    }

    public function validate_path($path): string|false
    {
        return anibas_fm_normalize_remote_path((string) $path, $this->base_path);
    }

    public function exists(string $path): bool
    {
        $normalized = $this->normalize_path($path);
        return $normalized !== false && $this->call_backend('exists', [$normalized]);
    }

    public function is_file(string $path): bool
    {
        $normalized = $this->normalize_path($path);
        return $normalized !== false && $this->call_backend('is_file', [$normalized]);
    }

    public function is_dir(string $path): bool
    {
        $normalized = $this->normalize_path($path);
        return $normalized !== false && $this->call_backend('is_dir', [$normalized]);
    }

    public function mkdir(string $path): bool
    {
        $normalized = $this->normalize_path($path);
        return $normalized !== false && $this->call_backend('mkdir', [$normalized]);
    }

    public function scandir(string $path): array
    {
        $norm_path = $this->normalize_path($path);
        if ($norm_path === false) {
            return ['items' => [], 'total' => 0];
        }
        $data      = $this->call_backend('listDirectory', [$norm_path]);
        $items     = [];
        foreach ($data['items'] ?? [] as $item) {
            $item['path'] = $this->to_virtual_path($item['path']);
            $items[$item['path']] = $item;
        }
        return ['items' => $items, 'total' => count($items)];
    }

    public function listDirectory(string $path, int $page = 1, int $pageSize = 100): array
    {
        $normalized = $this->normalize_path($path);
        if ($normalized === false) {
            return ['items' => [], 'total_items' => 0, 'page' => max(1, $page), 'page_size' => min(1000, max(1, $pageSize)), 'has_more' => false];
        }
        $data = $this->call_backend('listDirectory', [$normalized, $page, $pageSize]);
        $data['items'] = $data['items'] ?? [];
        foreach ($data['items'] as &$item) {
            if (isset($item['path'])) {
                $item['path'] = $this->to_virtual_path($item['path']);
            }
        }
        unset($item);
        return $data;
    }

    public function iterateDirectory(string $path, ?array $cursor = null, int $maxItems = 1000, array $options = []): array
    {
        $normalized = $this->normalize_path($path);
        if ($normalized === false) {
            return ['entries' => [], 'next_cursor' => ['done' => true], 'has_more' => false];
        }
        if (! method_exists($this->backend, 'iterateDirectory')) {
            return parent::iterateDirectory($path, $cursor, $maxItems, $options);
        }
        $page = $this->call_backend('iterateDirectory', [$normalized, $cursor, $maxItems, $options]);
        $page['entries'] = $page['entries'] ?? [];
        foreach ($page['entries'] as &$entry) {
            if (isset($entry['path'])) {
                $entry['path'] = $this->to_virtual_path($entry['path']);
            }
        }
        unset($entry);
        return $page;
    }

    public function rmdir(string $path): bool
    {
        $normalized = $this->normalize_path($path);
        return $normalized !== false && $this->call_backend('rmdir', [$normalized]);
    }

    public function queuedRmdir(string $path, string $root = '', bool $allow_trash_root = false): bool
    {
        $normalized = $this->normalize_path($path);
        if ($normalized === false) {
            return false;
        }
        return method_exists($this->backend, 'queuedRmdir')
            ? $this->call_backend('queuedRmdir', [$normalized, $root, $allow_trash_root])
            : $this->call_backend('rmdir', [$normalized]);
    }

    public function copy(string $source, string $target): bool
    {
        $normalized_source = $this->normalize_path($source);
        $normalized_target = $this->normalize_path($target);
        if ($normalized_source === false || $normalized_target === false) {
            return false;
        }
        return $this->call_backend('copyFileInChunks', [$normalized_source, $normalized_target]);
    }

    public function copyFileInChunks($source, $target, ?int $chunk_size = null, $bytes_copied = 0): int
    {
        $normalized_source = $this->normalize_path($source);
        $normalized_target = $this->normalize_path($target);
        if ($normalized_source === false || $normalized_target === false) {
            return self::COPY_ERROR_SOURCE_NOT_FOUND;
        }
        return $this->call_backend('copyFileInChunks', [$normalized_source, $normalized_target, $chunk_size, $bytes_copied]);
    }

    public function getCopyProgress($source, $target): array
    {
        $normalized_source = $this->normalize_path($source);
        $normalized_target = $this->normalize_path($target);
        if ($normalized_source === false || $normalized_target === false) {
            return ['bytes_copied' => 0, 'next_bytes_copied' => 0];
        }
        if (method_exists($this->backend, 'getCopyProgress')) {
            return $this->call_backend('getCopyProgress', [$normalized_source, $normalized_target]);
        }
        return ['bytes_copied' => 0, 'next_bytes_copied' => 0];
    }

    public function move(string $source, string $target): bool
    {
        $normalized_source = $this->normalize_path($source);
        $normalized_target = $this->normalize_path($target);
        if ($normalized_source === false || $normalized_target === false) {
            return false;
        }
        return $this->call_backend('move', [$normalized_source, $normalized_target]);
    }

    public function unlink(string $path): bool
    {
        $normalized = $this->normalize_path($path);
        return $normalized !== false && $this->call_backend('unlink', [$normalized]);
    }

    public function get_contents(string $path): string|false
    {
        $normalized = $this->normalize_path($path);
        if ($normalized === false) {
            return false;
        }
        return $this->call_backend('get_contents', [$normalized]);
    }

    public function stream_contents(string $path): bool
    {
        $normalized = $this->normalize_path($path);
        if ($normalized === false) {
            return false;
        }
        if (method_exists($this->backend, 'stream_contents')) {
            return $this->call_backend('stream_contents', [$normalized]);
        }
        return parent::stream_contents($path);
    }

    public function get_size(string $path): int|false
    {
        $normalized = $this->normalize_path($path);
        if ($normalized === false) {
            return false;
        }
        if (method_exists($this->backend, 'get_size')) {
            return $this->call_backend('get_size', [$normalized]);
        }
        return false;
    }

    public function put_contents(string $path, string $content): bool
    {
        $normalized = $this->normalize_path($path);
        return $normalized !== false && $this->call_backend('put_contents', [$normalized, $content]);
    }

    public function append_contents(string $path, string $content): bool
    {
        $normalized = $this->normalize_path($path);
        return $normalized !== false && $this->call_backend('append_contents', [$normalized, $content]);
    }

    public function download_to_local(string $remote_path, string $local_path): bool
    {
        $dir = dirname($local_path);
        if (! is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        $normalized = $this->normalize_path($remote_path);
        return $normalized !== false && $this->call_backend('download_to_local', [$normalized, $local_path]);
    }

    public function upload_from_local(string $local_path, string $remote_path): bool
    {
        $normalized = $this->normalize_path($remote_path);
        return $normalized !== false && $this->call_backend('upload_from_local', [$local_path, $normalized]);
    }

    public function supports_chunked_transfer(): bool
    {
        return true;
    }

    public function download_to_local_chunked(string $remote_path, string $local_path, int $offset = 0, int $chunk_size = 2097152): array
    {
        $normalized = $this->normalize_path($remote_path);
        if ($normalized === false) {
            return ['status' => self::COPY_ERROR_SOURCE_NOT_FOUND, 'bytes_copied' => $offset];
        }
        return $this->call_backend('download_to_local_chunked', [$normalized, $local_path, $offset, $chunk_size]);
    }

    public function upload_from_local_chunked(string $local_path, string $remote_path, int $offset = 0, int $chunk_size = 2097152): array
    {
        $normalized = $this->normalize_path($remote_path);
        if ($normalized === false) {
            return ['status' => self::COPY_ERROR_CREATING_FILE, 'bytes_copied' => $offset];
        }
        return $this->call_backend('upload_from_local_chunked', [$local_path, $normalized, $offset, $chunk_size]);
    }

    private function normalize_path($path)
    {
        return $this->validate_path((string) $path);
    }

    private function to_virtual_path(string $path): string
    {
        $base = '/' . ltrim(rtrim(str_replace('\\', '/', $this->base_path), '/'), '/');
        $path = '/' . ltrim(str_replace('\\', '/', $path), '/');

        if ($base === '') {
            return $path;
        }

        if ($path === $base) {
            return '/';
        }

        $base_with_sep = $base . '/';
        if (strpos($path, $base_with_sep) === 0) {
            return '/' . ltrim(substr($path, strlen($base_with_sep)), '/');
        }

        return $path;
    }
}

class SFTPPhpseclibBackend
{
    private $sftp;

    public function __construct($host, $username, $password, $private_key, $port)
    {
        $this->sftp = new \phpseclib3\Net\SFTP($host, $port);

        if ($private_key) {
            $key = \phpseclib3\Crypt\PublicKeyLoader::load(anibas_fm_read_small_file($private_key, 65536));
            if (! $this->sftp->login($username, $key)) {
                throw new \Exception('SFTP key authentication failed');
            }
        } elseif ($password) {
            if (! $this->sftp->login($username, $password)) {
                throw new \Exception('SFTP password authentication failed');
            }
        } else {
            throw new \Exception('SFTP requires password or private key');
        }
    }

    public function exists($path)
    {
        return $this->sftp->file_exists($path);
    }

    public function is_file($path)
    {
        return $this->sftp->is_file($path);
    }

    public function is_dir($path)
    {
        return $this->sftp->is_dir($path);
    }

    public function mkdir($path)
    {
        return $this->sftp->mkdir($path, -1, true);
    }

    public function scandir($path)
    {
        $list = $this->sftp->rawlist($path);
        if ($list === false) return [];
        return array_values(array_filter(array_keys($list), fn($k) => $k !== '.' && $k !== '..'));
    }

    public function listDirectory($path, int $page = 1, int $pageSize = 100)
    {
        // rawlist fetches name + stat (size, mtime, type) in one SFTP round-trip
        $raw = $this->sftp->rawlist($path);

        if ($raw === false) {
            return ['items' => [], 'total_items' => 0, 'page' => max(1, $page), 'page_size' => min(1000, max(1, $pageSize)), 'has_more' => false];
        }

        $items = [];

        $page = max(1, $page);
        $pageSize = min(1000, max(1, $pageSize));
        $offset = ($page - 1) * $pageSize;
        $position = 0;
        $has_more = false;

        foreach ($raw as $name => $stat) {
            if ($name === '.' || $name === '..') continue;
            if ($position++ < $offset) continue;
            if (count($items) >= $pageSize) {
                $has_more = true;
                break;
            }

            $full_path = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;

            // type: 1 = regular file, 2 = directory, 3 = symlink
            $is_dir    = isset($stat['type']) && (int) $stat['type'] === 2;
            $size      = isset($stat['size'])  ? (int) $stat['size']  : 0;
            $mtime     = isset($stat['mtime']) ? (int) $stat['mtime'] : 0;

            $item = [
                'name'          => $name,
                'path'          => $full_path,
                'is_folder'     => $is_dir,
                'permission'    => isset($stat['mode']) ? $stat['mode'] : 0,
                'last_modified' => $mtime,
                'has_children'  => false,
                'files'         => [],
            ];

            if (! $is_dir) {
                $item['filename']  = $name;
                $item['filesize']  = $size;
                $item['file_type'] = 'File';
            }

            $items[$name] = $item;
        }

        return [
            'items'       => array_values($items),
            'total_items' => $offset + count($items) + ($has_more ? 1 : 0),
            'page'        => $page,
            'page_size'   => $pageSize,
            'has_more'    => $has_more,
        ];
    }

    public function iterateDirectory($path, ?array $cursor = null, int $maxItems = 1000, array $options = []): array
    {
        $offset = is_array($cursor) && isset($cursor['offset']) ? max(0, (int) $cursor['offset']) : 0;
        $data = $this->listDirectory($path, intdiv($offset, max(1, $maxItems)) + 1, min(1000, max(1, $maxItems)));
        $entries = [];
        foreach ($data['items'] as $item) {
            $entries[] = [
                'name'      => $item['name'] ?? '',
                'is_folder' => ! empty($item['is_folder']),
                'path'      => $item['path'] ?? '',
                'filesize'  => $item['filesize'] ?? 0,
            ];
        }
        $next_offset = $offset + count($entries);
        return [
            'entries'     => $entries,
            'next_cursor' => ! empty($data['has_more']) ? ['offset' => $next_offset] : ['done' => true],
            'has_more'    => ! empty($data['has_more']),
        ];
    }

    public function getDetails($path)
    {
        $stat = $this->sftp->stat($path);
        if ($stat === false) {
            return false;
        }

        $isDir = isset($stat['type']) && (int) $stat['type'] === 2;
        $size  = isset($stat['size'])  ? (int) $stat['size']  : 0;
        $mtime = isset($stat['mtime']) ? (int) $stat['mtime'] : 0;
        $mode  = isset($stat['mode'])  ? $stat['mode']        : 0;
        $uid   = isset($stat['uid'])   ? (int) $stat['uid']   : null;
        $gid   = isset($stat['gid'])   ? (int) $stat['gid']   : null;

        return [
            'name'             => basename($path),
            'path'             => $path,
            'is_folder'        => $isDir,
            'size'             => $isDir ? 0 : $size,
            'last_modified'    => $mtime,
            'created'          => null,
            'permission'       => $mode,
            'permission_octal' => $mode ? sprintf('%o', $mode & 0777) : null,
            'owner'            => $uid !== null ? (string) $uid : null,
            'group'            => $gid !== null ? (string) $gid : null,
            'extension'        => $isDir ? '' : pathinfo($path, PATHINFO_EXTENSION),
            'mime_type'        => null,
        ];
    }

    public function rmdir($path)
    {
        if (! $this->sftp->rmdir($path)) {
            throw new \Exception(esc_html__('Cannot remove directory "', 'anibas-file-manager') . esc_html(basename($path)) . '": ' . esc_html($this->sftp->getLastSFTPError()));
        }
        return true;
    }

    public function queuedRmdir($path, string $root = '', bool $allow_trash_root = false): bool
    {
        return $this->rmdir($path);
    }

    public function copy($source, $target)
    {
        return $this->copyFileInChunks($source, $target);
    }

    /**
     * Copy file error codes
     */
    const COPY_NO_ERROR = 0;
    const COPY_ERROR_CREATING_FILE = 1;
    const COPY_ERROR_APPENDING_TO_FILE = 2;
    const COPY_ERROR_DOWNLOADING_CHUNK = 3;
    const COPY_ERROR_UPLOADING_CHUNK = 4;
    const COPY_ERROR_SOURCE_NOT_FOUND = 5;
    const COPY_ERROR_SOURCE_EMPTY = 6;
    const COPY_ERROR_NO_DATA_RECEIVED = 7;
    const COPY_ERROR_VERIFICATION_FAILED = 8;
    const COPY_OPERATION_COMPLETE = 9;
    const COPY_OPERATION_IN_PROGRESS = 10;

    /**
     * Copy file in chunks to avoid memory limits - resumable approach
     * Uses phpseclib range reads and RESUME mode to avoid loading entire file into memory.
     */
    public function copyFileInChunks($source, $target, ?int $chunk_size = null, $bytes_copied = 0): int
    {
        // Use dynamic chunk size from settings if not provided
        if ($chunk_size === null) {
            $chunk_size = intval(anibas_fm_get_option('chunk_size', ANIBAS_FM_DEFAULT_CHUNK_SIZE));
        }

        // Ensure chunk size is within limits
        if ($chunk_size < ANIBAS_FM_CHUNK_SIZE_MIN) {
            $chunk_size = ANIBAS_FM_CHUNK_SIZE_MIN;
        }
        if ($chunk_size > ANIBAS_FM_CHUNK_SIZE_MAX) {
            $chunk_size = ANIBAS_FM_CHUNK_SIZE_MAX;
        }

        try {
            // Get file size via stat — no download needed
            $file_size = $this->sftp->filesize($source);

            if ($file_size === false || $file_size < 0) {
                ActivityLogger::get_instance()->log_message('SFTP Copy error: Failed to stat source file');
                return self::COPY_ERROR_SOURCE_NOT_FOUND;
            }

            if ($file_size === 0) {
                return $this->put_contents($target, '')
                    ? self::COPY_OPERATION_COMPLETE
                    : self::COPY_ERROR_CREATING_FILE;
            }

            // Check if copy is already complete
            if ($bytes_copied >= $file_size) {
                ActivityLogger::get_instance()->log_message('SFTP Copy already completed: ' . $bytes_copied . ' of ' . $file_size . ' bytes');
                return self::COPY_OPERATION_COMPLETE;
            }

            // Range-read only the chunk we need from source
            $chunk_data = $this->sftp->get($source, false, $bytes_copied, $chunk_size);

            if ($chunk_data === false) {
                ActivityLogger::get_instance()->log_message('SFTP Copy error: Failed to read chunk at position ' . $bytes_copied);
                return self::COPY_ERROR_DOWNLOADING_CHUNK;
            }

            $chunk_size_actual = strlen($chunk_data);

            if ($chunk_size_actual === 0) {
                ActivityLogger::get_instance()->log_message('SFTP Copy error: No data available for chunk at position ' . $bytes_copied);
                return self::COPY_ERROR_NO_DATA_RECEIVED;
            }

            if ($bytes_copied === 0) {
                // First chunk — create or overwrite target file
                $result = $this->sftp->put($target, $chunk_data);
                if ($result === false) {
                    ActivityLogger::get_instance()->log_message('SFTP Copy error: Failed to create target file');
                    return self::COPY_ERROR_CREATING_FILE;
                }
            } else {
                // Subsequent chunks — append using RESUME mode (no re-download of target)
                $result = $this->sftp->put($target, $chunk_data, \phpseclib3\Net\SFTP::SOURCE_STRING | \phpseclib3\Net\SFTP::RESUME);
                if ($result === false) {
                    ActivityLogger::get_instance()->log_message('SFTP Copy error: Failed to append chunk to target file');
                    return self::COPY_ERROR_APPENDING_TO_FILE;
                }
            }

            $new_bytes_copied = $bytes_copied + $chunk_size_actual;

            // Log progress
            $progress = ($new_bytes_copied / $file_size) * 100;
            $is_complete = $new_bytes_copied >= $file_size;

            ActivityLogger::get_instance()->log_message(
                "SFTP Copy chunk completed: position {$bytes_copied}-{$new_bytes_copied}, " .
                    round($progress, 2) . "% " .
                    ($is_complete ? "(COMPLETE)" : "(resume with bytes_copied={$new_bytes_copied})")
            );

            return $is_complete ? self::COPY_OPERATION_COMPLETE : self::COPY_OPERATION_IN_PROGRESS;
        } catch (\Exception $e) {
            ActivityLogger::get_instance()->log_message('SFTP chunked copy failed at position ' . $bytes_copied . ': ' . $e->getMessage());
            return self::COPY_ERROR_DOWNLOADING_CHUNK;
        }
    }

    /**
     * Get copy progress info for resumable operations
     */
    public function getCopyProgress($source, $target): array
    {
        try {
            $file_size  = $this->sftp->filesize($source);
            $file_size  = ($file_size !== false && $file_size >= 0) ? (int) $file_size : 0;
            $target_size = 0;
            if ($this->sftp->file_exists($target)) {
                $ts = $this->sftp->filesize($target);
                $target_size = ($ts !== false && $ts >= 0) ? (int) $ts : 0;
            }

            return [
                'file_size'        => $file_size,
                'bytes_copied'     => $target_size,
                'progress_percent' => $file_size > 0 ? ($target_size / $file_size) * 100 : 0,
                'is_complete'      => $target_size >= $file_size && $file_size > 0,
                'next_bytes_copied' => $target_size,
            ];
        } catch (\Exception $e) {
            throw new \Exception('Failed to get SFTP copy progress: ' . esc_html($e->getMessage()));
        }
    }

    /**
     * Delete destination file/folder for cancelled operations
     */
    public function deleteDestination($destination): bool
    {
        try {
            // Check if destination exists and delete it
            if ($this->sftp->file_exists($destination)) {
                return $this->sftp->delete($destination);
            }
            return true; // Nothing to delete
        } catch (\Exception $e) {
            ActivityLogger::get_instance()->log_message('Failed to delete SFTP destination: ' . $e->getMessage());
            throw $e;
        }
    }

    public function move($source, $target)
    {
        return $this->sftp->rename($source, $target);
    }

    public function unlink($path)
    {
        if (! $this->sftp->delete($path)) {
            ActivityLogger::get_instance()->log_message('SFTP unlink failed for "' . $path . '": ' . $this->sftp->getLastSFTPError());
            return false;
        }
        return true;
    }

    public function get_contents($path)
    {
        $content = $this->sftp->get($path);
        return ($content !== false) ? $content : false;
    }

    public function stream_contents($path): bool
    {
        try {
            return $this->sftp->get($path, static function ($chunk) {
                echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary stream
                flush();
            }) !== false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function get_size($path)
    {
        $size = $this->sftp->filesize($path);
        return ($size !== false && $size >= 0) ? (int) $size : false;
    }

    public function put_contents($path, $content)
    {
        return $this->sftp->put($path, $content);
    }

    public function append_contents($path, $content)
    {
        return $this->sftp->put($path, $content, \phpseclib3\Net\SFTP::SOURCE_STRING | \phpseclib3\Net\SFTP::RESUME);
    }

    public function download_to_local(string $remote_path, string $local_path): bool
    {
        try {
            return $this->sftp->get($remote_path, $local_path) !== false;
        } catch (\Throwable $e) {
            @unlink($local_path);
            return false;
        }
    }

    public function upload_from_local(string $local_path, string $remote_path): bool
    {
        try {
            return $this->sftp->put($remote_path, $local_path, \phpseclib3\Net\SFTP::SOURCE_LOCAL_FILE);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Chunked download from SFTP to local file using phpseclib range reads.
     */
    public function download_to_local_chunked(string $remote_path, string $local_path, int $offset = 0, int $chunk_size = 2097152): array
    {
        $chunk_size = intval(anibas_fm_get_option('chunk_size', ANIBAS_FM_DEFAULT_CHUNK_SIZE));
        if ($chunk_size < ANIBAS_FM_CHUNK_SIZE_MIN) $chunk_size = ANIBAS_FM_CHUNK_SIZE_MIN;
        if ($chunk_size > ANIBAS_FM_CHUNK_SIZE_MAX) $chunk_size = ANIBAS_FM_CHUNK_SIZE_MAX;

        try {
            $file_size = $this->sftp->filesize($remote_path);
            if ($file_size === false || $file_size < 0) {
                return ['status' => 5, 'bytes_copied' => 0];
            }
            if ((int) $file_size === 0) {
                $dir = dirname($local_path);
                if (! is_dir($dir)) {
                    wp_mkdir_p($dir);
                }
                return @touch($local_path)
                    ? ['status' => 9, 'bytes_copied' => 0]
                    : ['status' => 1, 'bytes_copied' => 0];
            }

            if ($offset >= $file_size) {
                return ['status' => 9, 'bytes_copied' => $file_size];
            }

            // Range read from remote
            $chunk_data = $this->sftp->get($remote_path, false, $offset, $chunk_size);
            if ($chunk_data === false || strlen($chunk_data) === 0) {
                return ['status' => 3, 'bytes_copied' => $offset];
            }

            // Write chunk to local file
            $mode = ($offset === 0) ? 'wb' : 'ab';
            $fp = fopen($local_path, $mode); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
            if (! $fp) {
                return ['status' => 1, 'bytes_copied' => $offset];
            }
            fwrite($fp, $chunk_data);
            fclose($fp);

            $new_offset = $offset + strlen($chunk_data);
            $is_complete = $new_offset >= $file_size;

            return [
                'status'       => $is_complete ? 9 : 10,
                'bytes_copied' => $new_offset,
            ];
        } catch (\Throwable $e) {
            ActivityLogger::get_instance()->log_message('SFTP phpseclib download_to_local_chunked error at offset ' . $offset . ': ' . $e->getMessage());
            return ['status' => 3, 'bytes_copied' => $offset];
        }
    }

    /**
     * Chunked upload from local file to SFTP using phpseclib RESUME mode.
     */
    public function upload_from_local_chunked(string $local_path, string $remote_path, int $offset = 0, int $chunk_size = 2097152): array
    {
        $chunk_size = intval(anibas_fm_get_option('chunk_size', ANIBAS_FM_DEFAULT_CHUNK_SIZE));
        if ($chunk_size < ANIBAS_FM_CHUNK_SIZE_MIN) $chunk_size = ANIBAS_FM_CHUNK_SIZE_MIN;
        if ($chunk_size > ANIBAS_FM_CHUNK_SIZE_MAX) $chunk_size = ANIBAS_FM_CHUNK_SIZE_MAX;

        $file_size = filesize($local_path);
        if ($file_size === false) {
            return ['status' => 5, 'bytes_copied' => 0];
        }

        if ((int) $file_size === 0) {
            return $this->put_contents($remote_path, '')
                ? ['status' => 9, 'bytes_copied' => 0]
                : ['status' => 1, 'bytes_copied' => 0];
        }

        if ($offset >= $file_size) {
            return ['status' => 9, 'bytes_copied' => $file_size];
        }

        try {
            // Read chunk from local file
            $fp = fopen($local_path, 'rb'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
            if (! $fp) {
                return ['status' => 1, 'bytes_copied' => $offset];
            }
            fseek($fp, $offset);
            $chunk_data = fread($fp, $chunk_size);
            fclose($fp);

            $chunk_actual = strlen($chunk_data);
            if ($chunk_actual === 0) {
                return ['status' => 7, 'bytes_copied' => $offset];
            }

            if ($offset === 0) {
                $result = $this->sftp->put($remote_path, $chunk_data);
            } else {
                $result = $this->sftp->put($remote_path, $chunk_data, \phpseclib3\Net\SFTP::SOURCE_STRING | \phpseclib3\Net\SFTP::RESUME);
            }

            if ($result === false) {
                $code = ($offset === 0) ? 1 : 2;
                return ['status' => $code, 'bytes_copied' => $offset];
            }

            $new_offset = $offset + $chunk_actual;
            $is_complete = $new_offset >= $file_size;

            return [
                'status'       => $is_complete ? 9 : 10,
                'bytes_copied' => $new_offset,
            ];
        } catch (\Throwable $e) {
            ActivityLogger::get_instance()->log_message('SFTP phpseclib upload_from_local_chunked error at offset ' . $offset . ': ' . $e->getMessage());
            return ['status' => 4, 'bytes_copied' => $offset];
        }
    }
}

class SFTPCurlBackend
{
    private $host;
    private $username;
    private $password;
    private $private_key;
    private $port;

    public function __construct($host, $username, $password, $private_key, $port)
    {
        $this->host = $host;
        $this->username = $username;
        $this->password = $password;
        $this->private_key = $private_key;
        $this->port = $port;
    }

    private function curl_request($path, $options = [])
    {
        $url = "sftp://{$this->host}:{$this->port}{$path}";

        $ch = curl_init();
        if (! $ch) {
            throw new \RuntimeException('cURL is not available');
        }
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 25);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        if ($this->private_key) {
            curl_setopt($ch, CURLOPT_SSH_PRIVATE_KEYFILE, $this->private_key);
            curl_setopt($ch, CURLOPT_SSH_PUBLIC_KEYFILE, $this->private_key . '.pub');
        } else {
            curl_setopt($ch, CURLOPT_USERPWD, "{$this->username}:{$this->password}");
        }

        foreach ($options as $key => $value) {
            curl_setopt($ch, $key, $value);
        }

        $result = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || $result === false) {
            throw new \Exception(esc_html($this->translate_sftp_error($error)));
        }

        return $result;
    }

    private function translate_sftp_error($error)
    {
        if (stripos($error, 'timeout') !== false) {
            return esc_html__('Connection timeout - server took too long to respond', 'anibas-file-manager');
        }
        if (stripos($error, 'authentication') !== false || stripos($error, 'login') !== false) {
            return esc_html__('Authentication failed - check username/password or SSH key', 'anibas-file-manager');
        }
        if (stripos($error, 'permission') !== false || stripos($error, 'access denied') !== false) {
            return esc_html__('Permission denied - check file/folder permissions', 'anibas-file-manager');
        }
        if (stripos($error, 'no such file') !== false || stripos($error, 'not found') !== false) {
            return esc_html__('File or directory not found', 'anibas-file-manager');
        }
        if (stripos($error, 'disk') !== false || stripos($error, 'space') !== false) {
            return esc_html__('Insufficient disk space on server', 'anibas-file-manager');
        }
        if (stripos($error, 'connection refused') !== false) {
            return esc_html__('Connection refused - check server address and port', 'anibas-file-manager');
        }
        return $error;
    }

    public function exists($path)
    {
        try {
            $this->curl_request($path, [CURLOPT_NOBODY => true]);
            return true;
        } catch (\Exception $e) {
            ActivityLogger::get_instance()->log_message('SFTP exists check failed for "' . $path . '": ' . $e->getMessage());
            return false;
        }
    }

    public function is_file($path)
    {
        return $this->exists($path) && ! $this->is_dir($path);
    }

    public function is_dir($path)
    {
        try {
            $this->curl_request($path, [CURLOPT_DIRLISTONLY => true]);
            return true;
        } catch (\Exception $e) {
            ActivityLogger::get_instance()->log_message('SFTP is_dir check failed for "' . $path . '": ' . $e->getMessage());
            return false;
        }
    }

    public function mkdir($path)
    {
        try {
            $this->curl_request($path, [
                CURLOPT_FTP_CREATE_MISSING_DIRS => true,
                CURLOPT_QUOTE => ['mkdir ' . $path]
            ]);
            return true;
        } catch (\Exception $e) {
            ActivityLogger::get_instance()->log_message('SFTP mkdir failed for "' . $path . '": ' . $e->getMessage());
            return false;
        }
    }

    public function scandir($path)
    {
        try {
            $result = $this->curl_request($path, [CURLOPT_DIRLISTONLY => true]);
            return array_values(array_filter(explode("\n", $result)));
        } catch (\Exception $e) {
            ActivityLogger::get_instance()->log_message('SFTP scandir failed for "' . $path . '": ' . $e->getMessage());
            return [];
        }
    }

    public function listDirectory($path, int $page = 1, int $pageSize = 100)
    {
        $page = max(1, $page);
        $pageSize = min(1000, max(1, $pageSize));
        $offset = ($page - 1) * $pageSize;
        $data = $this->read_directory_page($path, $offset, $pageSize);

        return [
            'items'       => array_values($data['items']),
            'total_items' => $offset + count($data['items']) + (! empty($data['has_more']) ? 1 : 0),
            'page'        => $page,
            'page_size'   => $pageSize,
            'has_more'    => ! empty($data['has_more']),
        ];
    }

    public function iterateDirectory($path, ?array $cursor = null, int $maxItems = 1000, array $options = []): array
    {
        $offset = is_array($cursor) && isset($cursor['offset']) ? max(0, (int) $cursor['offset']) : 0;
        $data = $this->read_directory_page($path, $offset, min(1000, max(1, $maxItems)));
        $entries = [];
        foreach ($data['items'] as $item) {
            $entries[] = [
                'name'      => $item['name'] ?? '',
                'is_folder' => ! empty($item['is_folder']),
                'path'      => $item['path'] ?? '',
                'filesize'  => $item['filesize'] ?? 0,
            ];
        }
        $next_offset = $offset + count($entries);
        return [
            'entries'     => $entries,
            'next_cursor' => ! empty($data['has_more']) ? ['offset' => $next_offset] : ['done' => true],
            'has_more'    => ! empty($data['has_more']),
        ];
    }

    private function read_directory_page($path, int $offset, int $limit): array
    {
        $url = "sftp://{$this->host}:{$this->port}{$path}";
        $items = [];
        $seen = 0;
        $buffer = '';
        $has_more = false;
        $stopped = false;

        $consume_line = function (string $line) use (&$items, &$seen, &$has_more, &$stopped, $path, $offset, $limit): void {
            $parsed = $this->parse_ls_line(trim($line));
            if (! $parsed || $parsed['name'] === '.' || $parsed['name'] === '..') {
                return;
            }
            if ($seen++ < $offset) {
                return;
            }
            if (count($items) >= $limit) {
                $has_more = true;
                $stopped = true;
                return;
            }
            $full_path = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $parsed['name'];
            $item = [
                'name'          => $parsed['name'],
                'path'          => $full_path,
                'is_folder'     => $parsed['is_dir'],
                'permission'    => 0,
                'last_modified' => $parsed['mtime'],
                'has_children'  => false,
                'files'         => [],
            ];
            if (! $parsed['is_dir']) {
                $item['filename']  = $parsed['name'];
                $item['filesize']  = $parsed['size'];
                $item['file_type'] = 'File';
            }
            $items[$parsed['name']] = $item;
        };

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 25);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        if ($this->private_key) {
            curl_setopt($ch, CURLOPT_SSH_PRIVATE_KEYFILE, $this->private_key);
            curl_setopt($ch, CURLOPT_SSH_PUBLIC_KEYFILE, $this->private_key . '.pub');
        } else {
            curl_setopt($ch, CURLOPT_USERPWD, "{$this->username}:{$this->password}");
        }
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $chunk) use (&$buffer, $consume_line, &$stopped) {
            $buffer .= $chunk;
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);
                $consume_line(trim($line));
                if ($stopped) {
                    return 0;
                }
            }
            return strlen($chunk);
        });

        $ok = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        if (! $stopped && $buffer !== '') {
            $consume_line(trim($buffer));
        }
        if (! $stopped && ($error || $ok === false)) {
            throw new \Exception(esc_html($this->translate_sftp_error($error)));
        }
        return ['items' => $items, 'has_more' => $has_more];
    }

    /**
     * Parse a single line of `ls -la` output.
     * Format: <perms> <links> <user> <group> <size> <month> <day> <time|year> <name>
     * Example: -rw-r--r-- 1 user group 12345 Jan  5 14:30 file.txt
     */
    private function parse_ls_line($line)
    {
        // Split into at most 9 tokens; everything after the 8th space is the filename
        $parts = preg_split('/\s+/', $line, 9);
        if (count($parts) < 9) return null;

        [$perms,,,, $size, $month, $day, $time_year, $name] = $parts;

        // First character: 'd' = directory, '-' = file, 'l' = symlink, etc.
        $is_dir = (strlen($perms) > 0 && $perms[0] === 'd');

        // Date: "Jan 5 14:30" or "Jan 5 2024"
        $mtime = strtotime("$month $day $time_year");
        if ($mtime === false) $mtime = 0;

        return [
            'is_dir' => $is_dir,
            'size'   => (int) $size,
            'mtime'  => (int) $mtime,
            'name'   => $name,
        ];
    }

    public function rmdir($path)
    {
        $this->curl_request($path, [CURLOPT_QUOTE => ['rmdir ' . $path]]);
        return true;
    }

    public function queuedRmdir($path, string $root = '', bool $allow_trash_root = false): bool
    {
        return $this->rmdir($path);
    }

    public function copy($source, $target)
    {
        try {
            return $this->copyFileInChunks($source, $target);
        } catch (\Exception $e) {
            ActivityLogger::get_instance()->log_message('Failed to copy file: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Copy file error codes
     */
    const COPY_NO_ERROR = 0;
    const COPY_ERROR_CREATING_FILE = 1;
    const COPY_ERROR_APPENDING_TO_FILE = 2;
    const COPY_ERROR_DOWNLOADING_CHUNK = 3;
    const COPY_ERROR_UPLOADING_CHUNK = 4;
    const COPY_ERROR_SOURCE_NOT_FOUND = 5;
    const COPY_ERROR_SOURCE_EMPTY = 6;
    const COPY_ERROR_NO_DATA_RECEIVED = 7;
    const COPY_ERROR_VERIFICATION_FAILED = 8;
    const COPY_OPERATION_COMPLETE = 9;
    const COPY_OPERATION_IN_PROGRESS = 10;

    /**
     * Copy file in chunks to avoid memory limits - resumable approach
     */
    public function copyFileInChunks($source, $target, ?int $chunk_size = null, $bytes_copied = 0): int
    {
        // Use dynamic chunk size from settings if not provided
        if ($chunk_size === null) {
            $chunk_size = intval(anibas_fm_get_option('chunk_size', ANIBAS_FM_DEFAULT_CHUNK_SIZE));
        }

        // Ensure chunk size is within limits
        if ($chunk_size < ANIBAS_FM_CHUNK_SIZE_MIN) {
            $chunk_size = ANIBAS_FM_CHUNK_SIZE_MIN;
        }
        if ($chunk_size > ANIBAS_FM_CHUNK_SIZE_MAX) {
            $chunk_size = ANIBAS_FM_CHUNK_SIZE_MAX;
        }

        try {
            // Get file size for progress tracking
            $url = "sftp://{$this->host}:{$this->port}{$source}";
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_NOBODY, true);
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 25);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

            if ($this->private_key) {
                curl_setopt($ch, CURLOPT_SSH_PRIVATE_KEYFILE, $this->private_key);
                curl_setopt($ch, CURLOPT_SSH_PUBLIC_KEYFILE, $this->private_key . '.pub');
            } else {
                curl_setopt($ch, CURLOPT_USERPWD, "{$this->username}:{$this->password}");
            }

            curl_exec($ch);
            $size_error = curl_error($ch);
            $file_size = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
            curl_close($ch);

            if ($size_error) {
                throw new \RuntimeException($this->translate_sftp_error($size_error));
            }

            if ($file_size === 0) {
                return $this->put_contents($target, '')
                    ? self::COPY_OPERATION_COMPLETE
                    : self::COPY_ERROR_CREATING_FILE;
            }

            // Check if copy is already complete
            if ($bytes_copied >= $file_size) {
                ActivityLogger::get_instance()->log_message('SFTP cURL Copy already completed: ' . $bytes_copied . ' of ' . $file_size . ' bytes');
                return self::COPY_OPERATION_COMPLETE;
            }

            // Download single chunk from source
            $source_ch = curl_init();
            curl_setopt($source_ch, CURLOPT_URL, $url);
            curl_setopt($source_ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($source_ch, CURLOPT_BUFFERSIZE, $chunk_size);
            curl_setopt($source_ch, CURLOPT_TIMEOUT, 25);
            curl_setopt($source_ch, CURLOPT_CONNECTTIMEOUT, 10);

            // Set range to download only the chunk we need
            $range_start = $bytes_copied;
            $range_end = min($bytes_copied + $chunk_size - 1, $file_size - 1);
            curl_setopt($source_ch, CURLOPT_RANGE, "{$range_start}-{$range_end}");

            if ($this->private_key) {
                curl_setopt($source_ch, CURLOPT_SSH_PRIVATE_KEYFILE, $this->private_key);
                curl_setopt($source_ch, CURLOPT_SSH_PUBLIC_KEYFILE, $this->private_key . '.pub');
            } else {
                curl_setopt($source_ch, CURLOPT_USERPWD, "{$this->username}:{$this->password}");
            }

            $chunk_data = curl_exec($source_ch);
            $error = curl_error($source_ch);
            curl_close($source_ch);

            if ($error) {
                ActivityLogger::get_instance()->log_message('SFTP cURL Copy error: Failed to download chunk at position ' . $bytes_copied . ': ' . $error);
                throw new \RuntimeException($this->translate_sftp_error($error));
            }

            if ($chunk_data === false) {
                ActivityLogger::get_instance()->log_message('SFTP cURL Copy error: Failed to download chunk at position ' . $bytes_copied);
                return self::COPY_ERROR_DOWNLOADING_CHUNK;
            }

            $chunk_size_actual = strlen($chunk_data);
            if ($chunk_size_actual === 0) {
                ActivityLogger::get_instance()->log_message('SFTP cURL Copy error: No data received for chunk at position ' . $bytes_copied);
                return self::COPY_ERROR_NO_DATA_RECEIVED;
            }

            // Upload chunk to target
            $target_url = "sftp://{$this->host}:{$this->port}{$target}";
            $target_ch = curl_init();
            curl_setopt($target_ch, CURLOPT_URL, $target_url);
            curl_setopt($target_ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($target_ch, CURLOPT_UPLOAD, true);
            curl_setopt($target_ch, CURLOPT_INFILESIZE, $chunk_size_actual);
            curl_setopt($target_ch, CURLOPT_TIMEOUT, 25);
            curl_setopt($target_ch, CURLOPT_CONNECTTIMEOUT, 10);

            // Create temp handle for this chunk
            $chunk_handle = fopen('php://temp', 'r+');
            fwrite($chunk_handle, $chunk_data);
            rewind($chunk_handle);
            curl_setopt($target_ch, CURLOPT_INFILE, $chunk_handle);

            // For first chunk, create new file. For subsequent chunks, append
            if ($bytes_copied === 0) {
                // First chunk - create new file
                // SFTP cURL doesn't have direct append, so we'll need to handle differently
            } else {
                // Subsequent chunks - append to existing file
                curl_setopt($target_ch, CURLOPT_APPEND, true);
            }

            if ($this->private_key) {
                curl_setopt($target_ch, CURLOPT_SSH_PRIVATE_KEYFILE, $this->private_key);
                curl_setopt($target_ch, CURLOPT_SSH_PUBLIC_KEYFILE, $this->private_key . '.pub');
            } else {
                curl_setopt($target_ch, CURLOPT_USERPWD, "{$this->username}:{$this->password}");
            }

            $upload_result = curl_exec($target_ch);
            $upload_error = curl_error($target_ch);
            curl_close($target_ch);
            fclose($chunk_handle);

            if ($upload_error) {
                $error_code = ($bytes_copied === 0) ? self::COPY_ERROR_CREATING_FILE : self::COPY_ERROR_APPENDING_TO_FILE;
                ActivityLogger::get_instance()->log_message('SFTP cURL Copy error: Failed to upload chunk at position ' . $bytes_copied . ': ' . $upload_error . ' (code: ' . $error_code . ')');
                throw new \RuntimeException($this->translate_sftp_error($upload_error));
            }

            if ($upload_result === false) {
                $error_code = ($bytes_copied === 0) ? self::COPY_ERROR_CREATING_FILE : self::COPY_ERROR_APPENDING_TO_FILE;
                ActivityLogger::get_instance()->log_message('SFTP cURL Copy error: Failed to upload chunk at position ' . $bytes_copied . ' (code: ' . $error_code . ')');
                return $error_code;
            }

            $new_bytes_copied = $bytes_copied + $chunk_size_actual;

            // Log progress
            $progress = ($new_bytes_copied / $file_size) * 100;
            $is_complete = $new_bytes_copied >= $file_size;

            ActivityLogger::get_instance()->log_message(
                "SFTP cURL Copy chunk completed: position {$bytes_copied}-{$new_bytes_copied}, " .
                    round($progress, 2) . "% " .
                    ($is_complete ? "(COMPLETE)" : "(resume with bytes_copied={$new_bytes_copied})")
            );

            // Return appropriate status code
            return $is_complete ? self::COPY_OPERATION_COMPLETE : self::COPY_OPERATION_IN_PROGRESS;
        } catch (\Exception $e) {
            ActivityLogger::get_instance()->log_message('SFTP cURL Copy exception at position ' . $bytes_copied . ': ' . $e->getMessage());
            if (stripos($e->getMessage(), 'SSH') !== false || stripos($e->getMessage(), 'connection') !== false) {
                throw $e;
            }
            return self::COPY_ERROR_DOWNLOADING_CHUNK;
        }
    }

    /**
     * Get copy progress info for resumable operations
     */
    public function getCopyProgress($source, $target): array
    {
        try {
            // Get source file size
            $url = "sftp://{$this->host}:{$this->port}{$source}";
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_NOBODY, true);
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 25);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

            if ($this->private_key) {
                curl_setopt($ch, CURLOPT_SSH_PRIVATE_KEYFILE, $this->private_key);
                curl_setopt($ch, CURLOPT_SSH_PUBLIC_KEYFILE, $this->private_key . '.pub');
            } else {
                curl_setopt($ch, CURLOPT_USERPWD, "{$this->username}:{$this->password}");
            }

            curl_exec($ch);
            $file_size = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);

            // Get target file size (if exists)
            $target_size = 0;
            try {
                $target_url = "sftp://{$this->host}:{$this->port}{$target}";
                $target_ch = curl_init();
                curl_setopt($target_ch, CURLOPT_URL, $target_url);
                curl_setopt($target_ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($target_ch, CURLOPT_NOBODY, true);
                curl_setopt($target_ch, CURLOPT_HEADER, false);
                curl_setopt($target_ch, CURLOPT_TIMEOUT, 25);
                curl_setopt($target_ch, CURLOPT_CONNECTTIMEOUT, 10);

                if ($this->private_key) {
                    curl_setopt($target_ch, CURLOPT_SSH_PRIVATE_KEYFILE, $this->private_key);
                    curl_setopt($target_ch, CURLOPT_SSH_PUBLIC_KEYFILE, $this->private_key . '.pub');
                } else {
                    curl_setopt($target_ch, CURLOPT_USERPWD, "{$this->username}:{$this->password}");
                }

                curl_exec($target_ch);
                $target_size = curl_getinfo($target_ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
            } catch (\Exception $e) {
                // Target file doesn't exist yet
                $target_size = 0;
            }

            return [
                'file_size' => $file_size,
                'bytes_copied' => $target_size,
                'progress_percent' => $file_size > 0 ? ($target_size / $file_size) * 100 : 0,
                'is_complete' => $target_size >= $file_size && $file_size > 0,
                'next_bytes_copied' => $target_size
            ];
        } catch (\Exception $e) {
            throw new \Exception('Failed to get SFTP cURL copy progress: ' . esc_html($e->getMessage()));
        }
    }

    /**
     * Delete destination file/folder for cancelled operations
     */
    public function deleteDestination($destination): bool
    {
        try {
            // Check if destination exists and delete it
            $url = "sftp://{$this->host}:{$this->port}{$destination}";
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_NOBODY, true);
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 25);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

            if ($this->private_key) {
                curl_setopt($ch, CURLOPT_SSH_PRIVATE_KEYFILE, $this->private_key);
                curl_setopt($ch, CURLOPT_SSH_PUBLIC_KEYFILE, $this->private_key . '.pub');
            } else {
                curl_setopt($ch, CURLOPT_USERPWD, "{$this->username}:{$this->password}");
            }

            $result = curl_exec($ch);
            $error = curl_error($ch);

            if ($result !== false && !$error) {
                // Destination exists, delete it
                $delete_ch = curl_init();
                curl_setopt($delete_ch, CURLOPT_URL, $url);
                curl_setopt($delete_ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($delete_ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                curl_setopt($delete_ch, CURLOPT_TIMEOUT, 25);
                curl_setopt($delete_ch, CURLOPT_CONNECTTIMEOUT, 10);

                if ($this->private_key) {
                    curl_setopt($delete_ch, CURLOPT_SSH_PRIVATE_KEYFILE, $this->private_key);
                    curl_setopt($delete_ch, CURLOPT_SSH_PUBLIC_KEYFILE, $this->private_key . '.pub');
                } else {
                    curl_setopt($delete_ch, CURLOPT_USERPWD, "{$this->username}:{$this->password}");
                }

                $delete_result = curl_exec($delete_ch);
                $delete_error = curl_error($delete_ch);

                if ($delete_error) {
                    throw new \Exception('Failed to delete SFTP destination: ' . $delete_error);
                }

                return $delete_result !== false;
            }
            return true; // Nothing to delete
        } catch (\Exception $e) {
            ActivityLogger::get_instance()->log_message('Failed to delete SFTP cURL destination: ' . $e->getMessage());
            throw $e;
        }
    }

    public function move($source, $target)
    {
        try {
            $this->curl_request($source, [CURLOPT_QUOTE => ["rename {$source} {$target}"]]);
            return true;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function unlink($path)
    {
        try {
            $this->curl_request($path, [CURLOPT_QUOTE => ['rm ' . $path]]);
            return true;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function get_contents($path)
    {
        try {
            return $this->curl_request($path, [CURLOPT_RETURNTRANSFER => true]);
        } catch (\Exception $e) {
            return false;
        }
    }

    public function stream_contents($path): bool
    {
        try {
            $url = "sftp://{$this->host}:{$this->port}{$path}";
            $ch  = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 25);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            if ($this->private_key) {
                curl_setopt($ch, CURLOPT_SSH_PRIVATE_KEYFILE, $this->private_key);
                curl_setopt($ch, CURLOPT_SSH_PUBLIC_KEYFILE, $this->private_key . '.pub');
            } else {
                curl_setopt($ch, CURLOPT_USERPWD, "{$this->username}:{$this->password}");
            }
            curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function ($ch, $chunk) {
                echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary stream
                flush();
                return strlen($chunk);
            });
            $result = curl_exec($ch);
            $error = curl_error($ch);
            curl_close($ch);
            return $result !== false && $error === '';
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function get_size($path)
    {
        try {
            $url = "sftp://{$this->host}:{$this->port}{$path}";
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_NOBODY, true);
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 25);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

            if ($this->private_key) {
                curl_setopt($ch, CURLOPT_SSH_PRIVATE_KEYFILE, $this->private_key);
                curl_setopt($ch, CURLOPT_SSH_PUBLIC_KEYFILE, $this->private_key . '.pub');
            } else {
                curl_setopt($ch, CURLOPT_USERPWD, "{$this->username}:{$this->password}");
            }

            curl_exec($ch);
            $size = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
            return ($size !== false && $size >= 0) ? (int) $size : false;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function put_contents($path, $content)
    {
        return $this->upload_content($path, $content);
    }

    public function append_contents($path, $content)
    {
        return $this->upload_content($path, $content, true);
    }

    private function upload_content($path, $content, bool $append = false)
    {
        $url = "sftp://{$this->host}:{$this->port}{$path}";
        $stream = fopen('php://temp', 'r+'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        if (! $stream) {
            return false;
        }
        fwrite($stream, $content);
        rewind($stream);

        $ch = curl_init();
        if (! $ch) {
            fclose($stream);
            return false;
        }

        try {
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_UPLOAD, true);
            curl_setopt($ch, CURLOPT_INFILE, $stream);
            curl_setopt($ch, CURLOPT_INFILESIZE, strlen($content));
            curl_setopt($ch, CURLOPT_TIMEOUT, 25);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            if ($append) {
                curl_setopt($ch, CURLOPT_APPEND, true);
            }

            if ($this->private_key) {
                curl_setopt($ch, CURLOPT_SSH_PRIVATE_KEYFILE, $this->private_key);
                curl_setopt($ch, CURLOPT_SSH_PUBLIC_KEYFILE, $this->private_key . '.pub');
            } else {
                curl_setopt($ch, CURLOPT_USERPWD, "{$this->username}:{$this->password}");
            }

            $result = curl_exec($ch);
            $error = curl_error($ch);

            if ($error || $result === false) {
                ActivityLogger::get_instance()->log_message('SFTP cURL put_contents failed for "' . $path . '": ' . ($error ?: 'unknown error'));
                throw new \RuntimeException($error ?: 'SFTP upload failed');
            }

            return true;
        } finally {
            curl_close($ch);
            fclose($stream);
        }
    }

    public function download_to_local(string $remote_path, string $local_path): bool
    {
        $fp = fopen($local_path, 'wb'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        if (! $fp) {
            return false;
        }
        try {
            $url = "sftp://{$this->host}:{$this->port}{$remote_path}";
            $ch  = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_TIMEOUT, 25);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

            if ($this->private_key) {
                curl_setopt($ch, CURLOPT_SSH_PRIVATE_KEYFILE, $this->private_key);
                curl_setopt($ch, CURLOPT_SSH_PUBLIC_KEYFILE, $this->private_key . '.pub');
            } else {
                curl_setopt($ch, CURLOPT_USERPWD, "{$this->username}:{$this->password}");
            }

            $result = curl_exec($ch);
            $error  = curl_error($ch);
            curl_close($ch);
            fclose($fp);
            $fp = null;

            if ($error || $result === false) {
                @unlink($local_path);
                throw new \RuntimeException($this->translate_sftp_error($error ?: 'SFTP download failed'));
            }
            return true;
        } catch (\Throwable $e) {
            if (is_resource($fp)) {
                fclose($fp);
            }
            @unlink($local_path);
            if (stripos($e->getMessage(), 'SSH') !== false || stripos($e->getMessage(), 'connection') !== false) {
                throw $e;
            }
            return false;
        }
    }

    public function upload_from_local(string $local_path, string $remote_path): bool
    {
        $fp = fopen($local_path, 'rb'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        if (! $fp) {
            return false;
        }
        $size = filesize($local_path);
        try {
            $url = "sftp://{$this->host}:{$this->port}{$remote_path}";
            $ch  = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_UPLOAD, true);
            curl_setopt($ch, CURLOPT_INFILE, $fp);
            curl_setopt($ch, CURLOPT_INFILESIZE, $size);
            curl_setopt($ch, CURLOPT_TIMEOUT, 25);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

            if ($this->private_key) {
                curl_setopt($ch, CURLOPT_SSH_PRIVATE_KEYFILE, $this->private_key);
                curl_setopt($ch, CURLOPT_SSH_PUBLIC_KEYFILE, $this->private_key . '.pub');
            } else {
                curl_setopt($ch, CURLOPT_USERPWD, "{$this->username}:{$this->password}");
            }

            $result = curl_exec($ch);
            $error  = curl_error($ch);
            curl_close($ch);
            fclose($fp);
            $fp = null;
            if ($error || $result === false) {
                throw new \RuntimeException($this->translate_sftp_error($error ?: 'SFTP upload failed'));
            }
            return true;
        } catch (\Throwable $e) {
            if (is_resource($fp)) {
                fclose($fp);
            }
            if (stripos($e->getMessage(), 'SSH') !== false || stripos($e->getMessage(), 'connection') !== false) {
                throw $e;
            }
            return false;
        }
    }

    /**
     * Chunked download from SFTP to local file using CURLOPT_RANGE.
     */
    public function download_to_local_chunked(string $remote_path, string $local_path, int $offset = 0, int $chunk_size = 2097152): array
    {
        $chunk_size = intval(anibas_fm_get_option('chunk_size', ANIBAS_FM_DEFAULT_CHUNK_SIZE));
        if ($chunk_size < ANIBAS_FM_CHUNK_SIZE_MIN) $chunk_size = ANIBAS_FM_CHUNK_SIZE_MIN;
        if ($chunk_size > ANIBAS_FM_CHUNK_SIZE_MAX) $chunk_size = ANIBAS_FM_CHUNK_SIZE_MAX;

        try {
            // Get file size
            $url = "sftp://{$this->host}:{$this->port}{$remote_path}";
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_NOBODY, true);
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 25);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

            if ($this->private_key) {
                curl_setopt($ch, CURLOPT_SSH_PRIVATE_KEYFILE, $this->private_key);
                curl_setopt($ch, CURLOPT_SSH_PUBLIC_KEYFILE, $this->private_key . '.pub');
            } else {
                curl_setopt($ch, CURLOPT_USERPWD, "{$this->username}:{$this->password}");
            }

            curl_exec($ch);
            $file_size = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);

            if ($file_size < 0) {
                return ['status' => 5, 'bytes_copied' => 0];
            }
            if ((int) $file_size === 0) {
                $dir = dirname($local_path);
                if (! is_dir($dir)) {
                    wp_mkdir_p($dir);
                }
                return @touch($local_path)
                    ? ['status' => 9, 'bytes_copied' => 0]
                    : ['status' => 1, 'bytes_copied' => 0];
            }

            if ($offset >= $file_size) {
                return ['status' => 9, 'bytes_copied' => $file_size];
            }

            // Range download
            $source_ch = curl_init();
            curl_setopt($source_ch, CURLOPT_URL, $url);
            curl_setopt($source_ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($source_ch, CURLOPT_BUFFERSIZE, $chunk_size);
            curl_setopt($source_ch, CURLOPT_TIMEOUT, 25);
            curl_setopt($source_ch, CURLOPT_CONNECTTIMEOUT, 10);

            $range_end = min($offset + $chunk_size - 1, $file_size - 1);
            curl_setopt($source_ch, CURLOPT_RANGE, "{$offset}-{$range_end}");

            if ($this->private_key) {
                curl_setopt($source_ch, CURLOPT_SSH_PRIVATE_KEYFILE, $this->private_key);
                curl_setopt($source_ch, CURLOPT_SSH_PUBLIC_KEYFILE, $this->private_key . '.pub');
            } else {
                curl_setopt($source_ch, CURLOPT_USERPWD, "{$this->username}:{$this->password}");
            }

            $chunk_data = curl_exec($source_ch);
            $error = curl_error($source_ch);

            if ($error || $chunk_data === false || strlen($chunk_data) === 0) {
                return ['status' => 3, 'bytes_copied' => $offset];
            }

            // Write chunk to local file
            $mode = ($offset === 0) ? 'wb' : 'ab';
            $fp = fopen($local_path, $mode); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
            if (! $fp) {
                return ['status' => 1, 'bytes_copied' => $offset];
            }
            fwrite($fp, $chunk_data);
            fclose($fp);

            $new_offset = $offset + strlen($chunk_data);
            $is_complete = $new_offset >= $file_size;

            return [
                'status'       => $is_complete ? 9 : 10,
                'bytes_copied' => $new_offset,
            ];
        } catch (\Throwable $e) {
            ActivityLogger::get_instance()->log_message('SFTP cURL download_to_local_chunked error at offset ' . $offset . ': ' . $e->getMessage());
            return ['status' => 3, 'bytes_copied' => $offset];
        }
    }

    /**
     * Chunked upload from local file to SFTP using CURLOPT_APPEND.
     */
    public function upload_from_local_chunked(string $local_path, string $remote_path, int $offset = 0, int $chunk_size = 2097152): array
    {
        $chunk_size = intval(anibas_fm_get_option('chunk_size', ANIBAS_FM_DEFAULT_CHUNK_SIZE));
        if ($chunk_size < ANIBAS_FM_CHUNK_SIZE_MIN) $chunk_size = ANIBAS_FM_CHUNK_SIZE_MIN;
        if ($chunk_size > ANIBAS_FM_CHUNK_SIZE_MAX) $chunk_size = ANIBAS_FM_CHUNK_SIZE_MAX;

        $file_size = filesize($local_path);
        if ($file_size === false) {
            return ['status' => 5, 'bytes_copied' => 0];
        }

        if ((int) $file_size === 0) {
            return $this->put_contents($remote_path, '')
                ? ['status' => 9, 'bytes_copied' => 0]
                : ['status' => 1, 'bytes_copied' => 0];
        }

        if ($offset >= $file_size) {
            return ['status' => 9, 'bytes_copied' => $file_size];
        }

        try {
            // Read chunk from local file
            $fp = fopen($local_path, 'rb'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
            if (! $fp) {
                return ['status' => 1, 'bytes_copied' => $offset];
            }
            fseek($fp, $offset);
            $chunk_data = fread($fp, $chunk_size);
            fclose($fp);

            $chunk_actual = strlen($chunk_data);
            if ($chunk_actual === 0) {
                return ['status' => 7, 'bytes_copied' => $offset];
            }

            // Upload chunk
            $url = "sftp://{$this->host}:{$this->port}{$remote_path}";
            $chunk_handle = fopen('php://temp', 'r+'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
            fwrite($chunk_handle, $chunk_data);
            rewind($chunk_handle);

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_UPLOAD, true);
            curl_setopt($ch, CURLOPT_INFILE, $chunk_handle);
            curl_setopt($ch, CURLOPT_INFILESIZE, $chunk_actual);
            curl_setopt($ch, CURLOPT_TIMEOUT, 25);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

            if ($offset > 0) {
                curl_setopt($ch, CURLOPT_APPEND, true);
            }

            if ($this->private_key) {
                curl_setopt($ch, CURLOPT_SSH_PRIVATE_KEYFILE, $this->private_key);
                curl_setopt($ch, CURLOPT_SSH_PUBLIC_KEYFILE, $this->private_key . '.pub');
            } else {
                curl_setopt($ch, CURLOPT_USERPWD, "{$this->username}:{$this->password}");
            }

            $result = curl_exec($ch);
            $error = curl_error($ch);
            fclose($chunk_handle);

            if ($error || $result === false) {
                $code = ($offset === 0) ? 1 : 2;
                return ['status' => $code, 'bytes_copied' => $offset];
            }

            $new_offset = $offset + $chunk_actual;
            $is_complete = $new_offset >= $file_size;

            return [
                'status'       => $is_complete ? 9 : 10,
                'bytes_copied' => $new_offset,
            ];
        } catch (\Throwable $e) {
            ActivityLogger::get_instance()->log_message('SFTP cURL upload_from_local_chunked error at offset ' . $offset . ': ' . $e->getMessage());
            return ['status' => 4, 'bytes_copied' => $offset];
        }
    }
}
