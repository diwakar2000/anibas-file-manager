<?php

namespace Anibas;

if (! defined('ABSPATH')) exit;

/**
 * Database explorer queries and tightly scoped row edits.
 */
class DatabaseManager
{
    private const DEFAULT_PAGE_SIZE = 50;
    private const MAX_PAGE_SIZE = 100;
    private const MAX_OFFSET = 100000;
    private const MAX_CELL_RESPONSE_BYTES = 65536;

    public function list_scopes(): array
    {
        global $wpdb;

        $scopes = [
            [
                'id' => 'current',
                'label' => is_multisite() ? sprintf('Current site #%d', get_current_blog_id()) : 'Current site',
                'type' => 'site',
                'blog_id' => get_current_blog_id(),
                'prefix' => $wpdb->prefix,
                'current' => true,
            ],
        ];

        if (is_multisite() && current_user_can('manage_network_options')) {
            $scopes[] = [
                'id' => 'network',
                'label' => 'Network/global tables',
                'type' => 'network',
                'blog_id' => 0,
                'prefix' => $wpdb->base_prefix,
                'current' => false,
            ];
        }

        return $scopes;
    }

    public function list_tables(string $scope_id = 'current'): array
    {
        global $wpdb;

        $scope = $this->resolve_scope($scope_id);
        $like = $wpdb->esc_like($scope['prefix']) . '%';
        $sql = $wpdb->prepare('SHOW TABLE STATUS LIKE %s', $like);
        $rows = $wpdb->get_results($sql, ARRAY_A);

        if (! is_array($rows)) {
            throw new \RuntimeException($wpdb->last_error ?: 'Failed to list database tables');
        }

        $tables = [];
        foreach ($rows as $row) {
            $name = (string) ($row['Name'] ?? '');
            if ($name === '' || ! DatabaseSafetyPolicy::is_safe_identifier($name)) {
                continue;
            }

            $classification = DatabaseSafetyPolicy::classify_table($name, $wpdb->prefix, $wpdb->base_prefix);
            if ($scope_id === 'current' && $classification['scope'] !== 'site') {
                continue;
            }
            if ($scope_id === 'network' && $classification['scope'] !== 'network') {
                continue;
            }

            $tables[] = [
                'name' => $name,
                'slug' => $classification['slug'],
                'scope' => $classification['scope'],
                'risk' => $classification['risk'],
                'is_core' => $classification['is_core'],
                'is_user_table' => $classification['is_user_table'],
                'is_options_table' => $classification['is_options_table'],
                'engine' => $this->string_or_null($row['Engine'] ?? null),
                'rows_estimate' => isset($row['Rows']) ? (int) $row['Rows'] : null,
                'data_length' => isset($row['Data_length']) ? (int) $row['Data_length'] : null,
                'index_length' => isset($row['Index_length']) ? (int) $row['Index_length'] : null,
                'collation' => $this->string_or_null($row['Collation'] ?? null),
                'comment' => $this->string_or_null($row['Comment'] ?? null),
            ];
        }

        usort($tables, function (array $a, array $b): int {
            if ($a['is_core'] !== $b['is_core']) {
                return $a['is_core'] ? -1 : 1;
            }
            return strnatcasecmp($a['name'], $b['name']);
        });

        return [
            'scope' => $scope,
            'tables' => $tables,
        ];
    }

