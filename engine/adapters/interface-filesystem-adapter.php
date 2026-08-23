<?php

namespace Anibas;

if ( ! defined( 'ABSPATH' ) ) exit;


abstract class FileSystemAdapter
{
    protected const FALLBACK_BUFFER_LIMIT = 1048576;

    /** Storage ID this adapter represents (e.g. 'local', 'ftp', 's3'). Set by StorageManager. */
    protected ?string $storage_id = null;

    public function set_storage_id(string $id): void
    {
        $this->storage_id = $id;
    }

    public function get_storage_id(): ?string
    {
        return $this->storage_id;
    }

    abstract public function validate_path(string $path): string|false;
    abstract public function exists(string $path): bool;
    abstract public function is_file(string $path): bool;
    abstract public function is_dir(string $path): bool;
    abstract public function mkdir(string $path): bool;
    abstract public function scandir(string $path): array;
    abstract public function listDirectory(string $path, int $page = 1, int $pageSize = 100): array;

    /**
     * Streaming directory iterator for queue-building (background jobs).
     *
     * Returns a chunk of entries plus a cursor for resumption. Designed to
     * walk directories with millions of children without loading or sorting
     * the whole listing in one call. Order is adapter-defined and may differ
     * from listDirectory(); callers must not depend on it.
     *
     * Adapters with native pagination (e.g. S3 continuation tokens, local
     * readdir) should override this with a true streaming implementation.
     * The default below performs a single full listDirectory() call and is
     * adequate only for adapters whose listings already fit in memory.
     *
     * @param string     $path     Validated path on this adapter.
     * @param array|null $cursor   Opaque cursor from a previous call, or null to start.
     * @param int        $maxItems Soft cap on entries returned in this call.
     * @param array      $options  Adapter-specific options for background jobs.
     * @return array{entries: array, next_cursor: array|null, has_more: bool}
     *   entries: list of {name, is_folder, path, filesize?}
     */
    public function iterateDirectory(string $path, ?array $cursor = null, int $maxItems = 1000, array $options = []): array
    {
        // Default fallback: single-shot listDirectory(), drained on first call.
        if (! empty($cursor['done'])) {
            return ['entries' => [], 'next_cursor' => ['done' => true], 'has_more' => false];
        }

        $data  = $this->listDirectory($path);
        $items = $data['items'] ?? [];

        $entries = [];
        foreach ($items as $item) {
            $entries[] = [
                'name'      => $item['name'] ?? '',
                'is_folder' => ! empty($item['is_folder']),
                'path'      => $item['path'] ?? '',
                'filesize'  => $item['filesize'] ?? 0,
            ];
        }

        return [
            'entries'     => $entries,
            'next_cursor' => ['done' => true],
            'has_more'    => false,
        ];
    }
    abstract public function rmdir(string $path): bool;
    abstract public function copy(string $source, string $target): bool;
    abstract public function move(string $source, string $target): bool;
    abstract public function unlink(string $path): bool;
    abstract public function put_contents(string $path, string $content): bool;
    abstract public function append_contents(string $path, string $content): bool;
    abstract public function get_contents(string $path): string|false;

    /**
     * Delete a file or folder, routing to trash when applicable.
     *
     * Implementations decide between trash, synchronous unlink, or background
     * job (for large folder deletes). The handler stays unaware of the choice.
     *
     * @param string $path Validated path on this adapter.
     * @return true|array|\WP_Error
     *   - true             on synchronous success
     *   - ['job_id'=>...]  when work was enqueued for background processing
     *   - WP_Error         on failure (use ownership_hint() to enrich messages)
     */
    public function delete(string $path)
    {
        if (! $this->exists($path)) {
            return new \WP_Error('not_found', __('File or folder not found', 'anibas-file-manager'));
        }

        if ($this->is_dir($path)) {
            if ($this->storage_id && class_exists('Anibas\\BackgroundProcessor')) {
                $job_id = BackgroundProcessor::enqueue_delete_job($path, $this->storage_id);
                if (is_wp_error($job_id)) {
                    return $job_id;
                }
                return ['job_id' => $job_id];
            }
            return $this->rmdir($path)
                ? true
                : new \WP_Error('delete_failed', __('Failed to delete folder', 'anibas-file-manager'));
        }

        return $this->unlink($path)
            ? true
            : new \WP_Error('delete_failed', __('Failed to delete file', 'anibas-file-manager'));
    }

