<?php

namespace Anibas;

if (! defined('ABSPATH')) exit;

/**
 * Endpoint for the asynchronous background worker loopback.
 */
class WorkerAjaxHandler
{
    public function __construct()
    {
        // We use standard WordPress ajax hooks manually because this needs nopriv support
        // and doesn't fit the standard authenticated AjaxHandler architecture.
        add_action('wp_ajax_anibas_fm_run_worker', [$this, 'handle_worker']);
        add_action('wp_ajax_nopriv_anibas_fm_run_worker', [$this, 'handle_worker']);
    }

    public function handle_worker()
    {
        ActivityLogger::log_message('[WorkerAjaxHandler] handle_worker() triggered via AJAX.');
        $secret = isset($_POST['worker_secret']) ? wp_unslash($_POST['worker_secret']) : '';

        // 1. Verify authorization securely (server-to-server token)
        if (! AsyncWorkerDispatcher::verify_secret($secret)) {
            ActivityLogger::log('blocked', $secret, ' an attempt to run worker with invalid secret');
            wp_die('Unauthorized worker request', 'Unauthorized', 401);
        }

        // 2. Prevent infinite loops / runaway processes by checking if the lock is free.
        // run_worker() natively acquires the lock and will return immediately if it fails.
        
        // 3. Run the worker (this processes 1 chunk of 10 seconds max)
        ActivityLogger::log_message('[WorkerAjaxHandler] Calling BackgroundProcessor::run_worker()');
        BackgroundProcessor::run_worker();
        ActivityLogger::log_message('[WorkerAjaxHandler] BackgroundProcessor::run_worker() completed for this slice.');

        // 4. If there are still pending jobs after this slice, dispatch another async loopback
        // to continue the work in a fresh PHP process, preventing timeout.
        ActivityLogger::log_message('[WorkerAjaxHandler] Re-dispatching AsyncWorkerDispatcher::dispatch() to check/continue remaining jobs.');
        AsyncWorkerDispatcher::dispatch();

        // End the AJAX request cleanly
        ActivityLogger::log_message('[WorkerAjaxHandler] Ending AJAX request cleanly.');
        wp_die();
    }
}
