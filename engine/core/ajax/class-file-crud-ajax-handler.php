<?php

namespace Anibas;

if (! defined('ABSPATH')) exit;

/**
 * AJAX endpoints for filesystem CRUD: list, create folder/file, delete,
 * empty folder, rename, get details, download and preview.
 */
class FileCrudAjaxHandler extends AjaxHandler
{
    public function __construct()
    {
        parent::__construct();
        $this->register_actions([
            ANIBAS_FM_GET_FILE_LIST    => 'get_file_list',
            ANIBAS_FM_CREATE_FOLDER    => 'create_folder',
            ANIBAS_FM_CREATE_FILE      => 'create_file',
            ANIBAS_FM_DELETE_FILE      => 'delete_file',
            ANIBAS_FM_EMPTY_FOLDER     => 'empty_folder',
            ANIBAS_FM_RENAME_FILE      => 'rename_file',
            ANIBAS_FM_GET_FILE_DETAILS => 'get_file_details',
            ANIBAS_FM_DOWNLOAD_FILE    => 'download_file',
            ANIBAS_FM_PREVIEW_FILE     => 'preview_file',
        ]);
    }

    public function get_file_list()
    {
        $this->check_privilege();

        $dir = anibas_fm_fetch_request_variable('get', 'dir', '/');
        $page = intval(anibas_fm_fetch_request_variable('get', 'page', 1));
        $storage = anibas_fm_fetch_request_variable('get', 'storage', 'local');

        if ($storage !== 'local') {
            $adapter = $this->get_storage_adapter($storage);
            if (! $adapter) {
                wp_send_json_error(array('error' => esc_html__('Invalid storage', 'anibas-file-manager')));
            }

            try {
                $result = $adapter->listDirectory($dir);
                wp_send_json_success(array(
                    'path' => $dir,
                    'page' => $page,
                    'page_size' => ANIBAS_FILE_MANAGER_DEFAULT_FILE_SIZE,
                    'total_items' => $result['total_items'],
                    'has_more' => false,
                    'items' => $result['items']
                ));
            } catch (\Exception $e) {
                wp_send_json_error(array('error' => esc_html($e->getMessage())));
            }
        } else {
            if ($path = $this->validate_path($dir)) {
                $fm = new LocalFileSystemAdapter();
                wp_send_json_success($fm->listDirectory($path, $page, ANIBAS_FILE_MANAGER_DEFAULT_FILE_SIZE));
            } else {
                wp_send_json_error(array('error' => 'PathInvalid', 'message' => esc_html__('Path does not exist', 'anibas-file-manager')));
            }
        }
    }

