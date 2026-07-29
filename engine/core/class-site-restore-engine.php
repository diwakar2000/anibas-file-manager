<?php
declare(strict_types=1);

namespace Anibas;

if (! defined('ABSPATH')) exit;

/**
 * Coordinates full-site restore from an ANFM package.
 *
 * The archive is always extracted into a protected staging directory first.
 * Database rows are restored from the embedded database manifest using the
 * chunked DatabaseRestoreEngine, then files are swapped into place.
 */
class SiteRestoreEngine
{
    public const FILE_MODE_STAGING_SWAP = 'staging_swap';
    public const DB_MODE_STAGING_SWAP = DatabaseRestoreEngine::MODE_STAGING_SWAP;
    public const DB_MODE_OVERWRITE = DatabaseRestoreEngine::MODE_OVERWRITE;
    private const RESTORE_LAST_MU_PLUGINS_DIR = 'anibas-restore-last-mu-plugins';

    private string $job_id;
    private string $state_dir;
    private string $state_file;
    private string $lock_file;

    public function __construct(string $job_id)
    {
        $this->job_id = $this->sanitize_job_id($job_id);
        $this->state_dir = anibas_fm_get_site_restore_state_dir($this->job_id);
        $this->state_file = $this->state_dir . '/state.json';
        $this->lock_file = $this->state_dir . '/lock';
    }

    /**
     * @return array<string,mixed>
     */
    public static function start(string $archive_path, ?string $password, string $db_mode = self::DB_MODE_STAGING_SWAP, bool $preserve_old_data = false): array
    {
        if (self::is_running()) {
            throw new \RuntimeException(esc_html__('A site restore is already in progress.', 'anibas-file-manager'));
        }
        if (anibas_fm_is_backup_running()) {
            throw new \RuntimeException(esc_html__('A site backup is already in progress.', 'anibas-file-manager'));
        }

        $job_id = 'restore_' . wp_generate_password(12, false);
        $engine = new self($job_id);
        $staging_dir = anibas_fm_get_site_restore_staging_dir($job_id);
        $archive_path = wp_normalize_path($archive_path);

        $validator = new SiteBackupPackageValidator();
        $package = $validator->validate($archive_path);
        $required_disk = (int) ($package['size'] ?? 0) + (128 * 1024 * 1024);
        $preflight = function_exists('anibas_fm_runtime_preflight')
            ? anibas_fm_runtime_preflight($staging_dir, $required_disk, 32 * 1024 * 1024, true)
            : ['ok' => true, 'errors' => [], 'warnings' => []];
        if (empty($preflight['ok'])) {
            $errors = is_array($preflight['errors'] ?? null) ? $preflight['errors'] : [];
            throw new \RuntimeException(esc_html(implode(' ', $errors)));
        }

        $state = [
            'job_id' => $job_id,
            'phase' => 'init',
            'archive_path' => $archive_path,
            'archive_name' => basename($archive_path),
            'staging_dir' => $staging_dir,
            'db_mode' => $db_mode === self::DB_MODE_OVERWRITE ? self::DB_MODE_OVERWRITE : self::DB_MODE_STAGING_SWAP,
            'file_mode' => self::FILE_MODE_STAGING_SWAP,
            'preserve_old_data' => $db_mode !== self::DB_MODE_OVERWRITE && $preserve_old_data,
            'package' => $package,
            'archive_progress' => [],
            'database_progress' => [],
            'file_progress' => [],
            'runtime_snapshot' => self::snapshot_runtime_state(),
            'preflight' => $preflight,
            'started_at' => time(),
            'updated_at' => time(),
        ];

        $engine->save_state($state);
        self::deactivate_plugins_for_restore();
        self::set_lock($job_id, basename($archive_path));

        return [
            'job_id' => $job_id,
            'progress' => $engine->progress($state),
        ];
    }

    public static function is_running(): bool
    {
        $lock = get_transient(ANIBAS_FM_SITE_RESTORE_LOCK_KEY);
        return is_array($lock) && ! empty($lock['job_id']);
    }

    /**
     * @return array<string,mixed>|false
     */
    public static function get_lock()
    {
        $lock = get_transient(ANIBAS_FM_SITE_RESTORE_LOCK_KEY);
        return is_array($lock) ? $lock : false;
    }

    public static function clear_lock(): void
    {
        delete_transient(ANIBAS_FM_SITE_RESTORE_LOCK_KEY);
    }