    public function get_schema(string $table, string $scope_id = 'current'): array
    {
        $table_info = $this->assert_table_allowed($table, $scope_id);
        $scope = $table_info['scope'];
        $status = $table_info['status'];
        $quoted = DatabaseSafetyPolicy::quote_identifier($table);

        global $wpdb;
        $columns_raw = $wpdb->get_results('DESCRIBE ' . $quoted, ARRAY_A);
        if (! is_array($columns_raw)) {
            throw new \RuntimeException($wpdb->last_error ?: 'Failed to read table schema');
        }

        $indexes_raw = $wpdb->get_results('SHOW INDEX FROM ' . $quoted, ARRAY_A);
        if (! is_array($indexes_raw)) {
            throw new \RuntimeException($wpdb->last_error ?: 'Failed to read table indexes');
        }

        $columns = array_map(function (array $row) use ($table): array {
            return $this->normalize_column($row, $table);
        }, $columns_raw);
        $indexes = $this->normalize_indexes($indexes_raw);
        $primary_key = $this->primary_key_columns($columns);
        $classification = DatabaseSafetyPolicy::classify_table($table, $wpdb->prefix, $wpdb->base_prefix);
        $edit_enabled = anibas_fm_database_edit_enabled();
        $edit_block_reason = DatabaseSafetyPolicy::edit_block_reason($classification, $primary_key);
        $insert_block_reason = DatabaseSafetyPolicy::insert_block_reason($classification);
        $columns = $this->annotate_columns($columns, $primary_key, $table);

        return [
            'scope' => $scope,
            'table' => [
                'name' => $table,
                'slug' => $classification['slug'],
                'scope' => $classification['scope'],
                'risk' => $classification['risk'],
                'is_core' => $classification['is_core'],
                'is_user_table' => $classification['is_user_table'],
                'is_options_table' => $classification['is_options_table'],
                'engine' => $this->string_or_null($status['Engine'] ?? null),
                'rows_estimate' => isset($status['Rows']) ? (int) $status['Rows'] : null,
                'data_length' => isset($status['Data_length']) ? (int) $status['Data_length'] : null,
                'index_length' => isset($status['Index_length']) ? (int) $status['Index_length'] : null,
                'collation' => $this->string_or_null($status['Collation'] ?? null),
                'comment' => $this->string_or_null($status['Comment'] ?? null),
            ],
            'columns' => $columns,
            'indexes' => $indexes,
            'primary_key' => $primary_key,
            'read_only' => ! ($edit_enabled && $edit_block_reason === null),
            'can_edit_rows' => $edit_enabled && $edit_block_reason === null,
            'can_insert_rows' => $edit_enabled && $insert_block_reason === null,
            'edit_block_reason' => $edit_enabled ? $edit_block_reason : null,
            'insert_block_reason' => $edit_enabled ? $insert_block_reason : null,
        ];
    }

    public function get_rows(string $table, string $scope_id = 'current', array $args = []): array
    {
        $schema = $this->get_schema($table, $scope_id);
        $columns = $schema['columns'];
        $column_meta = DatabaseSafetyPolicy::columns_by_name($columns);
        $page_size = isset($args['page_size']) ? (int) $args['page_size'] : self::DEFAULT_PAGE_SIZE;
        $page_size = min(self::MAX_PAGE_SIZE, max(1, $page_size));
        $fetch_size = $page_size + 1;
        $quoted = DatabaseSafetyPolicy::quote_identifier($table);
        $page = isset($args['page']) ? max(1, (int) $args['page']) : 1;
        $total_rows_estimate = $schema['table']['rows_estimate'];
        $total_pages_estimate = is_int($total_rows_estimate)
            ? max(1, (int) ceil(max(0, $total_rows_estimate) / $page_size))
            : null;
        $max_offset_page = (int) floor(self::MAX_OFFSET / $page_size) + 1;
        $max_page = $total_pages_estimate === null ? $max_offset_page : min($total_pages_estimate, $max_offset_page);
        $max_page = max(1, $max_page);
        $is_limited = $total_pages_estimate !== null && $total_pages_estimate > $max_offset_page;
        $page = min($page, $max_page);
        $offset = ($page - 1) * $page_size;

        global $wpdb;

        if ($offset > self::MAX_OFFSET) {
            throw new \RuntimeException('Deep database paging is limited to avoid slow table scans.');
        }

        $order_columns = $this->order_columns($schema);
        $order_sql = '';
        if (! empty($order_columns)) {
            $order_parts = array_map(static function (string $column): string {
                return DatabaseSafetyPolicy::quote_identifier($column) . ' ASC';
            }, $order_columns);
            $order_sql = ' ORDER BY ' . implode(', ', $order_parts);
        }

        $projection = $this->safe_select_projection($columns);
        $sql = 'SELECT ' . $projection['select_sql'] . ' FROM ' . $quoted . $order_sql . ' LIMIT ' . (int) $fetch_size . ' OFFSET ' . (int) $offset;
        $rows = $this->fetch_projected_rows_unbuffered($sql, $projection['size_aliases']);

        $has_more = count($rows) > $page_size;
        $rows = array_slice($rows, 0, $page_size);
        if ($has_more && $max_page <= $page && $page < $max_offset_page) {
            $max_page = $page + 1;
        }

        return [
            'schema' => $schema,
            'rows' => $this->redact_rows($table, $rows, $column_meta),
            'pagination' => [
                'mode' => 'numbered',
                'page' => $page,
                'page_size' => $page_size,
                'has_more' => $has_more,
                'total_rows_estimate' => $total_rows_estimate,
                'total_pages_estimate' => $total_pages_estimate,
                'max_page' => $max_page,
                'offset_limit' => self::MAX_OFFSET,
                'is_limited' => $is_limited,
            ],
        ];
    }

