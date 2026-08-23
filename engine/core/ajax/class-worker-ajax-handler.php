<?php

/**
 * AJAX handler exposing the asynchronous background worker loopback
 * endpoint used to drive queued jobs.
 *
 * @package Anibas_File_Manager
 */

namespace Anibas;

if (! defined('ABSPATH')) exit;

/**
 * Endpoint for the asynchronous background worker loopback.
 */
class WorkerAjaxHandler
{
    /**
     * Register the worker loopback action on both the authenticated and
     * nopriv AJAX hooks (bypassing the shared AjaxHandler base, since the
     * worker loopback authenticates via a shared secret rather than a
     * logged-in WordPress user).
     */
    public function __construct()
    {
        // We use standard WordPress ajax hooks manually because this needs nopriv support
        // and doesn't fit the standard authenticated AjaxHandler architecture.
        add_action('wp_ajax_anibas_fm_run_worker', [$this, 'handle_worker']);
        add_action('wp_ajax_nopriv_anibas_fm_run_worker', [$this, 'handle_worker']);
    }

    /**
     * Process one time-sliced chunk of queued background jobs, triggered by
     * the async loopback request that AsyncWorkerDispatcher fires after
     * enqueuing work.
     *
     * Verifies the `worker_secret` POST field against
     * AsyncWorkerDispatcher's shared secret and dies with 401 if it
     * doesn't match — this endpoint is nopriv, so the secret is the only
     * authentication. On success, disables user-abort and the PHP time
     * limit so the slice can't be cut short by the client disconnecting or
     * a request timeout, releases any PHP session lock so it doesn't block
     * concurrent polling requests, then delegates to
     * BackgroundProcessor::run_worker() (which itself no-ops if another
     * worker already holds the processing lock). If no other worker is
     * running afterward, re-dispatches itself to continue any remaining
     * queued work; always ends with wp_die() to close the request cleanly.
     */
    public function handle_worker()
    {
        ActivityLogger::log_message('[WorkerAjaxHandler] handle_worker() triggered via AJAX.');
        $secret = isset($_POST['worker_secret']) ? wp_unslash($_POST['worker_secret']) : '';

        // 1. Verify authorization securely (server-to-server token)
        if (! AsyncWorkerDispatcher::verify_secret($secret)) {
            ActivityLogger::log_message('[WorkerAjaxHandler] Blocked worker request with invalid secret.');
            wp_die('Unauthorized worker request', 'Unauthorized', ['response' => 401]);
        }

        // 2. Keep the worker alive even if the dispatcher closed the socket
        // (dispatch uses blocking=false, so the client side won't wait for us).
        ignore_user_abort(true);
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        // 3. Release any active PHP session lock so concurrent requests
        // (polling, re-dispatch, other plugin AJAX) are not blocked.
        if (function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        // 4. Run the worker (processes 1 chunk, time-sliced internally).
        // run_worker() natively acquires the lock and returns immediately if another
        // worker already holds it — so it's safe to call unconditionally.
        ActivityLogger::log_message('[WorkerAjaxHandler] Calling BackgroundProcessor::run_worker()');
        BackgroundProcessor::run_worker();
        ActivityLogger::log_message('[WorkerAjaxHandler] BackgroundProcessor::run_worker() completed for this slice.');

        // 5. Re-dispatch only if no other worker is currently processing. If the lock
        // is still held, another worker is running and will re-dispatch itself when
        // done — dispatching here would just pile up redundant loopback requests.
        if (! BackgroundProcessor::is_worker_locked()) {
            ActivityLogger::log_message('[WorkerAjaxHandler] Re-dispatching AsyncWorkerDispatcher::dispatch() to check/continue remaining jobs.');
            AsyncWorkerDispatcher::dispatch();
        } else {
            ActivityLogger::log_message('[WorkerAjaxHandler] Skipping re-dispatch: another worker holds the lock.');
        }

        // End the AJAX request cleanly
        ActivityLogger::log_message('[WorkerAjaxHandler] Ending AJAX request cleanly.');
        wp_die();
    }
}
