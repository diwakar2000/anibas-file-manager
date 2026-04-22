<?php

namespace Anibas;

if ( ! defined( 'ABSPATH' ) ) exit;


/**
 * S3-compatible adapter with chunked uploads/downloads
 * Supports AWS S3, MinIO, DigitalOcean Spaces, Wasabi, etc.
 * Uses AnibasS3Client — no external composer dependencies required.
 */
class S3FileSystemAdapter extends FileSystemAdapter
{
    private AnibasS3Client $s3_client;
    private string $bucket;
    private string $prefix;
    private int $chunk_size = 5242880; // 5MB chunks

    /**
     * @param AnibasS3Client $s3_client  Pre-configured lightweight S3 client
     * @param string         $bucket     S3 bucket name
     * @param string         $prefix     Optional path prefix inside the bucket
     * @param int            $chunk_size Multipart chunk size in bytes (min 5MB)
     */
    public function __construct(AnibasS3Client $s3_client, string $bucket, string $prefix = '', int $chunk_size = 5242880)
    {
        $this->s3_client = $s3_client;
        $this->bucket    = $bucket;
        $this->prefix    = trim($prefix, '/');
        $this->chunk_size = max($chunk_size, 5242880); // S3 minimum part size is 5MB
    }

    private function translate_s3_error($e)
    {
        $message = $e->getMessage();
        $code = method_exists($e, 'getAwsErrorCode') ? $e->getAwsErrorCode() : '';

        $translations = [
            'NoSuchBucket' => esc_html__('Bucket does not exist', 'anibas-file-manager'),
            'AccessDenied' => esc_html__('Access denied - check credentials and permissions', 'anibas-file-manager'),
            'InvalidAccessKeyId' => esc_html__('Invalid access key', 'anibas-file-manager'),
            'SignatureDoesNotMatch' => esc_html__('Invalid secret key', 'anibas-file-manager'),
            'NoSuchKey' => esc_html__('File not found', 'anibas-file-manager'),
            'EntityTooLarge' => esc_html__('File too large', 'anibas-file-manager'),
            'InvalidBucketName' => esc_html__('Invalid bucket name', 'anibas-file-manager'),
            'BucketAlreadyExists' => esc_html__('Bucket name already taken', 'anibas-file-manager'),
            'RequestTimeout' => esc_html__('Request timeout - try again', 'anibas-file-manager'),
            'SlowDown' => esc_html__('Too many requests - slow down', 'anibas-file-manager'),
        ];

        if ($code && isset($translations[$code])) {
            /* translators: %s: S3 error message */
            return sprintf(esc_html__('S3 Error: %s', 'anibas-file-manager'), $translations[$code]);
        }

        if (stripos($message, 'credentials') !== false) {
            return esc_html__('Invalid S3 credentials', 'anibas-file-manager');
        }
        if (stripos($message, 'timeout') !== false) {
            return esc_html__('Connection timeout - check network and endpoint', 'anibas-file-manager');
        }

        return $message;
    }

    public function validate_path($path)
    {
        // S3 handles path constraints, return as-is
        return $path;
    }

    public function exists($path)
    {
        $key = $this->get_key($path);
        if ($key === '') {
            return true; // bucket root always exists
        }
        $result = $this->s3_client->listObjectsV2([
            'Bucket'  => $this->bucket,
            'Prefix'  => $key,
            'MaxKeys' => 1,
        ]);
        if (empty($result['Contents'])) {
            return false;
        }
        $first_key = $result['Contents'][0]['Key'];
        return $first_key === $key || str_starts_with($first_key, $key . '/');
    }

    public function is_file($path)
    {
        return $this->exists($path) && ! $this->is_dir($path);
    }

    public function is_dir($path)
    {
        $key = $this->get_key($path);
        if (! str_ends_with($key, '/')) {
            $key .= '/';
        }
        $result = $this->s3_client->listObjectsV2([
            'Bucket' => $this->bucket,
            'Prefix' => $key,
            'MaxKeys' => 1,
        ]);
        return ! empty($result['Contents']) || ! empty($result['CommonPrefixes']);
    }

