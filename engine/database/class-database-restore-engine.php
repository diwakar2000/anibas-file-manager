<?php
declare(strict_types=1);

namespace Anibas;

if (! defined('ABSPATH')) exit;

class DatabaseRestoreStagingException extends \RuntimeException
{
}

/**
 * Chunked database restore groundwork.
 *
 * Default mode is staging_swap: import into temporary tables first, then
 * atomically rename staging tables into place. Overwrite mode is supported for
 * low-space servers but is intentionally not the default.
 */
class DatabaseRestoreEngine
{
    public const MODE_STAGING_SWAP = 'staging_swap';
    public const MODE_OVERWRITE = 'overwrite';

    private const UNKNOWN_MEMORY_ROW_LIMIT = 1048576;

    private \wpdb $wpdb;
    private string $job_id;
    private string $state_dir;
    private string $manifest_file;
    private string $state_file;
    private string $lock_file;
    private int $time_budget;
    private int $row_line_limit;

    public function __construct(string $job_id, string $state_dir, string $manifest_file)
    {
        global $wpdb;

        if (! isset($wpdb) || ! $wpdb instanceof \wpdb) {
            throw new \RuntimeException('WordPress database connection is not available.');
        }

        $this->wpdb = $wpdb;
        $this->job_id = $this->sanitize_job_id($job_id);
        $this->state_dir = untrailingslashit(wp_normalize_path($state_dir));
        $this->manifest_file = wp_normalize_path($manifest_file);
        $this->state_file = $this->state_dir . '/restore-state.json';
        $this->lock_file = $this->state_dir . '/restore.lock';
        $this->time_budget = $this->default_time_budget();
        $this->row_line_limit = $this->safe_row_line_limit();

        $this->ensure_directory($this->state_dir);
        anibas_fm_protect_dir($this->state_dir);
    }

    public function initialize(string $mode = self::MODE_STAGING_SWAP, array $runtime_option_overrides = [], bool $preserve_old_data = false): array
    {
        $mode = $this->normalize_mode($mode);
        $manifest = $this->load_and_validate_manifest();
        $state = [
            'job_id' => $this->job_id,
            'phase' => 'prepare',
            'mode' => $mode,
            'current_table_index' => 0,
            'row_file_offset' => 0,
            'rows_imported' => 0,
            'table_rows_imported' => [],
            'prepared_tables' => [],
            'staging_tables' => [],
            'old_tables' => [],
            'preserve_old_data' => $mode === self::MODE_STAGING_SWAP && $preserve_old_data,
            'old_table_cleanup_cursor' => 0,
            'runtime_option_overrides' => $this->sanitize_runtime_option_overrides($runtime_option_overrides),
            'restore_url_replacements' => $this->build_restore_url_replacements($manifest),
            'captured_runtime_options' => [],
            'started_at' => time(),
            'updated_at' => time(),
        ];

        $this->save_json($this->state_file, $state);
        return $this->progress($manifest, $state);
    }

    /**
     * @return array<string,mixed>
     */
    public function captured_runtime_options(): array
    {
        $state = $this->load_state();
        return is_array($state['captured_runtime_options'] ?? null) ? $state['captured_runtime_options'] : [];
    }