    /**
     * Build a hint about ownership/permissions when a delete fails on local storage.
     * Returns an empty string when the info isn't available or doesn't apply.
     */
    public static function ownership_hint(string $path): string
    {
        if (! function_exists('posix_geteuid') || ! function_exists('posix_getpwuid')) {
            return '';
        }
        $file_uid = @fileowner($path);
        $proc_uid = @posix_geteuid();
        if ($file_uid === false || $file_uid === $proc_uid) {
            return '';
        }
        $owner_info = @posix_getpwuid($file_uid);
        $proc_info  = @posix_getpwuid($proc_uid);
        $owner_name = is_array($owner_info) && isset($owner_info['name']) ? $owner_info['name'] : ('uid=' . $file_uid);
        $proc_name  = is_array($proc_info)  && isset($proc_info['name'])  ? $proc_info['name']  : ('uid=' . $proc_uid);

        return sprintf(
            /* translators: 1: owner of the file, 2: web server process user */
            __(' Owned by "%1$s" but the web server runs as "%2$s" — change ownership or grant write permissions.', 'anibas-file-manager'),
            $owner_name,
            $proc_name
        );
    }

    /**
     * Explain why a delete just failed. Combines the last PHP/syscall error
     * (in-use, read-only, permission, etc.) with parent-folder writability and
     * ownership checks. Caller should error_clear_last() right before the
     * failing call so the message we read belongs to that operation.
     *
     * Returns a leading-space-prefixed sentence (or empty string) so it can be
     * appended directly to a generic "Failed to delete." message.
     */
    public static function delete_failure_reason(string $path): string
    {
        $hints = [];

        $last = error_get_last();
        $msg  = is_array($last) ? (string) ($last['message'] ?? '') : '';

        // Map common errno strings PHP surfaces from unlink/rmdir/rename.
        // Order matters: check the more specific busy/ro/not-empty cases
        // before the generic permission match.
        if ($msg !== '') {
            if (stripos($msg, 'busy') !== false) {
                $hints[] = __('The file appears to be in use by another process.', 'anibas-file-manager');
            } elseif (stripos($msg, 'read-only') !== false) {
                $hints[] = __('The filesystem is mounted read-only.', 'anibas-file-manager');
            } elseif (stripos($msg, 'directory not empty') !== false) {
                $hints[] = __('The folder is not empty.', 'anibas-file-manager');
            } elseif (stripos($msg, 'no space') !== false) {
                $hints[] = __('No space left on device (trash needs free space).', 'anibas-file-manager');
            } elseif (stripos($msg, 'permission denied') !== false || stripos($msg, 'not permitted') !== false) {
                $hints[] = __('Permission denied by the operating system.', 'anibas-file-manager');
            }
        }

        // Removing an entry requires write+execute on the *parent* directory,
        // not the file itself — call this out separately from the file owner.
        $parent = dirname($path);
        if (is_dir($parent) && ! is_writable($parent)) {
            $hints[] = __('The parent folder is not writable.', 'anibas-file-manager');
        }

        $own = self::ownership_hint($path);
        if ($own !== '') {
            $hints[] = trim($own);
        }

        return $hints ? ' ' . implode(' ', $hints) : '';
    }
    public function stream_contents(string $path): bool
    {
        if (! $this->can_use_buffered_fallback($path)) {
            return false;
        }

        $content = $this->get_contents($path);
        if ($content !== false) {
            echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary file stream
            flush();
            return true;
        }
        return false;
    }
    public function get_temporary_link(string $path, int $duration = 3600): string|false
    {
        return false;
    }
    public function get_size(string $path): int|false
    {
        return false;
    }
    public function is_empty(string $path): bool
    {
        return false;
    }

    /**
     * Get extended metadata for a single file/folder.
     * Returns fields beyond what listDirectory provides (owner, group, mime, created, etc.).
     * Adapters override to supply richer data; fields that aren't available return null.
     *
     * @param string $path Validated absolute path.
     * @return array|false
     */
    public function getDetails(string $path): array|false
    {
        $name  = basename($path);
        $isDir = $this->is_dir($path);

        return [
            'name'             => $name,
            'path'             => $path,
            'is_folder'        => $isDir,
            'size'             => null,
            'last_modified'    => null,
            'created'          => null,
            'permission'       => null,
            'permission_octal' => null,
            'owner'            => null,
            'group'            => null,
            'extension'        => $isDir ? '' : pathinfo($name, PATHINFO_EXTENSION),
            'mime_type'        => null,
        ];
    }

