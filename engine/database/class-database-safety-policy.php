<?php

namespace Anibas;

if (! defined('ABSPATH')) exit;

/**
 * Database safety rules: table classification, identifier checks, mutation
 * allow-lists, and explicit WordPress-critical value redaction.
 */
class DatabaseSafetyPolicy
{
    private const CORE_TABLES = [
        'commentmeta',
        'comments',
        'links',
        'options',
        'postmeta',
        'posts',
        'term_relationships',
        'term_taxonomy',
        'termmeta',
        'terms',
        'usermeta',
        'users',
    ];

    private const MULTISITE_GLOBAL_TABLES = [
        'blogmeta',
        'blogs',
        'blog_versions',
        'registration_log',
        'signups',
        'site',
        'sitemeta',
        'sitecategories',
        'users',
        'usermeta',
    ];

    private const PROTECTED_USER_COLUMNS = [
        'user_pass',
    ];

    private const PROTECTED_OPTION_KEYS = [
        'home',
        'home_url',
        'site_url',
        'siteurl',
        'cron',
        'rewrite_rules',
    ];

    private const PROTECTED_SITE_META_KEYS = [
        'siteurl',
        'site_url',
        'home',
        'home_url',
        'cron',
        'rewrite_rules',
    ];

    public static function is_safe_identifier(string $identifier): bool
    {
        return $identifier !== '' && strlen($identifier) <= 64 && (bool) preg_match('/^[A-Za-z0-9_$]+$/', $identifier);
    }

    public static function quote_identifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    public static function classify_table(string $table, string $current_prefix, string $base_prefix): array
    {
        $slug = $table;
        $scope = 'other';

        if ($current_prefix !== '' && strpos($table, $current_prefix) === 0) {
            $slug = substr($table, strlen($current_prefix));
            $scope = 'site';
        } elseif ($base_prefix !== '' && strpos($table, $base_prefix) === 0) {
            $slug = substr($table, strlen($base_prefix));
            $scope = preg_match('/^\d+_/', $slug) ? 'site' : 'network';
        }

        $is_core = in_array($slug, self::CORE_TABLES, true) || in_array($slug, self::MULTISITE_GLOBAL_TABLES, true);
        $is_user_table = in_array($slug, ['users', 'usermeta'], true);
        $is_options_table = in_array($slug, ['options', 'sitemeta'], true);

        return [
            'slug' => $slug,
            'scope' => $scope,
            'is_core' => $is_core,
            'is_user_table' => $is_user_table,
            'is_options_table' => $is_options_table,
            'risk' => $is_user_table || $is_options_table ? 'sensitive' : ($is_core ? 'normal' : 'custom'),
        ];
    }

    public static function redact_row(string $table, array $row, array $column_meta): array
    {
        $redacted = [];
        $protected_value_columns = self::protected_value_columns($table, $row);

        foreach ($row as $column => $value) {
            $meta = $column_meta[$column] ?? [];
            if (self::should_redact_column($table, $column) || in_array(strtolower($column), $protected_value_columns, true)) {
                $redacted[$column] = '[redacted]';
                continue;
            }

            if (self::is_binary_column($meta)) {
                $redacted[$column] = $value === null ? null : '[binary data]';
                continue;
            }

            $redacted[$column] = self::format_value_for_response($value);
        }

        return $redacted;
    }

    public static function columns_by_name(array $columns): array
    {
        $by_name = [];
        foreach ($columns as $column) {
            if (isset($column['name'])) {
                $by_name[$column['name']] = $column;
            }
        }
        return $by_name;
    }

    public static function can_edit_table(array $classification): bool
    {
        return in_array($classification['scope'] ?? '', ['site', 'network'], true);
    }

    public static function can_insert_table(array $classification): bool
    {
        return self::can_edit_table($classification) && ($classification['slug'] ?? '') !== 'users';
    }

