<?php
declare(strict_types=1);

namespace Anibas;

if (! defined('ABSPATH')) exit;

/**
 * Chunked WordPress database backup groundwork.
 *
 * The engine writes a small manifest plus one JSONL row stream per table. It
 * never keeps a full table in PHP memory and advances by primary-key keyset
 * pagination when possible, falling back to small LIMIT/OFFSET batches only
 * for tables without a usable key.
 */
class DatabaseBackupEngine
{
    public const FORMAT = 'anibas-database-backup';
    public const FORMAT_VERSION = 1;

    private const DEFAULT_BATCH_ROWS = 200;
    private const UNKNOWN_MEMORY_ROW_LIMIT = 1048576;

    private \wpdb $wpdb;
    private string $job_id;
    private string $state_dir;
    private string $rows_dir;
    private string $manifest_file;
    private string $state_file;
    private string $lock_file;
    private int $time_budget;
    private int $batch_rows;
    private int $row_byte_limit;

    public function __construct(string $job_id, string $state_dir, int $batch_rows = self::DEFAULT_BATCH_ROWS)
    {
        global $wpdb;

        if (! isset($wpdb) || ! $wpdb instanceof \wpdb) {
            throw new \RuntimeException('WordPress database connection is not available.');
        }

        $this->wpdb = $wpdb;
        $this->job_id = $this->sanitize_job_id($job_id);
        $this->state_dir = untrailingslashit(wp_normalize_path($state_dir));
        $this->rows_dir = $this->state_dir . '/rows';
        $this->manifest_file = $this->state_dir . '/manifest.json';
        $this->state_file = $this->state_dir . '/state.json';
        $this->lock_file = $this->state_dir . '/lock';
        $this->time_budget = $this->default_time_budget();
        $this->batch_rows = max(1, min(1000, $batch_rows));
        $this->row_byte_limit = $this->safe_row_byte_limit();

        $this->ensure_directory($this->state_dir);
        $this->ensure_directory($this->rows_dir);
        anibas_fm_protect_dir($this->state_dir);
    }

    public function initialize(string $scope = 'current'): array
    {
        $scope = $this->normalize_scope($scope);
        $manifest = $this->create_manifest($scope);
        $state = [
            'job_id' => $this->job_id,
            'scope' => $scope,
            'phase' => 'export',
            'current_table_index' => 0,
            'offset' => 0,
            'last_key' => null,
            'rows_exported' => 0,
            'started_at' => time(),
            'updated_at' => time(),
        ];

        $this->save_json($this->manifest_file, $manifest);
        $this->save_json($this->state_file, $state);

        return $this->progress($manifest, $state);
    }

    public function run_step(): array
    {
        $lock = $this->acquire_lock();
        try {
            $manifest = $this->load_manifest();
            $state = $this->load_state();
            if (($state['phase'] ?? '') === 'complete') {
                return $this->progress($manifest, $state);
            }

            $started = microtime(true);
            while ((microtime(true) - $started) < $this->time_budget) {
                $table_index = (int) ($state['current_table_index'] ?? 0);
                if (! isset($manifest['tables'][$table_index]) || ! is_array($manifest['tables'][$table_index])) {
                    $state['phase'] = 'complete';
                    $manifest['completed_at'] = time();
                    $manifest['package_size'] = $this->directory_size($this->state_dir);
                    $state['updated_at'] = time();
                    $this->save_json($this->manifest_file, $manifest);
                    $this->save_json($this->state_file, $state);
                    return $this->progress($manifest, $state);
                }

                $rows_before = (int) ($state['rows_exported'] ?? 0);
                $table_complete = $this->export_table_batch($manifest, $state, $started);
                $state['updated_at'] = time();
                $this->save_json($this->manifest_file, $manifest);
                $this->save_json($this->state_file, $state);

                if (! $table_complete
                    && ((microtime(true) - $started) >= $this->time_budget || (int) ($state['rows_exported'] ?? 0) === $rows_before)
                ) {
                    break;
                }
            }

            return $this->progress($manifest, $state);
        } finally {
            $this->release_lock($lock);
        }
    }

