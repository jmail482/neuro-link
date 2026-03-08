<?php
/**
 * AJAX Worker Trigger — Neuro Link
 * File: includes/class-ajax-worker.php
 *
 * Fires the worker over AJAX so queued tasks process immediately
 * after enqueue — without waiting for WP-Cron's next tick.
 *
 * Usage: POST wp-admin/admin-ajax.php  action=nl_run_worker  nonce=...
 * Called by the chat JS immediately after a successful /run enqueue.
 *
 * @package NeuroLink
 */
namespace NeuroLink;

defined( 'ABSPATH' ) || exit;

class AJAX_Worker {

    public static function register(): void {
        add_action( 'wp_ajax_nl_run_worker', [ __CLASS__, 'handle' ] );
    }

    public static function handle(): void {
        // Verify nonce.
        if ( ! check_ajax_referer( 'nl_worker_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'error' => 'Bad nonce.' ], 403 );
        }

        // Only logged-in users (nonce already scoped, but belt-and-suspenders).
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'error' => 'Not authenticated.' ], 401 );
        }

        // Define context flag so Worker::run() passes its guard.
        if ( ! defined( 'NL_MANUAL_WORKER' ) ) {
            define( 'NL_MANUAL_WORKER', true );
        }

        Worker::run();

        wp_send_json_success( [ 'triggered' => true ] );
    }
}
