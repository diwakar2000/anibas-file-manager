<?php

/**
 * AJAX handler exposing database browser endpoints (scopes, tables, schema,
 * rows, and gated edit operations).
 *
 * @package Anibas_File_Manager
 */

namespace Anibas;

if (! defined('ABSPATH')) exit;

/**
 * Database explorer endpoints. Mutating requests use the separate edit gate
 * and table-level safety policy.
 */
class DatabaseAjaxHandler extends AjaxHandler
{
    /**
     * Register the database browser/edit AJAX actions.
     */
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

    /**
     * Verify the database browser password and, on success, issue a
     * short-lived database session token.
     *
     * Requires database browsing to be enabled, a valid database nonce,
     * admin privilege, and the file manager token. If no database password
     * is configured, immediately reports success with an empty token
     * (nothing to gate). Otherwise the submitted `password` POST field is
     * checked against the stored hash, and on match a random token is
     * generated and its hash stored in a per-user transient for later
     * verification by has_valid_database_token().
     */
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

    /**
     * Check whether the current user's database session token is still
     * valid, so the UI can decide whether to show the password gate again.
     *
     * Requires database browsing to be enabled, a valid database nonce,
     * admin privilege, and the file manager token. Reports valid
     * immediately if no database password is configured.
     */
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

    /**
     * List the available database scopes (e.g. current site vs. network)
     * a user can browse.
     *
     * Requires database view privilege (view enabled, nonce, admin, file
     * manager token, and database session token if a database password is
     * configured).
     */
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

    /**
     * List the tables within the requested database scope.
     *
     * Requires database view privilege. Reads `scope` from the request
     * (defaults to 'current').
     */
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

    /**
     * Get column/schema information for a table.
     *
     * Requires database view privilege. Reads `scope` (default 'current')
     * and `table` from the request.
     */
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

    /**
     * Fetch one page of rows from a table.
     *
     * Requires database view privilege. Reads `scope` (default 'current'),
     * `table`, and pagination via `page_size` (default 50) and `page`
     * (default 1) from the request.
     */
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

    /**
     * Update a single cell's value in a table row.
     *
     * Requires database edit privilege (view privilege plus the edit
     * feature being enabled by both the wp-config constant and settings).
     * Reads `scope`, `table`, `column` as text, and `row_key`/`old_value`/
     * `value` as raw JSON payloads (row_key must decode to an array; an
     * invalid payload throws and is reported as a DatabaseUpdateFailed
     * error). The old value is passed through so the manager can detect a
     * concurrent modification before applying the write.
     */
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

    /**
     * Delete a single row from a table by its primary key.
     *
     * Requires database edit privilege. Reads `scope`, `table`, and
     * `row_key` (raw JSON, must decode to an array) from the request; the
     * underlying manager also enforces a table-level delete policy that can
     * block deletion for protected tables.
     */
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

    /**
     * Insert a new row into a table.
     *
     * Requires database edit privilege. Reads `scope`, `table`, and
     * `values` (raw JSON, must decode to an array of column => value) from
     * the request.
     */
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
        $decoded = json_decode($this->request_raw_string($key, '{}'), true);
        if (! is_array($decoded)) {
            throw new \InvalidArgumentException('Invalid request payload.');
        }

        return $decoded;
    }

    private function request_json_value(string $key): mixed
    {
        $decoded = json_decode($this->request_raw_string($key, 'null'), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('Invalid request payload.');
        }

        return $decoded;
    }

    private function request_raw_string(string $key, string $default): string
    {
        if (array_key_exists($key, $_POST)) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- AJAX actions validate nonce/auth before decoding raw JSON fields.
            // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- raw JSON must be decoded before validating database cell values.
            $value = wp_unslash($_POST[$key]);
        } elseif (array_key_exists($key, $_GET)) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- AJAX actions validate nonce/auth before decoding raw JSON fields.
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- raw JSON must be decoded before validating database cell values.
            $value = wp_unslash($_GET[$key]);
        } else {
            return $default;
        }

        if (is_array($value)) {
            throw new \InvalidArgumentException('Invalid request payload.');
        }

        return (string) $value;
    }
}