    public function update_cell(string $table, string $scope_id, array $row_key, string $column, $old_value, $new_value): array
    {
        $schema = $this->get_schema($table, $scope_id);
        $this->assert_rows_editable($schema);

        $columns = DatabaseSafetyPolicy::columns_by_name($schema['columns']);
        if (! isset($columns[$column]) || empty($columns[$column]['editable'])) {
            throw new \InvalidArgumentException('This column cannot be edited.');
        }

        $normalized_key = $this->normalize_row_key($row_key, $schema['primary_key']);
        $current = $this->fetch_row_by_key($table, $normalized_key, $schema['columns']);
        if (! $current) {
            throw new \RuntimeException('The row no longer exists.');
        }

        $redacted = DatabaseSafetyPolicy::redact_row($table, $current, $columns);
        $current_value = $redacted[$column] ?? null;
        if (! DatabaseSafetyPolicy::response_value_is_editable($current_value)) {
            throw new \InvalidArgumentException('This value cannot be edited from the table view.');
        }

        if ($current_value !== $old_value) {
            throw new \RuntimeException('This value changed after it was loaded. Refresh the table and try again.');
        }

        global $wpdb;
        $where = $normalized_key;
        $where[$column] = $old_value;
        $result = $wpdb->update($table, [$column => $this->normalize_value($new_value)], $where);
        if ($result === false) {
            throw new \RuntimeException($wpdb->last_error ?: 'Failed to update row');
        }

        return [
            'affected' => (int) $result,
        ];
    }

    public function delete_row(string $table, string $scope_id, array $row_key): array
    {
        $schema = $this->get_schema($table, $scope_id);
        $this->assert_rows_editable($schema);
        $delete_block_reason = DatabaseSafetyPolicy::delete_block_reason($schema['table']);
        if ($delete_block_reason !== null) {
            throw new \InvalidArgumentException($delete_block_reason);
        }

        $normalized_key = $this->normalize_row_key($row_key, $schema['primary_key']);

        if (! $this->row_exists_by_key($table, $normalized_key)) {
            throw new \RuntimeException('The row no longer exists.');
        }

        global $wpdb;
        $result = $wpdb->delete($table, $normalized_key);
        if ($result === false) {
            throw new \RuntimeException($wpdb->last_error ?: 'Failed to delete row');
        }

        return [
            'affected' => (int) $result,
        ];
    }

