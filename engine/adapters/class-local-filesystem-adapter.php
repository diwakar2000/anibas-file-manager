<?php

namespace Anibas;

if ( ! defined( 'ABSPATH' ) ) exit;


/**
 * Local File System Adapter.
 */
class LocalFileSystemAdapter extends FileSystemAdapter
{

    private string $rootPath;
    private array $protectedPaths = [];
    private $fs;

    public function __construct()
    {
        $this->rootPath = realpath(ABSPATH);
        $this->initProtectedPaths();
        $this->initFilesystem();
    }

    public function is_local_storage(): bool
    {
        return true;
    }

    /* =========================================================
       INITIALIZATION
    ========================================================= */

    private function initFilesystem(): void
    {
        if (! function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        WP_Filesystem();
        global $wp_filesystem;
        $this->fs = $wp_filesystem;
    }

    private function initProtectedPaths(): void
    {
        $paths = array_merge(
            anibas_fm_get_blocked_paths(),
            anibas_fm_exclude_paths()
        );

        foreach ($paths as $path) {
            // Convert frontend path (starting with /) to real path
            $path = ltrim($path, '/\\');
            $real = $path ? realpath($this->rootPath . DIRECTORY_SEPARATOR . $path) : false;
            if ($real) {
                $this->protectedPaths[] = untrailingslashit($real);
            }
        }

        $this->protectedPaths = array_unique($this->protectedPaths);
    }

    /**
     * Legacy initialization method from LocalFileSystemAdapter.
     * Included for compatibility but calls the optimized initProtectedPaths.
     */
    private function init_protected_paths()
    {
        $this->initProtectedPaths();
    }

    /* =========================================================
       HARDENED SECURITY GATE (Replaces validate_path)
    ========================================================= */

    /**
     * Validates and returns the real path if allowed, or false otherwise.
     * Replaces validate_path from LocalFileSystemAdapter.
     */
    public function assertAllowed(string $path): string|false
    {
        // Remove null bytes
        $path = str_replace(chr(0), '', $path);

        // Handle relative paths from frontend
        if (strpos($path, $this->rootPath) !== 0) {
            $path = $this->frontendPathToReal($path);
        }

        // Try realpath first for existing paths
        $real = realpath($path);

        // If path doesn't exist, manually normalize
        if (! $real) {
            $real = $this->normalizePath($path);
            if (! $real) {
                return false;
            }
        }

        // Must be inside WordPress root
        if (strpos($real, $this->rootPath) !== 0) {
            return false;
        }

        // Block symlinks (only check if path exists)
        if (file_exists($path) && is_link($path)) {
            return false;
        }

        // Block protected paths
        foreach ($this->protectedPaths as $protected) {
            if (strpos($real, $protected) === 0) {
                return false;
            }
        }

        // Block wildcard patterns (*.sql, *.sql.gz) and specific filenames
        $blocked_patterns = array('*.sql', '*.sql.gz', '.htaccess', 'wp-config.php', '.env', '.git', 'nginx.conf', '.user.ini', 'php.ini', 'web.config');
        $basename = basename($real);

        foreach ($blocked_patterns as $pattern) {
            if (strpos($pattern, '*') !== false) {
                // Wildcard pattern
                $regex = str_replace('*', '.*', preg_quote($pattern, '/'));
                if (preg_match('/' . $regex . '$/i', $basename)) {
                    return false;
                }
            } else {
                // Exact filename match (case-insensitive)
                if (strcasecmp($basename, $pattern) === 0) {
                    return false;
                }
            }
        }

        return $real;
    }

    /**
     * Mapping validate_path to assertAllowed to fulfill interface requirements.
     */
    public function validate_path($path)
    {
        return $this->assertAllowed((string) $path);
    }

    /**
     * Manually normalize a path without requiring it to exist.
     * Resolves . and .. components and prevents directory traversal.
     */
    private function normalizePath(string $path): string|false
    {
        // Convert to absolute path if relative
        if ($path[0] !== '/' && strpos($path, ':') === false) {
            $path = $this->rootPath . '/' . $path;
        }

        // Split into parts
        $parts = explode(DIRECTORY_SEPARATOR, $path);
        $normalized = array();

        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if (empty($normalized)) {
                    return false; // Traversal above root
                }
                array_pop($normalized);
            } else {
                $normalized[] = $part;
            }
        }

