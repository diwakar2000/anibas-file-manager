<?php

/**
 * Dispatches non-blocking HTTP loopback requests that drive the background
 * job worker without blocking the requesting page load.
 *
 * @package Anibas_File_Manager
 */

namespace Anibas;

if (! defined('ABSPATH')) exit;

/**
 * Handles dispatching asynchronous, non-blocking HTTP loopback requests
 * to process background jobs without locking the user interface.
 */
class AsyncWorkerDispatcher
{
    private static $secret_key = 'anibas_fm_worker_secret';

    /**
     * Dispatches a non-blocking request to the worker AJAX endpoint.
     */
    public static function dispatch()
    {
        ActivityLogger::log_message('[AsyncWorkerDispatcher] dispatch() called.');
        // Don't dispatch if there's no work to do
        if (! self::has_pending_jobs()) {
            ActivityLogger::log_message('[AsyncWorkerDispatcher] dispatch() aborted: no pending jobs.');
            return false;
        }

        $secret = self::get_or_create_secret();
        $url    = admin_url('admin-ajax.php');
        ActivityLogger::log_message('[AsyncWorkerDispatcher] dispatch() targeting URL: ' . $url);

        // Intentionally NOT passing $_COOKIE: the loopback authenticates via the
        // server-to-server secret and hits the nopriv endpoint. Forwarding cookies
        // would (a) cause PHP session locking when the originating request still
        // holds the session, and (b) unnecessarily authenticate the loopback.
        $args = [
            'timeout'   => 0.1,
            'blocking'  => false,
            // Mirrors WP core's own wp-cron.php loopback: 'https_local_ssl_verify'
            // defaults to false there too, because self-signed/staging certs on the
            // same site would otherwise break the loopback. This request only ever
            // targets admin_url() on the current site, never third-party traffic.
            'sslverify' => apply_filters('https_local_ssl_verify', false), // phpcs:ignore WordPress.WP.SslVerify.enable_ssl_verify_false_use_disable_message
            'body'      => [
                'action'        => 'anibas_fm_run_worker',
                'worker_secret' => $secret,
            ],
        ];

        $response = wp_remote_post($url, $args);
        
        if ( is_wp_error( $response ) ) {
            ActivityLogger::log_message('[AsyncWorkerDispatcher] wp_remote_post failed: ' . $response->get_error_message());
        } else {
            ActivityLogger::log_message('[AsyncWorkerDispatcher] wp_remote_post dispatched successfully.');
        }

        return true;
    }

    /**
     * Checks if there are any jobs that need processing.
     */
    private static function has_pending_jobs()
    {
        $queue = anibas_fm_get_option('anibas_fm_job_queue_v2', []);
        if (empty($queue)) {
            return false;
        }

        foreach ($queue as $job) {
            $status = $job['status'] ?? '';
            if (in_array($status, ['pending', 'processing', 'retrying'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Gets the authorization secret, or generates a new one if missing.
     */
    public static function get_or_create_secret()
    {
        $secret = get_transient(self::$secret_key);
        if (! $secret) {
            $secret = wp_generate_password(32, false);
            // Secret lasts for a day, regenerates automatically
            set_transient(self::$secret_key, $secret, DAY_IN_SECONDS);
        }
        return $secret;
    }

    /**
     * Verifies a provided secret against the stored one.
     */
    public static function verify_secret($provided_secret)
    {
        $stored = get_transient(self::$secret_key);
        if (! $stored || ! $provided_secret) {
            return false;
        }
        return hash_equals($stored, $provided_secret);
    }
}