    public function run_step(): array
    {
        $lock = $this->acquire_lock();
        $foreign_key_reset = false;

        try {
            $manifest = $this->load_and_validate_manifest();
            $state = $this->load_state();
            if (($state['phase'] ?? '') === 'paused_staging_failed') {
                return $this->progress($manifest, $state);
            }

            $this->wpdb->query('SET FOREIGN_KEY_CHECKS=0');
            $foreign_key_reset = true;

            $started = microtime(true);
            while ((microtime(true) - $started) < $this->time_budget) {
                if (($state['phase'] ?? '') === 'complete') {
                    break;
                }

                $table_index = (int) ($state['current_table_index'] ?? 0);
                if (isset($manifest['tables'][$table_index]) && is_array($manifest['tables'][$table_index])) {
                    try {
                        $this->restore_table_step($manifest['tables'][$table_index], $table_index, $state, $started);
                    } catch (DatabaseRestoreStagingException $e) {
                        if (($state['mode'] ?? self::MODE_STAGING_SWAP) === self::MODE_STAGING_SWAP && empty($state['old_tables'])) {
                            $this->cleanup_staging_tables($state);
                            $state['phase'] = 'paused_staging_failed';
                            $state['staging_failure_error'] = $e->getMessage();
                            $state['staging_cleanup_complete'] = true;
                            $state['updated_at'] = time();
                            $this->save_json($this->state_file, $state);
                            return $this->progress($manifest, $state);
                        }
                        throw $e;
                    }
                    $this->save_json($this->state_file, $state);
                    continue;
                }

                if (($state['mode'] ?? self::MODE_STAGING_SWAP) === self::MODE_STAGING_SWAP
                    && ! in_array(($state['phase'] ?? ''), ['swap', 'cleanup_old_tables'], true)
                ) {
                    $state['phase'] = 'swap';
                    $state['updated_at'] = time();
                    $this->save_json($this->state_file, $state);
                    continue;
                }

                if (($state['phase'] ?? '') === 'swap') {
                    $this->swap_staging_tables($state);
                    if (empty($state['preserve_old_data'])) {
                        $state['phase'] = 'cleanup_old_tables';
                        $state['updated_at'] = time();
                        $this->save_json($this->state_file, $state);
                        continue;
                    }
                    $state['phase'] = 'complete';
                    $state['completed_at'] = time();
                    $state['updated_at'] = time();
                    $this->save_json($this->state_file, $state);
                    break;
                }

                if (($state['phase'] ?? '') === 'cleanup_old_tables') {
                    if (! $this->cleanup_old_tables_step($state, $started)) {
                        $state['updated_at'] = time();
                        $this->save_json($this->state_file, $state);
                        break;
                    }

                    $state['phase'] = 'complete';
                    $state['completed_at'] = time();
                    $state['updated_at'] = time();
                    $this->save_json($this->state_file, $state);
                    break;
                }

                $state['phase'] = 'complete';
                $state['completed_at'] = time();
                $state['updated_at'] = time();
                $this->save_json($this->state_file, $state);
                break;
            }

            return $this->progress($manifest, $state);
        } finally {
            if ($foreign_key_reset) {
                $this->wpdb->query('SET FOREIGN_KEY_CHECKS=1');
            }
            $this->release_lock($lock);
        }
    }

    public function state_path(): string
    {
        return $this->state_file;
    }

    public function cleanup_staging_for_overwrite_fallback(): void
    {
        $lock = $this->acquire_lock();
        try {
            if (! is_file($this->state_file)) {
                return;
            }

            $state = $this->load_state();
            $old_tables = is_array($state['old_tables'] ?? null) ? array_filter($state['old_tables']) : [];
            if (! empty($old_tables)) {
                throw new \RuntimeException('Cannot continue with overwrite after database table swap has started.');
            }

            $this->cleanup_staging_tables($state);

            foreach ([$this->state_file, $this->state_file . '.tmp'] as $file) {
                if (is_file($file)) {
                    wp_delete_file($file);
                }
            }
        } finally {
            $this->release_lock($lock);
        }
    }

    public function cleanup_failed_staging_restore(): bool
    {
        $lock = $this->acquire_lock();
        try {
            if (! is_file($this->state_file)) {
                return true;
            }

            $state = $this->load_state();
            if (($state['mode'] ?? self::MODE_STAGING_SWAP) !== self::MODE_STAGING_SWAP) {
                return false;
            }

            $old_tables = is_array($state['old_tables'] ?? null) ? array_filter($state['old_tables']) : [];
            if (! empty($old_tables)) {
                return false;
            }

            $this->cleanup_staging_tables($state);
            foreach ([$this->state_file, $this->state_file . '.tmp', $this->lock_file] as $file) {
                if (is_file($file)) {
                    wp_delete_file($file);
                }
            }

            return true;
        } finally {
            $this->release_lock($lock);
        }
    }

