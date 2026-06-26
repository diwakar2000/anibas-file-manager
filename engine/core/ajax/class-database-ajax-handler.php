<?php

namespace Anibas;

if (! defined('ABSPATH')) exit;

/**
 * Database explorer endpoints. Mutating requests use the separate edit gate
 * and table-level safety policy.
 */
class DatabaseAjaxHandler extends AjaxHandler
{
    public function __construct()
    {
        parent::__construct();
        $this->register_actions([
            ANIBAS_FM_DB_LIST_SCOPES     => 'list_scopes',
            ANIBAS_FM_DB_LIST_TABLES     => 'list_tables',
            ANIBAS_FM_DB_GET_SCHEMA      => 'get_schema',
            ANIBAS_FM_DB_GET_ROWS        => 'get_rows',
            ANIBAS_FM_DB_UPDATE_CELL     => 'update_cell',
            ANIBAS_FM_DB_DELETE_ROW      => 'delete_row',
            ANIBAS_FM_DB_INSERT_ROW      => 'insert_row',
            ANIBAS_FM_DB_VERIFY_PASSWORD => 'verify_password',
            ANIBAS_FM_DB_CHECK_AUTH      => 'check_auth',
        ]);
    }

    public function verify_password(): void
    {
        $this->check_database_view_enabled();
        $this->check_nonce(ANIBAS_FM_NONCE_DATABASE);
        $this->check_admin_privilege();
        $this->check_fm_token();

        if (! anibas_fm_database_password_required()) {
            $this->send_success([
                'token' => '',
                'expires_in' => 0,
            ]);
        }

        $password = anibas_fm_fetch_request_variable('post', 'password', '');
        $hash = anibas_fm_get_option('database_password_hash', '');

        if (empty($password) || empty($hash) || ! wp_check_password($password, $hash)) {
            $this->send_error(esc_html__('Invalid database password', 'anibas-file-manager'), 401);
        }

        $token = wp_generate_password(48, false, false);
        set_transient('anibas_fm_db_token_' . get_current_user_id(), hash('sha256', $token . '|' . $hash), ANIBAS_FM_DATABASE_TOKEN_TTL);

        $this->send_success([
            'token' => $token,
            'expires_in' => ANIBAS_FM_DATABASE_TOKEN_TTL,
        ]);
    }

    public function check_auth(): void
    {
        $this->check_database_view_enabled();
        $this->check_nonce(ANIBAS_FM_NONCE_DATABASE);
        $this->check_admin_privilege();
        $this->check_fm_token();

        if (! anibas_fm_database_password_required()) {
            $this->send_success([
                'valid' => true,
            ]);
        }

        if (! $this->has_valid_database_token()) {
            $this->send_error(array('error' => 'DBTokenRequired', 'message' => esc_html__('Database session expired. Please re-enter the database password.', 'anibas-file-manager')), 401);
        }

        $this->send_success([
            'valid' => true,
        ]);
    }

    public function list_scopes(): void
    {
        $this->check_database_view_privilege();

        try {
            $manager = new DatabaseManager();
            $this->send_success([
                'scopes' => $manager->list_scopes(),
            ]);
        } catch (\Throwable $e) {
            $this->send_error([
                'error' => 'DatabaseListScopesFailed',
                'message' => esc_html($e->getMessage()),
            ]);
        }
    }

    public function list_tables(): void
    {
        $this->check_database_view_privilege();

        try {
            $manager = new DatabaseManager();
            $scope = $this->request_text('scope', 'current');
            $this->send_success($manager->list_tables($scope));
        } catch (\Throwable $e) {
            $this->send_error([
                'error' => 'DatabaseListTablesFailed',
                'message' => esc_html($e->getMessage()),
            ]);
        }
    }

    public function get_schema(): void
    {
        $this->check_database_view_privilege();

        try {
            $manager = new DatabaseManager();
            $scope = $this->request_text('scope', 'current');
            $table = $this->request_text('table', '');
            $this->send_success($manager->get_schema($table, $scope));
        } catch (\Throwable $e) {
            $this->send_error([
                'error' => 'DatabaseSchemaFailed',
                'message' => esc_html($e->getMessage()),
            ]);
        }
    }

    public function get_rows(): void
    {
        $this->check_database_view_privilege();

        try {
            $manager = new DatabaseManager();
            $scope = $this->request_text('scope', 'current');
            $table = $this->request_text('table', '');
            $args = [
                'page_size' => (int) anibas_fm_fetch_request_variable('request', 'page_size', 50),
                'page' => (int) anibas_fm_fetch_request_variable('request', 'page', 1),
            ];

            $this->send_success($manager->get_rows($table, $scope, $args));
        } catch (\Throwable $e) {
            $this->send_error([
                'error' => 'DatabaseRowsFailed',
                'message' => esc_html($e->getMessage()),
            ]);
        }
    }

    public function update_cell(): void
    {
        $this->check_database_edit_privilege();

        try {
            $manager = new DatabaseManager();
            $scope = $this->request_text('scope', 'current');
            $table = $this->request_text('table', '');
            $column = $this->request_text('column', '');
            $row_key = $this->request_json_array('row_key');
            $old_value = $this->request_json_value('old_value');
            $new_value = $this->request_json_value('value');

            $this->send_success($manager->update_cell($table, $scope, $row_key, $column, $old_value, $new_value));
        } catch (\Throwable $e) {
            $this->send_error([
                'error' => 'DatabaseUpdateFailed',
                'message' => esc_html($e->getMessage()),
            ]);
        }
    }

    public function delete_row(): void
    {
        $this->check_database_edit_privilege();

        try {
            $manager = new DatabaseManager();
            $scope = $this->request_text('scope', 'current');
            $table = $this->request_text('table', '');
            $row_key = $this->request_json_array('row_key');

            $this->send_success($manager->delete_row($table, $scope, $row_key));
        } catch (\Throwable $e) {
            $this->send_error([
                'error' => 'DatabaseDeleteFailed',
                'message' => esc_html($e->getMessage()),
            ]);
        }
    }

    public function insert_row(): void
    {
        $this->check_database_edit_privilege();

        try {
            $manager = new DatabaseManager();
            $scope = $this->request_text('scope', 'current');
            $table = $this->request_text('table', '');
            $values = $this->request_json_array('values');

            $this->send_success($manager->insert_row($table, $scope, $values));
        } catch (\Throwable $e) {
            $this->send_error([
                'error' => 'DatabaseInsertFailed',
                'message' => esc_html($e->getMessage()),
            ]);
        }
    }

    private function request_text(string $key, string $default): string
    {
        $value = anibas_fm_fetch_request_variable('request', $key, $default);
        if (is_array($value)) {
            return $default;
        }
        return sanitize_text_field((string) $value);
    }

    private function request_json_array(string $key): array
    {
        $raw = anibas_fm_fetch_request_variable('request', $key, '{}');
        if (is_array($raw)) {
            return $raw;
        }

        $decoded = json_decode((string) $raw, true);
        if (! is_array($decoded)) {
            throw new \InvalidArgumentException('Invalid request payload.');
        }

        return $decoded;
    }

    private function request_json_value(string $key)
    {
        $raw = anibas_fm_fetch_request_variable('request', $key, 'null');
        if (is_array($raw)) {
            throw new \InvalidArgumentException('Invalid request payload.');
        }

        $decoded = json_decode((string) $raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('Invalid request payload.');
        }

        return $decoded;
    }
}