    public function manifest_path(): string
    {
        return $this->manifest_file;
    }

    public function state_path(): string
    {
        return $this->state_file;
    }

    public function cleanup(bool $remove_manifest = false): void
    {
        foreach ([$this->lock_file, $this->state_file, $this->state_file . '.tmp'] as $file) {
            if (is_file($file)) {
                wp_delete_file($file);
            }
        }

        if ($remove_manifest) {
            $this->delete_tree($this->state_dir);
        }
    }

    /**
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $state
     */
    private function export_table_batch(array &$manifest, array &$state, float $started): bool
    {
        $table_index = (int) ($state['current_table_index'] ?? 0);
        $table = $manifest['tables'][$table_index];
        $table_name = (string) ($table['name'] ?? '');
        if ($table_name === '' || ! DatabaseSafetyPolicy::is_safe_identifier($table_name)) {
            throw new \RuntimeException('Invalid table name in database backup manifest.');
        }

        $rows_file = $this->state_dir . '/' . ltrim((string) ($table['rows_file'] ?? ''), '/');
        $this->assert_path_inside($rows_file, $this->rows_dir);
        $this->align_rows_file_to_manifest($rows_file, (int) ($table['rows_file_size'] ?? 0));

        $refs = $this->fetch_row_refs($table, $state);
        if (empty($refs)) {
            $manifest['tables'][$table_index]['complete'] = true;
            clearstatcache(true, $rows_file);
            $manifest['tables'][$table_index]['rows_file_size'] = is_file($rows_file) ? (int) filesize($rows_file) : 0;
            $manifest['tables'][$table_index]['rows_exported'] = (int) ($table['rows_exported'] ?? 0);
            $state['current_table_index'] = $table_index + 1;
            $state['offset'] = 0;
            $state['last_key'] = null;
            return true;
        }

        $handle = @fopen($rows_file, 'ab');
        if (! $handle) {
            throw new \RuntimeException('Failed to open database backup row stream.');
        }

        $exported = 0;
        $paused_for_memory = false;
        foreach ($refs as $ref) {
            if ((microtime(true) - $started) >= $this->time_budget) {
                break;
            }

            $row_bytes = max(0, (int) ($ref['__anfm_row_bytes'] ?? 0));
            if ($row_bytes > $this->row_byte_limit) {
                fclose($handle);
                throw new \RuntimeException(sprintf(
                    'Database row in %s is %s before encoding, which exceeds the memory-safe backup limit of %s for this PHP process.',
                    $table_name,
                    $this->format_bytes($row_bytes),
                    $this->format_bytes($this->row_byte_limit)
                ));
            }

            if (! $this->has_memory_headroom_for_row($row_bytes)) {
                $paused_for_memory = true;
                break;
            }

            $row = $this->fetch_single_row($table, $ref, (int) ($state['offset'] ?? 0));
            if ($row === null) {
                break;
            }

            $line = DatabaseBackupCodec::encode_row($row);
            $written = fwrite($handle, $line);
            if ($written === false || $written !== strlen($line)) {
                fclose($handle);
                throw new \RuntimeException('Failed to write database backup row.');
            }

            $exported++;
            $state['rows_exported'] = (int) ($state['rows_exported'] ?? 0) + 1;

            if (! empty($table['keyset_columns']) && is_array($table['keyset_columns'])) {
                $state['last_key'] = $this->row_key_values($row, $table['keyset_columns']);
            } else {
                $state['offset'] = (int) ($state['offset'] ?? 0) + 1;
            }
        }

        fclose($handle);

        if ($exported === 0 && $paused_for_memory) {
            throw new \RuntimeException('Available PHP memory is too low to safely fetch the next database row for backup.');
        }

        $manifest['tables'][$table_index]['rows_exported'] = (int) ($table['rows_exported'] ?? 0) + $exported;
        clearstatcache(true, $rows_file);
        $manifest['tables'][$table_index]['rows_file_size'] = (int) filesize($rows_file);

        if ($exported === count($refs) && count($refs) < $this->batch_rows) {
            $manifest['tables'][$table_index]['complete'] = true;
            $state['current_table_index'] = $table_index + 1;
            $state['offset'] = 0;
            $state['last_key'] = null;
            return true;
        }

        return false;
    }