    /**
     * Whether this adapter represents local (non-remote) storage.
     * Override in LocalFileSystemAdapter to return true.
     */
    public function is_local_storage(): bool
    {
        return false;
    }

    /**
     * Whether this adapter supports resumable chunked transfers (Range-based download, append-based upload).
     * Adapters that return false will be blocked from cross-storage transfers.
     */
    public function supports_chunked_transfer(): bool
    {
        return false;
    }

    public function requires_local_upload_assembly(): bool
    {
        return false;
    }

    /**
     * Download a remote file to a local filesystem path.
     * Default implementation uses get_contents() — adapters should override for streaming.
     *
     * @param string $remote_path Path on the remote storage.
     * @param string $local_path  Absolute path on the local filesystem.
     * @return bool
     */
    public function download_to_local(string $remote_path, string $local_path): bool
    {
        if (! $this->can_use_buffered_fallback($remote_path)) {
            return false;
        }

        $content = $this->get_contents($remote_path);
        if ($content === false) {
            return false;
        }
        $dir = dirname($local_path);
        if (! is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        return file_put_contents($local_path, $content) !== false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents
    }

    /**
     * Upload a local filesystem file to remote storage.
     * Default implementation uses put_contents() — adapters should override for streaming.
     *
     * @param string $local_path  Absolute path on the local filesystem.
     * @param string $remote_path Path on the remote storage.
     * @return bool
     */
    public function upload_from_local(string $local_path, string $remote_path): bool
    {
        try {
            $content = anibas_fm_read_small_file($local_path);
        } catch (\Throwable $e) {
            return false;
        }
        return $this->put_contents($remote_path, $content) !== false;
    }

    /**
     * Chunked cross-storage transfer: download a portion of a remote file.
     *
     * Returns an int status code matching the COPY_* constants from LocalFileSystemAdapter:
     *   9  = COPY_OPERATION_COMPLETE
     *   10 = COPY_OPERATION_IN_PROGRESS (more data to fetch)
     *   1+ = error codes
     *
     * @param string $remote_path  Path on the remote storage.
     * @param string $local_path   Local temp file to append chunks to.
     * @param int    $offset       Byte offset to resume from.
     * @param int    $chunk_size   Bytes to fetch in this call.
     * @return array{status: int, bytes_copied: int}
     */
    public function download_to_local_chunked(string $remote_path, string $local_path, int $offset = 0, int $chunk_size = 2097152): array
    {
        // Default: single-shot download (adapters override for true chunking)
        if ($offset === 0) {
            $ok = $this->download_to_local($remote_path, $local_path);
            if (! $ok) {
                return ['status' => 1, 'bytes_copied' => 0];
            }
            $size = file_exists($local_path) ? filesize($local_path) : 0;
            return ['status' => 9, 'bytes_copied' => $size];
        }
        // If called with offset > 0 on an adapter that doesn't support chunking,
        // assume the file was already fully downloaded
        $size = file_exists($local_path) ? filesize($local_path) : 0;
        return ['status' => 9, 'bytes_copied' => $size];
    }

    /**
     * Chunked cross-storage transfer: upload a local file in chunks.
     *
     * Same return semantics as download_to_local_chunked.
     *
     * @param string $local_path   Local file to read from.
     * @param string $remote_path  Path on the remote storage.
     * @param int    $offset       Byte offset to resume from.
     * @param int    $chunk_size   Bytes to send in this call.
     * @return array{status: int, bytes_copied: int}
     */
    public function upload_from_local_chunked(string $local_path, string $remote_path, int $offset = 0, int $chunk_size = 2097152): array
    {
        // Default: single-shot upload (adapters override for true chunking)
        if ($offset === 0) {
            $ok = $this->upload_from_local($local_path, $remote_path);
            if (! $ok) {
                return ['status' => 1, 'bytes_copied' => 0];
            }
            return ['status' => 9, 'bytes_copied' => filesize($local_path)];
        }
        return ['status' => 9, 'bytes_copied' => filesize($local_path)];
    }

    private function can_use_buffered_fallback(string $path): bool
    {
        $size = $this->get_size($path);
        return is_int($size) && $size >= 0 && $size <= self::FALLBACK_BUFFER_LIMIT;
    }
}