    public function insert_row(string $table, string $scope_id, array $values): array
    {
        $schema = $this->get_schema($table, $scope_id);
        if (empty($schema['can_insert_rows'])) {
            throw new \InvalidArgumentException($schema['insert_block_reason'] ?: 'Rows cannot be added to this table.');
        }

        $columns = DatabaseSafetyPolicy::columns_by_name($schema['columns']);
        $data = [];
        foreach ($values as $column => $value) {
            if (! is_string($column) || ! isset($columns[$column]) || empty($columns[$column]['insertable'])) {
                throw new \InvalidArgumentException('One or more submitted columns cannot be inserted.');
            }

            $data[$column] = $this->normalize_value($value);
        }

        if (empty($data)) {
            throw new \InvalidArgumentException('Enter at least one value before adding a row.');
        }

        $redacted = DatabaseSafetyPolicy::redact_row($table, $data, $columns);
        foreach ($redacted as $column => $value) {
            if (! DatabaseSafetyPolicy::response_value_is_editable($value)) {
                throw new \InvalidArgumentException('Sensitive or binary values cannot be inserted from the table view.');
            }
        }

        global $wpdb;
        $result = $wpdb->insert($table, $data);
        if ($result === false) {
            throw new \RuntimeException($wpdb->last_error ?: 'Failed to insert row');
        }

        return [
            'affected' => (int) $result,
            'insert_id' => (int) $wpdb->insert_id,
        ];
    }

    private function resolve_scope(string $scope_id): array
    {
        foreach ($this->list_scopes() as $scope) {
            if ($scope['id'] === $scope_id) {
                return $scope;
            }
        }

        throw new \InvalidArgumentException('Invalid database scope');
    }

    private function assert_table_allowed(string $table, string $scope_id): array
    {
        if (! DatabaseSafetyPolicy::is_safe_identifier($table)) {
            throw new \InvalidArgumentException('Invalid table name');
        }

        $scope = $this->resolve_scope($scope_id);
        if (strpos($table, $scope['prefix']) !== 0) {
            throw new \InvalidArgumentException('Table is outside the selected database scope');
        }

        global $wpdb;
        $sql = $wpdb->prepare('SHOW TABLE STATUS LIKE %s', $wpdb->esc_like($table));
        $row = $wpdb->get_row($sql, ARRAY_A);
        if (! $row) {
            throw new \InvalidArgumentException('Table not found');
        }

        return [
            'scope' => $scope,
            'status' => $row,
        ];
    }

    private function normalize_column(array $row, string $table): array
    {
        $name = (string) ($row['Field'] ?? '');
        $type = (string) ($row['Type'] ?? '');
        $nullable = strtoupper((string) ($row['Null'] ?? '')) === 'YES';
        $key = (string) ($row['Key'] ?? '');

        return [
            'name' => $name,
            'type' => $type,
            'nullable' => $nullable,
            'key' => $key,
            'default' => array_key_exists('Default', $row) && $row['Default'] !== null ? (string) $row['Default'] : null,
            'extra' => (string) ($row['Extra'] ?? ''),
            'redacted' => $this->column_is_redacted($table, $name),
        ];
    }

    private function normalize_indexes(array $rows): array
    {
        $indexes = [];
        foreach ($rows as $row) {
            $name = (string) ($row['Key_name'] ?? '');
            if ($name === '') {
                continue;
            }

            if (! isset($indexes[$name])) {
                $indexes[$name] = [
                    'name' => $name,
                    'unique' => isset($row['Non_unique']) ? ((int) $row['Non_unique'] === 0) : false,
                    'primary' => $name === 'PRIMARY',
                    'columns' => [],
                ];
            }

            $indexes[$name]['columns'][] = [
                'name' => (string) ($row['Column_name'] ?? ''),
                'sequence' => isset($row['Seq_in_index']) ? (int) $row['Seq_in_index'] : count($indexes[$name]['columns']) + 1,
                'sub_part' => isset($row['Sub_part']) ? (int) $row['Sub_part'] : null,
            ];
        }

        return array_values($indexes);
    }

    private function primary_key_columns(array $columns): array
    {
        $primary = [];
        foreach ($columns as $column) {
            if (($column['key'] ?? '') === 'PRI') {
                $primary[] = $column['name'];
            }
        }
        return $primary;
    }