    /**
     * @param array<string,mixed> $table
     * @param array<string,mixed> $state
     * @return array<int,array<string,mixed>>
     */
    private function fetch_row_refs(array $table, array $state): array
    {
        $table_name = (string) $table['name'];
        $quoted = DatabaseSafetyPolicy::quote_identifier($table_name);
        $order_columns = $table['keyset_columns'] ?? [];
        $size_expression = $this->row_size_expression($table);
        $where = '';
        $params = [];

        if (is_array($order_columns) && ! empty($order_columns)) {
            $select_columns = implode(', ', array_map(static function (string $column): string {
                return DatabaseSafetyPolicy::quote_identifier($column);
            }, $order_columns));
            $order = implode(', ', array_map(static function (string $column): string {
                return DatabaseSafetyPolicy::quote_identifier($column) . ' ASC';
            }, $order_columns));

            if (! empty($state['last_key']) && is_array($state['last_key'])) {
                [$where, $params] = $this->keyset_where_sql($order_columns, $state['last_key']);
            }

            $sql = 'SELECT ' . $select_columns . ', ' . $size_expression . ' AS __anfm_row_bytes FROM ' . $quoted . $where . ' ORDER BY ' . $order . ' LIMIT ' . $this->batch_rows;
        } else {
            $offset = max(0, (int) ($state['offset'] ?? 0));
            $sql = 'SELECT ' . $size_expression . ' AS __anfm_row_bytes FROM ' . $quoted . ' LIMIT ' . $this->batch_rows . ' OFFSET ' . $offset;
        }

        if (! empty($params)) {
            $sql = $this->wpdb->prepare($sql, $params);
        }

        $result = $this->query_unbuffered($sql);
        $refs = [];
        try {
            while (($row = $result->fetch_assoc()) !== null) {
                $refs[] = $row;
            }
        } finally {
            $result->free();
        }

        return $refs;
    }

    /**
     * @param array<string,mixed> $table
     * @param array<string,mixed> $ref
     * @return array<string,mixed>|null
     */
    private function fetch_single_row(array $table, array $ref, int $offset): ?array
    {
        $table_name = (string) $table['name'];
        $quoted = DatabaseSafetyPolicy::quote_identifier($table_name);
        $order_columns = $table['keyset_columns'] ?? [];
        $params = [];

        if (is_array($order_columns) && ! empty($order_columns)) {
            $clauses = [];
            foreach ($order_columns as $column) {
                $quoted_column = DatabaseSafetyPolicy::quote_identifier((string) $column);
                if (! array_key_exists((string) $column, $ref) || $ref[(string) $column] === null) {
                    $clauses[] = $quoted_column . ' IS NULL';
                    continue;
                }
                $clauses[] = $quoted_column . ' <=> %s';
                $params[] = (string) $ref[(string) $column];
            }
            $sql = 'SELECT * FROM ' . $quoted . ' WHERE ' . implode(' AND ', $clauses) . ' LIMIT 1';
        } else {
            $sql = 'SELECT * FROM ' . $quoted . ' LIMIT 1 OFFSET ' . max(0, $offset);
        }

        if (! empty($params)) {
            $sql = $this->wpdb->prepare($sql, $params);
        }

        $result = $this->query_unbuffered($sql);
        try {
            $row = $result->fetch_assoc();
            return is_array($row) ? $row : null;
        } finally {
            $result->free();
        }
    }