    /**
     * @return array<string,mixed>
     */
    public function run_step(?string $password = null): array
    {
        $lock = $this->acquire_lock();

        try {
            $state = $this->load_state();
            $phase = (string) ($state['phase'] ?? 'init');

            if ($phase === 'init') {
                $archive = (string) $state['archive_path'];
                $staging = (string) $state['staging_dir'];
                $archive_engine = ArchiveRestoreEngine::get_instance($archive, $staging);
                $scan = $archive_engine->prepare_manifest_cache_step($password);
                $state['archive_progress'] = $scan;
                if (empty($scan['complete'])) {
                    $state['updated_at'] = time();
                    $this->save_state($state);
                    return $this->progress($state);
                }
                $state['archive_info'] = [
                    'total'      => (int) ($scan['total'] ?? 0),
                    'total_size' => (int) ($scan['total_size'] ?? 0),
                ];
                $state['phase'] = 'extract';
                $state['updated_at'] = time();
                $this->save_state($state);
                return $this->progress($state);
            }

            if ($phase === 'extract') {
                $archive_engine = ArchiveRestoreEngine::get_instance((string) $state['archive_path'], (string) $state['staging_dir']);
                $more = $archive_engine->run_step($password);
                $state['archive_progress'] = $archive_engine->progress();
                $state['updated_at'] = time();

                if ($more) {
                    $this->save_state($state);
                    return $this->progress($state);
                }

                $archive_engine->cleanup();
                $state['database_manifest'] = $this->locate_database_manifest((string) $state['staging_dir']);
                $state['phase'] = 'restore_database';
                $this->save_state($state);
                return $this->progress($state);
            }

            if ($phase === 'restore_database') {
                $db_progress = $this->run_database_restore_step($state);
                $state['database_progress'] = $db_progress;
                $state['updated_at'] = time();

                if (empty($db_progress['complete'])) {
                    $this->save_state($state);
                    return $this->progress($state);
                }

                self::hold_plugin_activation_until_files_are_ready($state);
                $state['database_complete'] = true;
                $state['phase'] = 'apply_files';
                $this->save_state($state);
                return $this->progress($state);
            }

            if ($phase === 'apply_files') {
                $state['file_progress'] = $this->apply_file_staging_swap((string) $state['staging_dir']);
                if (empty($state['preserve_old_data'])) {
                    $state['phase'] = 'cleanup_old_files';
                    $state['updated_at'] = time();
                    $this->save_state($state);
                    return $this->progress($state);
                }

                self::restore_plugin_activation_after_restore($state);
                $state['phase'] = 'complete';
                $state['completed_at'] = time();
                $state['updated_at'] = time();
                $this->save_state($state);
                $this->cleanup_staging((string) $state['staging_dir']);
                self::clear_lock();
                return $this->progress($state);
            }

            if ($phase === 'cleanup_old_files') {
                $state['old_file_cleanup_progress'] = $this->cleanup_old_files_step($state);
                $state['updated_at'] = time();

                if (empty($state['old_file_cleanup_progress']['complete'])) {
                    $this->save_state($state);
                    return $this->progress($state);
                }

                self::restore_plugin_activation_after_restore($state);
                $state['phase'] = 'complete';
                $state['completed_at'] = time();
                $this->save_state($state);
                $this->cleanup_staging((string) $state['staging_dir']);
                self::clear_lock();
                return $this->progress($state);
            }

            return $this->progress($state);
        } catch (\Throwable $e) {
            $this->mark_failed($e->getMessage());
            throw $e;
        } finally {
            $this->release_lock($lock);
        }
    }

    public function cancel(): void
    {
        $state = is_file($this->state_file) ? $this->load_state() : [];
        if (! $this->can_cancel_state($state)) {
            throw new \RuntimeException(esc_html__('This restore has reached the database stage and can no longer be cancelled safely.', 'anibas-file-manager'));
        }

        $phase = (string) ($state['phase'] ?? '');
        if ($phase !== 'complete' && ! empty($state['staging_dir']) && is_string($state['staging_dir'])) {
            $this->cleanup_staging($state['staging_dir']);
        }
        if (! empty($state['runtime_snapshot']) && is_array($state['runtime_snapshot'])) {
            self::restore_runtime_snapshot($state['runtime_snapshot']);
        }
        $this->cleanup_state();
        self::clear_lock();
    }