    private function annotate_columns(array $columns, array $primary_key, string $table): array
    {
        return array_map(static function (array $column) use ($primary_key, $table): array {
            $name = (string) ($column['name'] ?? '');
            $is_primary = in_array($name, $primary_key, true);
            $column['editable'] = DatabaseSafetyPolicy::can_edit_column($column, $table) && ! $is_primary;
            $column['insertable'] = DatabaseSafetyPolicy::can_insert_column($column, $table);
            return $column;
        }, $columns);
    }

    private function order_columns(array $schema): array
    {
        if (! empty($schema['primary_key'])) {
            return $schema['primary_key'];
        }

        $fallback = $this->fallback_order_column($schema['columns']);
        return $fallback ? [$fallback] : [];
    }

    private function fallback_order_column(array $columns): ?string
    {
        foreach ($columns as $column) {
            if (($column['key'] ?? '') === 'UNI') {
                return $column['name'];
            }
        }
        return $columns[0]['name'] ?? null;
    }

    private function assert_rows_editable(array $schema): void
    {
        if (empty($schema['can_edit_rows'])) {
            throw new \InvalidArgumentException($schema['edit_block_reason'] ?: 'Rows cannot be edited in this table.');
        }
    }

    private function normalize_row_key(array $row_key, array $primary_key): array
    {
        if (empty($primary_key)) {
            throw new \InvalidArgumentException('Rows need a primary key before they can be edited safely.');
        }

        $normalized = [];
        foreach ($primary_key as $column) {
            if (! array_key_exists($column, $row_key)) {
                throw new \InvalidArgumentException('The selected row key is incomplete.');
            }

            $normalized[$column] = $this->normalize_value($row_key[$column]);
        }

        return $normalized;
    }

    private function fetch_row_by_key(string $table, array $row_key, array $columns): ?array
    {
        global $wpdb;

        [$where_sql, $params] = $this->where_sql_for_key($row_key);
        $projection = $this->safe_select_projection($columns);
        $sql = 'SELECT ' . $projection['select_sql'] . ' FROM ' . DatabaseSafetyPolicy::quote_identifier($table) . ' WHERE ' . $where_sql . ' LIMIT 1';
        if (! empty($params)) {
            $sql = $wpdb->prepare($sql, $params);
        }

        $rows = $this->fetch_projected_rows_unbuffered($sql, $projection['size_aliases']);
        return $rows[0] ?? null;
    }

    private function row_exists_by_key(string $table, array $row_key): bool
    {
        global $wpdb;

        [$where_sql, $params] = $this->where_sql_for_key($row_key);
        $sql = 'SELECT 1 AS anfm_exists FROM ' . DatabaseSafetyPolicy::quote_identifier($table) . ' WHERE ' . $where_sql . ' LIMIT 1';
        if (! empty($params)) {
            $sql = $wpdb->prepare($sql, $params);
        }

        $result = $this->query_unbuffered($sql);
        try {
            return $result->fetch_assoc() !== null;
        } finally {
            $result->free();
        }
    }

    private function where_sql_for_key(array $row_key): array
    {
        $clauses = [];
        $params = [];
        foreach ($row_key as $column => $value) {
            $quoted = DatabaseSafetyPolicy::quote_identifier($column);
            if ($value === null) {
                $clauses[] = $quoted . ' IS NULL';
            } else {
                $clauses[] = $quoted . ' = %s';
                $params[] = (string) $value;
            }
        }

        if (empty($clauses)) {
            throw new \InvalidArgumentException('The selected row key is incomplete.');
        }

        return [implode(' AND ', $clauses), $params];
    }

    private function normalize_value($value)
    {
        if ($value === null) {
            return null;
        }

        if (! is_scalar($value)) {
            throw new \InvalidArgumentException('Database values must be scalar.');
        }

        return (string) $value;
    }

    private function redact_rows(string $table, array $rows, array $column_meta): array
    {
        return array_map(static function (array $row) use ($table, $column_meta): array {
            return DatabaseSafetyPolicy::redact_row($table, $row, $column_meta);
        }, $rows);
    }