    /**
     * @param array<int,string> $columns
     * @param array<string,mixed> $last_key
     * @return array{0:string,1:array<int,string>}
     */
    private function keyset_where_sql(array $columns, array $last_key): array
    {
        $clauses = [];
        $params = [];
        $prefix = [];

        foreach ($columns as $column) {
            if (! array_key_exists($column, $last_key)) {
                return ['', []];
            }

            $quoted = DatabaseSafetyPolicy::quote_identifier($column);
            $parts = $prefix;
            $parts[] = $quoted . ' > %s';
            $clauses[] = '(' . implode(' AND ', $parts) . ')';

            foreach ($columns as $prefix_column) {
                if ($prefix_column === $column) {
                    break;
                }
                $params[] = (string) $last_key[$prefix_column];
            }
            $params[] = (string) $last_key[$column];
            $prefix[] = $quoted . ' = %s';
        }

        return [' WHERE ' . implode(' OR ', $clauses), $params];
    }

    /**
     * @param array<string,mixed> $row
     * @param array<int,string> $columns
     * @return array<string,string>
     */
    private function row_key_values(array $row, array $columns): array
    {
        $values = [];
        foreach ($columns as $column) {
            $values[$column] = (string) ($row[$column] ?? '');
        }
        return $values;
    }

    /**
     * @param array<string,mixed> $table
     */
    private function row_size_expression(array $table): string
    {
        $columns = is_array($table['columns'] ?? null) ? $table['columns'] : [];
        $parts = [];
        foreach ($columns as $column) {
            if (! is_array($column)) {
                continue;
            }
            $name = (string) ($column['name'] ?? '');
            if ($name === '' || ! DatabaseSafetyPolicy::is_safe_identifier($name)) {
                continue;
            }
            $parts[] = 'COALESCE(OCTET_LENGTH(' . DatabaseSafetyPolicy::quote_identifier($name) . '), 0)';
        }

        return empty($parts) ? '0' : implode(' + ', $parts);
    }

