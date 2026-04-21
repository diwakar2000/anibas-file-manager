<?php

namespace Anibas;

if ( ! defined( 'ABSPATH' ) ) exit;

class FTPFileSystemAdapter extends FileSystemAdapter
{
    private $base_path;
    private $host;
    private $username;
    private $password;
    private $port;
    private $use_ssl;
    private $is_passive;
    private $insecure_ssl;
    private $last_copy_progress = null;

    public function __construct($host, $username, $password, $base_path = '/', $use_ssl = false, $port = null, $is_passive = true, $insecure_ssl = false)
    {
        $this->host = $host;
        $this->username = $username;
        $this->password = $password;
        $this->base_path = rtrim($base_path, '/');
        $this->use_ssl = $use_ssl;
        $this->port = $port ?? ($use_ssl ? 990 : 21);
        $this->is_passive = $is_passive;
        $this->insecure_ssl = (bool) $insecure_ssl;
    }

    public function validate_path($path)
    {
        // FTP server handles path constraints, return as-is
        return $path;
    }

    public function resolve_path($path, $context = 'default')
    {
        // Remove base_path if already present
        if ($this->base_path && strpos($path, $this->base_path) === 0) {
            $resolved = $path;
        } elseif ($this->base_path === '/') {
            $resolved = $path;
        } else {
            $resolved = rtrim($this->base_path, '/') . '/' . ltrim($path, '/');
        }

        // For FTP commands (QUOTE), don't quote - some servers don't support it
        // The path should work as-is since we're using CURLOPT_QUOTE
        return $resolved;
    }