    public static function edit_block_reason(array $classification, array $primary_key): ?string
    {
        if (! self::can_edit_table($classification)) {
            return __('This table is outside the editable WordPress scope.', 'anibas-file-manager');
        }

        if (empty($primary_key)) {
            return __('Rows need a primary key before they can be edited safely.', 'anibas-file-manager');
        }

        return null;
    }

    public static function insert_block_reason(array $classification): ?string
    {
        if (! self::can_edit_table($classification)) {
            return __('This table is outside the editable WordPress scope.', 'anibas-file-manager');
        }

        if (($classification['slug'] ?? '') === 'users') {
            return __('Users can be edited here, but new users must be created through WordPress so password hashes and roles are initialized correctly.', 'anibas-file-manager');
        }

        return null;
    }

    public static function delete_block_reason(array $classification): ?string
    {
        if (! self::can_edit_table($classification)) {
            return __('This table is outside the editable WordPress scope.', 'anibas-file-manager');
        }

        if (in_array($classification['slug'] ?? '', ['users', 'usermeta'], true)) {
            return __('Users and user metadata cannot be deleted from the database browser.', 'anibas-file-manager');
        }

        return null;
    }

    public static function can_edit_column(array $column, string $table = ''): bool
    {
        $name = (string) ($column['name'] ?? '');
        if (! self::is_safe_identifier($name) || self::should_redact_column($table, $name) || self::is_binary_column($column)) {
            return false;
        }

        $extra = strtolower((string) ($column['extra'] ?? ''));
        return strpos($extra, 'generated') === false;
    }

    public static function can_insert_column(array $column, string $table = ''): bool
    {
        if (! self::can_edit_column($column, $table)) {
            return false;
        }

        $extra = strtolower((string) ($column['extra'] ?? ''));
        return strpos($extra, 'auto_increment') === false;
    }

    public static function response_value_is_editable($value): bool
    {
        return $value !== '[redacted]'
            && $value !== '[binary data]'
            && $value !== '[value too large]'
            && $value !== '[unsupported value]'
            && (! is_string($value) || substr($value, -15) !== '... [truncated]');
    }

    public static function should_redact_column(string $table, string $column): bool
    {
        $slug = self::table_slug($table);
        return $slug === 'users' && in_array(strtolower($column), self::PROTECTED_USER_COLUMNS, true);
    }

    private static function protected_value_columns(string $table, array $row): array
    {
        $slug = self::table_slug($table);

        if ($slug === 'options') {
            $key = self::row_key_value($row, ['option_name']);
            return self::option_key_is_protected($key, self::PROTECTED_OPTION_KEYS) ? ['option_value'] : [];
        }

        if ($slug === 'sitemeta') {
            $key = self::row_key_value($row, ['meta_key']);
            return self::option_key_is_protected($key, self::PROTECTED_SITE_META_KEYS) ? ['meta_value'] : [];
        }

        return [];
    }

    private static function option_key_is_protected(?string $key, array $protected_keys): bool
    {
        return $key !== null && in_array(strtolower($key), $protected_keys, true);
    }

    private static function table_slug(string $table): string
    {
        global $wpdb;

        if (! isset($wpdb)) {
            return strtolower($table);
        }

        $classification = self::classify_table($table, (string) $wpdb->prefix, (string) $wpdb->base_prefix);
        return strtolower((string) ($classification['slug'] ?? $table));
    }

    private static function row_key_value(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && is_scalar($row[$key])) {
                return (string) $row[$key];
            }
        }
        return null;
    }

    private static function is_binary_column(array $meta): bool
    {
        $type = strtolower((string) ($meta['type'] ?? ''));
        return strpos($type, 'blob') !== false || strpos($type, 'binary') !== false;
    }

    private static function format_value_for_response($value)
    {
        if ($value === null) {
            return null;
        }

        if (! is_scalar($value)) {
            return '[unsupported value]';
        }

        $string = (string) $value;
        $string = wp_check_invalid_utf8($string, true);
        if (strlen($string) > 2000) {
            return substr($string, 0, 2000) . '... [truncated]';
        }

        return $string;
    }
}