    /**
     * @param array<int,array<string,mixed>> $columns
     * @return array{select_sql:string,size_aliases:array<string,string>}
     */
    private function safe_select_projection(array $columns): array
    {
        $select = [];
        $size_aliases = [];
        $used_aliases = [];

        foreach ($columns as $column) {
            $name = (string) ($column['name'] ?? '');
            if ($name !== '') {
                $used_aliases[$name] = true;
            }
        }

        foreach ($columns as $index => $column) {
            $name = (string) ($column['name'] ?? '');
            if ($name === '' || ! DatabaseSafetyPolicy::is_safe_identifier($name)) {
                continue;
            }

            $alias = $this->unique_internal_alias('anfm_size_' . (string) $index, $used_aliases);
            $quoted = DatabaseSafetyPolicy::quote_identifier($name);
            $quoted_alias = DatabaseSafetyPolicy::quote_identifier($alias);
            $size_expression = 'COALESCE(OCTET_LENGTH(' . $quoted . '), 0)';

            $select[] = $size_expression . ' AS ' . $quoted_alias;
            $select[] = 'CASE WHEN ' . $size_expression . ' > ' . self::MAX_CELL_RESPONSE_BYTES
                . ' THEN NULL ELSE ' . $quoted . ' END AS ' . $quoted;
            $size_aliases[$alias] = $name;
        }

        if (empty($select)) {
            throw new \RuntimeException('This table has no readable columns.');
        }

        return [
            'select_sql' => implode(', ', $select),
            'size_aliases' => $size_aliases,
        ];
    }

    /**
     * @param array<string,string> $size_aliases
     * @return array<int,array<string,mixed>>
     */
    private function fetch_projected_rows_unbuffered(string $sql, array $size_aliases): array
    {
        $result = $this->query_unbuffered($sql);
        $rows = [];
        try {
            while (($row = $result->fetch_assoc()) !== null) {
                $rows[] = $this->strip_projection_metadata($row, $size_aliases);
            }
        } finally {
            $result->free();
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,string> $size_aliases
     * @return array<string,mixed>
     */
    private function strip_projection_metadata(array $row, array $size_aliases): array
    {
        foreach ($size_aliases as $alias => $column) {
            $bytes = max(0, (int) ($row[$alias] ?? 0));
            unset($row[$alias]);
            if ($bytes > self::MAX_CELL_RESPONSE_BYTES) {
                $row[$column] = '[value too large]';
            }
        }

        return $row;
    }

    /**
     * @param array<string,bool> $used_aliases
     */
    private function unique_internal_alias(string $base, array &$used_aliases): string
    {
        $alias = $base;
        $suffix = 1;
        while (isset($used_aliases[$alias])) {
            $alias = substr($base, 0, 58) . '_' . (string) $suffix;
            $suffix++;
        }

        $used_aliases[$alias] = true;
        return $alias;
    }

    private function query_unbuffered(string $sql): \mysqli_result
    {
        global $wpdb;

        $dbh = $wpdb->dbh ?? null;
        if (! $dbh instanceof \mysqli) {
            throw new \RuntimeException('The active WordPress database driver does not support memory-safe unbuffered database reads.');
        }

        try {
            $result = @mysqli_query($dbh, $sql, MYSQLI_USE_RESULT);
        } catch (\Throwable $e) {
            throw new \RuntimeException($e->getMessage());
        }

        if (! $result instanceof \mysqli_result) {
            $message = mysqli_error($dbh);
            throw new \RuntimeException($message !== '' ? $message : 'Failed to open memory-safe database row stream.');
        }

        return $result;
    }

    private function column_is_redacted(string $table, string $name): bool
    {
        $probe = DatabaseSafetyPolicy::redact_row($table, [$name => 'x'], [$name => ['type' => 'varchar(255)']]);
        return ($probe[$name] ?? null) === '[redacted]';
    }

    private function string_or_null($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (string) $value;
    }
}