    public function create_folder()
    {
        $this->check_create_privilege();

        $user_id = get_current_user_id();
        $lock_key = 'anibas_fm_create_lock_' . $user_id;
        $retry_key = 'anibas_fm_create_retry_' . $user_id;

        if (get_transient($lock_key)) {
            wp_send_json_error(array('error' => esc_html__('Please wait before creating another folder', 'anibas-file-manager')));
        }

        $retry_count = get_transient($retry_key) ?: 0;

        if ($retry_count > 0) {
            ActivityLogger::log_retry_attempt('create_folder', $retry_count + 1);
        }

        if ($retry_count >= 3) {
            ActivityLogger::log_retry_timeout('create_folder', $retry_count + 1);
            delete_transient($retry_key);
            wp_send_json_error(array('error' => esc_html__('Folder creation failed after 3 attempts. Please try again later.', 'anibas-file-manager')));
        }

        set_transient($lock_key, true, ANIBAS_FM_LOCK_DURATION);
        set_transient($retry_key, $retry_count + 1, 35);

        $parent = anibas_fm_fetch_request_variable('post', 'parent', '/');
        $name = anibas_fm_fetch_request_variable('post', 'name', '');
        $storage = anibas_fm_fetch_request_variable('post', 'storage', 'local');

        if (empty($name)) {
            delete_transient($lock_key);
            delete_transient($retry_key);
            wp_send_json_error(array('error' => esc_html__('Folder name required', 'anibas-file-manager')));
        }

        if (strpos($name, '..') !== false || strpos($name, '/') !== false || strpos($name, '\\') !== false) {
            delete_transient($lock_key);
            delete_transient($retry_key);
            wp_send_json_error(array('error' => esc_html__('Invalid folder name', 'anibas-file-manager')));
        }

        sleep(ANIBAS_FM_OPERATION_DELAY);

        if ($storage !== 'local') {
            $adapter = $this->get_storage_adapter($storage);
            if (! $adapter) {
                delete_transient($lock_key);
                delete_transient($retry_key);
                wp_send_json_error(array('error' => esc_html__('Invalid storage', 'anibas-file-manager')));
            }

            try {
                $path = rtrim($parent, '/') . '/' . $name;
                $ok   = $adapter->mkdir($path);
                delete_transient($lock_key);
                delete_transient($retry_key);
                if ($ok === false) {
                    wp_send_json_error(array('error' => esc_html__('Failed to create folder on remote storage', 'anibas-file-manager')));
                }
                wp_send_json_success(array('message' => esc_html__('Folder created successfully', 'anibas-file-manager')));
            } catch (\Exception $e) {
                delete_transient($lock_key);
                delete_transient($retry_key);
                wp_send_json_error(array('error' => esc_html($e->getMessage())));
            }
        }
        $parent_path = $this->validate_path($parent);
        if (! $parent_path) {
            delete_transient($lock_key);
            wp_send_json_error(array('error' => 'PathInvalid', 'message' => esc_html__('Invalid parent path', 'anibas-file-manager')));
        }

        $new_folder_path = $parent_path . DIRECTORY_SEPARATOR . $name;

        if (file_exists($new_folder_path)) {
            delete_transient($lock_key);
            delete_transient($retry_key);
            wp_send_json_error(array('error' => esc_html__('Folder already exists', 'anibas-file-manager')));
        }

        // Validate the constructed path would be safe (simulate with parent check)
        $simulated_parent = dirname($new_folder_path);
        if ($simulated_parent !== $parent_path) {
            delete_transient($lock_key);
            delete_transient($retry_key);
            wp_send_json_error(array('error' => esc_html__('Invalid folder path', 'anibas-file-manager')));
        }

        sleep(ANIBAS_FM_OPERATION_DELAY);

        $fm = new LocalFileSystemAdapter();
        if ($fm->createFolder($new_folder_path)) {
            delete_transient($lock_key);
            delete_transient($retry_key);
            wp_send_json_success(array('message' => esc_html__('Folder created successfully', 'anibas-file-manager')));
        } else {
            delete_transient($lock_key);
            delete_transient($retry_key);
            wp_send_json_error(array('error' => esc_html__('Failed to create folder', 'anibas-file-manager')));
        }
    }

