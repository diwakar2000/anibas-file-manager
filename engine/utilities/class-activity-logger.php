<?php

namespace Anibas;

if (! defined('ABSPATH')) exit;


class ActivityLogger
{
    private static $log_file;
    private static $base_path;
    private static $instance = null;
    private static $initialized = false;

    private function __construct()
    {
        self::init();
    }


    /**
     * Get the singleton instance of ActivityLogger.
     *
     * @return ActivityLogger
     */
    public static function get_instance()
    {
        if (! self::$instance) {
            self::$instance = new ActivityLogger();
        }
        return self::$instance;
    }

    public static function init()
    {
        if (self::$initialized) {
            return;
        }

        $log_dir = anibas_fm_get_log_file_path();

        // Check if log file exceeds 100KB (102400 bytes)
        if (file_exists($log_dir . '/.activity.log')) {
            $file_size = filesize($log_dir . '/.activity.log');
            if ($file_size > 102400) {
                self::rotate_logs($log_dir);
            }
        }

        // Check if logs older than 1 month exist and count exceeds 5
        self::cleanup_old_logs($log_dir);

        self::$log_file = $log_dir . '/.activity.log';
        self::$base_path = ABSPATH;
        self::$initialized = true;
    }

    private static function rotate_logs($log_dir)
    {
        $timestamp = microtime(true);
        $random_suffix = $timestamp . wp_rand(10000000, 99999999);

        $old_log_file = $log_dir . '/.activity.log';
        $backup_log_file = $log_dir . '/.activity.' . $random_suffix . '.log';

        // Rename current log to backup
        if (file_exists($old_log_file)) {
            rename($old_log_file, $backup_log_file);
        }
    }

    private static function cleanup_old_logs($log_dir)
    {
        $log_files = glob($log_dir . '/.activity.*.log');

        if (count($log_files) > 5) {
            // Sort by modification time (oldest first)
            usort($log_files, function ($a, $b) {
                return filemtime($a) - filemtime($b);
            });

            // Remove oldest files beyond the 5 most recent
            $files_to_remove = array_slice($log_files, 0, count($log_files) - 5);

            foreach ($files_to_remove as $file) {
                wp_delete_file($file);
            }
        }

        // Check for logs older than 1 month
        $one_month_ago = time() - (30 * 24 * 60 * 60);
        foreach ($log_files as $file) {
            if (filemtime($file) < $one_month_ago) {
                wp_delete_file($file);
            }
        }
    }

    /**
     * Log file operations
     *
     * @param string $action Action performed
     * @param string $item_name Item name
     * @param string $source Source path
     * @param string|null $destination (optional) Destination path
     */
    public static function log($action, $item_name, $source, $destination = null)
    {
        self::initialize();

        $timestamp = gmdate('[ Y/m/d H:i:s ]');
        $source = str_replace(self::$base_path, '', (string) $source);

        if ($destination) {
            $destination = str_replace(self::$base_path, '', (string) $destination);
            $message = "{$timestamp} : {$item_name} {$action} from {$source} to {$destination}";
        } else {
            $message = "{$timestamp} : {$item_name} {$action} at {$source}";
        }

        self::write($message);
    }

    public static function log_message($message)
    {
        self::initialize();
        $timestamp = gmdate('[ Y/m/d H:i:s ]');
        self::write("{$timestamp} : {$message}");
    }

    public static function log_retry_attempt($operation, $attempt, $max_attempts = 3)
    {
        self::initialize();
        $timestamp = gmdate('[ Y/m/d H:i:s ]');
        self::write("{$timestamp} : retry {$operation} (attempt {$attempt}/{$max_attempts})");
    }

    public static function log_retry_timeout($operation, $total_attempts)
    {
        self::initialize();
        $timestamp = gmdate('[ Y/m/d H:i:s ]');
        self::write("{$timestamp} : retry-timeout {$operation} after {$total_attempts} attempts");
    }

    private static function initialize()
    {
        if (! self::$initialized) {
            self::init();
        }
    }

    /**
     * Append a line to the activity log file.
     *
     * Uses file_put_contents directly rather than error_log() (PCP flags
     * error_log as a dev-only function) or WP_Filesystem (not safe to init
     * from arbitrary request context; the log dir is created the same way
     * in anibas_fm_get_log_file_path).
     */
    private static function write($message)
    {
        if (! self::$log_file) {
            return;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents -- dedicated log file, WP_Filesystem not available in all call contexts
        @file_put_contents(self::$log_file, $message . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
