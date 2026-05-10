<?php

namespace Anibas;

if (! defined('ABSPATH')) exit;

/**
 * AJAX endpoints for chunked file upload: init token issue and per-chunk
 * receive. Final chunk hands off to BackgroundProcessor for assembly.
 */
class UploadAjaxHandler extends AjaxHandler
{
    public function __construct()
    {
        parent::__construct();
        $this->register_actions([
            ANIBAS_FM_INIT_UPLOAD  => 'init_upload',
            ANIBAS_FM_UPLOAD_CHUNK => 'upload_chunk',
        ]);
    }

    public function init_upload()
    {
        $this->check_create_privilege();

        $file_name = sanitize_file_name(wp_unslash($_POST['file_name'] ?? ''));
        $file_size = intval(wp_unslash($_POST['file_size'] ?? 0));

        if (empty($file_name) || $file_size <= 0) {
            $this->send_error(array('error' => esc_html__('Invalid file info', 'anibas-file-manager')));
        }

        $user_id = get_current_user_id();
        $upload_id = wp_generate_password(24, false, false);
        $upload_token = wp_generate_password(32, false, false);
        $token_key = 'anibas_fm_upload_' . $upload_id . '_' . $user_id;

        set_transient($token_key, $upload_token, ANIBAS_FM_UPLOAD_TOKEN_EXPIRY);

        $chunk_size = intval(anibas_fm_get_option('chunk_size', ANIBAS_FM_DEFAULT_CHUNK_SIZE));
        if ($chunk_size < ANIBAS_FM_CHUNK_SIZE_MIN) {
            $chunk_size = ANIBAS_FM_CHUNK_SIZE_MIN;
        }
        if ($chunk_size > ANIBAS_FM_CHUNK_SIZE_MAX) {
            $chunk_size = ANIBAS_FM_CHUNK_SIZE_MAX;
        }

        // Ensure chunk size doesn't exceed PHP upload limits
        $upload_max = wp_max_upload_size();
        if ($chunk_size > $upload_max) {
            $chunk_size = $upload_max;
        }

        $this->send_success(array(
            'upload_id' => $upload_id,
            'upload_token' => $upload_token,
            'chunk_size' => $chunk_size
        ));
    }