    private function query_unbuffered(string $sql): \mysqli_result
    {
        $dbh = $this->wpdb->dbh ?? null;
        if (! $dbh instanceof \mysqli) {
            throw new \RuntimeException('The active WordPress database driver does not support memory-safe unbuffered backup reads.');
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

    private function safe_row_byte_limit(): int
    {
        $memory = function_exists('anibas_fm_memory_headroom')
            ? anibas_fm_memory_headroom()
            : ['known' => false, 'available' => null];

        if (empty($memory['known'])) {
            return self::UNKNOWN_MEMORY_ROW_LIMIT;
        }

        $available = max(0, (int) ($memory['available'] ?? 0));
        $limit = (int) floor($available / 12);
        return max(262144, min(16 * 1024 * 1024, $limit));
    }

    private function has_memory_headroom_for_row(int $row_bytes): bool
    {
        $memory = function_exists('anibas_fm_memory_headroom')
            ? anibas_fm_memory_headroom()
            : ['known' => false, 'available' => null];

        if (empty($memory['known'])) {
            return true;
        }

        $available = max(0, (int) ($memory['available'] ?? 0));
        $needed = max(8 * 1024 * 1024, $row_bytes * 8);
        return $available > $needed;
    }

    private function align_rows_file_to_manifest(string $rows_file, int $expected_size): void
    {
        clearstatcache(true, $rows_file);
        if (! is_file($rows_file)) {
            if ($expected_size > 0) {
                throw new \RuntimeException('Database backup row stream is missing.');
            }
            return;
        }

        $actual_size = (int) filesize($rows_file);
        if ($actual_size === $expected_size) {
            return;
        }
        if ($actual_size < $expected_size) {
            throw new \RuntimeException('Database backup row stream is smaller than its saved manifest state.');
        }

        $handle = @fopen($rows_file, 'c+b');
        if (! $handle) {
            throw new \RuntimeException('Failed to repair database backup row stream.');
        }
        try {
            if (! ftruncate($handle, $expected_size)) {
                throw new \RuntimeException('Failed to trim database backup row stream to its saved state.');
            }
        } finally {
            fclose($handle);
        }
    }

    private function format_bytes(int $bytes): string
    {
        return function_exists('size_format')
            ? size_format($bytes)
            : number_format($bytes) . ' bytes';
    }

    /**
     * @return array<string,mixed>
     */
    private function create_manifest(string $scope): array
    {
        $tables = [];
        foreach ($this->list_tables_for_scope($scope) as $index => $status) {
            $name = (string) ($status['Name'] ?? '');
            if ($name === '' || ! DatabaseSafetyPolicy::is_safe_identifier($name)) {
                continue;
            }

            $engine = (string) ($status['Engine'] ?? '');
            if ($engine === '') {
                continue;
            }

            $create_sql = $this->show_create_table($name);
            $columns = $this->describe_table($name);
            $keyset_columns = $this->keyset_columns($name, $columns);
            $classification = DatabaseSafetyPolicy::classify_table($name, (string) $this->wpdb->prefix, (string) $this->wpdb->base_prefix);
            $rows_file = 'rows/' . str_pad((string) $index, 5, '0', STR_PAD_LEFT) . '-' . md5($name) . '.jsonl';
            $slug = (string) ($classification['slug'] ?? $name);

            $tables[] = [
                'name' => $name,
                'slug' => $slug,
                'scope' => (string) ($classification['scope'] ?? 'other'),
                'engine' => $engine,
                'collation' => isset($status['Collation']) ? (string) $status['Collation'] : '',
                'row_count_estimate' => isset($status['Rows']) ? (int) $status['Rows'] : 0,
                'data_length' => isset($status['Data_length']) ? (int) $status['Data_length'] : 0,
                'index_length' => isset($status['Index_length']) ? (int) $status['Index_length'] : 0,
                'create_sql' => $create_sql,
                'columns' => $columns,
                'keyset_columns' => $keyset_columns,
                'backup_priority' => $this->backup_priority($slug),
                'restore_priority' => $this->restore_priority($slug),
                'rows_file' => $rows_file,
                'rows_file_size' => 0,
                'rows_exported' => 0,
                'complete' => false,
            ];
        }

        usort($tables, static function (array $a, array $b): int {
            $priority = ((int) $a['backup_priority']) <=> ((int) $b['backup_priority']);
            return $priority !== 0 ? $priority : strnatcasecmp((string) $a['name'], (string) $b['name']);
        });

        return [
            'format' => self::FORMAT,
            'format_version' => self::FORMAT_VERSION,
            'row_format' => DatabaseBackupCodec::FORMAT,
            'job_id' => $this->job_id,
            'scope' => $scope,
            'created_at' => time(),
            'site_url' => site_url(),
            'home_url' => home_url(),
            'wordpress_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'mysql_version' => (string) $this->wpdb->db_version(),
            'charset' => (string) $this->wpdb->charset,
            'collate' => (string) $this->wpdb->collate,
            'is_multisite' => is_multisite(),
            'blog_id' => get_current_blog_id(),
            'base_prefix' => (string) $this->wpdb->base_prefix,
            'prefix' => (string) $this->wpdb->prefix,
            'tables' => $tables,
            'table_count' => count($tables),
            'package_size' => 0,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function list_tables_for_scope(string $scope): array
    {
        $rows = $this->wpdb->get_results('SHOW TABLE STATUS', ARRAY_A);
        if (! is_array($rows)) {
            throw new \RuntimeException($this->wpdb->last_error ?: 'Failed to list database tables.');
        }

        $filtered = [];
        foreach ($rows as $row) {
            $name = (string) ($row['Name'] ?? '');
            if ($name === '' || ! DatabaseSafetyPolicy::is_safe_identifier($name)) {
                continue;
            }

            $classification = DatabaseSafetyPolicy::classify_table($name, (string) $this->wpdb->prefix, (string) $this->wpdb->base_prefix);
            if ($scope === 'current' && ($classification['scope'] ?? '') !== 'site') {
                continue;
            }
            if ($scope === 'network' && ($classification['scope'] ?? '') !== 'network') {
                continue;
            }

            $filtered[] = $row;
        }

        return $filtered;
    }

    private function show_create_table(string $table): string
    {
        $row = $this->wpdb->get_row('SHOW CREATE TABLE ' . DatabaseSafetyPolicy::quote_identifier($table), ARRAY_N);
        if (! is_array($row) || ! isset($row[1]) || ! is_string($row[1])) {
            throw new \RuntimeException('Failed to read CREATE TABLE statement for ' . $table . '.');
        }
        return $row[1];
    }

    /**
     * @return array<int,array<string,string|bool|null>>
     */
    private function describe_table(string $table): array
    {
        $rows = $this->wpdb->get_results('DESCRIBE ' . DatabaseSafetyPolicy::quote_identifier($table), ARRAY_A);
        if (! is_array($rows)) {
            throw new \RuntimeException('Failed to read table columns for ' . $table . '.');
        }

        return array_map(static function (array $row): array {
            return [
                'name' => (string) ($row['Field'] ?? ''),
                'type' => (string) ($row['Type'] ?? ''),
                'nullable' => strtoupper((string) ($row['Null'] ?? '')) === 'YES',
                'key' => (string) ($row['Key'] ?? ''),
                'default' => array_key_exists('Default', $row) && $row['Default'] !== null ? (string) $row['Default'] : null,
                'extra' => (string) ($row['Extra'] ?? ''),
            ];
        }, $rows);
    }

    /**
     * @return array<int,string>
     */
    private function keyset_columns(string $table, array $columns): array
    {
        $indexes = $this->wpdb->get_results('SHOW INDEX FROM ' . DatabaseSafetyPolicy::quote_identifier($table), ARRAY_A);
        if (! is_array($indexes)) {
            return [];
        }

        $nullable = [];
        foreach ($columns as $column) {
            if (! is_array($column)) {
                continue;
            }
            $name = (string) ($column['name'] ?? '');
            if ($name !== '') {
                $nullable[$name] = ! empty($column['nullable']);
            }
        }

        $primary = [];
        $unique = [];
        $invalid_unique = [];
        foreach ($indexes as $index) {
            $name = (string) ($index['Key_name'] ?? '');
            $column = (string) ($index['Column_name'] ?? '');
            $seq = isset($index['Seq_in_index']) ? (int) $index['Seq_in_index'] : 0;
            if ($column === '' || $seq <= 0) {
                continue;
            }
            if ($name === 'PRIMARY') {
                $primary[$seq] = $column;
            } elseif ((int) ($index['Non_unique'] ?? 1) === 0 && empty($invalid_unique[$name]) && empty($index['Sub_part']) && empty($nullable[$column]) && empty($unique[$name])) {
                $unique[$name] = [$seq => $column];
            } elseif ((int) ($index['Non_unique'] ?? 1) === 0 && empty($invalid_unique[$name]) && empty($index['Sub_part']) && empty($nullable[$column])) {
                $unique[$name][$seq] = $column;
            } elseif ((int) ($index['Non_unique'] ?? 1) === 0) {
                unset($unique[$name]);
                $invalid_unique[$name] = true;
            }
        }

        if (! empty($primary)) {
            ksort($primary);
            return array_values($primary);
        }

        foreach ($unique as $columns) {
            ksort($columns);
            return array_values($columns);
        }

        return [];
    }

    private function backup_priority(string $slug): int
    {
        $order = [
            'commentmeta' => 10,
            'comments' => 20,
            'postmeta' => 30,
            'term_relationships' => 40,
            'posts' => 50,
            'termmeta' => 60,
            'term_taxonomy' => 70,
            'terms' => 80,
            'usermeta' => 90,
            'users' => 100,
            'links' => 110,
            'options' => 120,
            'blogmeta' => 130,
            'blogs' => 140,
            'sitemeta' => 150,
            'site' => 160,
        ];

        return $order[strtolower($slug)] ?? 500;
    }

    private function restore_priority(string $slug): int
    {
        $order = [
            'users' => 10,
            'usermeta' => 20,
            'terms' => 30,
            'term_taxonomy' => 40,
            'termmeta' => 50,
            'posts' => 60,
            'postmeta' => 70,
            'term_relationships' => 80,
            'comments' => 90,
            'commentmeta' => 100,
            'links' => 110,
            'options' => 120,
            'blogs' => 130,
            'blogmeta' => 140,
            'site' => 150,
            'sitemeta' => 160,
        ];

        return $order[strtolower($slug)] ?? 500;
    }

    /**
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private function progress(array $manifest, array $state): array
    {
        $total_estimate = 0;
        $exported = 0;
        foreach ($manifest['tables'] ?? [] as $table) {
            if (! is_array($table)) {
                continue;
            }
            $total_estimate += max(0, (int) ($table['row_count_estimate'] ?? 0));
            $exported += max(0, (int) ($table['rows_exported'] ?? 0));
        }

        return [
            'complete' => ($state['phase'] ?? '') === 'complete',
            'phase' => (string) ($state['phase'] ?? 'export'),
            'current_table_index' => (int) ($state['current_table_index'] ?? 0),
            'table_count' => count($manifest['tables'] ?? []),
            'rows_exported' => $exported,
            'rows_estimate' => $total_estimate,
            'percent' => $total_estimate > 0 ? round(min(100, ($exported / $total_estimate) * 100), 2) : 0,
            'manifest' => basename($this->manifest_file),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function load_manifest(): array
    {
        $manifest = anibas_fm_read_small_json_file($this->manifest_file);
        if (! is_array($manifest) || ($manifest['format'] ?? '') !== self::FORMAT) {
            throw new \RuntimeException('Database backup manifest is missing or invalid.');
        }
        return $manifest;
    }

    /**
     * @return array<string,mixed>
     */
    private function load_state(): array
    {
        $state = anibas_fm_read_small_json_file($this->state_file);
        if (! is_array($state)) {
            throw new \RuntimeException('Database backup state is missing or invalid.');
        }
        return $state;
    }

    private function sanitize_job_id(string $job_id): string
    {
        $job_id = preg_replace('/[^A-Za-z0-9_-]/', '', $job_id) ?: '';
        if ($job_id === '') {
            throw new \InvalidArgumentException('Database backup job id is required.');
        }
        return $job_id;
    }

    private function normalize_scope(string $scope): string
    {
        return in_array($scope, ['current', 'network', 'all'], true) ? $scope : 'current';
    }

    private function default_time_budget(): int
    {
        return function_exists('anibas_fm_safe_time_budget')
            ? anibas_fm_safe_time_budget(20, 0.55)
            : 20;
    }

    private function ensure_directory(string $dir): void
    {
        if (! is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        if (! is_dir($dir)) {
            throw new \RuntimeException('Failed to create database backup state directory.');
        }
    }

    /**
     * @param array<string,mixed> $data
     */
    private function save_json(string $path, array $data): void
    {
        $tmp = $path . '.tmp';
        $json = wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (! is_string($json) || @file_put_contents($tmp, $json) === false) {
            throw new \RuntimeException('Failed to write database backup state.');
        }
        if (! @rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Failed to commit database backup state.');
        }
    }

    /**
     * @return resource
     */
    private function acquire_lock()
    {
        $lock = @fopen($this->lock_file, 'c');
        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock) {
                fclose($lock);
            }
            throw new \RuntimeException('Another database backup step is already running.');
        }
        return $lock;
    }

    /**
     * @param resource $lock
     */
    private function release_lock($lock): void
    {
        if (is_resource($lock)) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function assert_path_inside(string $path, string $root): void
    {
        $path = untrailingslashit(wp_normalize_path($path));
        $root = untrailingslashit(wp_normalize_path($root));
        if ($path !== $root && strpos(trailingslashit($path), trailingslashit($root)) !== 0) {
            throw new \RuntimeException('Database backup path escaped its state directory.');
        }
    }

    private function directory_size(string $dir): int
    {
        $total = 0;
        if (! is_dir($dir)) {
            return 0;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $item) {
            if ($item instanceof \SplFileInfo && $item->isFile()) {
                $total += (int) $item->getSize();
            }
        }
        return $total;
    }

    private function delete_tree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if (! $item instanceof \SplFileInfo) {
                continue;
            }
            if ($item->isDir() && ! $item->isLink()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }
}