    private function build_curl($path = '/', $options = [])
    {
        $resolved = $this->resolve_path($path);
        $encoded = implode('/', array_map('rawurlencode', explode('/', $resolved)));
        $url = "ftp://{$this->host}:{$this->port}" . $encoded;


        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_USERPWD, "{$this->username}:{$this->password}");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 25);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        if ($this->is_passive) {
            curl_setopt($ch, CURLOPT_FTP_USE_EPSV, true);
            curl_setopt($ch, CURLOPT_FTP_USE_EPRT, false);
        } else {
            curl_setopt($ch, CURLOPT_FTP_USE_EPSV, false);
            curl_setopt($ch, CURLOPT_FTP_USE_EPRT, true);
            curl_setopt($ch, CURLOPT_FTPPORT, '-');
        }

        if ($this->use_ssl) {
            curl_setopt($ch, CURLOPT_USE_SSL, CURLFTPSSL_ALL);
            curl_setopt($ch, CURLOPT_FTPSSLAUTH, CURLFTPAUTH_TLS);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, ! $this->insecure_ssl);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $this->insecure_ssl ? 0 : 2);
        }

        foreach ($options as $key => $value) {
            curl_setopt($ch, $key, $value);
        }

        return $ch;
    }

    private function send_request($ch)
    {
        $result = curl_exec($ch);
        $error = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        if ($error) {
            throw new \Exception(esc_html($this->translate_ftp_error($error, $http_code)));
        }

        return $result;
    }

    private function translate_ftp_error($error, $code)
    {
        // FTP response codes
        $ftp_errors = [
            // 4xx: Transient Negative Completion (Temporary failures)
            421 => esc_html__('Service not available, closing control connection.', 'anibas-file-manager'),
            425 => esc_html__('Can\'t open data connection.', 'anibas-file-manager'),
            426 => esc_html__('Connection closed; transfer aborted.', 'anibas-file-manager'),
            450 => esc_html__('File unavailable (busy or locked).', 'anibas-file-manager'),
            451 => esc_html__('Local error in processing.', 'anibas-file-manager'),
            452 => esc_html__('Insufficient storage space.', 'anibas-file-manager'),

            // 5xx: Permanent Negative Completion (The command failed)
            500 => esc_html__('Command not recognized or syntax error.', 'anibas-file-manager'),
            501 => esc_html__('Invalid parameters or arguments.', 'anibas-file-manager'),

            503 => esc_html__('Bad sequence of commands.', 'anibas-file-manager'),

            532 => esc_html__('Need account for storing files.', 'anibas-file-manager'),

            550 => esc_html__('File unavailable or permission denied.', 'anibas-file-manager'),
            551 => esc_html__('Page type unknown.', 'anibas-file-manager'),
            552 => esc_html__('Exceeded storage allocation.', 'anibas-file-manager'),
            553 => esc_html__('File name not allowed.', 'anibas-file-manager'),

            502 => esc_html__('Command not implemented (Server might not support SSL/TLS)', 'anibas-file-manager'),
            504 => esc_html__('Command parameter not implemented (Server rejected SSL/TLS request)', 'anibas-file-manager'),
            
            // Happens when Server requires SSL but you didn't use it
            534 => esc_html__('Connection denied for policy reasons (Server likely requires FTPS/SSL)', 'anibas-file-manager'),
            
            // Catch-all for login failures (can be plain text vs SSL mismatch)
            530 => esc_html__('Authentication failed or encryption required by server', 'anibas-file-manager'),
        ];

        // Extract FTP code from error message
        if (preg_match('/(\d{3})/', $error, $matches)) {
            $ftp_code = (int) $matches[1];
            if (isset($ftp_errors[$ftp_code])) {
                $message = $ftp_errors[$ftp_code];

                // 530/534 with SSL off usually means the server requires FTPS.
                // Without this hint users assume their password is wrong.
                if (! $this->use_ssl && in_array($ftp_code, [530, 534], true)) {
                    $message .= ' ' . esc_html__('If the server requires FTPS, enable "Use SSL (FTPS)" in connection settings.', 'anibas-file-manager');
                }

                // 425 = data channel failed. Tell the user to flip the Passive Mode
                // toggle in connection settings — the most common fix for this error.
                if ($ftp_code === 425) {
                    $current_mode = $this->is_passive ? esc_html__('Passive', 'anibas-file-manager') : esc_html__('Active', 'anibas-file-manager');
                    $other_mode   = $this->is_passive ? esc_html__('Active', 'anibas-file-manager') : esc_html__('Passive', 'anibas-file-manager');
                    /* translators: 1: current FTP mode, 2: alternate FTP mode to try */
                    $message .= ' ' . sprintf(esc_html__('Currently using %1$s mode — try toggling "Passive Mode" to %2$s in connection settings, or check that the firewall allows the data port range.', 'anibas-file-manager'), $current_mode, $other_mode);
                }

                /* translators: 1: FTP error code, 2: error message */
                return sprintf(esc_html__('FTP Error %1$d: %2$s', 'anibas-file-manager'), $ftp_code, $message);
            }
        }

        // cURL-level SSL/login errors that fire before any FTP response code.
        // CURLE_USE_SSL_FAILED = 64, CURLE_LOGIN_DENIED = 67.
        if (! $this->use_ssl && (stripos($error, 'AUTH') !== false || stripos($error, 'SSL') !== false || stripos($error, 'TLS') !== false)) {
            return esc_html__('FTP server appears to require SSL/TLS. Enable "Use SSL (FTPS)" in connection settings.', 'anibas-file-manager');
        }

        // Common curl FTP errors
        if (stripos($error, 'timeout') !== false) {
            return esc_html__('Connection timeout - server took too long to respond', 'anibas-file-manager');
        }
        if (stripos($error, 'couldn\'t connect') !== false) {
            return esc_html__('Could not connect to FTP server', 'anibas-file-manager');
        }
        if (stripos($error, 'access denied') !== false || stripos($error, 'permission') !== false) {
            return esc_html__('Permission denied - check file/folder permissions', 'anibas-file-manager');
        }
        if (stripos($error, 'disk') !== false || stripos($error, 'space') !== false) {
            return esc_html__('Insufficient disk space on server', 'anibas-file-manager');
        }

        return $error;
    }

    private static $connection_curl_errors = [
        CURLE_COULDNT_RESOLVE_HOST,  // 6
        CURLE_COULDNT_CONNECT,       // 7
        CURLE_OPERATION_TIMEDOUT,    // 28
        CURLE_SSL_CONNECT_ERROR,     // 35
        CURLE_RECV_ERROR,            // 56
        CURLE_SEND_ERROR,            // 55
    ];

    private function check_curl_connection_error($ch)
    {
        $errno = curl_errno($ch);
        if ($errno && in_array($errno, self::$connection_curl_errors, true)) {
            $error = curl_error($ch);
            throw new \Exception(esc_html__('FTP connection error: ', 'anibas-file-manager') . esc_html($error));
        }
    }

    public function exists($path)
    {
        // Try as directory first (with trailing slash)
        $dir_path = rtrim($path, '/') . '/';
        $ch = $this->build_curl($dir_path, [CURLOPT_NOBODY => true, CURLOPT_HEADER => true]);
        curl_exec($ch);
        $this->check_curl_connection_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($http_code >= 200 && $http_code < 400) {
            return true;
        }

        // Try as file (without trailing slash)
        $ch = $this->build_curl($path, [CURLOPT_NOBODY => true, CURLOPT_HEADER => true]);
        curl_exec($ch);
        $this->check_curl_connection_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        return $http_code >= 200 && $http_code < 400;
    }

    public function is_file($path)
    {
        try {
            $parent = dirname($path);
            $name = basename($path);

            // Add trailing slash for directory listing
            $list_path = rtrim($parent, '/') . '/';
            $ch = $this->build_curl($list_path, [CURLOPT_CUSTOMREQUEST => 'LIST']);
            $result = $this->send_request($ch);

            $lines = explode("\n", $result);

            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;

                $parts = preg_split('/\s+/', $line, 9);
                if (count($parts) < 9) continue;

                $filename = $parts[8];

                if ($filename === $name && $parts[0][0] !== 'd') {
                    return true;
                }
            }
            return false;
        } catch (\Exception $e) {
            ActivityLogger::get_instance()->log_message('FTP is_file check failed for "' . $path . '": ' . $e->getMessage());
            return false;
        }
    }

    public function is_dir($path)
    {
        // Root is always a directory
        if ($path === '/') {
            return true;
        }

        try {
            $parent = dirname($path);
            $name = basename($path);

            // Add trailing slash for directory listing
            $list_path = rtrim($parent, '/') . '/';
            $ch = $this->build_curl($list_path, [CURLOPT_CUSTOMREQUEST => 'LIST']);
            $result = $this->send_request($ch);
            $lines = explode("\n", $result);

            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;

                $parts = preg_split('/\s+/', $line, 9);
                if (count($parts) < 9) continue;

                $filename = $parts[8];
                if ($filename === $name && $parts[0][0] === 'd') {
                    return true;
                }
            }
            return false;
        } catch (\Exception $e) {
            ActivityLogger::get_instance()->log_message('FTP is_dir check failed for "' . $path . '": ' . $e->getMessage());
            return false;
        }
    }

    public function mkdir($path)
    {
        $resolved = $this->resolve_path($path, 'command');
        $ch = $this->build_curl('/', [
            CURLOPT_QUOTE => ['MKD ' . $resolved],
            CURLOPT_NOBODY => true
        ]);
        $this->send_request($ch);
        return true;
    }

    public function scandir($path)
    {
        $resolved = $this->resolve_path($path);
        $list_path = rtrim($resolved, '/') . '/';

        $ch = $this->build_curl($list_path, [CURLOPT_CUSTOMREQUEST => 'LIST']);
        $result = $this->send_request($ch);
        $lines = explode("\n", trim($result));
        $items = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            // Parse Unix-style listing: drwxr-xr-x ... filename
            $parts = preg_split('/\s+/', $line, 9);
            if (count($parts) < 9) continue;

            $name = $parts[8];
            if ($name === '.' || $name === '..') continue;

            $full_path = rtrim($path, '/') . '/' . $name;
            $is_dir = $parts[0][0] === 'd';

            $items[$full_path] = [
                'name' => $name,
                'path' => $full_path,
                'is_folder' => $is_dir,
                'filesize' => $is_dir ? 0 : (int)$parts[4],
                'file_type' => $is_dir ? 'folder' : 'file',
                'last_modified' => 0,
            ];
        }

        return ['items' => $items, 'total' => count($items)];
    }

    public function listDirectory($path)
    {
        $result = $this->scandir($path);
        return [
            'items' => array_values($result['items']),
            'total_items' => $result['total']
        ];
    }

    public function getDetails($path)
    {
        $parent   = dirname($path);
        $target   = basename($path);
        $resolved = $this->resolve_path($parent);
        $list_path = rtrim($resolved, '/') . '/';

        try {
            $ch     = $this->build_curl($list_path, [CURLOPT_CUSTOMREQUEST => 'LIST']);
            $result = $this->send_request($ch);
        } catch (\Exception $e) {
            return false;
        }

        $lines = explode("\n", trim($result));

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            // Unix-style: drwxr-xr-x 2 owner group 4096 Jan 01 12:00 filename
            $parts = preg_split('/\s+/', $line, 9);
            if (count($parts) < 9 || $parts[8] !== $target) continue;

            $isDir = $parts[0][0] === 'd';

            return [
                'name'             => $target,
                'path'             => $path,
                'is_folder'        => $isDir,
                'size'             => $isDir ? 0 : (int) $parts[4],
                'last_modified'    => null,
                'created'          => null,
                'permission'       => null,
                'permission_octal' => null,
                'permission_str'   => $parts[0],
                'owner'            => $parts[2],
                'group'            => $parts[3],
                'extension'        => $isDir ? '' : pathinfo($target, PATHINFO_EXTENSION),
                'mime_type'        => null,
            ];
        }

        return false;
    }

    public function rmdir($path)
    {

        // First, recursively delete all contents
        try {
            $contents = $this->scandir($path);

            if (isset($contents['items'])) {
                foreach ($contents['items'] as $item) {
                    if ($item['is_folder']) {
                        $this->rmdir($item['path']);
                    } else {
                        $this->unlink($item['path']);
                    }
                }
            }
        } catch (\Exception $e) {
        }

        // Now delete the empty directory
        $resolved = $this->resolve_path($path, 'command');

        try {
            $ch = $this->build_curl('/', [
                CURLOPT_QUOTE => ['RMD ' . $resolved],
                CURLOPT_NOBODY => true
            ]);
            $result = $this->send_request($ch);
            return $result !== false;
        } catch (\Exception $e) {
            throw $e;
        }
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
        if ($chunk_size < 262144) { // Min 256KB
            $chunk_size = 262144;
        }
        if ($chunk_size > 10485760) { // Max 10MB
            $chunk_size = 10485760;
        }

        // Get file size for progress tracking
        $size_ch = $this->build_curl($source);
        curl_setopt($size_ch, CURLOPT_NOBODY, true);
        curl_setopt($size_ch, CURLOPT_HEADER, false);
        $this->send_request($size_ch);
        $file_size = curl_getinfo($size_ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);

        if ($file_size === 0) {
            ActivityLogger::get_instance()->log_message('FTP Copy error: Source file is empty or not found');
            return self::COPY_ERROR_SOURCE_NOT_FOUND;
        }

        // Check if copy is already complete
        if ($bytes_copied >= $file_size) {
            ActivityLogger::get_instance()->log_message('FTP Copy already completed: ' . $bytes_copied . ' of ' . $file_size . ' bytes');
            return self::COPY_OPERATION_COMPLETE;
        }

        try {
            // Download single chunk from source
            $source_ch = $this->build_curl($source);
            curl_setopt($source_ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($source_ch, CURLOPT_BUFFERSIZE, $chunk_size);

            // Set range to download only the chunk we need
            $range_start = $bytes_copied;
            $range_end = min($bytes_copied + $chunk_size - 1, $file_size - 1);
            curl_setopt($source_ch, CURLOPT_RANGE, "{$range_start}-{$range_end}");

            $chunk_data = $this->send_request($source_ch);
            if ($chunk_data === false) {
                ActivityLogger::get_instance()->log_message('FTP Copy error: Failed to download chunk at position ' . $bytes_copied);
                return self::COPY_ERROR_DOWNLOADING_CHUNK;
            }

            $chunk_size_actual = strlen($chunk_data);
            if ($chunk_size_actual === 0) {
                ActivityLogger::get_instance()->log_message('FTP Copy error: No data received for chunk at position ' . $bytes_copied);
                return self::COPY_ERROR_NO_DATA_RECEIVED;
            }

            // Upload chunk to target
            $target_ch = $this->build_curl($target);
            curl_setopt($target_ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($target_ch, CURLOPT_UPLOAD, true);
            curl_setopt($target_ch, CURLOPT_INFILESIZE, $chunk_size_actual);

            // Create temp handle for this chunk
            $chunk_handle = fopen('php://temp', 'r+');
            try {
                fwrite($chunk_handle, $chunk_data);
                rewind($chunk_handle);
                curl_setopt($target_ch, CURLOPT_INFILE, $chunk_handle);

                // For first chunk, create new file. For subsequent chunks, append
                if ($bytes_copied === 0) {
                    // First chunk - create new file
                    curl_setopt($target_ch, CURLOPT_FTP_CREATE_MISSING_DIRS, true);
                } else {
                    // Subsequent chunks - append to existing file
                    curl_setopt($target_ch, CURLOPT_FTPAPPEND, true);
                }

                $upload_result = $this->send_request($target_ch);
            } finally {
                if (is_resource($chunk_handle)) {
                    fclose($chunk_handle);
                }
            }

            if ($upload_result === false) {
                $error_code = ($bytes_copied === 0) ? self::COPY_ERROR_CREATING_FILE : self::COPY_ERROR_APPENDING_TO_FILE;
                ActivityLogger::get_instance()->log_message('FTP Copy error: Failed to upload chunk at position ' . $bytes_copied . ' (code: ' . $error_code . ')');
                return $error_code;
            }

            $new_bytes_copied = $bytes_copied + $chunk_size_actual;

            // Log progress
            $progress = ($new_bytes_copied / $file_size) * 100;
            $is_complete = $new_bytes_copied >= $file_size;

            // Store accurate progress — getCopyProgress will use this instead of unreliable SIZE
            $this->last_copy_progress = [
                'file_size' => $file_size,
                'bytes_copied' => $new_bytes_copied,
                'progress_percent' => round($progress, 2),
                'is_complete' => $is_complete,
                'next_bytes_copied' => $new_bytes_copied
            ];

            ActivityLogger::get_instance()->log_message(
                "FTP Copy chunk completed: position {$bytes_copied}-{$new_bytes_copied}, " .
                    round($progress, 2) . "% " .
                    ($is_complete ? "(COMPLETE)" : "(resume with bytes_copied={$new_bytes_copied})")
            );

            // Return appropriate status code
            return $is_complete ? self::COPY_OPERATION_COMPLETE : self::COPY_OPERATION_IN_PROGRESS;
        } catch (\Exception $e) {
            ActivityLogger::get_instance()->log_message('FTP Copy exception at position ' . $bytes_copied . ': ' . $e->getMessage());
            return self::COPY_ERROR_DOWNLOADING_CHUNK;
        }
    }

    /**
     * Get copy progress info for resumable operations
     */
    public function getCopyProgress($source, $target): array
    {
        // Use accurate progress from last copyFileInChunks call (avoids unreliable FTP SIZE)
        if ($this->last_copy_progress !== null) {
            $progress = $this->last_copy_progress;
            $this->last_copy_progress = null;
            return $progress;
        }

        // Fallback: query FTP server directly (used when no recent chunk write)
        try {
            $size_ch = $this->build_curl($source);
            curl_setopt($size_ch, CURLOPT_NOBODY, true);
            curl_setopt($size_ch, CURLOPT_HEADER, false);
            $this->send_request($size_ch);
            $file_size = curl_getinfo($size_ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);

            $target_size = 0;
            try {
                $target_ch = $this->build_curl($target);
                curl_setopt($target_ch, CURLOPT_NOBODY, true);
                curl_setopt($target_ch, CURLOPT_HEADER, false);
                $this->send_request($target_ch);
                $target_size = curl_getinfo($target_ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
                if ($target_size < 0) {
                    $target_size = 0;
                }
            } catch (\Exception $e) {
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
            throw new \Exception('Failed to get FTP copy progress: ' . esc_html($e->getMessage()));
        }
    }

    /**
     * Delete destination file/folder for cancelled operations
     */
    public function deleteDestination($destination): bool
    {
        try {
            // Check if destination exists
            $check_ch = $this->build_curl($destination);
            curl_setopt($check_ch, CURLOPT_NOBODY, true);
            curl_setopt($check_ch, CURLOPT_HEADER, false);
            $result = $this->send_request($check_ch);

            if ($result !== false) {
                // Destination exists, delete it
                $delete_ch = $this->build_curl('/', [
                    CURLOPT_QUOTE => ['DELE ' . $this->resolve_path($destination, 'command')],
                    CURLOPT_NOBODY => true
                ]);
                $delete_result = $this->send_request($delete_ch);
                return $delete_result !== false;
            }
            return true; // Nothing to delete
        } catch (\Exception $e) {
            ActivityLogger::get_instance()->log_message('Failed to delete FTP destination: ' . $e->getMessage());
            throw $e;
        }
    }

    public function move($source, $target)
    {
        $source_resolved = $this->resolve_path($source, 'command');
        $target_resolved = $this->resolve_path($target, 'command');
        $ch = $this->build_curl('/', [
            CURLOPT_QUOTE => [
                'RNFR ' . $source_resolved,
                'RNTO ' . $target_resolved
            ],
            CURLOPT_NOBODY => true
        ]);
        $result = $this->send_request($ch);
        return $result !== false;
    }

    public function unlink($path)
    {
        $resolved = $this->resolve_path($path, 'command');

        try {
            $ch = $this->build_curl('/', [
                CURLOPT_QUOTE => ['DELE ' . $resolved],
                CURLOPT_NOBODY => true
            ]);
            $result = $this->send_request($ch);
            return $result !== false;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function get_contents($path)
    {
        try {
            $ch = $this->build_curl($path);
            return $this->send_request($ch);
        } catch (\Exception $e) {
            return false;
        }
    }

    public function stream_contents($path)
    {
        try {
            $ch = $this->build_curl($path);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
            curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) {
                echo $data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary file stream
                flush();
                return strlen($data);
            });
            $result = curl_exec($ch);
            $error = curl_error($ch);
            $http_code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

            if ($error) {
                throw new \Exception($this->translate_ftp_error($error, $http_code));
            }
            return $result !== false;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function get_size($path)
    {
        try {
            $ch = $this->build_curl($path);
            curl_setopt($ch, CURLOPT_NOBODY, true);
            curl_setopt($ch, CURLOPT_HEADER, false);
            $this->send_request($ch);
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
        $stream = null;
        try {
            // Create memory stream for binary-safe content
            $stream = fopen('php://temp', 'r+');
            fwrite($stream, $content);
            rewind($stream);

            $ch = $this->build_curl($path, [
                CURLOPT_UPLOAD => true,
                CURLOPT_INFILE => $stream,
                CURLOPT_INFILESIZE => strlen($content),
                CURLOPT_APPEND => true
            ]);
            $result = $this->send_request($ch);
            return $result !== false;
        } catch (\Exception $e) {
            ActivityLogger::get_instance()->log_message('FTP append_contents failed: ' . $e->getMessage() . ' for path: ' . $path);
            return false;
        } finally {
            if ($stream) {
                fclose($stream);
            }
        }
    }

    public function download_to_local(string $remote_path, string $local_path): bool
    {
        $dir = dirname($local_path);
        if (! is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        $fp = fopen($local_path, 'wb'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        if (! $fp) {
            return false;
        }
        try {
            $ch = $this->build_curl($remote_path);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_TIMEOUT, 300);
            $result = curl_exec($ch);
            $error  = curl_error($ch);
            fclose($fp);
            if ($error || $result === false) {
                @unlink($local_path);
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            fclose($fp);
            @unlink($local_path);
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
            $ch = $this->build_curl($remote_path, [
                CURLOPT_UPLOAD     => true,
                CURLOPT_INFILE     => $fp,
                CURLOPT_INFILESIZE => $size,
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 300);
            $result = $this->send_request($ch);
            fclose($fp);
            return $result !== false;
        } catch (\Throwable $e) {
            fclose($fp);
            return false;
        }
    }

    public function supports_chunked_transfer(): bool
    {
        return true;
    }

    /**
     * Chunked download from FTP to local file using CURLOPT_RANGE.
     */
    public function download_to_local_chunked(string $remote_path, string $local_path, int $offset = 0, int $chunk_size = 2097152): array
    {
        $chunk_size = intval(anibas_fm_get_option('chunk_size', ANIBAS_FM_DEFAULT_CHUNK_SIZE));
        if ($chunk_size < 262144) $chunk_size = 262144;
        if ($chunk_size > 10485760) $chunk_size = 10485760;

        try {
            // Get remote file size
            $size_ch = $this->build_curl($remote_path);
            curl_setopt($size_ch, CURLOPT_NOBODY, true);
            curl_setopt($size_ch, CURLOPT_HEADER, false);
            $this->send_request($size_ch);
            $file_size = curl_getinfo($size_ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);

            if ($file_size <= 0) {
                return ['status' => 5, 'bytes_copied' => 0]; // SOURCE_NOT_FOUND
            }

            if ($offset >= $file_size) {
                return ['status' => 9, 'bytes_copied' => $file_size]; // COMPLETE
            }

            // Range download
            $source_ch = $this->build_curl($remote_path);
            curl_setopt($source_ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($source_ch, CURLOPT_BUFFERSIZE, $chunk_size);
            $range_end = min($offset + $chunk_size - 1, $file_size - 1);
            curl_setopt($source_ch, CURLOPT_RANGE, "{$offset}-{$range_end}");

            $chunk_data = $this->send_request($source_ch);
            if ($chunk_data === false || strlen($chunk_data) === 0) {
                return ['status' => 3, 'bytes_copied' => $offset]; // DOWNLOAD_ERROR
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
            ActivityLogger::get_instance()->log_message('FTP download_to_local_chunked error at offset ' . $offset . ': ' . $e->getMessage());
            return ['status' => 3, 'bytes_copied' => $offset];
        }
    }

    /**
     * Chunked upload from local file to FTP using CURLOPT_FTPAPPEND.
     */
    public function upload_from_local_chunked(string $local_path, string $remote_path, int $offset = 0, int $chunk_size = 2097152): array
    {
        $chunk_size = intval(anibas_fm_get_option('chunk_size', ANIBAS_FM_DEFAULT_CHUNK_SIZE));
        if ($chunk_size < 262144) $chunk_size = 262144;
        if ($chunk_size > 10485760) $chunk_size = 10485760;

        $file_size = filesize($local_path);
        if ($file_size === false) {
            return ['status' => 5, 'bytes_copied' => 0];
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
                return ['status' => 7, 'bytes_copied' => $offset]; // NO_DATA
            }

            // Upload chunk via cURL
            $chunk_handle = fopen('php://temp', 'r+'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
            fwrite($chunk_handle, $chunk_data);
            rewind($chunk_handle);

            $ch = $this->build_curl($remote_path);
            curl_setopt($ch, CURLOPT_UPLOAD, true);
            curl_setopt($ch, CURLOPT_INFILE, $chunk_handle);
            curl_setopt($ch, CURLOPT_INFILESIZE, $chunk_actual);

            if ($offset === 0) {
                curl_setopt($ch, CURLOPT_FTP_CREATE_MISSING_DIRS, true);
            } else {
                curl_setopt($ch, CURLOPT_FTPAPPEND, true);
            }

            $result = $this->send_request($ch);
            fclose($chunk_handle);

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
            ActivityLogger::get_instance()->log_message('FTP upload_from_local_chunked error at offset ' . $offset . ': ' . $e->getMessage());
            return ['status' => 4, 'bytes_copied' => $offset];
        }
    }

    private function upload_content($path, $content)
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);

        $ch = $this->build_curl($path, [
            CURLOPT_UPLOAD => true,
            CURLOPT_INFILE => $stream,
            CURLOPT_INFILESIZE => strlen($content)
        ]);

        $result = $this->send_request($ch);
        fclose($stream);

        if ($result === false) {
            throw new \Exception('Failed to upload file');
        }

        return true;
    }
}