    public function upload_chunk()
    {
        $this->check_create_privilege();

        if (! function_exists('wp_handle_upload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $chunk_index = intval(wp_unslash($_POST['chunk_index'] ?? 0));
        $total_chunks = intval(wp_unslash($_POST['total_chunks'] ?? 1));
        $file_name = sanitize_file_name(wp_unslash($_POST['file_name'] ?? ''));
        $file_size = intval(wp_unslash($_POST['file_size'] ?? 0));
        $storage = sanitize_text_field(wp_unslash($_POST['storage'] ?? 'local'));
        $destination = sanitize_text_field(wp_unslash($_POST['destination'] ?? '/'));
        $upload_id = sanitize_text_field(wp_unslash($_POST['upload_id'] ?? ''));
        if ($storage === 'local') {
            $validated_abs = $this->validate_path($destination);
            if (! $validated_abs) {
                $this->send_error(array('error' => 'PathInvalid', 'message' => esc_html__('Invalid destination path', 'anibas-file-manager')));
            }
        } else {
            // Validate remote destination through the storage adapter to prevent
            // path traversal (e.g. ../../) escaping the bounded remote root.
            $adapter = $this->get_storage_adapter($storage);
            if (! $adapter) {
                $this->send_error(array('error' => esc_html__('Invalid storage', 'anibas-file-manager')));
            }
            $validated_remote = $adapter->validate_path($destination);
            if (! $validated_remote) {
                $this->send_error(array('error' => 'PathInvalid', 'message' => esc_html__('Invalid remote destination path', 'anibas-file-manager')));
            }
        }
        $upload_token = sanitize_text_field(wp_unslash($_POST['upload_token'] ?? ''));

        if (empty($file_name) || ! isset($_FILES['chunk']) || empty($upload_id) || empty($upload_token)) {
            $this->send_error(array('error' => esc_html__('Invalid request', 'anibas-file-manager')));
        }
        if (! preg_match('/^[A-Za-z0-9]{16,64}$/', $upload_id)) {
            $this->send_error(array('error' => esc_html__('Invalid upload session', 'anibas-file-manager')));
        }

        $user_id = get_current_user_id();
        $token_key = 'anibas_fm_upload_' . $upload_id . '_' . $user_id;
        $stored_token = get_transient($token_key);

        if (! $stored_token || ! hash_equals($stored_token, $upload_token)) {
            $this->send_error(array('error' => esc_html__('Invalid or expired upload token', 'anibas-file-manager')));
        }

        set_transient($token_key, $stored_token, ANIBAS_FM_UPLOAD_TOKEN_EXPIRY);

        $temp_root = wp_upload_dir()['basedir'] . '/anibas_fm_temp';
        $temp_dir  = $temp_root . '/' . $upload_id;

        if (! is_dir($temp_dir)) {
            if (! wp_mkdir_p($temp_dir)) {
                $this->send_error(array('error' => esc_html__('Failed to create temp directory', 'anibas-file-manager')));
            }
            // Protect the temp root (parent of all upload_id chunk dirs) so
            // in-flight upload chunks can't be fetched over HTTP.
            anibas_fm_protect_dir($temp_root);
        }

        if (! isset($_FILES['chunk']['error']) || $_FILES['chunk']['error'] !== UPLOAD_ERR_OK) {
            /* translators: %s: PHP upload error code */
            $error_msg = isset($_FILES['chunk']['error']) ? sprintf(esc_html__('Upload error code: %s', 'anibas-file-manager'), esc_html($_FILES['chunk']['error'])) : esc_html__('No file uploaded', 'anibas-file-manager');
            $this->send_error(array('error' => $error_msg));
        }

        $chunk_file = $temp_dir . '/chunk_' . $chunk_index;
        if (! move_uploaded_file($_FILES['chunk']['tmp_name'], $chunk_file)) {
            $error = error_get_last();
            ActivityLogger::log_message(sprintf('[Upload] Failed to save chunk %d/%d for "%s": %s', $chunk_index + 1, $total_chunks, $file_name, $error['message'] ?? 'Unknown error'));
            /* translators: %s: error message */
            $this->send_error(array('error' => sprintf(esc_html__('Failed to save chunk: %s', 'anibas-file-manager'), esc_html($error['message'] ?? esc_html__('Unknown error', 'anibas-file-manager')))));
        }

        if ($chunk_index === $total_chunks - 1) {
            ActivityLogger::log_message(sprintf('[Upload] All %d chunks received for "%s" (%s) → storage: %s, dest: %s', $total_chunks, $file_name, size_format($file_size), $storage, $destination));

            $assembly_token_key = $token_key . '_assembly';
            set_transient($assembly_token_key, $stored_token, 3600);

            $verify = get_transient($assembly_token_key);
            if (! $verify) {
                ActivityLogger::log_message('[Upload] Failed to create assembly token for "' . $file_name . '"');
                $this->send_error(array('error' => esc_html__('Failed to create assembly token', 'anibas-file-manager')));
            }

            delete_transient($token_key);

            $job_id = $this->enqueue_assembly_job($upload_id, $temp_dir, $total_chunks, $file_name, $destination, $storage, $user_id);

            if ($job_id) {
                ActivityLogger::log_message('[Upload] Assembly job enqueued: ' . $job_id);
                $this->send_success(array(
                    'message' => esc_html__('Upload complete, assembling file', 'anibas-file-manager'),
                    'job_id' => $job_id
                ));
            } else {
                ActivityLogger::log_message('[Upload] Failed to enqueue assembly job for "' . $file_name . '"');
                $this->cleanup_chunks($temp_dir);
                $this->send_error(array('error' => esc_html__('Failed to start assembly job', 'anibas-file-manager')));
            }
        } else {
            if ($chunk_index === 0) {
                ActivityLogger::log_message(sprintf('[Upload] Started receiving "%s" (%s) in %d chunks → storage: %s, dest: %s', $file_name, size_format($file_size), $total_chunks, $storage, $destination));
            }
            $this->send_success(array('message' => esc_html__('Chunk received', 'anibas-file-manager'), 'chunk' => $chunk_index));
        }
    }

    private function enqueue_assembly_job($upload_id, $temp_dir, $total_chunks, $file_name, $destination, $storage, $user_id)
    {
        $queue = anibas_fm_get_option('anibas_fm_job_queue_v2', []);

        $file_size = intval(wp_unslash($_POST['file_size'] ?? 0));

        $job_id = 'assembly_' . $upload_id;
        $queue[$job_id] = [
            'id' => $job_id,
            'type' => 'assembly',
            'temp_dir' => $temp_dir,
            'total_chunks' => $total_chunks,
            'file_name' => $file_name,
            'file_size' => $file_size,
            'destination' => $destination,
            'storage' => $storage,
            'user_id' => $user_id,
            'upload_id' => $upload_id,
            'status' => 'pending',
            'current_chunk' => 0,
            'created_at' => time(),
        ];

        anibas_fm_update_option('anibas_fm_job_queue_v2', $queue);
        AsyncWorkerDispatcher::dispatch();

        return $job_id;
    }

    private function cleanup_chunks($temp_dir)
    {
        if (! function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        \WP_Filesystem();
        global $wp_filesystem;

        if ($wp_filesystem->is_dir($temp_dir)) {
            $wp_filesystem->delete($temp_dir, true);
        }
    }
}
