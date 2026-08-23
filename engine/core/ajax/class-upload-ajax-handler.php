<?php

namespace Anibas;

if (! defined('ABSPATH')) exit;

// phpcs:disable WordPress.Security.NonceVerification.Missing -- Upload AJAX methods call check_create_privilege() before request and $_FILES metadata are consumed.

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

    public function init_upload(): void
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

        $chunk_size = $this->upload_chunk_size();
        $total_chunks = (int) ceil($file_size / $chunk_size);

        set_transient($token_key, array(
            'token'        => $upload_token,
            'file_name'    => $file_name,
            'file_size'    => $file_size,
            'chunk_size'   => $chunk_size,
            'total_chunks' => $total_chunks,
        ), ANIBAS_FM_UPLOAD_TOKEN_EXPIRY);

        $this->send_success(array(
            'upload_id' => $upload_id,
            'upload_token' => $upload_token,
            'chunk_size' => $chunk_size,
            'total_chunks' => $total_chunks,
        ));
    }

    public function upload_chunk(): void
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

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_create_privilege() validates nonce/auth before upload metadata is inspected.
        if (empty($file_name) || ! isset($_FILES['chunk']) || empty($upload_id) || empty($upload_token)) {
            $this->send_error(array('error' => esc_html__('Invalid request', 'anibas-file-manager')));
        }
        if (! preg_match('/^[A-Za-z0-9]{16,64}$/', $upload_id)) {
            $this->send_error(array('error' => esc_html__('Invalid upload session', 'anibas-file-manager')));
        }
        if ($file_size <= 0 || $total_chunks <= 0 || $chunk_index < 0 || $chunk_index >= $total_chunks) {
            $this->send_error(array('error' => esc_html__('Invalid upload metadata', 'anibas-file-manager')));
        }

        $user_id = get_current_user_id();
        $token_key = 'anibas_fm_upload_' . $upload_id . '_' . $user_id;
        $stored_session = get_transient($token_key);

        $session = $this->normalize_upload_session($stored_session, $upload_token, $file_name, $file_size, $total_chunks);
        if (! is_array($session)) {
            $this->send_error(array('error' => esc_html__('Invalid or expired upload token', 'anibas-file-manager')));
        }

        $session_chunk_size = (int) $session['chunk_size'];
        if ($total_chunks !== (int) $session['total_chunks']) {
            $this->send_error(array('error' => esc_html__('Invalid upload metadata', 'anibas-file-manager')));
        }

        set_transient($token_key, $session, ANIBAS_FM_UPLOAD_TOKEN_EXPIRY);

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

        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- PHP upload error metadata is validated against UPLOAD_ERR_OK before the chunk is accepted.
        if (! isset($_FILES['chunk']['error']) || $_FILES['chunk']['error'] !== UPLOAD_ERR_OK) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- error code is escaped for display and not trusted for control flow beyond UPLOAD_ERR_OK check above.
            /* translators: %s: PHP upload error code */
            $error_msg = isset($_FILES['chunk']['error']) ? sprintf(esc_html__('Upload error code: %s', 'anibas-file-manager'), esc_html($_FILES['chunk']['error'])) : esc_html__('No file uploaded', 'anibas-file-manager');
            $this->send_error(array('error' => $error_msg));
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- uploaded byte count is validated against the server-issued upload session.
        $uploaded_size = isset($_FILES['chunk']['size']) ? (int) $_FILES['chunk']['size'] : 0;
        $expected_size = $this->expected_chunk_size($file_size, $session_chunk_size, $chunk_index, $total_chunks);
        if ($uploaded_size <= 0 || $uploaded_size !== $expected_size) {
            $this->send_error(array('error' => esc_html__('Upload chunk size mismatch', 'anibas-file-manager')));
        }

        $chunk_file = $temp_dir . '/chunk_' . $chunk_index;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- tmp_name is provided by PHP's upload subsystem after UPLOAD_ERR_OK and session size validation.
        if (! move_uploaded_file($_FILES['chunk']['tmp_name'], $chunk_file)) {
            $error = error_get_last();
            ActivityLogger::log_message(sprintf('[Upload] Failed to save chunk %d/%d for "%s": %s', $chunk_index + 1, $total_chunks, $file_name, $error['message'] ?? 'Unknown error'));
            /* translators: %s: error message */
            $this->send_error(array('error' => sprintf(esc_html__('Failed to save chunk: %s', 'anibas-file-manager'), esc_html($error['message'] ?? esc_html__('Unknown error', 'anibas-file-manager')))));
        }

        if ($chunk_index === $total_chunks - 1) {
            if (! $this->all_chunks_ready($temp_dir, $file_size, $session_chunk_size, $total_chunks)) {
                $this->send_error(array('error' => esc_html__('Upload is incomplete. Please retry the failed chunks.', 'anibas-file-manager')));
            }

            ActivityLogger::log_message(sprintf('[Upload] All %d chunks received for "%s" (%s) → storage: %s, dest: %s', $total_chunks, $file_name, size_format($file_size), $storage, $destination));

            $assembly_token_key = $token_key . '_assembly';
            set_transient($assembly_token_key, $session['token'], 3600);

            $verify = get_transient($assembly_token_key);
            if (! $verify) {
                ActivityLogger::log_message('[Upload] Failed to create assembly token for "' . $file_name . '"');
                $this->send_error(array('error' => esc_html__('Failed to create assembly token', 'anibas-file-manager')));
            }

            delete_transient($token_key);

            $job_id = $this->enqueue_assembly_job($upload_id, $temp_dir, $total_chunks, $file_name, $file_size, $destination, $storage, $user_id);

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

    private function enqueue_assembly_job(string $upload_id, string $temp_dir, int $total_chunks, string $file_name, int $file_size, string $destination, string $storage, int $user_id): string
    {
        $queue = anibas_fm_get_option('anibas_fm_job_queue_v2', []);

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

    private function cleanup_chunks(string $temp_dir): void
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

    private function upload_chunk_size(): int
    {
        $chunk_size = intval(anibas_fm_get_option('chunk_size', ANIBAS_FM_DEFAULT_CHUNK_SIZE));
        if ($chunk_size < ANIBAS_FM_CHUNK_SIZE_MIN) {
            $chunk_size = ANIBAS_FM_CHUNK_SIZE_MIN;
        }
        if ($chunk_size > ANIBAS_FM_CHUNK_SIZE_MAX) {
            $chunk_size = ANIBAS_FM_CHUNK_SIZE_MAX;
        }

        $upload_max = wp_max_upload_size();
        if ($upload_max > 0 && $chunk_size > $upload_max) {
            $chunk_size = $upload_max;
        }

        return max(1, $chunk_size);
    }

    private function normalize_upload_session(mixed $stored_session, string $upload_token, string $file_name, int $file_size, int $total_chunks): array|false
    {
        if (is_string($stored_session)) {
            if (! hash_equals($stored_session, $upload_token)) {
                return false;
            }

            $chunk_size = $this->upload_chunk_size();
            return array(
                'token'        => $stored_session,
                'file_name'    => $file_name,
                'file_size'    => $file_size,
                'chunk_size'   => $chunk_size,
                'total_chunks' => (int) ceil($file_size / $chunk_size),
            );
        }

        if (! is_array($stored_session) || empty($stored_session['token']) || ! is_string($stored_session['token'])) {
            return false;
        }
        if (! hash_equals($stored_session['token'], $upload_token)) {
            return false;
        }

        $stored_name = (string) ($stored_session['file_name'] ?? '');
        $stored_size = (int) ($stored_session['file_size'] ?? 0);
        $stored_chunk_size = (int) ($stored_session['chunk_size'] ?? 0);
        $stored_total_chunks = (int) ($stored_session['total_chunks'] ?? 0);

        if ($stored_name !== $file_name || $stored_size !== $file_size || $stored_chunk_size <= 0 || $stored_total_chunks <= 0) {
            return false;
        }
        if ($stored_total_chunks !== $total_chunks) {
            return false;
        }

        return array(
            'token'        => $stored_session['token'],
            'file_name'    => $stored_name,
            'file_size'    => $stored_size,
            'chunk_size'   => $stored_chunk_size,
            'total_chunks' => $stored_total_chunks,
        );
    }

    private function expected_chunk_size(int $file_size, int $chunk_size, int $chunk_index, int $total_chunks): int
    {
        if ($chunk_index === $total_chunks - 1) {
            return $file_size - ($chunk_size * ($total_chunks - 1));
        }

        return $chunk_size;
    }

    private function all_chunks_ready(string $temp_dir, int $file_size, int $chunk_size, int $total_chunks): bool
    {
        for ($i = 0; $i < $total_chunks; $i++) {
            $chunk_file = $temp_dir . '/chunk_' . $i;
            if (! is_file($chunk_file)) {
                return false;
            }

            $size = filesize($chunk_file); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filesize -- verifies local upload chunk byte count before assembly.
            if ($size === false || $size !== $this->expected_chunk_size($file_size, $chunk_size, $i, $total_chunks)) {
                return false;
            }
        }

        return true;
    }
}
