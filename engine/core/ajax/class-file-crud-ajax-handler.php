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
        $dir = anibas_fm_fetch_request_variable('get', 'dir', '/');
        $page = intval(anibas_fm_fetch_request_variable('get', 'page', 1));
        $storage = anibas_fm_fetch_request_variable('get', 'storage', 'local');
        $page_size = min(500, max(1, (int) ANIBAS_FILE_MANAGER_DEFAULT_FILELIST_PAGE_SIZE));
        $this->check_file_list_privilege($storage);

        if ($storage !== 'local') {
            $adapter = $this->get_storage_adapter($storage);
            if (! $adapter) {
                $this->send_error(array('error' => esc_html__('Invalid storage', 'anibas-file-manager')));
            }

            try {
                $result = $adapter->listDirectory($dir, $page, $page_size);
                $items = $this->decorate_file_items($result['items'] ?? []);
                $this->send_success(array(
                    'path' => $dir,
                    'page' => $result['page'] ?? $page,
                    'page_size' => $result['page_size'] ?? $page_size,
                    'total_items' => $result['total_items'] ?? count($items),
                    'has_more' => ! empty($result['has_more']),
                    'items' => $items
                ));
            } catch (\Exception $e) {
                $this->send_error(array('error' => esc_html($e->getMessage())));
            }
        } else {
            if ($path = $this->validate_path($dir)) {
                $fm = new LocalFileSystemAdapter();
                $result = $fm->listDirectory($path, $page, $page_size);
                $result['items'] = $this->decorate_file_items($result['items'] ?? []);
                $this->send_success($result);
            } else {
                $this->send_error(array('error' => 'PathInvalid', 'message' => esc_html__('Path does not exist', 'anibas-file-manager')));
            }
        }
    }

    private function check_file_list_privilege($storage): void
    {
        $nonce = anibas_fm_fetch_request_variable('request', 'nonce');

        if ($storage === 'local' && wp_verify_nonce($nonce, ANIBAS_FM_NONCE_SETTINGS)) {
            $this->check_admin_privilege();
            $this->check_settings_auth();
            return;
        }

        $this->check_privilege();
    }

    public function create_folder()
    {
        $this->check_create_privilege();

        $user_id = get_current_user_id();
        $lock_key = 'anibas_fm_create_lock_' . $user_id;
        $retry_key = 'anibas_fm_create_retry_' . $user_id;

        if (get_transient($lock_key)) {
            $this->send_error(array('error' => esc_html__('Please wait before creating another folder', 'anibas-file-manager')));
        }

        $retry_count = get_transient($retry_key) ?: 0;

        if ($retry_count > 0) {
            ActivityLogger::log_retry_attempt('create_folder', $retry_count + 1);
        }

        if ($retry_count >= 3) {
            ActivityLogger::log_retry_timeout('create_folder', $retry_count + 1);
            $this->send_error(array('error' => esc_html__('Folder creation failed after 3 attempts. Please try again later.', 'anibas-file-manager')), null, [$retry_key]);
        }

        set_transient($lock_key, true, ANIBAS_FM_LOCK_DURATION);
        set_transient($retry_key, $retry_count + 1, 35);

        $parent = anibas_fm_fetch_request_variable('post', 'parent', '/');
        $name = anibas_fm_fetch_request_variable('post', 'name', '');
        $storage = anibas_fm_fetch_request_variable('post', 'storage', 'local');

        if (empty($name)) {
            $this->send_error(array('error' => esc_html__('Folder name required', 'anibas-file-manager')), null, [$lock_key, $retry_key]);
        }

        if (strpos($name, '..') !== false || strpos($name, '/') !== false || strpos($name, '\\') !== false) {
            $this->send_error(array('error' => esc_html__('Invalid folder name', 'anibas-file-manager')), null, [$lock_key, $retry_key]);
        }

        sleep(ANIBAS_FM_OPERATION_DELAY);

        if ($storage !== 'local') {
            $adapter = $this->get_storage_adapter($storage, [$lock_key, $retry_key]);
            if (! $adapter) {
                $this->send_error(array('error' => esc_html__('Invalid storage', 'anibas-file-manager')), null, [$lock_key, $retry_key]);
            }

            try {
                $path = rtrim($parent, '/') . '/' . $name;
                $ok   = $adapter->mkdir($path);
                if ($ok === false) {
                    $this->send_error(array('error' => esc_html__('Failed to create folder on remote storage', 'anibas-file-manager')), null, [$lock_key, $retry_key]);
                }
                $this->send_success(array('message' => esc_html__('Folder created successfully', 'anibas-file-manager')), null, [$lock_key, $retry_key]);
            } catch (\Exception $e) {
                $this->send_error(array('error' => esc_html($e->getMessage())), null, [$lock_key, $retry_key]);
            }
        }
        $parent_path = $this->validate_path($parent);
        if (! $parent_path) {
            $this->send_error(array('error' => 'PathInvalid', 'message' => esc_html__('Invalid parent path', 'anibas-file-manager')), null, [$lock_key, $retry_key]);
        }

        $new_folder_path = $parent_path . DIRECTORY_SEPARATOR . $name;

        if (file_exists($new_folder_path)) {
            $this->send_error(array('error' => esc_html__('Folder already exists', 'anibas-file-manager')), null, [$lock_key, $retry_key]);
        }

        $simulated_parent = dirname($new_folder_path);
        if ($simulated_parent !== $parent_path) {
            $this->send_error(array('error' => esc_html__('Invalid folder path', 'anibas-file-manager')), null, [$lock_key, $retry_key]);
        }

        $fm = new LocalFileSystemAdapter();
        if ($fm->createFolder($new_folder_path)) {
            $this->send_success(array('message' => esc_html__('Folder created successfully', 'anibas-file-manager')), null, [$lock_key, $retry_key]);
        } else {
            $this->send_error(array('error' => esc_html__('Failed to create folder', 'anibas-file-manager')), null, [$lock_key, $retry_key]);
        }
    }

    public function create_file()
    {
        $this->check_create_privilege();

        $user_id = get_current_user_id();
        $lock_key = 'anibas_fm_create_file_lock_' . $user_id;
        $retry_key = 'anibas_fm_create_file_retry_' . $user_id;

        if (get_transient($lock_key)) {
            $this->send_error(array('error' => esc_html__('Please wait before creating another file', 'anibas-file-manager')));
        }

        $retry_count = get_transient($retry_key) ?: 0;

        if ($retry_count > 0) {
            ActivityLogger::log_retry_attempt('create_file', $retry_count + 1);
        }

        if ($retry_count >= 3) {
            ActivityLogger::log_retry_timeout('create_file', $retry_count + 1);
            $this->send_error(array('error' => esc_html__('File creation failed after 3 attempts. Please try again later.', 'anibas-file-manager')), null, [$retry_key]);
        }

        set_transient($lock_key, true, ANIBAS_FM_LOCK_DURATION);
        set_transient($retry_key, $retry_count + 1, 35);

        $parent = anibas_fm_fetch_request_variable('post', 'parent', '/');
        $name = anibas_fm_fetch_request_variable('post', 'name', '');
        $content = isset($_POST['content']) ? wp_unslash($_POST['content']) : '';
        $storage = anibas_fm_fetch_request_variable('post', 'storage', 'local');

        if (empty($name)) {
            $this->send_error(array('error' => esc_html__('File name required', 'anibas-file-manager')), null, [$lock_key, $retry_key]);
        }

        if (strpos($name, '..') !== false || strpos($name, '/') !== false || strpos($name, '\\') !== false) {
            $this->send_error(array('error' => esc_html__('Invalid file name', 'anibas-file-manager')), null, [$lock_key, $retry_key]);
        }

        $max_size = min(wp_max_upload_size(), 1048576); // 1MB or WP max, whichever is less
        if (strlen($content) > $max_size) {
            $this->send_error(array(/* translators: %s: formatted file size */
                'error' => sprintf(esc_html__('Content exceeds maximum size of %s', 'anibas-file-manager'), size_format($max_size))), null, [$lock_key, $retry_key]);
        }

        sleep(ANIBAS_FM_OPERATION_DELAY);

        if ($storage !== 'local') {
            $adapter = $this->get_storage_adapter($storage, [$lock_key, $retry_key]);
            if (! $adapter) {
                $this->send_error(array('error' => esc_html__('Invalid storage', 'anibas-file-manager')), null, [$lock_key, $retry_key]);
            }

            try {
                $path = rtrim($parent, '/') . '/' . $name;
                $ok   = $adapter->put_contents($path, $content);
                if ($ok === false) {
                    $this->send_error(array('error' => esc_html__('Failed to create file on remote storage', 'anibas-file-manager')), null, [$lock_key, $retry_key]);
                }
                $this->send_success(array('message' => esc_html__('File created successfully', 'anibas-file-manager')), null, [$lock_key, $retry_key]);
            } catch (\Exception $e) {
                $this->send_error(array('error' => esc_html($e->getMessage())), null, [$lock_key, $retry_key]);
            }
        }
        $parent_path = $this->validate_path($parent);
        if (! $parent_path) {
            $this->send_error(array('error' => 'PathInvalid', 'message' => esc_html__('Invalid parent path', 'anibas-file-manager')), null, [$lock_key, $retry_key]);
        }

        $new_file_path = $parent_path . DIRECTORY_SEPARATOR . $name;

        if (file_exists($new_file_path)) {
            $this->send_error(array('error' => esc_html__('File already exists', 'anibas-file-manager')), null, [$lock_key, $retry_key]);
        }

        if (! function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        WP_Filesystem();
        global $wp_filesystem;

        if ($wp_filesystem->put_contents($new_file_path, $content, FS_CHMOD_FILE)) {
            $this->send_success(array('message' => esc_html__('File created successfully', 'anibas-file-manager')), null, [$lock_key, $retry_key]);
        } else {
            $this->send_error(array('error' => esc_html__('Failed to create file', 'anibas-file-manager')), null, [$lock_key, $retry_key]);
        }
    }

    public function delete_file()
    {
        $this->check_delete_privilege();

        $user_id  = get_current_user_id();
        $lock_key = 'anibas_fm_delete_lock_' . $user_id;

        if (get_transient($lock_key)) {
            $this->send_error(array('error' => esc_html__('Please wait before deleting another item', 'anibas-file-manager')));
        }
        set_transient($lock_key, true, ANIBAS_FM_LOCK_DURATION);

        $path         = anibas_fm_fetch_request_variable('post', 'path', '');
        $token        = anibas_fm_fetch_request_variable('post', 'token', '');
        $delete_token = anibas_fm_fetch_request_variable('post', 'delete_token', '');
        $storage      = sanitize_text_field(anibas_fm_fetch_request_variable('post', 'storage', 'local'));
        if ($storage === '') {
            $storage = 'local';
        }

        if (empty($path)) {
            $this->send_error(array('error' => esc_html__('Path required', 'anibas-file-manager')), null, [$lock_key]);
        }

        $delete_token_key = 'anibas_fm_delete_token_' . $user_id . '_' . md5($storage . '|' . $path);
        $stored_delete_token = get_transient($delete_token_key);
        if (! $delete_token || ! $stored_delete_token || ! hash_equals($stored_delete_token, $delete_token)) {
            $this->send_error(array('error' => 'DeleteTokenExpired', 'message' => esc_html__('Delete confirmation expired. Please try again.', 'anibas-file-manager')), null, [$lock_key]);
        }

        $delete_password_hash = anibas_fm_get_option('delete_password_hash', '');
        if (! empty($delete_password_hash)) {
            $stored_token = get_transient('anibas_fm_delete_auth_' . $user_id);
            if (! $token || ! $stored_token || ! hash_equals($stored_token, $token)) {
                $this->send_error(array('error' => 'DeletePasswordRequired'), null, [$lock_key]);
            }
        }

        delete_transient($delete_token_key);

        $adapter = $this->get_storage_adapter($storage, [$lock_key]);
        if (! $adapter) {
            $this->send_error(array('error' => esc_html__('Invalid storage', 'anibas-file-manager')), null, [$lock_key]);
        }

        $full_path = $adapter->validate_path($path);
        if (! $full_path) {
            $this->send_error(array('error' => 'PathInvalid', 'message' => esc_html__('Invalid path', 'anibas-file-manager')), null, [$lock_key]);
        }

        $result = $adapter->delete($full_path);

        if (is_wp_error($result)) {
            $this->send_wp_error($result, null, [$lock_key]);
        }
        if (is_array($result) && isset($result['job_id'])) {
            $this->send_success(array('job_id' => $result['job_id']), null, [$lock_key]);
        }
        $this->send_success(array(
            'message' => anibas_fm_trash_enabled() && $storage === 'local'
                ? esc_html__('Moved to trash', 'anibas-file-manager')
                : esc_html__('Deleted successfully', 'anibas-file-manager'),
        ), null, [$lock_key]);
    }

    public function empty_folder()
    {
        $this->check_delete_privilege();

        $path    = anibas_fm_fetch_request_variable('post', 'path', '');
        $token   = anibas_fm_fetch_request_variable('post', 'token', '');
        $storage = anibas_fm_fetch_request_variable('post', 'storage', 'local');

        if (empty($path)) {
            $this->send_error(array('error' => esc_html__('Path required', 'anibas-file-manager')));
        }

        // Enforce delete password if configured
        $user_id = get_current_user_id();
        $delete_password_hash = anibas_fm_get_option('delete_password_hash', '');
        if (! empty($delete_password_hash)) {
            $stored_token = get_transient('anibas_fm_delete_auth_' . $user_id);
            if (! $token || ! $stored_token || ! hash_equals($stored_token, $token)) {
                $this->send_error(array('error' => 'DeletePasswordRequired'));
            }
        }

        if ($storage === 'local') {
            $full_path = $this->validate_path($path);
            if (! $full_path || ! is_dir($full_path)) {
                $this->send_error(array('error' => 'PathInvalid', 'message' => esc_html__('Invalid folder path', 'anibas-file-manager')));
            }

            $fm     = new LocalFileSystemAdapter();
            $result = $fm->emptyFolder($full_path);
        } else {
            $adapter = $this->get_storage_adapter($storage);
            if (! $adapter) {
                $this->send_error(array('error' => esc_html__('Invalid storage', 'anibas-file-manager')));
            }

            $full_path = $adapter->validate_path($path);
            if (! $full_path || ! $adapter->is_dir($full_path)) {
                $this->send_error(array('error' => 'PathInvalid', 'message' => esc_html__('Invalid remote folder path', 'anibas-file-manager')));
            }

            $job_id = BackgroundProcessor::enqueue_delete_job($full_path, $storage, true, array(
                'recreate_keep_root' => true,
            ));
            if (is_wp_error($job_id)) {
                $result = $job_id;
            } else {
                $group_id = 'empty_' . wp_generate_password(12, false);
                BackgroundProcessor::annotate_jobs(array($job_id), array(
                    'ui_group_id'     => $group_id,
                    'ui_group_action' => 'empty',
                    'ui_group_label'  => basename($path),
                    'ui_group_source' => $path,
                    'ui_group_mode'   => 'delete',
                ));
                $result = array(
                    'job_ids'        => array($job_id),
                    'group_id'       => $group_id,
                    'operation_mode' => 'delete',
                );
            }
        }

        if (is_wp_error($result)) {
            $this->send_wp_error($result);
        }

        // Frontend polls queued empty-folder jobs.
        if (is_array($result) && isset($result['job_ids']) && is_array($result['job_ids']) && ! empty($result['job_ids'])) {
            $this->send_success(array(
                'job_ids'        => array_values($result['job_ids']),
                'group_id'       => $result['group_id'] ?? null,
                'operation_mode' => $result['operation_mode'] ?? null,
                'message'        => esc_html__('Emptying folder in the background…', 'anibas-file-manager'),
            ));
        }

        $this->send_success(array(
            'message' => anibas_fm_trash_enabled() && $storage === 'local'
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

        if ($path === '' || $new_name === '') {
            $this->send_error(array('error' => 'MissingParams', 'message' => esc_html__('Path and new name are required', 'anibas-file-manager')));
        }

        // Reject path separators and null bytes in the new name
        if (preg_match('/[\/\\\\]/', $new_name) || strpos($new_name, "\0") !== false) {
            $this->send_error(array('error' => 'InvalidName', 'message' => esc_html__('Name cannot contain path separators', 'anibas-file-manager')));
        }

        if ($storage === 'local') {
            $full_path = $this->validate_path($path);
            if (! $full_path || ! file_exists($full_path)) {
                $this->send_error(array('error' => 'PathInvalid', 'message' => esc_html__('Invalid path', 'anibas-file-manager')));
            }
            $new_full_path = dirname($full_path) . DIRECTORY_SEPARATOR . $new_name;
            $local_adapter = new LocalFileSystemAdapter();
            if (! $local_adapter->validate_path($new_full_path)) {
                $this->send_error(array('error' => 'PathInvalid', 'message' => esc_html__('Invalid target path', 'anibas-file-manager')));
            }
            if (file_exists($new_full_path)) {
                $this->send_error(array('error' => 'AlreadyExists', /* translators: %s: file or folder name */
                'message' => sprintf(esc_html__('\'%s\' already exists in this location', 'anibas-file-manager'), esc_html($new_name))));
            }
            if (! @rename($full_path, $new_full_path)) {
                $this->send_error(array('error' => 'RenameFailed', 'message' => esc_html__('Rename failed. Check file permissions.', 'anibas-file-manager')));
            }
        } else {
            $adapter   = $this->get_storage_adapter($storage);
            $full_path = $adapter->validate_path($path);
            if (! $full_path || (! $adapter->is_file($full_path) && ! $adapter->is_dir($full_path))) {
                $this->send_error(array('error' => 'PathInvalid', 'message' => esc_html__('Invalid path', 'anibas-file-manager')));
            }
            $dir           = rtrim(dirname($full_path), '/');
            $new_full_path = $dir . '/' . $new_name;
            if (! $adapter->validate_path($new_full_path)) {
                $this->send_error(array('error' => 'PathInvalid', 'message' => esc_html__('Invalid target path', 'anibas-file-manager')));
            }
            if ($adapter->exists($new_full_path)) {
                $this->send_error(array('error' => 'AlreadyExists', /* translators: %s: file or folder name */
                'message' => sprintf(esc_html__('\'%s\' already exists in this location', 'anibas-file-manager'), esc_html($new_name))));
            }

            // Route remote rename through BackgroundProcessor (S3 rename = copy + delete,
            // which can be slow for large files/folders and would otherwise time out).
            $job_id = BackgroundProcessor::enqueue_job($full_path, $new_full_path, 'move', 'overwrite', $storage, [
                'dest_is_final' => true,
            ]);
            if (is_wp_error($job_id)) {
                $this->send_error(array(
                    'error'   => 'RenameFailed',
                    'message' => $job_id->get_error_message(),
                ));
            }
            $this->send_success(array(
                'job_id'   => $job_id,
                /* translators: %s: new name */
                'message'  => sprintf(esc_html__('Rename job started for \'%s\'', 'anibas-file-manager'), esc_html($new_name)),
                'new_name' => $new_name,
            ));
            return;
        }

        $this->send_success(array(/* translators: %s: new name */
            'message' => sprintf(esc_html__('Renamed to \'%s\' successfully', 'anibas-file-manager'), esc_html($new_name)), 'new_name' => $new_name));
    }

    private function remote_request_path($adapter, string $path, string $storage_id = ''): string|false
    {
        if ($storage_id !== '' && method_exists($adapter, 'path_from_storage_id')) {
            $by_id = $adapter->path_from_storage_id($storage_id, $path);
            if ($by_id !== false) {
                return $by_id;
            }
        }

        return $adapter->validate_path($path);
    }

    private function safe_download_name(string $path): string
    {
        $filename = preg_replace('/[\r\n"\\\\]/', '', basename($path));
        return $filename !== '' ? $filename : 'download';
    }

    private function mime_from_path(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($ext) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'bmp' => 'image/bmp',
            'ico' => 'image/x-icon',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'ogg' => 'video/ogg',
            'mov' => 'video/quicktime',
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'txt', 'log' => 'text/plain; charset=UTF-8',
            'json' => 'application/json; charset=UTF-8',
            'html', 'htm' => 'text/html; charset=UTF-8',
            'css' => 'text/css; charset=UTF-8',
            'js', 'mjs' => 'text/javascript; charset=UTF-8',
            'xml' => 'application/xml; charset=UTF-8',
            default => 'application/octet-stream',
        };
    }

    /* =========================================================
       DOWNLOAD FILE — streams a file to the browser
    ========================================================= */

    public function download_file(): void
    {
        $this->check_privilege();

        $path    = anibas_fm_fetch_request_variable('get', 'path', '');
        $storage = anibas_fm_fetch_request_variable('get', 'storage', 'local');
        $storage_id = anibas_fm_fetch_request_variable('get', 'storage_id', '');
        $disposition = anibas_fm_fetch_request_variable('get', 'disposition', '') === 'inline' ? 'inline' : 'attachment';

        if (empty($path)) {
            wp_die(esc_html__('File path is required', 'anibas-file-manager'), esc_html__('Error', 'anibas-file-manager'), array('response' => 400));
        }

        if ($storage === 'local') {
            $full_path = $this->validate_path($path);
            if (! $full_path || ! is_file($full_path)) {
                wp_die(esc_html__('File not found', 'anibas-file-manager'), esc_html__('Error', 'anibas-file-manager'), array('response' => 404));
            }
            $filename = $this->safe_download_name($full_path);
            $filesize = filesize($full_path);

            // Robust MIME detection with fallback chain to avoid PHP warnings
            // corrupting the output buffer when fileinfo extension is unavailable.
            $mime = 'application/octet-stream';
            if (function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                if ($finfo) {
                    $detected = finfo_file($finfo, $full_path);
                    if ($detected) {
                        $mime = $detected;
                    }
                    finfo_close($finfo);
                }
            } elseif (function_exists('mime_content_type')) {
                $detected = @mime_content_type($full_path);
                if ($detected) {
                    $mime = $detected;
                }
            }

            if (ob_get_level()) ob_end_clean();
            header('Content-Type: ' . $mime);
            header('Content-Disposition: ' . $disposition . '; filename="' . $filename . '"');
            header('Content-Length: ' . $filesize);
            header('Cache-Control: no-cache, must-revalidate');
            readfile($full_path);
            exit;
        } else {
            $adapter   = StorageManager::get_instance()->get_adapter($storage);
            if (! $adapter) {
                wp_die(esc_html__('Invalid storage', 'anibas-file-manager'), esc_html__('Error', 'anibas-file-manager'), array('response' => 400));
            }
            $full_path = $this->remote_request_path($adapter, $path, $storage_id);
            if (! $full_path || ! $adapter->is_file($full_path)) {
                if ($full_path && $adapter->is_dir($full_path)) {
                    wp_die(esc_html__('Cannot download directories directly. Please use zip download.', 'anibas-file-manager'), esc_html__('Error', 'anibas-file-manager'), array('response' => 400));
                }
                wp_die(esc_html__('File not found', 'anibas-file-manager'), esc_html__('Error', 'anibas-file-manager'), array('response' => 404));
            }

            // Provider links often force attachment headers; inline previews
            // need to stream through WordPress so PDFs/images stay embedded.
            $temp_link = $disposition === 'attachment' ? $adapter->get_temporary_link($full_path, 3600) : false;
            if ($temp_link) {
                wp_redirect($temp_link);
                exit;
            }

            // Fallback to streaming for FTP/SFTP or if no link could be generated
            $download_name = $this->safe_download_name($path);
            header('Content-Description: File Transfer');
            header('Content-Type: ' . ($disposition === 'inline' ? $this->mime_from_path($path) : 'application/octet-stream'));
            header('Content-Disposition: ' . $disposition . '; filename="' . $download_name . '"');

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
        $storage_id = anibas_fm_fetch_request_variable('get', 'storage_id', '');
        $limit   = 102400; // 100KB

        if (empty($path)) {
            $this->send_error(array('error' => 'MissingParams', 'message' => esc_html__('Path is required', 'anibas-file-manager')));
        }

        if ($storage === 'local') {
            $full_path = $this->validate_path($path);
            if (! $full_path || ! is_file($full_path)) {
                $this->send_error(array('error' => 'NotFound', 'message' => esc_html__('File not found', 'anibas-file-manager')));
            }
            $handle = @fopen($full_path, 'rb');
            if (! $handle) {
                $this->send_error(array('error' => 'ReadFailed', 'message' => esc_html__('Failed to read file', 'anibas-file-manager')));
            }
            $content = fread($handle, $limit);
            fclose($handle);
            if ($content === false) {
                $this->send_error(array('error' => 'ReadFailed', 'message' => esc_html__('Failed to read file', 'anibas-file-manager')));
            }
            $this->send_success(array('content' => $content));
        } else {
            $adapter   = StorageManager::get_instance()->get_adapter($storage);
            if (! $adapter) {
                $this->send_error(array('error' => 'InvalidStorage', 'message' => esc_html__('Invalid storage', 'anibas-file-manager')));
            }
            $full_path = $this->remote_request_path($adapter, $path, $storage_id);
            if (! $full_path || ! $adapter->is_file($full_path)) {
                $this->send_error(array('error' => 'NotFound', 'message' => esc_html__('File not found', 'anibas-file-manager')));
            }

            // Refuse preview for files larger than the limit — fetching the whole
            // file into memory is unsafe for large files on remote storage.
            $file_size = method_exists($adapter, 'get_file_size') ? $adapter->get_file_size($full_path) : false;
            if ($file_size !== false && $file_size > $limit) {
                $this->send_error(array('error' => 'FileTooLarge', 'message' => esc_html__('File is too large to preview', 'anibas-file-manager')));
            }

            $content = $adapter->get_contents($full_path);
            if ($content !== false) {
                $this->send_success(array('content' => $content));
            } else {
                $this->send_error(array('error' => 'ReadFailed', 'message' => esc_html__('Failed to read from remote storage', 'anibas-file-manager')));
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
        $storage_id = anibas_fm_fetch_request_variable('get', 'storage_id', '');

        if (empty($path)) {
            $this->send_error(array('error' => 'MissingParams', 'message' => esc_html__('Path is required', 'anibas-file-manager')));
        }

        try {
            $adapter   = StorageManager::get_instance()->get_adapter($storage);
            if (! $adapter) {
                $this->send_error(array('error' => 'InvalidStorage', 'message' => esc_html__('Invalid storage', 'anibas-file-manager')));
            }
            $full_path = $this->remote_request_path($adapter, $path, $storage_id);

            if (! $full_path) {
                $this->send_error(array('error' => 'NotFound', 'message' => esc_html__('File or folder not found', 'anibas-file-manager')));
            }

            $details = $adapter->getDetails($full_path);
            if ($details === false) {
                $this->send_error(array('error' => 'NotFound', 'message' => esc_html__('Could not fetch details', 'anibas-file-manager')));
            }

            $this->send_success(array('details' => $this->decorate_file_item($details)));
        } catch (\Throwable $e) {
            $this->send_error(array('error' => 'Exception', 'message' => esc_html($e->getMessage())));
        }
    }

    private function decorate_file_items(array $items): array
    {
        foreach ($items as $key => $item) {
            if (is_array($item)) {
                $items[$key] = $this->decorate_file_item($item);
            }
        }
        return $items;
    }

    private function decorate_file_item(array $item): array
    {
        $name = (string) ($item['name'] ?? $item['filename'] ?? basename((string) ($item['path'] ?? '')));
        $path = (string) ($item['path'] ?? '');

        if (! empty($item['is_folder'])) {
            if (anibas_fm_is_site_backup_cloud_folder_name($name)) {
                $item['file_type'] = esc_html__('Full Site Backup Folder', 'anibas-file-manager');
                $item['backup_kind'] = 'site_backup_folder';
            }
            return $item;
        }

        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'anfm') {
            return $item;
        }

        if (anibas_fm_is_site_backup_cloud_path($path)) {
            $item['file_type'] = esc_html__('ANFM Full Site Backup', 'anibas-file-manager');
            $item['archive_kind'] = 'site_backup';
            $item['backup_kind'] = 'full_site';
        } else {
            $item['file_type'] = esc_html__('Anibas Archive', 'anibas-file-manager');
            $item['archive_kind'] = 'archive';
        }

        return $item;
    }
}