    /**
     * @return array<string,mixed>
     */
    public function fallback_to_overwrite(): array
    {
        $lock = $this->acquire_lock();

        try {
            $state = $this->load_state();
            if (! $this->can_retry_overwrite_state($state)) {
                throw new \RuntimeException(esc_html__('This restore is not waiting for an overwrite fallback decision.', 'anibas-file-manager'));
            }

            $manifest = (string) ($state['database_manifest'] ?? '');
            if ($manifest === '' || ! is_file($manifest)) {
                throw new \RuntimeException(esc_html__('Database manifest is missing from this site backup.', 'anibas-file-manager'));
            }

            $db_state_dir = $this->state_dir . '/database-restore';
            $restore = new DatabaseRestoreEngine($this->job_id, $db_state_dir, $manifest);
            $restore->cleanup_staging_for_overwrite_fallback();

            $state['db_mode'] = self::DB_MODE_OVERWRITE;
            $state['phase'] = 'restore_database';
            $state['database_progress'] = [];
            unset($state['database_initialized'], $state['database_complete'], $state['restored_runtime_options']);

            $state['database_progress'] = $this->run_database_restore_step($state);
            $state['updated_at'] = time();
            $this->save_state($state);

            return $this->progress($state);
        } finally {
            $this->release_lock($lock);
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function current_progress(): array
    {
        return $this->progress($this->load_state());
    }

    /**
     * @param array<string,mixed> $state
     */
    private function can_cancel_state(array $state): bool
    {
        $phase = (string) ($state['phase'] ?? '');
        if (in_array($phase, ['', 'init', 'extract'], true)) {
            return true;
        }

        if ($phase !== 'restore_database') {
            return false;
        }

        if (empty($state['database_initialized'])) {
            return true;
        }

        $database = is_array($state['database_progress'] ?? null) ? $state['database_progress'] : [];
        return ! empty($database['can_fallback_overwrite'])
            && ! empty($database['staging_cleanup_complete']);
    }

    /**
     * @param array<string,mixed> $state
     */
    private function can_retry_overwrite_state(array $state): bool
    {
        $database = is_array($state['database_progress'] ?? null) ? $state['database_progress'] : [];
        return (string) ($state['phase'] ?? '') === 'restore_database'
            && (string) ($state['db_mode'] ?? self::DB_MODE_STAGING_SWAP) === self::DB_MODE_STAGING_SWAP
            && ! empty($state['database_initialized'])
            && ! empty($database['can_fallback_overwrite']);
    }

    /**
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private function run_database_restore_step(array &$state): array
    {
        $manifest = (string) ($state['database_manifest'] ?? '');
        if ($manifest === '' || ! is_file($manifest)) {
            throw new \RuntimeException(esc_html__('Database manifest is missing from this site backup.', 'anibas-file-manager'));
        }

        $db_state_dir = $this->state_dir . '/database-restore';
        $restore = new DatabaseRestoreEngine($this->job_id, $db_state_dir, $manifest);
        if (empty($state['database_initialized'])) {
            $state['database_initialized'] = true;
            return $restore->initialize(
                (string) ($state['db_mode'] ?? self::DB_MODE_STAGING_SWAP),
                self::safe_runtime_option_overrides(),
                ! empty($state['preserve_old_data'])
            );
        }

        $progress = $restore->run_step();
        if (! empty($progress['complete'])) {
            $state['restored_runtime_options'] = $restore->captured_runtime_options();
        }

        return $progress;
    }

    private function locate_database_manifest(string $staging_dir): string
    {
        $staging_dir = untrailingslashit(wp_normalize_path($staging_dir));
        if (! is_dir($staging_dir)) {
            throw new \RuntimeException(esc_html__('Restore staging directory is missing.', 'anibas-file-manager'));
        }

        $matches = glob($staging_dir . '/.anibas-site-backup-*/database/manifest.json', GLOB_NOSORT);
        if (is_array($matches) && ! empty($matches) && is_file($matches[0])) {
            return wp_normalize_path($matches[0]);
        }

        $legacy = $staging_dir . '/.anibas-site-backup/database/manifest.json';
        if (is_file($legacy)) {
            return wp_normalize_path($legacy);
        }

        throw new \RuntimeException(esc_html__('This ANFM package does not contain a database backup manifest.', 'anibas-file-manager'));
    }

    /**
     * @return array<string,mixed>
     */
    private function apply_file_staging_swap(string $staging_dir): array
    {
        $root = untrailingslashit(wp_normalize_path(ABSPATH));
        $staging_dir = untrailingslashit(wp_normalize_path($staging_dir));
        if (! is_dir($staging_dir) || ! $this->path_is_inside($staging_dir, $root)) {
            throw new \RuntimeException(esc_html__('Restore staging directory is invalid.', 'anibas-file-manager'));
        }

        $swapped = [];
        $root_swaps = [];
        $content_swap = null;
        $mu_plugins_swap = null;
        $content_relative = ltrim(substr(wp_normalize_path(WP_CONTENT_DIR), strlen($root)), '/');
        $staged_content = $staging_dir . '/' . $content_relative;
        $quarantined_mu_plugins = $this->quarantine_staged_mu_plugins($staging_dir, $staged_content);

        try {
            foreach (['.htaccess', 'index.php', 'wp-cron.php', 'robots.txt'] as $root_file) {
                $source = $staging_dir . '/' . $root_file;
                $target = $root . '/' . $root_file;
                if (! is_file($source)) {
                    continue;
                }

                $old = null;
                if (file_exists($target)) {
                    $old = $this->next_old_path($target);
                    if (! @rename($target, $old)) {
                        throw new \RuntimeException(sprintf(esc_html__('Failed to preserve existing %s before restore.', 'anibas-file-manager'), $root_file));
                    }
                }

                if (! @rename($source, $target)) {
                    if ($old !== null) {
                        @rename($old, $target);
                    }
                    throw new \RuntimeException(sprintf(esc_html__('Failed to restore %s.', 'anibas-file-manager'), $root_file));
                }

                $root_swaps[] = ['target' => $target, 'old' => $old];
                if ($old !== null) {
                    $swapped[$root_file . '_old'] = $old;
                }
            }

            if (is_dir($staged_content)) {
                $old_content = $this->next_old_path(untrailingslashit(wp_normalize_path(WP_CONTENT_DIR)));
                if (! @rename(WP_CONTENT_DIR, $old_content)) {
                    throw new \RuntimeException(esc_html__('Failed to preserve existing wp-content before restore.', 'anibas-file-manager'));
                }
                if (! @rename($staged_content, WP_CONTENT_DIR)) {
                    @rename($old_content, WP_CONTENT_DIR);
                    throw new \RuntimeException(esc_html__('Failed to activate restored wp-content.', 'anibas-file-manager'));
                }
                $content_swap = [
                    'target' => untrailingslashit(wp_normalize_path(WP_CONTENT_DIR)),
                    'old' => $old_content,
                    'staged' => $staged_content,
                ];
                $swapped['wp_content_old'] = $old_content;
            }

            $mu_plugins_swap = $this->restore_quarantined_mu_plugins($quarantined_mu_plugins);
            if (! empty($mu_plugins_swap)) {
                $swapped['mu_plugins_restored_last'] = $mu_plugins_swap['target'];
                if (! empty($mu_plugins_swap['old'])) {
                    $swapped['mu_plugins_old'] = $mu_plugins_swap['old'];
                }
            }
        } catch (\Throwable $e) {
            $this->rollback_mu_plugins_swap($mu_plugins_swap);
            $this->rollback_content_swap($content_swap);
            $this->rollback_root_swaps($root_swaps);
            throw $e;
        }

        return [
            'mode' => self::FILE_MODE_STAGING_SWAP,
            'swapped' => $swapped,
        ];
    }

    private function quarantine_staged_mu_plugins(string $staging_dir, string $staged_content): ?string
    {
        $staged_mu_plugins = untrailingslashit(wp_normalize_path($staged_content)) . '/mu-plugins';
        if (! is_dir($staged_mu_plugins)) {
            return null;
        }

        $target = untrailingslashit(wp_normalize_path($staging_dir)) . '/' . self::RESTORE_LAST_MU_PLUGINS_DIR;
        if (file_exists($target)) {
            throw new \RuntimeException(esc_html__('Restore staging already contains a held MU plugins directory.', 'anibas-file-manager'));
        }
        if (! @rename($staged_mu_plugins, $target)) {
            throw new \RuntimeException(esc_html__('Failed to hold MU plugins until the final restore step.', 'anibas-file-manager'));
        }

        return $target;
    }

    /**
     * @return array{target:string,old:string|null,source:string}|null
     */
    private function restore_quarantined_mu_plugins(?string $quarantined_mu_plugins): ?array
    {
        if ($quarantined_mu_plugins === null || ! is_dir($quarantined_mu_plugins)) {
            return null;
        }

        $target = untrailingslashit(wp_normalize_path(WP_CONTENT_DIR)) . '/mu-plugins';
        $old = null;

        try {
            if (file_exists($target)) {
                $old = $this->next_old_path($target);
                if (! @rename($target, $old)) {
                    throw new \RuntimeException(esc_html__('Failed to preserve existing MU plugins before final restore.', 'anibas-file-manager'));
                }
            }

            if (! @rename($quarantined_mu_plugins, $target)) {
                throw new \RuntimeException(esc_html__('Failed to restore MU plugins as the final file step.', 'anibas-file-manager'));
            }

            return [
                'target' => $target,
                'old' => $old,
                'source' => $quarantined_mu_plugins,
            ];
        } catch (\Throwable $e) {
            if ($old !== null && file_exists($old) && ! file_exists($target)) {
                @rename($old, $target);
            }
            throw $e;
        }
    }

    /**
     * @param array{target:string,old:string|null,source:string}|null $swap
     */
    private function rollback_mu_plugins_swap(?array $swap): void
    {
        if (empty($swap)) {
            return;
        }

        $target = $swap['target'];
        $source = $swap['source'];
        $old = $swap['old'];

        if (is_dir($target) && ! file_exists($source)) {
            @rename($target, $source);
        }
        if ($old !== null && file_exists($old) && ! file_exists($target)) {
            @rename($old, $target);
        }
    }

    /**
     * @param array{target:string,old:string,staged:string}|null $swap
     */
    private function rollback_content_swap(?array $swap): void
    {
        if (empty($swap)) {
            return;
        }

        $target = $swap['target'];
        $old = $swap['old'];
        $staged = $swap['staged'];

        if (is_dir($target) && ! file_exists($staged)) {
            @rename($target, $staged);
        }
        if (is_dir($old) && ! file_exists($target)) {
            @rename($old, $target);
        }
    }

    /**
     * @param array<int,array{target:string,old:string|null}> $swaps
     */
    private function rollback_root_swaps(array $swaps): void
    {
        for ($i = count($swaps) - 1; $i >= 0; $i--) {
            $target = $swaps[$i]['target'];
            $old = $swaps[$i]['old'];
            if (is_file($target)) {
                @unlink($target);
            }
            if ($old !== null && file_exists($old)) {
                @rename($old, $target);
            }
        }
    }

    /**
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private function cleanup_old_files_step(array &$state): array
    {
        if (! isset($state['old_file_cleanup']) || ! is_array($state['old_file_cleanup'])) {
            $state['old_file_cleanup'] = [
                'targets' => $this->old_file_cleanup_targets(is_array($state['file_progress'] ?? null) ? $state['file_progress'] : []),
                'target_index' => 0,
                'stack' => [],
                'deleted' => 0,
            ];
        }

        $cleanup =& $state['old_file_cleanup'];
        $targets = is_array($cleanup['targets'] ?? null) ? array_values($cleanup['targets']) : [];
        $started = microtime(true);
        $budget = function_exists('anibas_fm_safe_time_budget') ? anibas_fm_safe_time_budget(20, 0.6) : 20;

        while ((microtime(true) - $started) < $budget) {
            $stack = is_array($cleanup['stack'] ?? null) ? $cleanup['stack'] : [];
            if (empty($stack)) {
                $index = (int) ($cleanup['target_index'] ?? 0);
                if (! isset($targets[$index])) {
                    $cleanup['complete'] = true;
                    $cleanup['stack'] = [];
                    return $this->old_file_cleanup_progress($cleanup, count($targets));
                }

                $target = (string) $targets[$index];
                $cleanup['target_index'] = $index + 1;
                if (! $this->is_safe_old_restore_path($target)) {
                    continue;
                }
                if (is_dir($target) && ! is_link($target)) {
                    $cleanup['stack'] = [['path' => $target]];
                    continue;
                }
                if (file_exists($target)) {
                    @unlink($target);
                    $cleanup['deleted'] = (int) ($cleanup['deleted'] ?? 0) + 1;
                }
                continue;
            }

            $idx = count($stack) - 1;
            $frame = $stack[$idx];
            $dir = (string) ($frame['path'] ?? '');
            if ($dir === '' || ! is_dir($dir)) {
                array_pop($stack);
                $cleanup['stack'] = $stack;
                continue;
            }

            try {
                $iterator = new \DirectoryIterator($dir);
            } catch (\Throwable $e) {
                array_pop($stack);
                $cleanup['stack'] = $stack;
                continue;
            }

            $done = true;
            for (; $iterator->valid(); $iterator->next()) {
                if ($iterator->isDot()) {
                    continue;
                }

                $path = $iterator->getPathname();
                if ($iterator->isDir() && ! $iterator->isLink()) {
                    $stack[] = ['path' => $path];
                    $done = false;
                    break;
                }

                @unlink($path);
                $cleanup['deleted'] = (int) ($cleanup['deleted'] ?? 0) + 1;

                if ((microtime(true) - $started) >= $budget) {
                    $done = false;
                    break;
                }
            }

            if ($done) {
                @rmdir($dir);
                $cleanup['deleted'] = (int) ($cleanup['deleted'] ?? 0) + 1;
                array_pop($stack);
            }

            $cleanup['stack'] = $stack;
        }

        return $this->old_file_cleanup_progress($cleanup, count($targets));
    }

    /**
     * @param array<string,mixed> $file_progress
     * @return array<int,string>
     */
    private function old_file_cleanup_targets(array $file_progress): array
    {
        $swapped = is_array($file_progress['swapped'] ?? null) ? $file_progress['swapped'] : [];
        $targets = [];
        foreach ($swapped as $key => $path) {
            if (! is_string($key) || ! is_string($path) || $path === '') {
                continue;
            }
            if ($key === 'wp_content_old' || $key === 'mu_plugins_old' || str_ends_with($key, '_old')) {
                $targets[] = wp_normalize_path($path);
            }
        }
        return array_values(array_unique($targets));
    }

    private function is_safe_old_restore_path(string $path): bool
    {
        $path = untrailingslashit(wp_normalize_path($path));
        if ($path === '' || strpos(basename($path), '-old-') === false) {
            return false;
        }

        $root = untrailingslashit(wp_normalize_path(ABSPATH));
        return $this->path_is_inside($path, $root);
    }

    /**
     * @param array<string,mixed> $cleanup
     * @return array<string,mixed>
     */
    private function old_file_cleanup_progress(array $cleanup, int $total): array
    {
        $current = min($total, (int) ($cleanup['target_index'] ?? 0));
        if (! empty($cleanup['stack'])) {
            $current = max(0, $current - 1);
        }

        return [
            'complete' => ! empty($cleanup['complete']),
            'current' => $current,
            'total' => $total,
            'deleted' => (int) ($cleanup['deleted'] ?? 0),
        ];
    }

    private function next_old_path(string $path): string
    {
        $path = untrailingslashit(wp_normalize_path($path));
        $dir = dirname($path);
        $info = pathinfo($path);
        $base = $info['filename'] ?? basename($path);
        $ext = isset($info['extension']) ? '.' . $info['extension'] : '';
        if (is_dir($path)) {
            $base = basename($path);
            $ext = '';
        }

        $stamp = gmdate('YmdHis') . '-' . substr(md5($this->job_id), 0, 8);
        for ($i = 0; $i <= 100; $i++) {
            $suffix = $i === 0 ? $stamp : $stamp . '-' . $i;
            $candidate = $dir . '/' . $base . '-old-' . $suffix . $ext;
            if (! file_exists($candidate)) {
                return $candidate;
            }
        }

        throw new \RuntimeException(esc_html__('Could not choose a recovery name for existing files.', 'anibas-file-manager'));
    }

    /**
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private function progress(array $state): array
    {
        $phase = (string) ($state['phase'] ?? 'init');
        $archive = is_array($state['archive_progress'] ?? null) ? $state['archive_progress'] : [];
        $database = is_array($state['database_progress'] ?? null) ? $state['database_progress'] : [];
        $preflight = is_array($state['preflight'] ?? null) ? $state['preflight'] : [];

        if ($phase === 'extract') {
            return [
                'complete' => false,
                'phase' => 'extract',
                'can_cancel' => $this->can_cancel_state($state),
                'can_fallback_overwrite' => false,
                'current' => (int) ($archive['current'] ?? 0),
                'total' => (int) ($archive['total'] ?? 0),
                'percent' => (float) ($archive['percent'] ?? 0),
                'preflight' => $preflight,
            ];
        }

        if ($phase === 'restore_database') {
            return [
                'complete' => false,
                'phase' => 'restore_database',
                'can_cancel' => $this->can_cancel_state($state),
                'current' => (int) ($database['rows_imported'] ?? 0),
                'total' => (int) ($database['rows_total'] ?? 0),
                'percent' => (float) ($database['percent'] ?? 0),
                'database' => $database,
                'can_fallback_overwrite' => ! empty($database['can_fallback_overwrite']),
                'preflight' => $preflight,
            ];
        }

        if ($phase === 'cleanup_old_files') {
            $cleanup = is_array($state['old_file_cleanup_progress'] ?? null) ? $state['old_file_cleanup_progress'] : [];
            $current = (int) ($cleanup['current'] ?? 0);
            $total = max(1, (int) ($cleanup['total'] ?? 1));
            return [
                'complete' => false,
                'phase' => 'cleanup_old_files',
                'can_cancel' => false,
                'can_fallback_overwrite' => false,
                'current' => $current,
                'total' => $total,
                'percent' => round(min(100, ($current / $total) * 100), 2),
                'preflight' => $preflight,
            ];
        }

        return [
            'complete' => $phase === 'complete',
            'failed' => $phase === 'failed',
            'phase' => $phase,
            'can_cancel' => $this->can_cancel_state($state),
            'can_fallback_overwrite' => false,
            'current' => $phase === 'complete' ? 1 : 0,
            'total' => 1,
            'percent' => $phase === 'complete' ? 100 : 0,
            'error' => isset($state['error']) && is_string($state['error']) ? $state['error'] : '',
            'cleanup_complete' => ! empty($state['cleanup_complete']),
            'preflight' => $preflight,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function load_state(): array
    {
        $state = anibas_fm_read_small_json_file($this->state_file);
        if (! is_array($state)) {
            throw new \RuntimeException(esc_html__('Site restore job not found or expired.', 'anibas-file-manager'));
        }
        return $state;
    }

    /**
     * @param array<string,mixed> $state
     */
    private function save_state(array $state): void
    {
        $tmp = $this->state_file . '.tmp';
        $json = wp_json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (! is_string($json) || @file_put_contents($tmp, $json) === false) {
            throw new \RuntimeException(esc_html__('Failed to write site restore state.', 'anibas-file-manager'));
        }
        if (! @rename($tmp, $this->state_file)) {
            @unlink($tmp);
            throw new \RuntimeException(esc_html__('Failed to commit site restore state.', 'anibas-file-manager'));
        }
    }

    private static function set_lock(string $job_id, string $archive_name): void
    {
        set_transient(ANIBAS_FM_SITE_RESTORE_LOCK_KEY, [
            'job_id' => $job_id,
            'archive' => $archive_name,
            'started_at' => time(),
        ], 2 * HOUR_IN_SECONDS);
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
            throw new \RuntimeException(esc_html__('Another site restore step is already running.', 'anibas-file-manager'));
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

    private function sanitize_job_id(string $job_id): string
    {
        $job_id = preg_replace('/[^A-Za-z0-9_-]/', '', $job_id) ?: '';
        if ($job_id === '') {
            throw new \InvalidArgumentException('Site restore job id is required.');
        }
        return $job_id;
    }

    private function path_is_inside(string $path, string $root): bool
    {
        $path = untrailingslashit(wp_normalize_path($path));
        $root = untrailingslashit(wp_normalize_path($root));
        return $path === $root || str_starts_with($path . '/', trailingslashit($root));
    }

    /**
     * @return array<string,mixed>
     */
    private static function snapshot_runtime_state(): array
    {
        return [
            'active_plugins' => get_option('active_plugins', []),
            'active_sitewide_plugins' => is_multisite() ? get_site_option('active_sitewide_plugins', []) : [],
            'template' => get_option('template', ''),
            'stylesheet' => get_option('stylesheet', ''),
        ];
    }

    private static function deactivate_plugins_for_restore(): void
    {
        $current = self::current_plugin_basename();
        update_option('active_plugins', [$current]);

        if (is_multisite()) {
            update_site_option('active_sitewide_plugins', []);
        }
    }

    /**
     * @param array<string,mixed> $state
     */
    private static function hold_plugin_activation_until_files_are_ready(array &$state): void
    {
        self::deactivate_plugins_for_restore();
    }

    /**
     * @param array<string,mixed> $state
     */
    private static function restore_plugin_activation_after_restore(array $state): void
    {
        if (! empty($state['restored_runtime_options']) && is_array($state['restored_runtime_options'])) {
            self::restore_captured_runtime_options($state['restored_runtime_options']);
            return;
        }

        $current = self::current_plugin_basename();
        $sitewide = is_array($state['restored_active_sitewide_plugins'] ?? null)
            ? self::sanitize_sitewide_plugins($state['restored_active_sitewide_plugins'])
            : [];
        $active = is_array($state['restored_active_plugins'] ?? null)
            ? array_values(array_filter($state['restored_active_plugins'], 'is_string'))
            : [];

        if (! isset($sitewide[$current]) && ! in_array($current, $active, true)) {
            $active[] = $current;
        }

        update_option('active_plugins', array_values(array_unique($active)));
        if (is_multisite()) {
            update_site_option('active_sitewide_plugins', $sitewide);
        }
    }

    /**
     * @return array<string,string>
     */
    private static function safe_runtime_option_overrides(): array
    {
        return [
            'active_plugins' => maybe_serialize([self::current_plugin_basename()]),
            'active_sitewide_plugins' => maybe_serialize([]),
        ];
    }

    /**
     * @param array<string,mixed> $captures
     */
    private static function restore_captured_runtime_options(array $captures): void
    {
        global $wpdb;

        if (! isset($wpdb) || ! $wpdb instanceof \wpdb) {
            return;
        }

        $active_plugins = is_array($captures['active_plugins'] ?? null) ? $captures['active_plugins'] : [];
        foreach ($active_plugins as $table => $serialized_value) {
            if (! is_string($table) || ! DatabaseSafetyPolicy::is_safe_identifier($table) || ! is_scalar($serialized_value)) {
                continue;
            }
            $value = (string) $serialized_value;
            if ($table === $wpdb->options) {
                $value = self::serialized_active_plugins_with_current($value);
            }
            $wpdb->query($wpdb->prepare(
                'UPDATE ' . DatabaseSafetyPolicy::quote_identifier($table) . ' SET option_value = %s WHERE option_name = %s LIMIT 1',
                $value,
                'active_plugins'
            ));
        }

        $sitewide_plugins = is_array($captures['active_sitewide_plugins'] ?? null) ? $captures['active_sitewide_plugins'] : [];
        foreach ($sitewide_plugins as $table => $serialized_value) {
            if (! is_string($table) || ! DatabaseSafetyPolicy::is_safe_identifier($table) || ! is_scalar($serialized_value)) {
                continue;
            }
            $wpdb->query($wpdb->prepare(
                'UPDATE ' . DatabaseSafetyPolicy::quote_identifier($table) . ' SET meta_value = %s WHERE meta_key = %s LIMIT 1',
                (string) $serialized_value,
                'active_sitewide_plugins'
            ));
        }

        self::clear_plugin_activation_caches();
        self::ensure_current_plugin_active();
    }

    private static function serialized_active_plugins_with_current(string $serialized_value): string
    {
        $active = [];
        if (function_exists('is_serialized') && is_serialized($serialized_value)) {
            $decoded = @unserialize($serialized_value, ['allowed_classes' => false]);
            $active = is_array($decoded) ? $decoded : [];
        }
        $active = is_array($active) ? array_values(array_filter($active, 'is_string')) : [];
        $current = self::current_plugin_basename();
        if (! in_array($current, $active, true)) {
            $active[] = $current;
        }
        return maybe_serialize(array_values(array_unique($active)));
    }

    private static function ensure_current_plugin_active(): void
    {
        $current = self::current_plugin_basename();
        $active = get_option('active_plugins', []);
        $active = is_array($active) ? array_values(array_filter($active, 'is_string')) : [];
        $sitewide = is_multisite() ? get_site_option('active_sitewide_plugins', []) : [];
        $sitewide = is_array($sitewide) ? self::sanitize_sitewide_plugins($sitewide) : [];

        if (! isset($sitewide[$current]) && ! in_array($current, $active, true)) {
            $active[] = $current;
            update_option('active_plugins', array_values(array_unique($active)));
        }
    }

    private static function clear_plugin_activation_caches(): void
    {
        wp_cache_delete('active_plugins', 'options');
        wp_cache_delete('alloptions', 'options');

        if (is_multisite()) {
            wp_cache_delete(get_current_network_id() . ':active_sitewide_plugins', 'site-options');
        }
    }

    /**
     * @param array<mixed,mixed> $plugins
     * @return array<string,mixed>
     */
    private static function sanitize_sitewide_plugins(array $plugins): array
    {
        $sanitized = [];
        foreach ($plugins as $plugin => $activated_at) {
            if (is_string($plugin) && $plugin !== '') {
                $sanitized[$plugin] = is_scalar($activated_at) ? $activated_at : time();
            }
        }
        return $sanitized;
    }

    /**
     * @param array<string,mixed> $snapshot
     */
    private static function restore_runtime_snapshot(array $snapshot): void
    {
        if (isset($snapshot['active_plugins']) && is_array($snapshot['active_plugins'])) {
            update_option('active_plugins', array_values(array_filter($snapshot['active_plugins'], 'is_string')));
        }
        if (is_multisite() && isset($snapshot['active_sitewide_plugins']) && is_array($snapshot['active_sitewide_plugins'])) {
            update_site_option('active_sitewide_plugins', $snapshot['active_sitewide_plugins']);
        }
        if (isset($snapshot['template']) && is_string($snapshot['template']) && $snapshot['template'] !== '') {
            update_option('template', $snapshot['template']);
        }
        if (isset($snapshot['stylesheet']) && is_string($snapshot['stylesheet']) && $snapshot['stylesheet'] !== '') {
            update_option('stylesheet', $snapshot['stylesheet']);
        }
    }

    private static function current_plugin_basename(): string
    {
        if (defined('ANIBAS_FILE_MANAGER_PLUGIN_DIR')) {
            $file = trailingslashit(ANIBAS_FILE_MANAGER_PLUGIN_DIR) . 'anibas-file-manager.php';
            if (function_exists('plugin_basename')) {
                return plugin_basename($file);
            }
        }
        return 'anibas-file-manager/anibas-file-manager.php';
    }

    private function mark_failed(string $message): void
    {
        $keep_lock = false;
        try {
            $state = is_file($this->state_file) ? $this->load_state() : [];
            if (! empty($state['runtime_snapshot']) && is_array($state['runtime_snapshot']) && ($state['phase'] ?? '') !== 'complete') {
                self::restore_runtime_snapshot($state['runtime_snapshot']);
            }

            if ($this->cleanup_failed_state($state)) {
                $this->cleanup_state();
                self::clear_lock();
                return;
            }

            $state['phase'] = 'failed';
            $state['error'] = $message;
            $state['cleanup_complete'] = false;
            $state['updated_at'] = time();
            $this->save_state($state);
            $keep_lock = true;
        } catch (\Throwable $ignored) {
            // Best effort recovery state only.
        }
        if (! $keep_lock) {
            self::clear_lock();
        }
    }

    /**
     * @param array<string,mixed> $state
     */
    private function cleanup_failed_state(array $state): bool
    {
        $phase = (string) ($state['phase'] ?? '');
        if (in_array($phase, ['', 'init', 'extract'], true)) {
            if (! empty($state['staging_dir']) && is_string($state['staging_dir'])) {
                $this->cleanup_staging($state['staging_dir']);
            }
            return true;
        }

        if ($phase !== 'restore_database' || ($state['db_mode'] ?? self::DB_MODE_STAGING_SWAP) !== self::DB_MODE_STAGING_SWAP) {
            return false;
        }

        $manifest = (string) ($state['database_manifest'] ?? '');
        if ($manifest !== '' && is_file($manifest)) {
            $db_state_dir = $this->state_dir . '/database-restore';
            $restore = new DatabaseRestoreEngine($this->job_id, $db_state_dir, $manifest);
            if (! $restore->cleanup_failed_staging_restore()) {
                return false;
            }
        }

        if (! empty($state['staging_dir']) && is_string($state['staging_dir'])) {
            $this->cleanup_staging($state['staging_dir']);
        }

        return true;
    }

    private function cleanup_state(): void
    {
        foreach ([$this->state_file, $this->state_file . '.tmp', $this->lock_file] as $file) {
            if (is_file($file)) {
                wp_delete_file($file);
            }
        }
        $this->delete_tree($this->state_dir, 'restore_');
    }

    private function cleanup_staging(string $staging_dir): void
    {
        $this->delete_tree($staging_dir, '.anibas-site-restore-');
    }

    private function delete_tree(string $dir, string $required_name_part): void
    {
        $dir = untrailingslashit(wp_normalize_path($dir));
        if ($dir === '' || ! is_dir($dir) || strpos(basename($dir), $required_name_part) === false) {
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