    public function mkdir($path)
    {
        $key = $this->get_key($path);
        if (! str_ends_with($key, '/')) {
            $key .= '/';
        }
        $this->s3_client->putObject([
            'Bucket' => $this->bucket,
            'Key' => $key,
            'Body' => '',
        ]);
        return true;
    }

    public function scandir($path)
    {
        $key = $this->get_key($path);
        if (! str_ends_with($key, '/')) {
            $key .= '/';
        }

        $result = $this->s3_client->listObjectsV2([
            'Bucket' => $this->bucket,
            'Prefix' => $key,
            'Delimiter' => '/',
        ]);

        $items = [];
        foreach ($result['Contents'] ?? [] as $object) {
            $items[] = basename($object['Key']);
        }
        foreach ($result['CommonPrefixes'] ?? [] as $prefix) {
            $items[] = basename(rtrim($prefix['Prefix'], '/'));
        }
        return $items;
    }

    public function listDirectory($path)
    {
        $key = $this->get_key($path);
        // Root path produces an empty key — use '' as prefix, not '/' which matches nothing
        if ($key !== '' && ! str_ends_with($key, '/')) {
            $key .= '/';
        }

        $result = $this->s3_client->listObjectsV2([
            'Bucket' => $this->bucket,
            'Prefix' => $key,
            'Delimiter' => '/',
        ]);

        $items = array();

        // Process folders first so their names are known before processing files.
        foreach ($result['CommonPrefixes'] ?? [] as $prefix) {
            $name = basename(rtrim($prefix['Prefix'], '/'));
            if (empty($name)) continue;
            $full_path = rtrim($path, '/') . '/' . $name;
            $items[$name] = array(
                'name' => $name,
                'path' => $full_path,
                'is_folder' => true,
                'permission' => 0,
                'last_modified' => 0,
                'has_children' => false,
                'files' => array()
            );
        }

        foreach ($result['Contents'] ?? [] as $object) {
            // Skip the directory-marker objects: zero-byte keys ending in '/' or
            // zero-byte keys whose name matches an already-known folder (Wasabi-style markers).
            if (str_ends_with($object['Key'], '/')) continue;

            $name = basename($object['Key']);
            if (empty($name)) continue;

            // Zero-byte object with the same name as a folder → it's a folder marker, skip it.
            if (isset($items[$name]) && $items[$name]['is_folder'] && (int) $object['Size'] === 0) continue;

            $full_path = rtrim($path, '/') . '/' . $name;
            $items[$name] = array(
                'name' => $name,
                'path' => $full_path,
                'is_folder' => false,
                'permission' => 0,
                'last_modified' => strtotime($object['LastModified']),
                'filename' => $name,
                'filesize' => $object['Size'],
                'file_type' => 'File'
            );
        }

        return [
            'items' => array_values($items),
            'total_items' => count($items)
        ];
    }

    public function getDetails($path)
    {
        $key   = $this->get_key($path);
        $isDir = $this->is_dir($path);
        $name  = basename($path);

        if ($isDir) {
            return [
                'name'             => $name,
                'path'             => $path,
                'is_folder'        => true,
                'size'             => 0,
                'last_modified'    => null,
                'created'          => null,
                'permission'       => null,
                'permission_octal' => null,
                'owner'            => null,
                'group'            => null,
                'extension'        => '',
                'mime_type'        => null,
            ];
        }

        try {
            $head = $this->s3_client->headObject([
                'Bucket' => $this->bucket,
                'Key'    => $key,
            ]);

            return [
                'name'             => $name,
                'path'             => $path,
                'is_folder'        => false,
                'size'             => (int) $head['ContentLength'],
                'last_modified'    => strtotime($head['LastModified']),
                'created'          => null,
                'permission'       => null,
                'permission_octal' => null,
                'owner'            => null,
                'group'            => null,
                'extension'        => pathinfo($name, PATHINFO_EXTENSION),
                'mime_type'        => $head['ContentType'] ?? null,
            ];
        } catch (\Exception $e) {
            return false;
        }
    }