    public function create_file()
    {
        $this->check_create_privilege();

        $user_id = get_current_user_id();
        $lock_key = 'anibas_fm_create_file_lock_' . $user_id;
        $retry_key = 'anibas_fm_create_file_retry_' . $user_id;

        if (get_transient($lock_key)) {
            wp_send_json_error(array('error' => esc_html__('Please wait before creating another file', 'anibas-file-manager')));
        }

        $retry_count = get_transient($retry_key) ?: 0;

        if ($retry_count > 0) {
            ActivityLogger::log_retry_attempt('create_file', $retry_count + 1);
        }

        if ($retry_count >= 3) {
            ActivityLogger::log_retry_timeout('create_file', $retry_count + 1);
            delete_transient($retry_key);
            wp_send_json_error(array('error' => esc_html__('File creation failed after 3 attempts. Please try again later.', 'anibas-file-manager')));
        }

        set_transient($lock_key, true, ANIBAS_FM_LOCK_DURATION);
        set_transient($retry_key, $retry_count + 1, 35);

        $parent = anibas_fm_fetch_request_variable('post', 'parent', '/');
        $name = anibas_fm_fetch_request_variable('post', 'name', '');
        $content = isset($_POST['content']) ? wp_unslash($_POST['content']) : '';
        $storage = anibas_fm_fetch_request_variable('post', 'storage', 'local');

        if (empty($name)) {
            delete_transient($lock_key);
            delete_transient($retry_key);
            wp_send_json_error(array('error' => esc_html__('File name required', 'anibas-file-manager')));
        }

        if (strpos($name, '..') !== false || strpos($name, '/') !== false || strpos($name, '\\') !== false) {
            delete_transient($lock_key);
            delete_transient($retry_key);
            wp_send_json_error(array('error' => esc_html__('Invalid file name', 'anibas-file-manager')));
        }

        $max_size = min(wp_max_upload_size(), 1048576); // 1MB or WP max, whichever is less
        if (strlen($content) > $max_size) {
            delete_transient($lock_key);
            delete_transient($retry_key);
            wp_send_json_error(array(/* translators: %s: formatted file size */
                'error' => sprintf(esc_html__('Content exceeds maximum size of %s', 'anibas-file-manager'), size_format($max_size))));
        }

        sleep(ANIBAS_FM_OPERATION_DELAY);

        if ($storage !== 'local') {
            $adapter = $this->get_storage_adapter($storage);
            if (! $adapter) {
                delete_transient($lock_key);
                wp_send_json_error(array('error' => esc_html__('Invalid storage', 'anibas-file-manager')));
            }

            try {
                $path = rtrim($parent, '/') . '/' . $name;
                $ok   = $adapter->put_contents($path, $content);
                delete_transient($lock_key);
                delete_transient($retry_key);
                if ($ok === false) {
                    wp_send_json_error(array('error' => esc_html__('Failed to create file on remote storage', 'anibas-file-manager')));
                }
                wp_send_json_success(array('message' => esc_html__('File created successfully', 'anibas-file-manager')));
            } catch (\Exception $e) {
                delete_transient($lock_key);
                delete_transient($retry_key);
                wp_send_json_error(array('error' => esc_html($e->getMessage())));
            }
        }
        $parent_path = $this->validate_path($parent);
        if (! $parent_path) {
            delete_transient($lock_key);
            wp_send_json_error(array('error' => 'PathInvalid', 'message' => esc_html__('Invalid parent path', 'anibas-file-manager')));
        }

        $new_file_path = $parent_path . DIRECTORY_SEPARATOR . $name;

        if (file_exists($new_file_path)) {
            delete_transient($lock_key);
            delete_transient($retry_key);
            wp_send_json_error(array('error' => esc_html__('File already exists', 'anibas-file-manager')));
        }

        if (! function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        WP_Filesystem();
        global $wp_filesystem;

        if ($wp_filesystem->put_contents($new_file_path, $content, FS_CHMOD_FILE)) {
            delete_transient($lock_key);
            delete_transient($retry_key);
            wp_send_json_success(array('message' => esc_html__('File created successfully', 'anibas-file-manager')));
        } else {
            delete_transient($lock_key);
            delete_transient($retry_key);
            wp_send_json_error(array('error' => esc_html__('Failed to create file', 'anibas-file-manager')));
        }
    }

    public function delete_file()
    {
        $this->check_delete_privilege();

        $user_id  = get_current_user_id();
        $lock_key = 'anibas_fm_delete_lock_' . $user_id;

        if (get_transient($lock_key)) {
            wp_send_json_error(array('error' => esc_html__('Please wait before deleting another item', 'anibas-file-manager')));
        }
        set_transient($lock_key, true, ANIBAS_FM_LOCK_DURATION);

        $path         = anibas_fm_fetch_request_variable('post', 'path', '');
        $token        = anibas_fm_fetch_request_variable('post', 'token', '');
        $delete_token = anibas_fm_fetch_request_variable('post', 'delete_token', '');
        $storage      = anibas_fm_fetch_request_variable('post', 'storage', 'local');

        if (empty($path)) {
            delete_transient($lock_key);
            wp_send_json_error(array('error' => esc_html__('Path required', 'anibas-file-manager')));
        }

        $stored_delete_token = get_transient('anibas_fm_delete_token_' . $user_id . '_' . md5($path));
        if (! $delete_token || ! $stored_delete_token || ! hash_equals($stored_delete_token, $delete_token)) {
            delete_transient($lock_key);
            wp_send_json_error(array('error' => 'DeleteTokenExpired', 'message' => esc_html__('Delete confirmation expired. Please try again.', 'anibas-file-manager')));
        }

        $delete_password_hash = anibas_fm_get_option('delete_password_hash', '');
        if (! empty($delete_password_hash)) {
            $stored_token = get_transient('anibas_fm_delete_auth_' . $user_id);
            if (! $token || ! $stored_token || ! hash_equals($stored_token, $token)) {
                delete_transient($lock_key);
                wp_send_json_error(array('error' => 'DeletePasswordRequired'));
            }
        }

        delete_transient('anibas_fm_delete_token_' . $user_id . '_' . md5($path));

        $adapter = $this->get_storage_adapter($storage);
        if (! $adapter) {
            delete_transient($lock_key);
            wp_send_json_error(array('error' => esc_html__('Invalid storage', 'anibas-file-manager')));
        }

        $full_path = $adapter->validate_path($path);
        if (! $full_path) {
            delete_transient($lock_key);
            wp_send_json_error(array('error' => 'PathInvalid', 'message' => esc_html__('Invalid path', 'anibas-file-manager')));
        }

        $result = $adapter->delete($full_path);
        delete_transient($lock_key);

        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'error'   => $result->get_error_code(),
                'message' => $result->get_error_message(),
            ));
        }
        if (is_array($result) && isset($result['job_id'])) {
            wp_send_json_success(array('job_id' => $result['job_id']));
        }
        wp_send_json_success(array(
            'message' => anibas_fm_trash_enabled() && $storage === 'local'
                ? esc_html__('Moved to trash', 'anibas-file-manager')
                : esc_html__('Deleted successfully', 'anibas-file-manager'),
        ));
    }

    public function empty_folder()
    {
        $this->check_delete_privilege();

        $path    = anibas_fm_fetch_request_variable('post', 'path', '');
        $token   = anibas_fm_fetch_request_variable('post', 'token', '');
        $storage = anibas_fm_fetch_request_variable('post', 'storage', 'local');

        if (empty($path)) {
            wp_send_json_error(array('error' => esc_html__('Path required', 'anibas-file-manager')));
        }

        // Enforce delete password if configured
        $user_id = get_current_user_id();
        $delete_password_hash = anibas_fm_get_option('delete_password_hash', '');
        if (! empty($delete_password_hash)) {
            $stored_token = get_transient('anibas_fm_delete_auth_' . $user_id);
            if (! $token || ! $stored_token || ! hash_equals($stored_token, $token)) {
                wp_send_json_error(array('error' => 'DeletePasswordRequired'));
            }
        }

        if ($storage !== 'local') {
            wp_send_json_error(array('error' => esc_html__('Empty folder is only supported for local storage', 'anibas-file-manager')));
        }

        $full_path = $this->validate_path($path);
        if (! $full_path || ! is_dir($full_path)) {
            wp_send_json_error(array('error' => 'PathInvalid', 'message' => esc_html__('Invalid folder path', 'anibas-file-manager')));
        }

        $fm     = new LocalFileSystemAdapter();
        $result = $fm->emptyFolder($full_path);

        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'error'   => $result->get_error_code(),
                'message' => $result->get_error_message(),
            ));
        }

        wp_send_json_success(array(
            'message' => anibas_fm_trash_enabled()
                ? esc_html__('Folder contents moved to trash', 'anibas-file-manager')
                : esc_html__('Folder emptied successfully', 'anibas-file-manager'),
        ));
    }

    /* =========================================================
       RENAME FILE / FOLDER
    ========================================================= */

    public function rename_file(): void
    {
        $this->check_create_privilege();

        $path     = anibas_fm_fetch_request_variable('post', 'path', '');
        $new_name = anibas_fm_fetch_request_variable('post', 'new_name', '');
        $storage  = anibas_fm_fetch_request_variable('post', 'storage', 'local');

        if (empty($path) || empty($new_name)) {
            wp_send_json_error(array('error' => 'MissingParams', 'message' => esc_html__('Path and new name are required', 'anibas-file-manager')));
        }

        // Reject path separators and null bytes in the new name
        if (preg_match('/[\/\\\\]/', $new_name) || strpos($new_name, "\0") !== false) {
            wp_send_json_error(array('error' => 'InvalidName', 'message' => esc_html__('Name cannot contain path separators', 'anibas-file-manager')));
        }

        if ($storage === 'local') {
            $full_path = $this->validate_path($path);
            if (! $full_path || ! file_exists($full_path)) {
                wp_send_json_error(array('error' => 'PathInvalid', 'message' => esc_html__('Invalid path', 'anibas-file-manager')));
            }
            $new_full_path = dirname($full_path) . DIRECTORY_SEPARATOR . $new_name;
            if (file_exists($new_full_path)) {
                wp_send_json_error(array('error' => 'AlreadyExists', /* translators: %s: file or folder name */
                'message' => sprintf(esc_html__('\'%s\' already exists in this location', 'anibas-file-manager'), esc_html($new_name))));
            }
            if (! @rename($full_path, $new_full_path)) {
                wp_send_json_error(array('error' => 'RenameFailed', 'message' => esc_html__('Rename failed. Check file permissions.', 'anibas-file-manager')));
            }
        } else {
            $adapter   = StorageManager::get_instance()->get_adapter($storage);
            $full_path = $adapter->validate_path($path);
            if (! $full_path || (! $adapter->is_file($full_path) && ! $adapter->is_dir($full_path))) {
                wp_send_json_error(array('error' => 'PathInvalid', 'message' => esc_html__('Invalid path', 'anibas-file-manager')));
            }
            $dir           = rtrim(dirname($full_path), '/');
            $new_full_path = $dir . '/' . $new_name;
            if ($adapter->exists($new_full_path)) {
                wp_send_json_error(array('error' => 'AlreadyExists', /* translators: %s: file or folder name */
                'message' => sprintf(esc_html__('\'%s\' already exists in this location', 'anibas-file-manager'), esc_html($new_name))));
            }

            // Route remote rename through BackgroundProcessor (S3 rename = copy + delete,
            // which can be slow for large files/folders and would otherwise time out).
            $job_id = BackgroundProcessor::enqueue_job($full_path, $new_full_path, 'move', 'overwrite', $storage, true);
            if (is_wp_error($job_id)) {
                wp_send_json_error(array(
                    'error'   => 'RenameFailed',
                    'message' => $job_id->get_error_message(),
                ));
            }
            wp_send_json_success(array(
                'job_id'   => $job_id,
                /* translators: %s: new name */
                'message'  => sprintf(esc_html__('Rename job started for \'%s\'', 'anibas-file-manager'), esc_html($new_name)),
                'new_name' => $new_name,
            ));
            return;
        }

        wp_send_json_success(array(/* translators: %s: new name */
            'message' => sprintf(esc_html__('Renamed to \'%s\' successfully', 'anibas-file-manager'), esc_html($new_name)), 'new_name' => $new_name));
    }

    /* =========================================================
       DOWNLOAD FILE — streams a file to the browser
    ========================================================= */

    public function download_file(): void
    {
        $this->check_privilege();

        $path    = anibas_fm_fetch_request_variable('get', 'path', '');
        $storage = anibas_fm_fetch_request_variable('get', 'storage', 'local');

        if (empty($path)) {
            wp_die(esc_html__('File path is required', 'anibas-file-manager'), esc_html__('Error', 'anibas-file-manager'), array('response' => 400));
        }

        if ($storage === 'local') {
            $full_path = $this->validate_path($path);
            if (! $full_path || ! is_file($full_path)) {
                wp_die(esc_html__('File not found', 'anibas-file-manager'), esc_html__('Error', 'anibas-file-manager'), array('response' => 404));
            }
            $filename = basename($full_path);
            $filename = preg_replace('/[\r\n"\\\\]/', '', $filename);
            $filesize = filesize($full_path);
            $mime     = mime_content_type($full_path) ?: 'application/octet-stream';

            if (ob_get_level()) ob_end_clean();
            header('Content-Type: ' . $mime);
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . $filesize);
            header('Cache-Control: no-cache, must-revalidate');
            readfile($full_path);
            exit;
        } else {
            $adapter   = StorageManager::get_instance()->get_adapter($storage);
            $full_path = $adapter->validate_path($path);
            if (! $full_path || ! $adapter->is_file($full_path)) {
                if ($adapter->is_dir($full_path)) {
                    wp_die(esc_html__('Cannot download directories directly. Please use zip download.', 'anibas-file-manager'), esc_html__('Error', 'anibas-file-manager'), array('response' => 400));
                }
                wp_die(esc_html__('File not found', 'anibas-file-manager'), esc_html__('Error', 'anibas-file-manager'), array('response' => 404));
            }

            // Attempt to get a temporary download link (e.g. S3 presigned URL)
            $temp_link = $adapter->get_temporary_link($full_path, 3600);
            if ($temp_link) {
                wp_redirect($temp_link);
                exit;
            }

            // Fallback to streaming for FTP/SFTP or if no link could be generated
            $download_name = preg_replace('/[\r\n"\\\\]/', '', basename($full_path));
            header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $download_name . '"');

            if (method_exists($adapter, 'get_size')) {
                $remote_size = $adapter->get_size($full_path);
                if ($remote_size !== false && $remote_size >= 0) {
                    header('Content-Length: ' . $remote_size);
                }
            }
            header('Expires: 0');
            header('Cache-Control: no-cache, must-revalidate');
            header('Pragma: public');

            // Clear any existing output buffers to prevent accumulating chunks in memory
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            $success = $adapter->stream_contents($full_path);

            if (!$success) {
                wp_die(esc_html__('Failed to read file from remote storage.', 'anibas-file-manager'), esc_html__('Error', 'anibas-file-manager'), array('response' => 500));
            }
            exit;
        }
    }

    /* =========================================================
       PREVIEW FILE — extracts a chunk for previewing
    ========================================================= */

    public function preview_file(): void
    {
        $this->check_privilege();

        $path    = anibas_fm_fetch_request_variable('get', 'path', '');
        $storage = anibas_fm_fetch_request_variable('get', 'storage', 'local');
        $limit   = 102400; // 100KB

        if (empty($path)) {
            wp_send_json_error(array('error' => 'MissingParams', 'message' => esc_html__('Path is required', 'anibas-file-manager')));
        }

        if ($storage === 'local') {
            $full_path = $this->validate_path($path);
            if (! $full_path || ! is_file($full_path)) {
                wp_send_json_error(array('error' => 'NotFound', 'message' => esc_html__('File not found', 'anibas-file-manager')));
            }
            $content = file_get_contents($full_path, false, null, 0, $limit);
            wp_send_json_success(array('content' => $content));
        } else {
            $adapter   = StorageManager::get_instance()->get_adapter($storage);
            $full_path = $adapter->validate_path($path);
            if (! $full_path || ! $adapter->is_file($full_path)) {
                wp_send_json_error(array('error' => 'NotFound', 'message' => esc_html__('File not found', 'anibas-file-manager')));
            }

            // Refuse preview for files larger than the limit — fetching the whole
            // file into memory is unsafe for large files on remote storage.
            $file_size = method_exists($adapter, 'get_file_size') ? $adapter->get_file_size($full_path) : false;
            if ($file_size !== false && $file_size > $limit) {
                wp_send_json_error(array('error' => 'FileTooLarge', 'message' => esc_html__('File is too large to preview', 'anibas-file-manager')));
            }

            $content = $adapter->get_contents($full_path);
            if ($content !== false) {
                wp_send_json_success(array('content' => $content));
            } else {
                wp_send_json_error(array('error' => 'ReadFailed', 'message' => esc_html__('Failed to read from remote storage', 'anibas-file-manager')));
            }
        }
    }

    /**
     * Return extended metadata for a single file or folder.
     */
    public function get_file_details(): void
    {
        $this->check_privilege();

        $path    = anibas_fm_fetch_request_variable('get', 'path', '');
        $storage = anibas_fm_fetch_request_variable('get', 'storage', 'local');

        if (empty($path)) {
            wp_send_json_error(array('error' => 'MissingParams', 'message' => esc_html__('Path is required', 'anibas-file-manager')));
        }

        try {
            $adapter   = StorageManager::get_instance()->get_adapter($storage);
            $full_path = $adapter->validate_path($path);

            if (! $full_path) {
                wp_send_json_error(array('error' => 'NotFound', 'message' => esc_html__('File or folder not found', 'anibas-file-manager')));
            }

            $details = $adapter->getDetails($full_path);
            if ($details === false) {
                wp_send_json_error(array('error' => 'NotFound', 'message' => esc_html__('Could not fetch details', 'anibas-file-manager')));
            }

            wp_send_json_success(array('details' => $details));
        } catch (\Throwable $e) {
            wp_send_json_error(array('error' => 'Exception', 'message' => esc_html($e->getMessage())));
        }
    }
}