    /**
     * @param array<string,mixed> $table
     * @param array<string,mixed> $state
     */
    private function restore_table_step(array $table, int $table_index, array &$state, float $started): void
    {
        $original_table = (string) ($table['name'] ?? '');
        if ($original_table === '' || ! DatabaseSafetyPolicy::is_safe_identifier($original_table)) {
            throw new \RuntimeException('Invalid table name in database restore manifest.');
        }

        if (empty($state['prepared_tables'][$original_table])) {
            $this->prepare_restore_table($table, $state);
            $state['prepared_tables'][$original_table] = true;
            $state['table_rows_imported'][$original_table] = 0;
            $state['row_file_offset'] = 0;
            $state['updated_at'] = time();
            return;
        }

        $target_table = $this->target_table_name($original_table, $state);
        $rows_file = $this->rows_file_path($table);
        $handle = @fopen($rows_file, 'rb');
        if (! $handle) {
            throw new \RuntimeException('Failed to open database restore row stream.');
        }

        $offset = max(0, (int) ($state['row_file_offset'] ?? 0));
        if ($offset > 0) {
            fseek($handle, $offset);
        }

        $imported_this_run = 0;
        $done = false;
        try {
            while (! feof($handle)) {
                if ((microtime(true) - $started) >= $this->time_budget) {
                    break;
                }

                $line = fgets($handle, $this->row_line_limit + 2);
                if ($line === false || $line === '') {
                    break;
                }
                if (strlen($line) > $this->row_line_limit || (substr($line, -1) !== "\n" && ! feof($handle))) {
                    throw new \RuntimeException(sprintf(
                        'A database restore row for %s is larger than the memory-safe import limit of %s for this PHP process.',
                        $original_table,
                        $this->format_bytes($this->row_line_limit)
                    ));
                }
                if (! $this->has_memory_headroom_for_line(strlen($line))) {
                    throw new \RuntimeException('Available PHP memory is too low to safely import the next database row.');
                }

                $row = DatabaseBackupCodec::decode_row($line);
                $this->insert_row($target_table, $row, $table, $state);
                $imported_this_run++;
                $state['rows_imported'] = (int) ($state['rows_imported'] ?? 0) + 1;
                $state['table_rows_imported'][$original_table] = (int) ($state['table_rows_imported'][$original_table] ?? 0) + 1;
                $state['row_file_offset'] = (int) ftell($handle);
            }
            $done = feof($handle);
        } finally {
            fclose($handle);
        }

        if ($done) {
            $expected = (int) ($table['rows_exported'] ?? 0);
            $actual = (int) ($state['table_rows_imported'][$original_table] ?? 0);
            if ($expected !== $actual) {
                throw new \RuntimeException(sprintf('Restored row count mismatch for %s: expected %d, got %d.', $original_table, $expected, $actual));
            }

            $state['current_table_index'] = $table_index + 1;
            $state['row_file_offset'] = 0;
            $state['updated_at'] = time();
        }
    }

