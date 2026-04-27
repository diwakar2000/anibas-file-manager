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
    private \WP_Filesystem_Direct $fs;
    private string $lastFailureReason = '';

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

        // Must be inside WordPress root.
        $root_norm = untrailingslashit(wp_normalize_path($this->rootPath));
        $real_norm = untrailingslashit(wp_normalize_path($real));
        if ($real_norm !== $root_norm && strpos(trailingslashit($real_norm), trailingslashit($root_norm)) !== 0) {
            return false;
        }

        // Block symlinks (only check if path exists)
        if (file_exists($path) && is_link($path)) {
            return false;
        }

        if ($this->isPrivateBackupPath($real)) {
            return false;
        }

        // Block protected paths
        foreach ($this->protectedPaths as $protected) {
            if ($real === $protected || strpos(trailingslashit($real), trailingslashit($protected)) === 0) {
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
    public function validate_path(string $path): string|false
    {
        return $this->assertAllowed($path);
    }

    /**
     * Manually normalize a path without requiring it to exist.
     * Resolves . and .. components and prevents directory traversal.
     */
    private function normalizePath(string $path): string|false
    {
        if ($path === '') {
            return false;
        }

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
     * Return true if the path is NOT inside any protected directory.
     * Separate from assertAllowed() which also covers blocked-filename patterns,
     * symlinks, and traversal; this one is a focused protected-subtree check
     * used when validating a newly-constructed target path.
     */
    private function is_allowed_path(string $full_path): bool
    {
        if ($this->isPrivateBackupPath($full_path)) {
            return false;
        }

        foreach ($this->protectedPaths as $protected) {
            if (strpos(trailingslashit($full_path), trailingslashit($protected)) === 0) {
                return false;
            }
        }
        return true;
    }

    private function assertQueuedPath(string $path, string $root): string|false
    {
        $path = str_replace(chr(0), '', $path);
        $root = str_replace(chr(0), '', $root);

        if (strpos($path, $this->rootPath) !== 0) {
            $path = $this->frontendPathToReal($path);
        }
        if (strpos($root, $this->rootPath) !== 0) {
            $root = $this->frontendPathToReal($root);
        }

        $real = realpath($path) ?: $this->normalizePath($path);
        $root_real = realpath($root) ?: $this->normalizePath($root);
        if (! $real || ! $root_real) {
            return false;
        }

        $real_norm = untrailingslashit(wp_normalize_path($real));
        $root_norm = untrailingslashit(wp_normalize_path($root_real));
        $wp_root   = untrailingslashit(wp_normalize_path($this->rootPath));

        if (! $this->path_is_inside_or_same($root_norm, $wp_root)
            || ! $this->path_is_inside_or_same($real_norm, $root_norm)) {
            return false;
        }

        if (file_exists($path) && is_link($path)) {
            return false;
        }

        if ($this->is_job_protected_path($real_norm)) {
            return false;
        }

        return $real;
    }

    private function is_job_protected_path(string $real_norm): bool
    {
        if ($this->isPrivateBackupPath($real_norm)) {
            return true;
        }

        foreach ($this->protectedPaths as $protected) {
            $protected = untrailingslashit(wp_normalize_path($protected));
            if ($this->path_is_inside_or_same($real_norm, $protected)) {
                return true;
            }
        }

        $trash_dir = $this->trashDirPath();
        $trash_real = realpath($trash_dir) ?: $this->normalizePath($trash_dir);
        return $trash_real && $this->path_is_inside_or_same($real_norm, untrailingslashit(wp_normalize_path($trash_real)));
    }

    private function trashDirPath(): string
    {
        return untrailingslashit(WP_CONTENT_DIR) . DIRECTORY_SEPARATOR . ANIBAS_FM_TRASH_DIR_NAME;
    }

    private function path_is_inside_or_same(string $candidate, string $root): bool
    {
        $candidate = untrailingslashit(wp_normalize_path($candidate));
        $root = untrailingslashit(wp_normalize_path($root));

        if ($candidate === '' || $root === '') {
            return false;
        }

        return $candidate === $root || strpos(trailingslashit($candidate), trailingslashit($root)) === 0;
    }

    public function queuedUnlink(string $path, string $root): bool
    {
        $validated = $this->assertQueuedPath($path, $root);
        if (! $validated) {
            return false;
        }
        if (! file_exists($validated)) {
            return true;
        }
        if (is_dir($validated)) {
            return false;
        }

        return @unlink($validated);
    }

    public function queuedRmdir(string $path, string $root): bool
    {
        $validated = $this->assertQueuedPath($path, $root);
        if (! $validated) {
            return false;
        }
        if (! file_exists($validated)) {
            return true;
        }
        if (! is_dir($validated)) {
            return false;
        }

        return @rmdir($validated);
    }

    private function has_protected_descendant(string $path): bool
    {
        $root = realpath($path) ?: $this->normalizePath($path);
        if (! $root || ! is_dir($root)) {
            return false;
        }

        foreach ($this->protectedPaths as $protected) {
            if ($this->path_is_inside($protected, $root)) {
                return true;
            }
        }

        $trash_dir = $this->trashDirPath();
        return $this->path_is_inside($trash_dir, $root);
    }

    private function path_is_inside(string $candidate, string $root): bool
    {
        $candidate = untrailingslashit(wp_normalize_path(realpath($candidate) ?: $this->normalizePath($candidate) ?: $candidate));
        $root      = untrailingslashit(wp_normalize_path(realpath($root) ?: $this->normalizePath($root) ?: $root));

        if ($candidate === '' || $root === '' || $candidate === $root) {
            return false;
        }

        return strpos(trailingslashit($candidate), trailingslashit($root)) === 0;
    }

    private function local_delete_failure_reason(string $path): string
    {
        if ($this->lastFailureReason !== '') {
            $reason = $this->lastFailureReason;
            $this->lastFailureReason = '';
            return ' ' . $reason;
        }

        $err = error_get_last();
        if (is_array($err) && ! empty($err['message'])) {
            return ' ' . $err['message'];
        }

        if (! is_writable(dirname($path))) {
            return ' ' . __('Parent directory is not writable.', 'anibas-file-manager');
        }

        return self::delete_failure_reason($path);
    }

    private function isPrivateBackupPath(string $path): bool
    {
        $real = realpath($path) ?: $this->normalizePath($path);
        if (! $real) {
            return false;
        }

        $contentRoot = realpath(WP_CONTENT_DIR) ?: $this->normalizePath(WP_CONTENT_DIR);
        if (! $contentRoot || ($real !== $contentRoot && strpos(trailingslashit($real), trailingslashit($contentRoot)) !== 0)) {
            return false;
        }

        $relative = trim(str_replace('\\', '/', substr($real, strlen($contentRoot))), '/');
        $topLevel = explode('/', $relative)[0] ?? '';

        return $topLevel === ANIBAS_FM_BACKUP_DIR_NAME || strpos($topLevel, '.anibas-backups-') === 0;
    }

    /* =========================================================
       FILE INFORMATION
    ========================================================= */

    public function exists(string $path): bool
    {
        $validated = $this->assertAllowed($path);
        return $validated && file_exists($validated);
    }

    public function is_file(string $path): bool
    {
        $validated = $this->assertAllowed($path);
        return $validated && is_file($validated);
    }

    public function is_dir(string $path): bool 
    {
        $validated = $this->assertAllowed($path);
        return $validated && is_dir($validated);
    }

    public function is_empty(string $path): bool 
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

    public function scandir(string $path): array
    {
        $validated = $this->assertAllowed($path);
        if (! $validated) {
            return [];
        }
        $entries = @scandir($validated);
        if (! $entries) {
            return [];
        }
        return array_values(array_filter(
            $entries,
            static fn($e) => $e !== '.' && $e !== '..'
        ));
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

        // Prune disallowed subtrees at the source so recursion doesn't descend
        // into them (old code used $iterator->next() which is a no-op inside
        // foreach, causing children of rejected dirs to leak into the result
        // and mis-parent under the previous sibling).
        $trashName = defined('ANIBAS_FM_TRASH_DIR_NAME') ? ANIBAS_FM_TRASH_DIR_NAME : '.trash';
        $filter = new \RecursiveCallbackFilterIterator(
            $dirIterator,
            function (\SplFileInfo $file) use ($trashName): bool {
                if ($file->isDir() && $file->getFilename() === $trashName) {
                    return false;
                }
                return (bool) $this->assertAllowed($file->getPathname());
            }
        );

        $iterator = new \RecursiveIteratorIterator(
            $filter,
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $filename => $fileInfo) {
            $fullPath = $fileInfo->getPathname();
            $depth = $iterator->getDepth();
            $stack = array_slice($stack, 0, $depth + 1);

            $item = [
                'is_folder'     => $fileInfo->isDir(),
                'path'          => $this->sanitizePath($fullPath, strlen($this->rootPath)),
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

    /**
     * Streaming readdir-based iterator for background-job queue building.
     *
     * Walks the directory with opendir/readdir and resumes via an integer
     * position cursor. No sorting and no in-memory accumulation of the whole
     * listing — safe for directories with millions of direct children.
     *
     * Cursor stability assumes the directory is not mutated between calls.
     * The list phase only reads the source, so this holds for copy/move/delete
     * jobs (the source is not modified until the transfer/delete phases run
     * on the already-built queue).
     */
    public function iterateDirectory(string $path, ?array $cursor = null, int $maxItems = 1000, array $options = []): array
    {
        $empty = ['entries' => [], 'next_cursor' => null, 'has_more' => false];
        $recursive_root = isset($options['recursive_root']) && is_string($options['recursive_root'])
            ? $options['recursive_root']
            : '';

        $root = $recursive_root !== ''
            ? $this->assertQueuedPath($path, $recursive_root)
            : $this->assertAllowed($path);
        if (! $root) {
            return $empty;
        }
        if (! is_dir($root)) {
            return $empty;
        }

        $skip        = is_array($cursor) && isset($cursor['offset']) ? max(0, (int) $cursor['offset']) : 0;
        $rootPathLen = strlen($this->rootPath);

        $entries  = [];
        $position = 0;
        $hasMore  = false;

        $dh = @opendir($root);
        if ($dh === false) {
            return $empty;
        }

        try {
            while (($name = readdir($dh)) !== false) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $position++;

                if ($position <= $skip) {
                    continue;
                }

                $fullPath = $root . DIRECTORY_SEPARATOR . $name;
                $isDir    = is_dir($fullPath);

                if ($isDir
                    && $recursive_root === ''
                    && ($name === ANIBAS_FM_TRASH_DIR_NAME || $this->isPrivateBackupPath($fullPath))) {
                    continue;
                }
                $validated = $recursive_root !== ''
                    ? $this->assertQueuedPath($fullPath, $recursive_root)
                    : $this->assertAllowed($fullPath);
                if (! $validated) {
                    continue;
                }

                $entry = [
                    'name'      => $name,
                    'is_folder' => $isDir,
                    'path'      => $this->sanitizePath($validated, $rootPathLen),
                ];
                if (! $isDir) {
                    $entry['filesize'] = @filesize($fullPath) ?: 0;
                }
                $entries[] = $entry;

                if (count($entries) >= $maxItems) {
                    $hasMore = (readdir($dh) !== false);
                    break;
                }
            }
        } finally {
            closedir($dh);
        }

        return [
            'entries'     => $entries,
            'next_cursor' => ['offset' => $position],
            'has_more'    => $hasMore,
        ];
    }

    public function listDirectory(string $path, int $page = 1, int $pageSize = 100): array
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

                if ($fileInfo->isDir()
                    && ($fileInfo->getFilename() === ANIBAS_FM_TRASH_DIR_NAME || $this->isPrivateBackupPath($fileInfo->getPathname()))) {
                    continue;
                }

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

    public function getDetails(string $path): array|false
    {
        $validated = $this->assertAllowed($path);
        if (! $validated || ! file_exists($validated)) {
            return false;
        }

        $fi    = new \SplFileInfo($validated);
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
                if ($finfo) {
                    $mime = finfo_file($finfo, $validated) ?: '';
                    finfo_close($finfo);
                }
            } elseif (function_exists('mime_content_type')) {
                $mime = mime_content_type($validated) ?: '';
            }
        }

        return [
            'name'             => $fi->getFilename(),
            'path'             => $this->sanitizePath($validated, strlen($this->rootPath)),
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

    public function mkdir(string $path): bool
    {
        $parent = $this->assertAllowed(dirname($path));
        if (! $parent) {
            return false;
        }

        // Validate the final target path so blocked basenames (wp-config.php,
        // .env, .htaccess, *.sql, etc.) can't slip through as new directories.
        $absolute_path = $parent . DIRECTORY_SEPARATOR . basename($path);
        if (! $this->assertAllowed($absolute_path) || ! $this->is_allowed_path($absolute_path)) {
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

    public function rmdir(string $path): bool
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

        // Re-validate the assembled path so blocked basenames don't bypass via
        // a validated parent (e.g. createFile("wp-content/wp-config.php")).
        if (! $this->assertAllowed($full_path)) {
            return false;
        }

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

    public function put_contents(string $path, string $content): bool
    {
        return $this->createFile($path, $content);
    }

    public function append_contents(string $path, string $content): bool
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

    public function unlink(string $path): bool
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
    public function delete(string $path)
    {
        $validated = $this->assertAllowed($path);
        if (! $validated || ! file_exists($validated)) {
            return new \WP_Error('not_found', __('File or folder not found', 'anibas-file-manager'));
        }

        if (anibas_fm_trash_enabled()) {
            error_clear_last();
            $trash_result = $this->moveToTrash($validated);
            if ($trash_result === true) {
                return true;
            }
            // Cross-device fallback: moveToTrash enqueued a chunked move job.
            // Propagate the job_id so the frontend polls instead of pretending
            // the work finished synchronously.
            if (is_string($trash_result)) {
                return ['job_id' => $trash_result];
            }
            return new \WP_Error(
                'trash_failed',
                __('Failed to move to trash.', 'anibas-file-manager') . $this->local_delete_failure_reason($validated)
            );
        }

        if (is_dir($validated)) {
            if ($this->has_protected_descendant($validated)) {
                return new \WP_Error(
                    'delete_failed',
                    __('Folder contains protected file-manager paths and cannot be deleted as a whole.', 'anibas-file-manager')
                );
            }
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
            __('Failed to delete file.', 'anibas-file-manager') . $this->local_delete_failure_reason($validated)
        );
    }

    /**
     * Move a file or folder to the .trash directory instead of deleting it.
     * Items are stored as: .trash/{timestamp}_{basename}
     *
     * Return shape:
     *   true            — synchronous rename succeeded, item is in trash now
     *   string          — job_id of an in-flight chunked move (cross-device path)
     *   false           — failure
     */
    public function moveToTrash(string $path): bool|string
    {
        $validated = $this->assertAllowed($path);
        if (! $validated || ! file_exists($validated)) {
            return false;
        }

        return $this->moveValidatedToTrash($validated);
    }

    public function moveQueuedItemToTrash(string $path, string $root): bool|string
    {
        $validated = $this->assertQueuedPath($path, $root);
        if (! $validated || ! file_exists($validated)) {
            return false;
        }

        return $this->moveValidatedToTrash($validated);
    }

    private function moveValidatedToTrash(string $validated): bool|string
    {
        $trash_dir = anibas_fm_get_trash_dir();
        $basename  = basename($validated);

        $trash_id = time() . '_' . uniqid() . '_' . $basename;
        $dest     = $trash_dir . DIRECTORY_SEPARATOR . $trash_id;

        $is_dir_src = is_dir($validated);
        $size_src   = $is_dir_src ? 0 : (int) @filesize($validated);

        if ($is_dir_src && $this->path_is_inside($dest, $validated)) {
            $this->lastFailureReason = __('Cannot move a folder into its own trash subtree.', 'anibas-file-manager');
            ActivityLogger::get_instance()->log_message('Trash blocked because destination is inside source: ' . $validated . ' -> ' . $dest);
            return false;
        }

        if ($is_dir_src && $this->has_protected_descendant($validated)) {
            $this->lastFailureReason = __('Folder contains protected file-manager paths and cannot be moved to trash as a whole.', 'anibas-file-manager');
            ActivityLogger::get_instance()->log_message('Trash blocked because source contains protected descendants: ' . $validated);
            return false;
        }

        $result = anibas_fm_safe_move($validated, $dest);
        if ($result) {
            // Index-entry metadata. list_trash() already skips orphaned
            // entries (where the file doesn't exist at $dest yet), so the
            // in-flight async case won't show up in trash UI until the job
            // finishes copying — at which point it appears with this entry.
            $entry = [
                'original_path' => ltrim(str_replace(wp_normalize_path(ABSPATH), '', wp_normalize_path($validated)), '/'),
                'basename'      => $basename,
                'trashed_at'    => time(),
                'is_dir'        => $is_dir_src,
                'filesize'      => $size_src,
            ];

            // Atomic read-modify-write under an exclusive lock; the previous
            // code read then wrote separately, so two concurrent trash ops
            // could stomp each other's index entries.
            $index_file = $trash_dir . DIRECTORY_SEPARATOR . 'index.json';
            $fp = @fopen($index_file, 'c+');
            if ($fp) {
                if (flock($fp, LOCK_EX)) {
                    $content = stream_get_contents($fp);
                    $index   = $content ? (json_decode($content, true) ?: []) : [];
                    $index[$trash_id] = $entry;

                    rewind($fp);
                    ftruncate($fp, 0);
                    fwrite($fp, wp_json_encode($index));
                    fflush($fp);
                    flock($fp, LOCK_UN);
                }
                fclose($fp);
            }

            ActivityLogger::get_instance()->log('trashed', $basename, dirname($validated));
        } else {
            $error_msg = sprintf('Failed to move %s to trash. Reason: %s', $validated, print_r(error_get_last(), true));
            ActivityLogger::get_instance()->log_message($error_msg);
            if ($this->lastFailureReason === '') {
                $this->lastFailureReason = __('Filesystem move failed.', 'anibas-file-manager');
            }
            error_clear_last();
        }
        return $result;
    }

    /**
     * Remove (or trash) every child of a directory, leaving the directory itself
     * in place. Chunked to survive arbitrarily large folders.
     *
     * Trash mode: move the whole root into .trash as a single atomic rename,
     * then recreate an empty root at the original path. O(1) regardless of
     * how many descendants live under $path.
     *
     * No-trash mode: enqueue a single background delete job with keep_root=true.
     * DeletePhase walks the tree time-sliced and unlinks in chunks — no sync
     * loop in the request that triggered the empty.
     *
     * @return true|array|\WP_Error
     *   - true               on synchronous success (trash mode)
     *   - ['job_id' => ...]  when work was enqueued
     *   - \WP_Error          on failure
     */
    public function emptyFolder(string $path)
    {
        $validated = $this->assertAllowed($path);
        if (! $validated || ! is_dir($validated)) {
            return new \WP_Error('not_found', __('Folder not found', 'anibas-file-manager'));
        }

        $group_id = 'empty_' . wp_generate_password(12, false);
        $group_meta = [
            'ui_group_id'     => $group_id,
            'ui_group_action' => 'empty',
            'ui_group_label'  => basename($validated),
            'ui_group_source' => $validated,
        ];

        if (anibas_fm_trash_enabled()) {
            $trash_dir = anibas_fm_get_trash_dir();

            // Same-FS detection via stat()['dev'] — when source and trash live
            // on the same filesystem, the atomic rename optimization works
            // and finishes in O(1) regardless of tree size. Different devices
            // (typical Docker bind-mount: /var/www/html overlay vs.
            // wp-content bind-mount) can't atomically rename a folder, so we
            // fall back to per-child moveToTrash — the folder itself stays
            // put while each child gets renamed-or-enqueued individually.
            $src_stat   = @stat($validated);
            $trash_stat = @stat($trash_dir);
            $same_fs    = $src_stat && $trash_stat
                && isset($src_stat['dev'], $trash_stat['dev'])
                && $src_stat['dev'] === $trash_stat['dev'];

            if ($same_fs) {
                error_clear_last();
                $r = $this->moveToTrash($validated);
                if ($r !== true) {
                    return new \WP_Error(
                        'empty_failed',
                        __('Failed to empty folder.', 'anibas-file-manager') . $this->local_delete_failure_reason($validated)
                    );
                }
                if (! wp_mkdir_p($validated)) {
                    return new \WP_Error(
                        'empty_recreate_failed',
                        __('Folder contents were trashed but the folder could not be recreated.', 'anibas-file-manager')
                    );
                }
                ActivityLogger::get_instance()->log('emptied', basename($validated), dirname($validated));
                return true;
            }

            // Cross-FS fallback. The root folder stays put; every direct child
            // gets moved to trash. Push the iteration into a single background
            // job so the AJAX request returns immediately even when the folder
            // has hundreds of thousands of direct children. The job's
            // DeletePhase walks the children chunked and calls moveToTrash on
            // each one (which itself may rename atomically or enqueue a sub-job
            // for cross-FS directory moves).
            $job_id = BackgroundProcessor::enqueue_empty_folder_trash_job($validated, 'local');
            if (is_wp_error($job_id)) {
                return $job_id;
            }

            BackgroundProcessor::annotate_jobs([$job_id], $group_meta + [
                'ui_group_mode' => 'trash',
            ]);

            ActivityLogger::get_instance()->log('emptied', basename($validated), dirname($validated));
            return [
                'job_ids'        => [$job_id],
                'group_id'       => $group_id,
                'operation_mode' => 'trash',
            ];
        }

        // No-trash path: hand off to BackgroundProcessor so the delete runs
        // chunked. keep_root=true preserves the root folder itself. Returned
        // as a single-element job_ids array so the AJAX layer + frontend
        // can use one shape across both the no-trash and cross-FS-trash
        // branches (the latter genuinely produces multiple jobs).
        $job_id = BackgroundProcessor::enqueue_delete_job($validated, 'local', true);
        if (is_wp_error($job_id)) {
            return $job_id;
        }

        BackgroundProcessor::annotate_jobs([$job_id], $group_meta + [
            'ui_group_mode' => 'delete',
        ]);

        ActivityLogger::get_instance()->log('emptied', basename($validated), dirname($validated));
        return [
            'job_ids'        => [$job_id],
            'group_id'       => $group_id,
            'operation_mode' => 'delete',
        ];
    }

    public function readFile(string $filename)
    {
        return $this->get_contents($filename);
    }

    public function get_contents(string $path): string|false
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

    public function copy(string $source, string $target): bool
    {
        $validated_source = $this->assertAllowed($source);
        $validated_target_dir = $this->assertAllowed(dirname($target));

        if (! $validated_source || ! $validated_target_dir) {
            return false;
        }

        $target_path = $validated_target_dir . DIRECTORY_SEPARATOR . basename($target);

        // Match move()'s target-path protection and also enforce blocked-basename
        // rules via assertAllowed, so the target can't land on wp-config.php etc.
        if (! $this->assertAllowed($target_path) || ! $this->is_allowed_path($target_path)) {
            return false;
        }

        if (class_exists('Anibas\BackgroundProcessor')) {
            if (is_dir($validated_source) && $this->has_protected_descendant($validated_source)) {
                return false;
            }
            return ! is_wp_error(BackgroundProcessor::enqueue_job($validated_source, $target_path, 'copy', 'skip', 'local', [
                'dest_is_final' => true,
            ]));
        }

        return false;
    }

    public function move(string $source, string $target): bool
    {
        $validated_source = $this->assertAllowed($source);
        $validated_target_dir = $this->assertAllowed(dirname($target));

        if (! $validated_source || ! $validated_target_dir) {
            ActivityLogger::get_instance()->log_message('Local move validation failed for source: ' . $source . ' or target: ' . $target);
            return false;
        }

        $target_path = $validated_target_dir . DIRECTORY_SEPARATOR . basename($target);

        if (! $this->assertAllowed($target_path) || ! $this->is_allowed_path($target_path)) {
            ActivityLogger::get_instance()->log_message('Local move blocked - protected or disallowed target path: ' . $target_path);
            return false;
        }

        if (is_dir($validated_source)) {
            if ($this->has_protected_descendant($validated_source)) {
                ActivityLogger::get_instance()->log_message('Local move blocked - source contains protected descendants: ' . $validated_source);
                return false;
            }
            return ! is_wp_error(BackgroundProcessor::enqueue_job($validated_source, $target_path, 'move', 'skip', 'local', [
                'dest_is_final' => true,
            ]));
        }

        // Fast rename when possible; otherwise queue chunked copy+remove_source.
        if (! anibas_fm_safe_move($validated_source, $target_path)) {
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
        if ($chunk_size < ANIBAS_FM_CHUNK_SIZE_MIN) {
            $chunk_size = ANIBAS_FM_CHUNK_SIZE_MIN;
        }
        if ($chunk_size > ANIBAS_FM_CHUNK_SIZE_MAX) {
            $chunk_size = ANIBAS_FM_CHUNK_SIZE_MAX;
        }

        // Ensure destination directory exists (recursive; fs->mkdir is single-level).
        $dest_dir = dirname($destination);
        if (! is_dir($dest_dir)) {
            if (! wp_mkdir_p($dest_dir)) {
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
            if (@file_put_contents($destination, '') !== false) {
                chmod($destination, FS_CHMOD_FILE);
                return self::COPY_OPERATION_COMPLETE;
            }
            ActivityLogger::get_instance()->log_message('Local Copy error: Failed to create empty destination file');
            return self::COPY_ERROR_CREATING_FILE;
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
                    // Unexpected EOF before $source_size — source was truncated
                    // or the read returned empty without an error. Returning
                    // IN_PROGRESS here would cause the caller to retry forever.
                    fclose($source_handle);
                    fclose($dest_handle);
                    ActivityLogger::get_instance()->log_message(
                        'Local Copy error: Unexpected EOF at position ' .
                        ($bytes_copied + $bytes_copied_current) . ' of ' . $source_size
                    );
                    return self::COPY_ERROR_READING_CHUNK;
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
        $validated = $this->assertAllowed($destination);
        if (! $validated) {
            return false;
        }

        try {
            if (file_exists($validated)) {
                if (is_dir($validated)) {
                    return $this->fs->rmdir($validated);
                } else {
                    return $this->fs->delete($validated);
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
        $new_path = $dir . DIRECTORY_SEPARATOR . $name . '_' . (string) microtime(true) . '_' . wp_rand(100000, 999999) . $ext;

        // Double-check the extremely unlikely case of collision
        $counter = 0;
        while ($this->fs->exists($new_path) && $counter < 10) {
            $new_path = $dir . DIRECTORY_SEPARATOR . $name . '_' . (string) microtime(true) . '_' . wp_rand(100000, 999999) . $ext;
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
