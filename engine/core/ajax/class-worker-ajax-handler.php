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
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- this is a nopriv server-to-server loopback endpoint (dispatched by the background processor itself, not a logged-in browser session), so a WP nonce doesn't apply; the raw secret is only ever compared with hash_equals() in verify_secret() below, never stored or output, so traditional sanitization would just corrupt the comparison.
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
            // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- this endpoint is the background-job worker loopback itself; it intentionally needs to run past the normal request timeout to finish a processing phase, unlike a typical request handler.
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