    /**
     * @param array<string,mixed> $table
     * @param array<string,mixed> $state
     */
    private function prepare_restore_table(array $table, array &$state): void
    {
        $original_table = (string) $table['name'];
        $target_table = $this->target_table_name($original_table, $state);
        $create_sql = (string) ($table['create_sql'] ?? '');
        if ($create_sql === '') {
            throw new \RuntimeException('Missing CREATE TABLE statement for ' . $original_table . '.');
        }

        $is_staging = ($state['mode'] ?? self::MODE_STAGING_SWAP) === self::MODE_STAGING_SWAP;
        $drop_result = $this->wpdb->query('DROP TABLE IF EXISTS ' . DatabaseSafetyPolicy::quote_identifier($target_table));
        if ($drop_result === false && $is_staging) {
            throw new DatabaseRestoreStagingException($this->wpdb->last_error ?: 'Failed to prepare database staging table ' . $target_table . '.');
        }

        $rewritten = $this->rewrite_create_table_name($create_sql, $original_table, $target_table);
        $result = $this->wpdb->query($rewritten);
        if ($result === false) {
            if ($is_staging) {
                throw new DatabaseRestoreStagingException($this->wpdb->last_error ?: 'Failed to create database staging table ' . $target_table . '.');
            }
            throw new \RuntimeException($this->wpdb->last_error ?: 'Failed to create restore table ' . $target_table . '.');
        }
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $table_manifest
     */
    private function insert_row(string $table, array $row, array $table_manifest, array &$state): void
    {
        if (empty($row)) {
            return;
        }

        $row = $this->apply_runtime_option_overrides($row, $table_manifest, $state);
        $row = $this->apply_restore_url_replacements($row, $table_manifest, $state);
        $generated_columns = $this->generated_columns($table_manifest);
        $columns = [];
        $placeholders = [];
        $params = [];

        foreach ($row as $column => $value) {
            if (! DatabaseSafetyPolicy::is_safe_identifier($column)) {
                throw new \RuntimeException('Invalid column name in database restore row.');
            }
            if (isset($generated_columns[$column])) {
                continue;
            }
            $columns[] = DatabaseSafetyPolicy::quote_identifier($column);
            if ($value === null) {
                $placeholders[] = 'NULL';
            } else {
                $placeholders[] = '%s';
                $params[] = $value;
            }
        }

        if (empty($columns)) {
            return;
        }

        $sql = 'INSERT INTO ' . DatabaseSafetyPolicy::quote_identifier($table)
            . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
        if (! empty($params)) {
            $sql = $this->wpdb->prepare($sql, $params);
        }

        $result = $this->wpdb->query($sql);
        if ($result === false) {
            throw new \RuntimeException($this->wpdb->last_error ?: 'Failed to insert database restore row.');
        }
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $table_manifest
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private function apply_runtime_option_overrides(array $row, array $table_manifest, array &$state): array
    {
        $slug = strtolower((string) ($table_manifest['slug'] ?? $table_manifest['name'] ?? ''));
        $table_name = (string) ($table_manifest['name'] ?? '');
        $overrides = is_array($state['runtime_option_overrides'] ?? null) ? $state['runtime_option_overrides'] : [];

        if ($this->is_options_slug($slug) && (string) ($row['option_name'] ?? '') === 'active_plugins') {
            $this->capture_runtime_option($state, 'active_plugins', $table_name, $row['option_value'] ?? '');
            if (isset($overrides['active_plugins']) && is_string($overrides['active_plugins'])) {
                $row['option_value'] = $overrides['active_plugins'];
            }
        }

        if ($slug === 'sitemeta' && (string) ($row['meta_key'] ?? '') === 'active_sitewide_plugins') {
            $this->capture_runtime_option($state, 'active_sitewide_plugins', $table_name, $row['meta_value'] ?? '');
            if (isset($overrides['active_sitewide_plugins']) && is_string($overrides['active_sitewide_plugins'])) {
                $row['meta_value'] = $overrides['active_sitewide_plugins'];
            }
        }

        return $row;
    }

    /**
     * @param array<string,mixed> $manifest
     * @return array<string,string>
     */
    private function build_restore_url_replacements(array $manifest): array
    {
        return [
            'source_site_url' => $this->normalize_restore_url((string) ($manifest['site_url'] ?? '')),
            'source_home_url' => $this->normalize_restore_url((string) ($manifest['home_url'] ?? '')),
            'destination_site_url' => $this->normalize_restore_url(site_url()),
            'destination_home_url' => $this->normalize_restore_url(home_url()),
            'destination_network_site_url' => $this->normalize_restore_url(is_multisite() ? network_site_url() : site_url()),
        ];
    }

    private function normalize_restore_url(string $url): string
    {
        $url = trim($url);
        return $url === '' ? '' : untrailingslashit($url);
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $table_manifest
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private function apply_restore_url_replacements(array $row, array $table_manifest, array &$state): array
    {
        $context = is_array($state['restore_url_replacements'] ?? null) ? $state['restore_url_replacements'] : [];
        if (empty($context)) {
            $context = $this->build_restore_url_replacements($this->load_and_validate_manifest());
            $state['restore_url_replacements'] = $context;
        }

        $map = $this->restore_url_replacement_map($context);
        if (empty($map)) {
            return $this->apply_explicit_restore_url_options($row, $table_manifest, $context);
        }

        foreach ($row as $column => $value) {
            if (! is_string($column) || ! is_string($value) || ! $this->column_allows_url_rewrite($column, $table_manifest)) {
                continue;
            }
            $row[$column] = $this->rewrite_restore_value($value, $map);
        }

        return $this->apply_explicit_restore_url_options($row, $table_manifest, $context);
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $table_manifest
     * @param array<string,string> $context
     * @return array<string,mixed>
     */
    private function apply_explicit_restore_url_options(array $row, array $table_manifest, array $context): array
    {
        $slug = strtolower((string) ($table_manifest['slug'] ?? $table_manifest['name'] ?? ''));
        if ($this->is_options_slug($slug) && isset($row['option_name'])) {
            $name = strtolower((string) $row['option_name']);
            if ($name === 'siteurl' || $name === 'site_url') {
                $row['option_value'] = (string) ($context['destination_site_url'] ?? '');
            } elseif ($name === 'home' || $name === 'home_url') {
                $row['option_value'] = (string) ($context['destination_home_url'] ?? '');
            }
        }

        if ($slug === 'sitemeta' && isset($row['meta_key'])) {
            $name = strtolower((string) $row['meta_key']);
            if ($name === 'siteurl' || $name === 'site_url') {
                $row['meta_value'] = (string) ($context['destination_network_site_url'] ?? $context['destination_site_url'] ?? '');
            }
        }

        return $row;
    }

    /**
     * @param array<string,string> $context
     * @return array<string,string>
     */
    private function restore_url_replacement_map(array $context): array
    {
        $pairs = [
            [(string) ($context['source_site_url'] ?? ''), (string) ($context['destination_site_url'] ?? '')],
            [(string) ($context['source_home_url'] ?? ''), (string) ($context['destination_home_url'] ?? '')],
        ];
        $map = [];

        foreach ($pairs as $pair) {
            [$source, $destination] = $pair;
            if ($source === '' || $destination === '' || $source === $destination) {
                continue;
            }

            foreach ($this->restore_url_plain_variants($source, $destination) as $variant) {
                [$from, $to] = $variant;
                if ($from !== '' && $from !== $to) {
                    $map[$from] = $to;
                }
            }
        }

        uksort($map, static function (string $a, string $b): int {
            return strlen($b) <=> strlen($a);
        });

        return $map;
    }

    /**
     * @return array<int,array{0:string,1:string}>
     */
    private function restore_url_plain_variants(string $source, string $destination): array
    {
        $plain_pairs = [];
        foreach ([$source, untrailingslashit($source), trailingslashit($source)] as $index => $from) {
            $to = $index === 2 ? trailingslashit($destination) : untrailingslashit($destination);
            $plain_pairs[$from] = $to;
        }

        $source_alt_scheme = $this->alternate_http_scheme($source);
        if ($source_alt_scheme !== '') {
            $plain_pairs[$source_alt_scheme] = untrailingslashit($destination);
            $plain_pairs[trailingslashit($source_alt_scheme)] = trailingslashit($destination);
        }

        $variants = [];
        foreach ($plain_pairs as $from => $to) {
            if (! is_string($from) || $from === '') {
                continue;
            }
            $variants[] = [$from, $to];
            $variants[] = [str_replace('/', '\\/', $from), str_replace('/', '\\/', $to)];
            $variants[] = [rawurlencode($from), rawurlencode($to)];
            $variants[] = [$this->lowercase_percent_encoding(rawurlencode($from)), $this->lowercase_percent_encoding(rawurlencode($to))];
            $variants[] = [urlencode($from), urlencode($to)];
            $variants[] = [$this->lowercase_percent_encoding(urlencode($from)), $this->lowercase_percent_encoding(urlencode($to))];
        }

        return $variants;
    }

    private function alternate_http_scheme(string $url): string
    {
        if (str_starts_with($url, 'https://')) {
            return 'http://' . substr($url, 8);
        }
        if (str_starts_with($url, 'http://')) {
            return 'https://' . substr($url, 7);
        }
        return '';
    }

    private function lowercase_percent_encoding(string $value): string
    {
        return (string) preg_replace_callback('/%[0-9A-F]{2}/', static function (array $match): string {
            return strtolower((string) $match[0]);
        }, $value);
    }

    /**
     * @param array<string,mixed> $table_manifest
     */
    private function column_allows_url_rewrite(string $column, array $table_manifest): bool
    {
        $columns = is_array($table_manifest['columns'] ?? null) ? $table_manifest['columns'] : [];
        foreach ($columns as $meta) {
            if (! is_array($meta) || (string) ($meta['name'] ?? '') !== $column) {
                continue;
            }

            $type = strtolower((string) ($meta['type'] ?? ''));
            if ($type === '') {
                return true;
            }
            if (preg_match('/blob|binary|varbinary|geometry|point|linestring|polygon/', $type)) {
                return false;
            }
            return true;
        }

        return true;
    }

    /**
     * @param array<string,string> $map
     */
    private function rewrite_restore_value(string $value, array $map): string
    {
        if (! $this->contains_restore_url_candidate($value, $map)) {
            return $value;
        }

        if (function_exists('is_serialized') && is_serialized($value)) {
            // allowed_classes is false so objects come back as __PHP_Incomplete_class
            // (no constructor/wakeup/destruct executes) but still round-trip correctly
            // through serialize() below, since only public properties are rewritten.
            $unserialized = @unserialize($value, ['allowed_classes' => false]);
            if ($unserialized !== false || $value === serialize(false)) {
                return serialize($this->rewrite_restore_structure($unserialized, $map));
            }
        }

        return strtr($value, $map);
    }

    /**
     * @param array<string,string> $map
     */
    private function contains_restore_url_candidate(string $value, array $map): bool
    {
        foreach ($map as $from => $_to) {
            if ($from !== '' && strpos($value, $from) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param mixed $value
     * @param array<string,string> $map
     * @return mixed
     */
    private function rewrite_restore_structure($value, array $map)
    {
        if (is_string($value)) {
            return strtr($value, $map);
        }

        if (is_array($value)) {
            $rewritten = [];
            foreach ($value as $key => $item) {
                $next_key = is_string($key) ? strtr($key, $map) : $key;
                $rewritten[$next_key] = $this->rewrite_restore_structure($item, $map);
            }
            return $rewritten;
        }

        if (is_object($value)) {
            foreach ($value as $key => $item) {
                $value->{$key} = $this->rewrite_restore_structure($item, $map);
            }
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $state
     */
    private function capture_runtime_option(array &$state, string $key, string $table, $value): void
    {
        if ($table === '' || ! DatabaseSafetyPolicy::is_safe_identifier($table)) {
            return;
        }
        if (! isset($state['captured_runtime_options']) || ! is_array($state['captured_runtime_options'])) {
            $state['captured_runtime_options'] = [];
        }
        if (! isset($state['captured_runtime_options'][$key]) || ! is_array($state['captured_runtime_options'][$key])) {
            $state['captured_runtime_options'][$key] = [];
        }
        if (! array_key_exists($table, $state['captured_runtime_options'][$key])) {
            $state['captured_runtime_options'][$key][$table] = is_scalar($value) || $value === null ? (string) $value : '';
        }
    }

    private function is_options_slug(string $slug): bool
    {
        return $slug === 'options' || (bool) preg_match('/^\d+_options$/', $slug);
    }

    /**
     * @param array<string,mixed> $table_manifest
     * @return array<string,bool>
     */
    private function generated_columns(array $table_manifest): array
    {
        $generated = [];
        $columns = is_array($table_manifest['columns'] ?? null) ? $table_manifest['columns'] : [];
        foreach ($columns as $column) {
            if (! is_array($column)) {
                continue;
            }
            $name = (string) ($column['name'] ?? '');
            $extra = strtolower((string) ($column['extra'] ?? ''));
            if ($name !== '' && strpos($extra, 'generated') !== false) {
                $generated[$name] = true;
            }
        }

        return $generated;
    }

    /**
     * @param array<string,mixed> $state
     */
    private function swap_staging_tables(array &$state): void
    {
        $staging_tables = is_array($state['staging_tables'] ?? null) ? $state['staging_tables'] : [];
        if (empty($staging_tables)) {
            return;
        }

        $parts = [];
        foreach ($staging_tables as $original => $staging) {
            if (! is_string($original) || ! is_string($staging)) {
                continue;
            }
            if (! DatabaseSafetyPolicy::is_safe_identifier($original) || ! DatabaseSafetyPolicy::is_safe_identifier($staging)) {
                throw new \RuntimeException('Invalid table name in restore swap plan.');
            }

            $old = $this->old_table_name($original);
            $state['old_tables'][$original] = $old;

            if ($this->table_exists($old)) {
                throw new \RuntimeException('Restore recovery table already exists for ' . $original . '.');
            }

            if ($this->table_exists($original)) {
                $parts[] = DatabaseSafetyPolicy::quote_identifier($original) . ' TO ' . DatabaseSafetyPolicy::quote_identifier($old);
            }
            $parts[] = DatabaseSafetyPolicy::quote_identifier($staging) . ' TO ' . DatabaseSafetyPolicy::quote_identifier($original);
        }

        if (empty($parts)) {
            return;
        }

        $result = $this->wpdb->query('RENAME TABLE ' . implode(', ', $parts));
        if ($result === false) {
            throw new \RuntimeException($this->wpdb->last_error ?: 'Failed to swap restored database tables into place.');
        }
    }

    /**
     * @param array<string,mixed> $state
     */
    private function target_table_name(string $original_table, array &$state): string
    {
        if (($state['mode'] ?? self::MODE_STAGING_SWAP) === self::MODE_OVERWRITE) {
            return $original_table;
        }

        if (! isset($state['staging_tables'][$original_table])) {
            $state['staging_tables'][$original_table] = $this->staging_table_name($original_table);
        }

        return (string) $state['staging_tables'][$original_table];
    }

    private function staging_table_name(string $table): string
    {
        $suffix = '_anfm_' . substr(md5($this->job_id), 0, 10);
        return substr($table, 0, max(1, 64 - strlen($suffix))) . $suffix;
    }

    private function old_table_name(string $table): string
    {
        $suffix = '_old_' . substr(md5($this->job_id), 0, 10);
        return substr($table, 0, max(1, 64 - strlen($suffix))) . $suffix;
    }

    private function is_job_staging_table(string $table): bool
    {
        $suffix = '_anfm_' . substr(md5($this->job_id), 0, 10);
        return DatabaseSafetyPolicy::is_safe_identifier($table) && str_ends_with($table, $suffix);
    }

    private function is_job_old_table(string $table): bool
    {
        $suffix = '_old_' . substr(md5($this->job_id), 0, 10);
        return DatabaseSafetyPolicy::is_safe_identifier($table) && str_ends_with($table, $suffix);
    }

    /**
     * @param array<string,mixed> $state
     */
    private function cleanup_old_tables_step(array &$state, float $started): bool
    {
        $old_tables = is_array($state['old_tables'] ?? null) ? $state['old_tables'] : [];
        if (empty($old_tables)) {
            return true;
        }

        $keys = array_values(array_keys($old_tables));
        $cursor = max(0, (int) ($state['old_table_cleanup_cursor'] ?? 0));

        while (isset($keys[$cursor])) {
            $old_table = $old_tables[$keys[$cursor]] ?? '';
            if (is_string($old_table) && $this->is_job_old_table($old_table)) {
                $result = $this->wpdb->query('DROP TABLE IF EXISTS ' . DatabaseSafetyPolicy::quote_identifier($old_table));
                if ($result === false) {
                    throw new \RuntimeException($this->wpdb->last_error ?: 'Failed to clean up old database table ' . $old_table . '.');
                }
            }

            $cursor++;
            $state['old_table_cleanup_cursor'] = $cursor;

            if ((microtime(true) - $started) >= $this->time_budget) {
                return false;
            }
        }

        $state['old_tables'] = [];
        $state['old_table_cleanup_cursor'] = 0;
        return true;
    }

    /**
     * @param array<string,mixed> $state
     */
    private function cleanup_staging_tables(array &$state): void
    {
        $staging_tables = is_array($state['staging_tables'] ?? null) ? $state['staging_tables'] : [];
        foreach ($staging_tables as $staging_table) {
            if (! is_string($staging_table) || ! $this->is_job_staging_table($staging_table)) {
                continue;
            }

            $result = $this->wpdb->query('DROP TABLE IF EXISTS ' . DatabaseSafetyPolicy::quote_identifier($staging_table));
            if ($result === false) {
                throw new \RuntimeException($this->wpdb->last_error ?: 'Failed to clean up database staging table ' . $staging_table . '.');
            }
        }

        $state['staging_tables'] = [];
        $state['prepared_tables'] = [];
        $state['table_rows_imported'] = [];
        $state['current_table_index'] = 0;
        $state['row_file_offset'] = 0;
        $state['rows_imported'] = 0;
    }

    private function rewrite_create_table_name(string $create_sql, string $original, string $target): string
    {
        $pattern = '/^CREATE\s+TABLE\s+`?' . preg_quote($original, '/') . '`?/i';
        $rewritten = preg_replace($pattern, 'CREATE TABLE ' . DatabaseSafetyPolicy::quote_identifier($target), $create_sql, 1);
        if (! is_string($rewritten) || $rewritten === $create_sql) {
            throw new \RuntimeException('Failed to rewrite CREATE TABLE statement for restore.');
        }
        return $rewritten;
    }

    /**
     * @return array<string,mixed>
     */
    private function load_and_validate_manifest(): array
    {
        $manifest = anibas_fm_read_small_json_file($this->manifest_file);
        if (! is_array($manifest)
            || ($manifest['format'] ?? '') !== DatabaseBackupEngine::FORMAT
            || (int) ($manifest['format_version'] ?? 0) !== DatabaseBackupEngine::FORMAT_VERSION
            || ($manifest['row_format'] ?? '') !== DatabaseBackupCodec::FORMAT) {
            throw new \RuntimeException('Database backup manifest is missing or unsupported.');
        }

        if (empty($manifest['tables']) || ! is_array($manifest['tables'])) {
            throw new \RuntimeException('Database backup manifest contains no tables.');
        }

        foreach ($manifest['tables'] as $index => $table) {
            if (! is_array($table)) {
                throw new \RuntimeException('Invalid table entry in database backup manifest.');
            }
            $name = (string) ($table['name'] ?? '');
            if ($name === '' || ! DatabaseSafetyPolicy::is_safe_identifier($name)) {
                throw new \RuntimeException('Invalid table name in database backup manifest.');
            }
            $rows_file = $this->rows_file_path($table);
            $expected_size = (int) ($table['rows_file_size'] ?? -1);
            clearstatcache(true, $rows_file);
            $actual_size = is_file($rows_file) ? (int) filesize($rows_file) : -1;
            if ($actual_size < 0) {
                throw new \RuntimeException(sprintf('Database backup row stream is missing for %s.', $name));
            }
            if ($expected_size !== $actual_size) {
                $manifest['tables'][$index]['rows_file_size'] = $actual_size;
                $manifest['tables'][$index]['rows_file_size_warning'] = sprintf(
                    'Stored row stream size metadata for %s was %d bytes; actual extracted stream is %d bytes.',
                    $name,
                    $expected_size,
                    $actual_size
                );
            }
        }

        usort($manifest['tables'], function (array $a, array $b): int {
            $priority = $this->manifest_restore_priority($a) <=> $this->manifest_restore_priority($b);
            return $priority !== 0 ? $priority : strnatcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        return $manifest;
    }

    /**
     * @param array<string,mixed> $table
     */
    private function rows_file_path(array $table): string
    {
        $manifest_dir = dirname($this->manifest_file);
        $rows_file = $manifest_dir . '/' . ltrim((string) ($table['rows_file'] ?? ''), '/');
        $rows_file = wp_normalize_path($rows_file);
        $rows_root = wp_normalize_path($manifest_dir . '/rows');
        if ($rows_file !== $rows_root && strpos(trailingslashit($rows_file), trailingslashit($rows_root)) !== 0) {
            throw new \RuntimeException('Database backup row stream path escaped its manifest directory.');
        }
        return $rows_file;
    }

    /**
     * @return array<string,mixed>
     */
    private function load_state(): array
    {
        $state = anibas_fm_read_small_json_file($this->state_file);
        if (! is_array($state)) {
            throw new \RuntimeException('Database restore state is missing or invalid.');
        }
        return $state;
    }

    /**
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private function progress(array $manifest, array $state): array
    {
        $total_rows = 0;
        foreach ($manifest['tables'] as $table) {
            if (is_array($table)) {
                $total_rows += max(0, (int) ($table['rows_exported'] ?? 0));
            }
        }

        $rows_imported = max(0, (int) ($state['rows_imported'] ?? 0));
        return [
            'complete' => ($state['phase'] ?? '') === 'complete',
            'phase' => (string) ($state['phase'] ?? 'prepare'),
            'mode' => (string) ($state['mode'] ?? self::MODE_STAGING_SWAP),
            'current_table_index' => (int) ($state['current_table_index'] ?? 0),
            'table_count' => count($manifest['tables']),
            'rows_imported' => $rows_imported,
            'rows_total' => $total_rows,
            'percent' => $total_rows > 0 ? round(min(100, ($rows_imported / $total_rows) * 100), 2) : 0,
            'error' => isset($state['staging_failure_error']) && is_string($state['staging_failure_error']) ? $state['staging_failure_error'] : '',
            'staging_cleanup_complete' => ! empty($state['staging_cleanup_complete']),
            'can_fallback_overwrite' => ($state['phase'] ?? '') === 'paused_staging_failed'
                && ($state['mode'] ?? self::MODE_STAGING_SWAP) === self::MODE_STAGING_SWAP
                && empty($state['old_tables'])
                && ! empty($state['staging_cleanup_complete']),
        ];
    }

    private function table_exists(string $table): bool
    {
        $sql = $this->wpdb->prepare('SHOW TABLES LIKE %s', $table);
        return (string) $this->wpdb->get_var($sql) === $table;
    }

    private function sanitize_job_id(string $job_id): string
    {
        $job_id = preg_replace('/[^A-Za-z0-9_-]/', '', $job_id) ?: '';
        if ($job_id === '') {
            throw new \InvalidArgumentException('Database restore job id is required.');
        }
        return $job_id;
    }

    private function normalize_mode(string $mode): string
    {
        return $mode === self::MODE_OVERWRITE ? self::MODE_OVERWRITE : self::MODE_STAGING_SWAP;
    }

    /**
     * @param array<mixed,mixed> $overrides
     * @return array<string,string>
     */
    private function sanitize_runtime_option_overrides(array $overrides): array
    {
        $sanitized = [];
        foreach (['active_plugins', 'active_sitewide_plugins'] as $key) {
            if (isset($overrides[$key]) && is_string($overrides[$key])) {
                $sanitized[$key] = $overrides[$key];
            }
        }
        return $sanitized;
    }

    private function default_time_budget(): int
    {
        return function_exists('anibas_fm_safe_time_budget')
            ? anibas_fm_safe_time_budget(20, 0.55)
            : 20;
    }

    private function safe_row_line_limit(): int
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

    private function has_memory_headroom_for_line(int $line_bytes): bool
    {
        $memory = function_exists('anibas_fm_memory_headroom')
            ? anibas_fm_memory_headroom()
            : ['known' => false, 'available' => null];

        if (empty($memory['known'])) {
            return true;
        }

        $available = max(0, (int) ($memory['available'] ?? 0));
        $needed = max(8 * 1024 * 1024, $line_bytes * 8);
        return $available > $needed;
    }

    /**
     * @param array<string,mixed> $table
     */
    private function manifest_restore_priority(array $table): int
    {
        if (isset($table['restore_priority'])) {
            return (int) $table['restore_priority'];
        }

        $slug = strtolower((string) ($table['slug'] ?? $table['name'] ?? ''));
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

        return $order[$slug] ?? 500;
    }

    private function format_bytes(int $bytes): string
    {
        return function_exists('size_format')
            ? size_format($bytes)
            : number_format($bytes) . ' bytes';
    }

    private function ensure_directory(string $dir): void
    {
        if (! is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        if (! is_dir($dir)) {
            throw new \RuntimeException('Failed to create database restore state directory.');
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
            throw new \RuntimeException('Failed to write database restore state.');
        }
        if (! @rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Failed to commit database restore state.');
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
            throw new \RuntimeException('Another database restore step is already running.');
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
}