    public function rmdir($path)
    {
        $key = $this->get_key($path);
        if (! str_ends_with($key, '/')) {
            $key .= '/';
        }

        $max_iterations = 10000; // up to ~10M objects at 1000/page
        $iterations     = 0;
        $last_first_key = null;

        do {
            $result = $this->s3_client->listObjectsV2([
                'Bucket'  => $this->bucket,
                'Prefix'  => $key,
                'MaxKeys' => 1000,
            ]);

            $contents = $result['Contents'] ?? [];
            if (empty($contents)) break;

            // If the first key of this page matches the first key of the previous
            // page, no deletes succeeded last round — abort instead of spinning.
            $first_key = $contents[0]['Key'] ?? null;
            if ($last_first_key !== null && $first_key === $last_first_key) {
                return false;
            }
            $last_first_key = $first_key;

            foreach ($contents as $object) {
                $this->s3_client->deleteObject([
                    'Bucket' => $this->bucket,
                    'Key'    => $object['Key'],
                ]);
            }

            $iterations++;
        } while ($iterations < $max_iterations);

        return $iterations < $max_iterations;
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
     * Copy file in chunks for S3, supporting resumable multipart copy for large files.
     */
    public function copyFileInChunks($source, $target, ?int $chunk_size = null, $bytes_copied = 0): int
    {
        $source_key = $this->get_key($source);
        $target_key = $this->get_key($target);

        try {
            // Check file size if it's the first call or not provided
            $head = $this->s3_client->headObject([
                'Bucket' => $this->bucket,
                'Key' => $source_key,
            ]);

            $size = $head['ContentLength'];
            if ($size === 0) {
                return self::COPY_ERROR_SOURCE_EMPTY;
            }

            // Use simple copy for files < 100MB to be fast, or if specifically requested not to be chunked
            // Note: S3 minimum multipart size is 5MB, so we only multipart if it makes sense.
            if ($size < 104857600) { // 100MB
                $this->s3_client->copyObject([
                    'Bucket' => $this->bucket,
                    'CopySource' => $this->bucket . '/' . $source_key,
                    'Key' => $target_key,
                ]);
                return self::COPY_OPERATION_COMPLETE;
            }

            // Use multipart copy for large files
            return $this->multipart_copy_resumable($source_key, $target_key, $size);
        } catch (\Exception $e) {
            ActivityLogger::get_instance()->log_message('S3 Copy error: ' . $e->getMessage());
            return self::COPY_ERROR_DOWNLOADING_CHUNK;
        }
    }

    /**
     * Get copy progress info for resumable operations
     */
    public function getCopyProgress($source, $target): array
    {
        $source_key = $this->get_key($source);
        $target_key = $this->get_key($target);
        $state_key = 'anibas_s3_multipart_' . md5($source_key . $target_key);
        $state = get_option($state_key, []);

        if (empty($state)) {
            // Check if target exists and is complete
            try {
                $head = $this->s3_client->headObject(['Bucket' => $this->bucket, 'Key' => $target_key]);
                $size = $head['ContentLength'];
                return [
                    'file_size' => $size,
                    'bytes_copied' => $size,
                    'progress_percent' => 100,
                    'is_complete' => true,
                    'next_bytes_copied' => $size
                ];
            } catch (\Exception $e) {
                return [
                    'file_size' => 0,
                    'bytes_copied' => 0,
                    'progress_percent' => 0,
                    'is_complete' => false,
                    'next_bytes_copied' => 0
                ];
            }
        }

        return [
            'file_size' => $state['total_size'],
            'bytes_copied' => $state['offset'],
            'progress_percent' => $state['total_size'] > 0 ? ($state['offset'] / $state['total_size']) * 100 : 0,
            'is_complete' => $state['offset'] >= $state['total_size'],
            'next_bytes_copied' => $state['offset']
        ];
    }

    private function multipart_copy_resumable($source_key, $target_key, $size)
    {
        $state_key = 'anibas_s3_multipart_' . md5($source_key . $target_key);
        $state = get_option($state_key, []);

        // Resume or start new
        if (empty($state['upload_id'])) {
            $result = $this->s3_client->createMultipartUpload([
                'Bucket' => $this->bucket,
                'Key' => $target_key,
            ]);
            $state = [
                'upload_id' => $result['UploadId'],
                'parts' => [],
                'offset' => 0,
                'part_number' => 1,
                'total_size' => $size,
                'source_key' => $source_key,
                'target_key' => $target_key,
            ];
            update_option($state_key, $state, false);
        }

        // Check if all chunks uploaded, do completion
        if ($state['offset'] >= $state['total_size']) {
            return $this->complete_multipart($state_key, $state) ? self::COPY_OPERATION_COMPLETE : self::COPY_ERROR_APPENDING_TO_FILE;
        }

        try {
            $end = min($state['offset'] + $this->chunk_size - 1, $state['total_size'] - 1);

            $part_result = $this->s3_client->uploadPartCopy([
                'Bucket' => $this->bucket,
                'Key' => $target_key,
                'UploadId' => $state['upload_id'],
                'PartNumber' => $state['part_number'],
                'CopySource' => $this->bucket . '/' . $source_key,
                'CopySourceRange' => "bytes={$state['offset']}-{$end}",
            ]);

            $state['parts'][] = [
                'PartNumber' => $state['part_number'],
                'ETag' => $part_result['CopyPartResult']['ETag'],
            ];

            $state['offset'] += $this->chunk_size;
            $state['part_number']++;

            // Save state after chunk
            update_option($state_key, $state, false);

            // If we just finished the last part, complete it immediately or let next call handle it
            if ($state['offset'] >= $state['total_size']) {
                return $this->complete_multipart($state_key, $state) ? self::COPY_OPERATION_COMPLETE : self::COPY_ERROR_APPENDING_TO_FILE;
            }

            return self::COPY_OPERATION_IN_PROGRESS;
        } catch (\Exception $e) {
            ActivityLogger::get_instance()->log_message('S3 Multipart Copy error: ' . $e->getMessage());
            throw new \Exception(esc_html($this->translate_s3_error($e)));
        }
    }

    private function complete_multipart($state_key, $state)
    {
        $log = ActivityLogger::get_instance();
        try {
            $log->log_message(sprintf('[S3 Multipart] Completing upload ID %s with %d parts (%s total)', $state['upload_id'], count($state['parts']), size_format($state['total_size'])));
            // Complete multipart upload
            $this->s3_client->completeMultipartUpload([
                'Bucket'          => $this->bucket,
                'Key'             => $state['target_key'],
                'UploadId'        => $state['upload_id'],
                'MultipartUpload' => ['Parts' => $state['parts']],
            ]);

            // Wrapup: cleanup and log
            delete_option($state_key);
            $log->log_message('[S3 Multipart] Upload complete: ' . $state['target_key']);

            // Log completion
            $log = get_option('anibas_s3_transfer_log', []);
            $log[] = [
                'source' => $state['source_key'],
                'target' => $state['target_key'],
                'size' => $state['total_size'],
                'parts' => count($state['parts']),
                'completed_at' => time(),
            ];
            update_option('anibas_s3_transfer_log', array_slice($log, -100), false);

            return true;
        } catch (\Exception $e) {
            $log->log_message('[S3 Multipart] CompleteMultipartUpload failed: ' . $e->getMessage() . ' — aborting upload.');
            // Abort on completion failure
            $this->s3_client->abortMultipartUpload([
                'Bucket'   => $this->bucket,
                'Key'      => $state['target_key'],
                'UploadId' => $state['upload_id'],
            ]);
            delete_option($state_key);
            throw new \Exception(esc_html($this->translate_s3_error($e)));
        }
    }

    public function move($source, $target)
    {
        // Use S3's native server-side CopyObject — completes in one API call for
        // objects up to 5GB (the S3 hard limit for single-call copies). This path
        // must finish synchronously because move() is invoked from request handlers
        // like restore_file_backup that can't resume across requests. For objects
        // >5GB, CopyObject throws InvalidRequest and we return false rather than
        // silently deleting the source.
        try {
            $this->s3_client->copyObject([
                'Bucket'     => $this->bucket,
                'CopySource' => $this->bucket . '/' . $this->get_key($source),
                'Key'        => $this->get_key($target),
            ]);
        } catch (\Exception $e) {
            ActivityLogger::get_instance()->log_message('S3 move copy failed: ' . $e->getMessage());
            return false;
        }
        return $this->unlink($source);
    }

    public function unlink($path)
    {
        $this->s3_client->deleteObject([
            'Bucket' => $this->bucket,
            'Key' => $this->get_key($path),
        ]);
        return true;
    }

    /**
     * Upload local file with chunked multipart upload
     */
    public function upload_file($local_path, $remote_path)
    {
        $size = filesize($local_path);
        $log  = ActivityLogger::get_instance();

        // Use simple single-part upload for files < 200MB
        if ($size < 209715200) {
            $log->log_message(sprintf('[S3 Upload] Single-part PUT: "%s" (%s) → %s', basename($local_path), size_format($size), $remote_path));
            try {
                $this->s3_client->putObject([
                    'Bucket' => $this->bucket,
                    'Key'    => $this->get_key($remote_path),
                    'Body'   => fopen($local_path, 'r'),
                ]);
                $log->log_message('[S3 Upload] Single-part PUT complete.');
            } catch (\Exception $e) {
                $log->log_message('[S3 Upload] Single-part PUT failed: ' . $e->getMessage());
                throw new \Exception(esc_html($this->translate_s3_error($e)));
            }
            return true;
        }

        return $this->multipart_upload_resumable($local_path, $remote_path, $size);
    }

    private function multipart_upload_resumable($local_path, $remote_path, $size)
    {
        $key       = $this->get_key($remote_path);
        $state_key = 'anibas_s3_multipart_' . md5($local_path . $key);
        $state     = get_option($state_key, []);
        $log       = ActivityLogger::get_instance();

        // Resume or start new
        if (empty($state['upload_id'])) {
            $log->log_message(sprintf('[S3 Multipart] Creating upload for "%s" (%s, chunk_size: %s)', basename($local_path), size_format($size), size_format($this->chunk_size)));
            $result = $this->s3_client->createMultipartUpload([
                'Bucket' => $this->bucket,
                'Key'    => $key,
            ]);
            $state = [
                'upload_id'   => $result['UploadId'],
                'parts'       => [],
                'offset'      => 0,
                'part_number' => 1,
                'total_size'  => $size,
                'local_path'  => $local_path,
                'target_key'  => $key,
            ];
            update_option($state_key, $state, false);
            $log->log_message('[S3 Multipart] Upload ID: ' . $result['UploadId']);
        }

        // Check if all parts uploaded — complete the multipart upload
        if ($state['offset'] >= $state['total_size']) {
            $log->log_message(sprintf('[S3 Multipart] All parts uploaded (%d parts). Completing multipart upload.', count($state['parts'])));
            return $this->complete_multipart($state_key, $state);
        }

        try {
            $part_num  = $state['part_number'];
            $offset    = $state['offset'];
            $remaining = $state['total_size'] - $offset;
            $read_size = min($this->chunk_size, $remaining);

            $log->log_message(sprintf('[S3 Multipart] Uploading part %d | offset: %s | chunk: %s | remaining: %s', $part_num, size_format($offset), size_format($read_size), size_format($remaining)));

            $handle = fopen($local_path, 'r');
            fseek($handle, $offset);
            $chunk = fread($handle, $read_size);
            fclose($handle);

            $part_result = $this->s3_client->uploadPart([
                'Bucket'     => $this->bucket,
                'Key'        => $key,
                'UploadId'   => $state['upload_id'],
                'PartNumber' => $part_num,
                'Body'       => $chunk,
            ]);

            $state['parts'][] = [
                'PartNumber' => $part_num,
                'ETag'       => $part_result['ETag'],
            ];

            $state['offset']      += $read_size;
            $state['part_number']++;

            // Save state after part
            update_option($state_key, $state, false);

            $log->log_message(sprintf('[S3 Multipart] Part %d OK (ETag: %s) | uploaded: %s / %s', $part_num, $part_result['ETag'], size_format($state['offset']), size_format($state['total_size'])));

            return false; // Not complete yet
        } catch (\Exception $e) {
            $log->log_message('[S3 Multipart] Part upload failed: ' . $e->getMessage());
            throw new \Exception(esc_html($this->translate_s3_error($e)));
        }
    }

    /**
     * Download to local file with chunked streaming
     */
    public function download_file($remote_path, $local_path)
    {
        $result = $this->s3_client->getObject([
            'Bucket' => $this->bucket,
            'Key' => $this->get_key($remote_path),
            '@http' => [
                'stream' => true,
                'sink' => $local_path,
            ],
        ]);
        return true;
    }

    public function download_to_local(string $remote_path, string $local_path): bool
    {
        $dir = dirname($local_path);
        if (! is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        try {
            return (bool) $this->download_file($remote_path, $local_path);
        } catch (\Throwable $e) {
            ActivityLogger::get_instance()->log_message('[S3] download_to_local failed: ' . $e->getMessage());
            return false;
        }
    }

    public function upload_from_local(string $local_path, string $remote_path): bool
    {
        try {
            $this->upload_file($local_path, $remote_path);
            return true;
        } catch (\Throwable $e) {
            ActivityLogger::get_instance()->log_message('[S3] upload_from_local failed: ' . $e->getMessage());
            return false;
        }
    }

    public function supports_chunked_transfer(): bool
    {
        return true;
    }

    /**
     * Chunked download from S3 to local file using Range header.
     */
    public function download_to_local_chunked(string $remote_path, string $local_path, int $offset = 0, int $chunk_size = 2097152): array
    {
        $chunk_size = intval(anibas_fm_get_option('chunk_size', ANIBAS_FM_DEFAULT_CHUNK_SIZE));
        if ($chunk_size < ANIBAS_FM_CHUNK_SIZE_MIN) $chunk_size = ANIBAS_FM_CHUNK_SIZE_MIN;
        if ($chunk_size > ANIBAS_FM_CHUNK_SIZE_MAX) $chunk_size = ANIBAS_FM_CHUNK_SIZE_MAX;

        try {
            // Get file size via HEAD
            $head = $this->s3_client->headObject([
                'Bucket' => $this->bucket,
                'Key'    => $this->get_key($remote_path),
            ]);
            $file_size = (int) $head['ContentLength'];

            if ($file_size <= 0) {
                return ['status' => 5, 'bytes_copied' => 0];
            }

            if ($offset >= $file_size) {
                return ['status' => 9, 'bytes_copied' => $file_size];
            }

            // Range download
            $range_end = min($offset + $chunk_size - 1, $file_size - 1);
            $result = $this->s3_client->getObject([
                'Bucket' => $this->bucket,
                'Key'    => $this->get_key($remote_path),
                'Range'  => "bytes={$offset}-{$range_end}",
            ]);

            $chunk_data = $result['Body'] ?? '';
            if (strlen($chunk_data) === 0) {
                return ['status' => 3, 'bytes_copied' => $offset];
            }

            // Write chunk to local file
            $dir = dirname($local_path);
            if (! is_dir($dir)) {
                wp_mkdir_p($dir);
            }

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
            ActivityLogger::get_instance()->log_message('[S3] download_to_local_chunked error at offset ' . $offset . ': ' . $e->getMessage());
            return ['status' => 3, 'bytes_copied' => $offset];
        }
    }

    /**
     * Chunked upload from local file to S3.
     * Files < 200MB: single-shot per chunk call (one call completes).
     * Files >= 200MB: multipart upload, one part per call (resumable via wp_options state).
     */
    public function upload_from_local_chunked(string $local_path, string $remote_path, int $offset = 0, int $chunk_size = 2097152): array
    {
        $file_size = filesize($local_path);
        if ($file_size === false) {
            return ['status' => 5, 'bytes_copied' => 0];
        }

        try {
            if ($file_size < 209715200) {
                // Small file: single PUT (completes in one call)
                if ($offset === 0) {
                    $ok = $this->upload_from_local($local_path, $remote_path);
                    return $ok
                        ? ['status' => 9, 'bytes_copied' => $file_size]
                        : ['status' => 1, 'bytes_copied' => 0];
                }
                // Already uploaded in a previous call
                return ['status' => 9, 'bytes_copied' => $file_size];
            }

            // Large file: multipart — one part per call
            $result = $this->multipart_upload_resumable($local_path, $remote_path, $file_size);

            if ($result === true) {
                return ['status' => 9, 'bytes_copied' => $file_size];
            }

            // Not complete — read state for bytes_copied
            $key       = $this->get_key($remote_path);
            $state_key = 'anibas_s3_multipart_' . md5($local_path . $key);
            $state     = get_option($state_key, []);
            $bytes     = $state['offset'] ?? 0;

            return ['status' => 10, 'bytes_copied' => $bytes];
        } catch (\Throwable $e) {
            ActivityLogger::get_instance()->log_message('[S3] upload_from_local_chunked error: ' . $e->getMessage());
            return ['status' => 4, 'bytes_copied' => $offset];
        }
    }

    private function get_key($path)
    {
        $path = ltrim($path, '/');
        if (! $this->prefix) {
            return $path;
        }
        if (strpos($path, $this->prefix . '/') === 0) {
            return $path;
        }
        return $this->prefix . '/' . $path;
    }

    public function get_file_size($path)
    {
        try {
            $result = $this->s3_client->headObject([
                'Bucket' => $this->bucket,
                'Key'    => $this->get_key($path),
            ]);
            return $result['ContentLength'];
        } catch (\Exception $e) {
            return false;
        }
    }

    public function get_contents($path)
    {
        try {
            $obj = $this->s3_client->getObject([
                'Bucket' => $this->bucket,
                'Key'    => $this->get_key($path),
            ]);
            return (string) ($obj['Body'] ?? '');
        } catch (\Exception $e) {
            return false;
        }
    }


    public function get_temporary_link($path, $duration = 3600)
    {
        try {
            return $this->s3_client->getPresignedUrl($this->bucket, $this->get_key($path), $duration);
        } catch (\Exception $e) {
            return false;
        }
    }

    public function put_contents($path, $content)
    {
        try {
            $this->s3_client->putObject([
                'Bucket' => $this->bucket,
                'Key' => $this->get_key($path),
                'Body' => $content,
            ]);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function append_contents($path, $content)
    {
        // S3 doesn't support byte-range appends natively.
        // Strategy: download existing content, concatenate, re-upload.
        // This is only practical for small files (e.g. manifest/log writes).
        // For large assembly workflows, use multipart upload directly.
        try {
            $key      = $this->get_key($path);
            $existing = '';

            try {
                $obj      = $this->s3_client->getObject(['Bucket' => $this->bucket, 'Key' => $key]);
                $existing = (string) ($obj['Body'] ?? '');
            } catch (\Exception $e) {
                // Object doesn't exist yet — start fresh
            }

            $this->s3_client->putObject([
                'Bucket' => $this->bucket,
                'Key'    => $key,
                'Body'   => $existing . $content,
            ]);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