        $prefix = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') ? '' : '/';
        return $prefix . implode(DIRECTORY_SEPARATOR, $normalized);
    }

    /**
     * Check if a path is protected.
     */
    private function check_protected_path($full_path): bool
    {
        foreach ($this->protectedPaths as $protected) {
            if (strpos(trailingslashit($full_path), trailingslashit($protected)) === 0) {
                return false;
            }
        }
        return true;
    }

    /* =========================================================
       FILE INFORMATION
    ========================================================= */

    public function exists($path)
    {
        $validated = $this->assertAllowed($path);
        return $validated && file_exists($validated);
    }

    public function is_file($path)
    {
        $validated = $this->assertAllowed($path);
        return $validated && is_file($validated);
    }

    public function is_dir($path)
    {
        $validated = $this->assertAllowed($path);
        return $validated && is_dir($validated);
    }

    public function is_empty($path)
    {
        $validated = $this->assertAllowed($path);
        if (! $validated || ! is_dir($validated)) {
            return false;
        }

        $handle = opendir($validated);
        if (! $handle) return false;

        while (($entry = readdir($handle)) !== false) {
            if ($entry !== '.' && $entry !== '..') {
                closedir($handle);
                return false;
            }
        }
        closedir($handle);
        return true;
    }

    /* =========================================================
       FILE LISTING
    ========================================================= */

    public function scandir($path)
    {
        $validated = $this->assertAllowed($path);
        if (! $validated) {
            return [];
        }
        return scandir($validated);
    }

    public function listFilesIterative(string $root): array
    {
        if (! $root = $this->assertAllowed($root)) {
            return [];
        }

        $result = [];
        $stack  = [&$result];

        $flags =
            \FilesystemIterator::SKIP_DOTS |
            \FilesystemIterator::CURRENT_AS_FILEINFO |
            \FilesystemIterator::KEY_AS_FILENAME;

        $dirIterator = new \RecursiveDirectoryIterator($root, $flags);

        $iterator = new \RecursiveIteratorIterator(
            $dirIterator,
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $filename => $fileInfo) {
            $fullPath = $fileInfo->getPathname();

            if (! $allowed = $this->assertAllowed($fullPath)) {
                if ($fileInfo->isDir()) {
                    $iterator->next();
                }
                continue;
            }

            $depth = $iterator->getDepth();
            $stack = array_slice($stack, 0, $depth + 1);

            $item = [
                'is_folder'     => $fileInfo->isDir(),
                'path'          => $this->sanitizePath($allowed, strlen($this->rootPath)),
                'permission'    => $fileInfo->getPerms(),
                'last_modified' => $fileInfo->getMTime(),
            ];

            if ($fileInfo->isDir()) {
                $item['files'] = [];
            } else {
                $item['filename']  = $filename;
                $item['filesize']  = $fileInfo->getSize();
                $item['file_type'] = $this->getFileTypeFromExtension(
                    $fileInfo->getExtension()
                );
            }

            $stack[$depth][$filename] = $item;

            if ($fileInfo->isDir()) {
                $stack[$depth + 1] = &$stack[$depth][$filename]['files'];
            }
        }

        return $result;
    }

    public function listDirectory($path, int $page = 1, int $pageSize = 100): array
    {
        if (! $root = $this->assertAllowed($path)) {
            return ['items' => [], 'total_items' => 0];
        }

        $rootPathLen = strlen($this->rootPath);

        $response = [
            'path'        => $this->sanitizePath($root, $rootPathLen),
            'page'        => $page,
            'page_size'   => $pageSize,
            'total_items' => 0,
            'has_more'    => false,
            'items'       => [],
        ];

        if (! is_dir($root)) {
            return $response;
        }

        $entries = [];
        $count = 0;
        $maxEntries = 10000;

        try {
            $iterator = new \DirectoryIterator($root);
            foreach ($iterator as $fileInfo) {
                if ($fileInfo->isDot()) continue;
                if ($count >= $maxEntries) break;

                // Hide the trash directory
                if ($fileInfo->getFilename() === ANIBAS_FM_TRASH_DIR_NAME && $fileInfo->isDir()) continue;

                $fullPath = $fileInfo->getPathname();
                if (! $this->assertAllowed($fullPath)) continue;

                $entries[] = [
                    'name'      => $fileInfo->getFilename(),
                    'is_folder' => $fileInfo->isDir(),
                    'info'      => clone $fileInfo
                ];
                $count++;
            }
        } catch (\Throwable $e) {
            return $response;
        }

        usort($entries, function ($a, $b) {
            if ($a['is_folder'] !== $b['is_folder']) {
                return $b['is_folder'] <=> $a['is_folder'];
            }
            return strnatcasecmp($a['name'], $b['name']);
        });

        $total = count($entries);
        $response['total_items'] = $total;

        $offset = ($page - 1) * $pageSize;
        $pagedEntries = array_slice($entries, $offset, $pageSize);
        $response['has_more'] = ($offset + $pageSize) < $total;

        foreach ($pagedEntries as $entry) {
            $fileInfo = $entry['info'];
            $filename = $entry['name'];
            $fullPath = $fileInfo->getPathname();

            $item = [
                'name'          => $filename,
                'is_folder'     => $entry['is_folder'],
                'path'          => $this->sanitizePath($fullPath, $rootPathLen),
                'permission'    => $fileInfo->getPerms(),
                'last_modified' => $fileInfo->getMTime(),
            ];

            if ($entry['is_folder']) {
                $item['has_children'] = $this->directoryHasChildren($fullPath);
                $item['files']        = [];
            } else {
                $item['filename']  = $filename;
                $item['filesize']  = $fileInfo->getSize();
                $item['file_type'] = $this->getFileTypeFromExtension($fileInfo->getExtension());
            }

            $response['items'][$filename] = $item;
        }

        return $response;
    }

    public function getDetails($path)
    {
        if (! file_exists($path)) {
            return false;
        }

        $fi    = new \SplFileInfo($path);
        $isDir = $fi->isDir();
        $perms = $fi->getPerms();

        $owner = null;
        if (function_exists('posix_getpwuid')) {
            $pw = posix_getpwuid($fi->getOwner());
            $owner = $pw ? $pw['name'] : (string) $fi->getOwner();
        }

        $group = null;
        if (function_exists('posix_getgrgid')) {
            $gr = posix_getgrgid($fi->getGroup());
            $group = $gr ? $gr['name'] : (string) $fi->getGroup();
        }

        $mime = '';
        if (! $isDir) {
            if (function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime  = finfo_file($finfo, $path) ?: '';
            } elseif (function_exists('mime_content_type')) {
                $mime = mime_content_type($path) ?: '';
            }
        }

        return [
            'name'             => $fi->getFilename(),
            'path'             => $path,
            'is_folder'        => $isDir,
            'size'             => $isDir ? 0 : $fi->getSize(),
            'last_modified'    => $fi->getMTime(),
            'created'          => $fi->getCTime(),
            'permission'       => $perms,
            'permission_octal' => sprintf('%o', $perms & 0777),
            'owner'            => $owner,
            'group'            => $group,
            'extension'        => $isDir ? '' : $fi->getExtension(),
            'mime_type'        => $mime,
        ];
    }

    /**
     * Replace root path with placeholder for security
     */
    private function sanitizePath(string $path, int $rootPathLen): string
    {
        $relativePath = substr($path, $rootPathLen);
        return DIRECTORY_SEPARATOR . ltrim($relativePath, '/\\');
    }

    /**
     * Convert frontend path (starting with /) to real filesystem path
     */
    public function frontendPathToReal(string $frontendPath): string
    {
        $frontendPath = ltrim($frontendPath, '/\\');
        return $frontendPath ? $this->rootPath . DIRECTORY_SEPARATOR . $frontendPath : $this->rootPath;
    }

    private function directoryHasChildren(string $path): bool
    {
        try {
            $iterator = new \FilesystemIterator(
                $path,
                \FilesystemIterator::SKIP_DOTS
            );
            foreach ($iterator as $file) {
                return true;
            }
        } catch (\Throwable $e) {
            return false;
        }
        return false;
    }

    /* =========================================================
       WRITE / DELETE OPERATIONS
    ========================================================= */

    public function mkdir($path)
    {
        $validated = $this->assertAllowed(dirname($path));
        if (! $validated) {
            return false;
        }

        $absolute_path = $this->assertAllowed($path) ?: $this->frontendPathToReal($path);

        if (! $this->check_protected_path($absolute_path)) {
            return false;
        }

        $result = wp_mkdir_p($absolute_path);
        if ($result) {
            ActivityLogger::get_instance()->log('created', basename($absolute_path), dirname($absolute_path));
        }
        return $result;
    }

    public function createFolder(string $path): bool
    {
        return $this->mkdir($path);
    }

    public function rmdir($path)
    {
        $validated = $this->assertAllowed($path);
        if (! $validated || ! is_dir($validated)) {
            return false;
        }

        $result = $this->fs->delete($validated, true);
        if ($result) {
            ActivityLogger::get_instance()->log('deleted', basename($validated), dirname($validated));
        }
        return $result;
    }

    public function removeFolder(string $folder): bool
    {
        return $this->rmdir($folder);
    }

    public function createFile(string $filename, string $content = ''): bool
    {
        $validated_dir = $this->assertAllowed(dirname($filename));
        if (! $validated_dir) {
            return false;
        }

        $full_path = $validated_dir . DIRECTORY_SEPARATOR . basename($filename);

        $result = (bool) $this->fs->put_contents(
            $full_path,
            $content,
            FS_CHMOD_FILE
        );

        if ($result) {
            ActivityLogger::get_instance()->log('created', basename($full_path), dirname($full_path));
        }

        return $result;
    }

    public function put_contents($path, $content)
    {
        return $this->createFile($path, $content);
    }

    public function append_contents($path, $content)
    {
        $validated = $this->assertAllowed($path);
        if (! $validated || ! file_exists($validated)) {
            return false;
        }

        $handle = fopen($validated, 'ab');
        if (! $handle) {
            return false;
        }

        $result = fwrite($handle, $content);
        fclose($handle);

        return $result !== false;
    }

    public function deleteFile(string $filename): bool
    {
        return $this->unlink($filename);
    }

    public function unlink($path)
    {
        $validated = $this->assertAllowed($path);
        if (! $validated) {
            return false;
        }

        $result = $this->fs->delete($validated, false);
        if ($result) {
            ActivityLogger::get_instance()->log('deleted', basename($validated), dirname($validated));
        }
        return $result;
    }

    /**
     * Delete a file or folder. When trash is enabled, every item (file OR
     * folder) goes to .trash via an atomic rename — fast for any size.
     * When disabled, files unlink synchronously and folders are handed off
     * to BackgroundProcessor so deep trees don't time out the request.
     *
     * @return true|array|\WP_Error
     */
    public function delete($path)
    {
        $validated = $this->assertAllowed($path);
        if (! $validated || ! file_exists($validated)) {
            return new \WP_Error('not_found', __('File or folder not found', 'anibas-file-manager'));
        }

        if (anibas_fm_trash_enabled()) {
            error_clear_last();
            if ($this->moveToTrash($validated)) {
                return true;
            }
            return new \WP_Error(
                'trash_failed',
                __('Failed to move to trash.', 'anibas-file-manager') . self::delete_failure_reason($validated)
            );
        }

        if (is_dir($validated)) {
            $job_id = BackgroundProcessor::enqueue_delete_job($validated, 'local');
            if (is_wp_error($job_id)) {
                return $job_id;
            }
            return ['job_id' => $job_id];
        }

        error_clear_last();
        if ($this->fs->delete($validated, false)) {
            ActivityLogger::get_instance()->log('deleted', basename($validated), dirname($validated));
            return true;
        }
        return new \WP_Error(
            'delete_failed',
            __('Failed to delete file.', 'anibas-file-manager') . self::delete_failure_reason($validated)
        );
    }

    /**
     * Move a file or folder to the .trash directory instead of deleting it.
     * Items are stored as: .trash/{timestamp}_{basename}
     */
    public function moveToTrash(string $path): bool
    {
        $validated = $this->assertAllowed($path);
        if (! $validated || ! file_exists($validated)) {
            return false;
        }

        $trash_dir = anibas_fm_get_trash_dir();
        $basename  = basename($validated);
        
        $trash_id = time() . '_' . uniqid() . '_' . $basename;
        $dest     = $trash_dir . '/' . $trash_id;

        $result = rename($validated, $dest);
        if ($result) {
            $index_file = $trash_dir . '/index.json';
            $index = [];
            if (file_exists($index_file)) {
                $content = file_get_contents($index_file);
                if ($content) {
                    $index = json_decode($content, true) ?: [];
                }
            }

            // Calculate relative path inside ABSPATH
            $relative_path = ltrim(str_replace(wp_normalize_path(ABSPATH), '', wp_normalize_path($validated)), '/');

            $index[$trash_id] = [
                'original_path' => $relative_path,
                'basename'      => $basename,
                'trashed_at'    => time(),
                'is_dir'        => is_dir($dest),
                'filesize'      => is_dir($dest) ? 0 : filesize($dest)
            ];

            file_put_contents($index_file, wp_json_encode($index), LOCK_EX);

            ActivityLogger::get_instance()->log('trashed', $basename, dirname($validated));
        }
        return $result;
    }

    /**
     * Remove (or trash) every child of a directory, leaving the directory itself
     * in place. Each child is routed through delete() so trash mode applies.
     *
     * @return true|\WP_Error
     */
    public function emptyFolder(string $path)
    {
        $validated = $this->assertAllowed($path);
        if (! $validated || ! is_dir($validated)) {
            return new \WP_Error('not_found', __('Folder not found', 'anibas-file-manager'));
        }

        $failures = [];
        foreach (new \DirectoryIterator($validated) as $item) {
            if ($item->isDot()) continue;
            $result = $this->delete($item->getPathname());
            if (is_wp_error($result)) {
                $failures[] = $item->getFilename() . ': ' . $result->get_error_message();
            }
        }

        if (! empty($failures)) {
            return new \WP_Error(
                'empty_partial',
                __('Some items could not be deleted:', 'anibas-file-manager') . ' ' . implode('; ', $failures)
            );
        }

        ActivityLogger::get_instance()->log('emptied', basename($validated), dirname($validated));
        return true;
    }

    public function readFile(string $filename)
    {
        return $this->get_contents($filename);
    }

    public function get_contents($path)
    {
        $validated = $this->assertAllowed($path);
        if (! $validated) {
            return false;
        }
        return $this->fs->get_contents($validated);
    }

    /* =========================================================
       COPY / MOVE OPERATIONS
    ========================================================= */

    public function copy($source, $target)
    {
        $validated_source = $this->assertAllowed($source);
        $validated_target_dir = $this->assertAllowed(dirname($target));

        if (! $validated_source || ! $validated_target_dir) {
            return false;
        }

        $target_path = $validated_target_dir . DIRECTORY_SEPARATOR . basename($target);

        if (is_file($validated_source)) {
            return $this->fs->copy($validated_source, $target_path, true, FS_CHMOD_FILE);
        }

        // For directories, use background processor if available
        if (class_exists('Anibas\BackgroundProcessor')) {
            return ! is_wp_error(BackgroundProcessor::enqueue_job($validated_source, $target_path, 'copy', 'skip'));
        }

        // Copy single file
        if (! copy($validated_source, $target_path)) {
            ActivityLogger::get_instance()->log_message('Local file copy failed from ' . $validated_source . ' to ' . $target_path);
            return false;
        }

        return true;
    }

    public function move($source, $target)
    {
        $validated_source = $this->assertAllowed($source);
        $validated_target_dir = $this->assertAllowed(dirname($target));

        if (! $validated_source || ! $validated_target_dir) {
            ActivityLogger::get_instance()->log_message('Local move validation failed for source: ' . $source . ' or target: ' . $target);
            return false;
        }

        $target_path = $validated_target_dir . DIRECTORY_SEPARATOR . basename($target);

        if (! $this->check_protected_path($target_path)) {
            ActivityLogger::get_instance()->log_message('Local move blocked - protected path: ' . $target_path);
            return false;
        }

        if (is_dir($validated_source)) {
            // Use background processor for directories
            return ! is_wp_error(BackgroundProcessor::enqueue_job($validated_source, $target_path, 'move', 'skip'));
        }

        // Move single file
        if (! rename($validated_source, $target_path)) {
            ActivityLogger::get_instance()->log_message('Local file move failed from ' . $validated_source . ' to ' . $target_path);
            return false;
        }

        return true;
    }

    public function movePath(string $source, string $destination): bool
    {
        return $this->move($source, $destination);
    }

    public function copyPath(string $source, string $destination): bool
    {
        return $this->copy($source, $destination);
    }

    public function processSingleFile(string $source, string $destination, string $action): bool
    {
        $source = $this->assertAllowed($source);
        $dest_dir = $this->assertAllowed(dirname($destination));

        if (! $source || ! $dest_dir) {
            throw new \Exception(esc_html__('Invalid paths', 'anibas-file-manager'));
        }

        $destination = $dest_dir . DIRECTORY_SEPARATOR . basename($destination);

        if ($action === 'move') {
            $result = $this->fs->move($source, $destination, true);
        } else {
            $result = $this->copyFileInChunks($source, $destination);
        }

        if (! $result) {
            throw new \Exception(esc_html__('Operation failed', 'anibas-file-manager'));
        }

        return true;
    }

    /**
     * Copy file error codes
     */
    const COPY_NO_ERROR = 0;
    const COPY_ERROR_CREATING_FILE = 1;
    const COPY_ERROR_APPENDING_TO_FILE = 2;
    const COPY_ERROR_READING_CHUNK = 3;
    const COPY_ERROR_WRITING_CHUNK = 4;
    const COPY_ERROR_SOURCE_NOT_FOUND = 5;
    const COPY_ERROR_SOURCE_EMPTY = 6;
    const COPY_ERROR_NO_DATA_RECEIVED = 7;
    const COPY_ERROR_VERIFICATION_FAILED = 8;
    const COPY_OPERATION_COMPLETE = 9;
    const COPY_OPERATION_IN_PROGRESS = 10;

    /**
     * Copy file in chunks to avoid memory limits
     */
    public function copyFileInChunks(string $source, string $destination, ?int $chunk_size = null, $bytes_copied = 0): int
    {
        // Use dynamic chunk size from settings if not provided
        if ($chunk_size === null) {
            $chunk_size = intval(anibas_fm_get_option('chunk_size', ANIBAS_FM_DEFAULT_CHUNK_SIZE));
        }

        // Ensure chunk size is within limits
        if ($chunk_size < 262144) { // Min 256KB
            $chunk_size = 262144;
        }
        if ($chunk_size > 10485760) { // Max 10MB
            $chunk_size = 10485760;
        }

        // Ensure destination directory exists
        $dest_dir = dirname($destination);
        if (! $this->fs->is_dir($dest_dir)) {
            if (! $this->fs->mkdir($dest_dir, FS_CHMOD_DIR)) {
                ActivityLogger::get_instance()->log_message('Local Copy error: Failed to create destination directory');
                return self::COPY_ERROR_CREATING_FILE;
            }
        }

        // Check if source file exists
        if (! file_exists($source)) {
            ActivityLogger::get_instance()->log_message('Local Copy error: Source file not found');
            return self::COPY_ERROR_SOURCE_NOT_FOUND;
        }

        $source_size = filesize($source);
        if ($source_size === 0) {
            ActivityLogger::get_instance()->log_message('Local Copy error: Source file is empty');
            return self::COPY_ERROR_SOURCE_EMPTY;
        }

        // Check if copy is already complete
        if (file_exists($destination)) {
            $target_size = filesize($destination);
            if ($target_size >= $source_size) {
                ActivityLogger::get_instance()->log_message('Local Copy already completed: ' . $target_size . ' of ' . $source_size . ' bytes');
                return self::COPY_OPERATION_COMPLETE;
            }
            $bytes_copied = $target_size; // Resume from current position
        }

        // Open source file for reading
        $source_handle = fopen($source, 'rb');
        if ($source_handle === false) {
            ActivityLogger::get_instance()->log_message('Local Copy error: Failed to open source file for reading');
            return self::COPY_ERROR_READING_CHUNK;
        }

        // Open destination file for writing
        $dest_handle = fopen($destination, $bytes_copied > 0 ? 'ab' : 'wb');
        if ($dest_handle === false) {
            fclose($source_handle);
            ActivityLogger::get_instance()->log_message('Local Copy error: Failed to open destination file for writing');
            return $bytes_copied === 0 ? self::COPY_ERROR_CREATING_FILE : self::COPY_ERROR_APPENDING_TO_FILE;
        }

        // Copy in chunks
        $bytes_copied_current = 0;

        try {
            // Seek to the correct position for resumable copying
            if ($bytes_copied > 0) {
                fseek($source_handle, $bytes_copied);
            }

            while ($bytes_copied_current < $source_size && ($bytes_copied + $bytes_copied_current) < $source_size) {
                $chunk = fread($source_handle, $chunk_size);
                if ($chunk === false) {
                    ActivityLogger::get_instance()->log_message('Local Copy error: Failed to read from source file at position ' . ($bytes_copied + $bytes_copied_current));
                    fclose($source_handle);
                    fclose($dest_handle);
                    if ($bytes_copied === 0 && file_exists($destination)) {
                        wp_delete_file($destination);
                    }
                    return self::COPY_ERROR_READING_CHUNK;
                }

                if (strlen($chunk) === 0) {
                    break; // End of file reached
                }

                $bytes_written = fwrite($dest_handle, $chunk);
                if ($bytes_written === false) {
                    ActivityLogger::get_instance()->log_message('Local Copy error: Failed to write to destination file at position ' . ($bytes_copied + $bytes_copied_current));
                    fclose($source_handle);
                    fclose($dest_handle);
                    if ($bytes_copied === 0 && file_exists($destination)) {
                        wp_delete_file($destination);
                    }
                    return self::COPY_ERROR_WRITING_CHUNK;
                }

                $bytes_copied_current += $bytes_written;

                // For resumable operations, only process one chunk per request
                // Always break after one chunk to ensure responsiveness and avoid timeouts
                break;
            }
        } catch (\Exception $e) {
            // Clean up on error
            fclose($source_handle);
            fclose($dest_handle);
            if (file_exists($destination) && $bytes_copied === 0) {
                wp_delete_file($destination);
            }
            ActivityLogger::get_instance()->log_message('Local Copy exception: ' . $e->getMessage());
            return self::COPY_ERROR_READING_CHUNK;
        }

        // Close handles
        fclose($source_handle);
        fclose($dest_handle);

        $total_bytes_copied = $bytes_copied + $bytes_copied_current;

        // Log progress
        $progress = ($total_bytes_copied / $source_size) * 100;
        $is_complete = $total_bytes_copied >= $source_size;

        ActivityLogger::get_instance()->log_message(
            "Local Copy chunk completed: position {$bytes_copied}-{$total_bytes_copied}, " .
                round($progress, 2) . "% " .
                ($is_complete ? "(COMPLETE)" : "(resume with bytes_copied={$total_bytes_copied})")
        );

        // Verify copy was successful for complete operations
        if ($is_complete && $total_bytes_copied !== $source_size) {
            if (file_exists($destination)) {
                wp_delete_file($destination);
            }
            ActivityLogger::get_instance()->log_message('Local Copy verification failed: copied ' . $total_bytes_copied . ' of ' . $source_size . ' bytes');
            return self::COPY_ERROR_VERIFICATION_FAILED;
        }

        // Set proper permissions
        if ($is_complete) {
            chmod($destination, FS_CHMOD_FILE);
        }

        // Return appropriate status code
        return $is_complete ? self::COPY_OPERATION_COMPLETE : self::COPY_OPERATION_IN_PROGRESS;
    }

    /**
     * Get copy progress info for resumable operations
     */
    public function getCopyProgress($source, $destination): array
    {
        try {
            $source_size = file_exists($source) ? filesize($source) : 0;
            $target_size = file_exists($destination) ? filesize($destination) : 0;

            return [
                'file_size' => $source_size,
                'bytes_copied' => $target_size,
                'progress_percent' => $source_size > 0 ? ($target_size / $source_size) * 100 : 0,
                'is_complete' => $target_size >= $source_size && $source_size > 0,
                'next_bytes_copied' => $target_size
            ];
        } catch (\Exception $e) {
            throw new \Exception(esc_html__('Failed to get Local copy progress: ', 'anibas-file-manager') . esc_html($e->getMessage()));
        }
    }

    /**
     * Delete destination file/folder for cancelled operations
     */
    public function deleteDestination($destination): bool
    {
        try {
            if (file_exists($destination)) {
                if (is_dir($destination)) {
                    return $this->fs->rmdir($destination);
                } else {
                    return $this->fs->unlink($destination);
                }
            }
            return true; // Nothing to delete
        } catch (\Exception $e) {
            ActivityLogger::get_instance()->log_message('Failed to delete destination: ' . $e->getMessage());
            throw $e;
        }
    }

    public function resolveNameClash(string $destination): string
    {
        $validated = $this->assertAllowed(dirname($destination));
        if (! $validated) return $destination;

        $destination = $validated . DIRECTORY_SEPARATOR . basename($destination);

        if (! $this->fs->exists($destination)) {
            return $destination;
        }

        $info = pathinfo($destination);
        $dir  = $info['dirname'];
        $name = $info['filename'];
        $ext  = isset($info['extension']) ? '.' . $info['extension'] : '';

        // More efficient approach: use timestamp + random digits to avoid loops
        $new_path = $dir . DIRECTORY_SEPARATOR . $name . '_' . date('Y-m-d_H-i-s') . '_' . mt_rand(100000, 999999) . $ext;

        // Double-check the extremely unlikely case of collision
        $counter = 0;
        while ($this->fs->exists($new_path) && $counter < 10) {
            $new_path = $dir . DIRECTORY_SEPARATOR . $name . '_' . date('Y-m-d_H-i-s') . '_' . mt_rand(100000, 999999) . $ext;
            $counter++;
        }

        return $new_path;
    }

    public function scanLevel(string $dir): array
    {
        if (! $dir = $this->assertAllowed($dir)) {
            throw new \Exception(esc_html__('Invalid directory', 'anibas-file-manager'));
        }

        $files = [];
        $folders = [];

        $iterator = new \DirectoryIterator($dir);
        foreach ($iterator as $fileinfo) {
            if ($fileinfo->isDot()) continue;

            $path = $fileinfo->getPathname();
            if (! $this->assertAllowed($path)) continue;

            if ($fileinfo->isDir()) {
                $folders[] = $path;
            } else {
                $files[] = $path;
            }
        }

        return ['files' => $files, 'folders' => $folders];
    }

    /* =========================================================
       HELPERS
    ========================================================= */

    public function getFileTypeFromExtension($extension): string
    {
        static $cache = [];
        $extension = strtolower($extension);
        if (isset($cache[$extension])) return $cache[$extension];

        $map = [
            'jpg' => esc_html__('Image', 'anibas-file-manager'),
            'jpeg' => esc_html__('Image', 'anibas-file-manager'),
            'png' => esc_html__('Image', 'anibas-file-manager'),
            'gif' => esc_html__('Image', 'anibas-file-manager'),
            'webp' => esc_html__('Image', 'anibas-file-manager'),
            'svg' => esc_html__('Vector Image', 'anibas-file-manager'),
            'pdf' => esc_html__('PDF Document', 'anibas-file-manager'),
            'zip' => esc_html__('Zip Archive', 'anibas-file-manager'),
            'tar' => esc_html__('TAR Archive', 'anibas-file-manager'),
            'anfm' => esc_html__('Anibas Archive', 'anibas-file-manager'),
            'php' => esc_html__('PHP Script', 'anibas-file-manager'),
            'html' => esc_html__('HTML Document', 'anibas-file-manager'),
            'css' => esc_html__('Stylesheet', 'anibas-file-manager'),
            'js' => esc_html__('JavaScript File', 'anibas-file-manager'),
            'json' => esc_html__('JSON File', 'anibas-file-manager'),
            'txt' => esc_html__('Text Document', 'anibas-file-manager'),
            'md' => esc_html__('Markdown Document', 'anibas-file-manager'),
            'mp4' => esc_html__('Video', 'anibas-file-manager'),
            'mp3' => esc_html__('Audio', 'anibas-file-manager'),
        ];

        return $cache[$extension] = $map[$extension] ?? esc_html__('File', 'anibas-file-manager');
    }
}
