<?php
/**
 * Zapier Webhook Receiver — Neuro Link
 * File: includes/class-zapier-webhook.php
 *
 * Receives inbound webhooks from Zapier and pushes tasks into the queue.
 * Also provides an outbound notifier to send results back to Zapier.
 *
 * Endpoints:
 *   POST /wp-json/neuro-link/v1/zapier/inbound   — Zapier sends data → queue task
 *   POST /wp-json/neuro-link/v1/zapier/register  — Save Zapier webhook URL for outbound
 *   GET  /wp-json/neuro-link/v1/zapier/status     — Connection status + recent events
 *
 * @package NeuroLink
 */

namespace NeuroLink;

defined( 'ABSPATH' ) || exit;

class Zapier_Webhook {

    const NS          = 'neuro-link/v1';
    const SECRET_KEY  = 'neuro_link_zapier_secret';
    const WEBHOOK_URL = 'neuro_link_zapier_outbound_url';
    const LOG_KEY     = 'neuro_link_zapier_log';
    const MAX_LOG     = 50;

    public function register_routes(): void {
        add_action( 'rest_api_init', [ $this, 'register' ] );
    }

    public function register(): void {
        // Inbound: Zapier → Neuro Link
        register_rest_route( self::NS, '/zapier/inbound', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_inbound' ],
            'permission_callback' => [ $this, 'verify_secret' ],
        ] );

        // Register outbound webhook URL (where Zapier listens for results)
        register_rest_route( self::NS, '/zapier/register', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_register' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
            'args'                => [
                'webhook_url' => [ 'required' => true, 'sanitize_callback' => 'esc_url_raw' ],
                'secret'      => [ 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        // Status
        register_rest_route( self::NS, '/zapier/status', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'handle_status' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
        ] );
    }

    // ── Inbound ───────────────────────────────────────────────────────────────

    /**
     * Receive payload from Zapier, map to a task, enqueue it.
     *
     * Expected payload (Zapier sends this as JSON body):
     * {
     *   "task_type": "chat|web_fetch|seo|custom",   (optional, default "zapier_task")
     *   "prompt":    "...",                          (text to process)
     *   "url":       "https://...",                  (for web_fetch tasks)
     *   "data":      { ... },                        (arbitrary extra data)
     *   "callback_url": "https://hooks.zapier.com/..." (optional per-zap override)
     * }
     */
    public function handle_inbound( \WP_REST_Request $r ): \WP_REST_Response {
        $body      = $r->get_json_params() ?: [];
        $task_type = sanitize_key( $body['task_type'] ?? 'zapier_task' );
        $prompt    = sanitize_textarea_field( $body['prompt'] ?? '' );
        $url       = esc_url_raw( $body['url'] ?? '' );
        $data      = $body['data'] ?? [];
        $cb_url    = esc_url_raw( $body['callback_url'] ?? '' );

        if ( empty( $prompt ) && empty( $url ) ) {
            return new \WP_REST_Response( [ 'success' => false, 'error' => 'prompt or url is required.' ], 400 );
        }

        // Build payload for the queue.
        $payload = [
            'source'       => 'zapier',
            'task_type'    => $task_type,
            'prompt'       => $prompt,
            'url'          => $url,
            'data'         => $data,
            'callback_url' => $cb_url ?: get_option( self::WEBHOOK_URL, '' ),
        ];

        $queue      = new Task_Queue();
        $request_id = $queue->enqueue( $payload, $task_type );

        // Log the event.
        $this->log_event( 'inbound', [
            'request_id' => $request_id,
            'task_type'  => $task_type,
            'has_prompt' => ! empty( $prompt ),
            'has_url'    => ! empty( $url ),
        ] );

        return rest_ensure_response( [
            'success'    => true,
            'request_id' => $request_id,
            'status'     => 'queued',
            'status_url' => rest_url( self::NS . '/status/' . $request_id ),
        ] );
    }

    // ── Register outbound URL ─────────────────────────────────────────────────

    public function handle_register( \WP_REST_Request $r ): \WP_REST_Response {
        $webhook_url = $r->get_param( 'webhook_url' );
        $secret      = $r->get_param( 'secret' ) ?: wp_generate_password( 32, false );

        update_option( self::WEBHOOK_URL, $webhook_url );
        update_option( self::SECRET_KEY,  $secret );

        return rest_ensure_response( [
            'success'     => true,
            'webhook_url' => $webhook_url,
            'secret'      => $secret,
            'inbound_url' => rest_url( self::NS . '/zapier/inbound' ),
        ] );
    }

    // ── Status ────────────────────────────────────────────────────────────────

    public function handle_status(): \WP_REST_Response {
        return rest_ensure_response( [
            'connected'      => ! empty( get_option( self::WEBHOOK_URL, '' ) ),
            'outbound_url'   => get_option( self::WEBHOOK_URL, '' ),
            'inbound_url'    => rest_url( self::NS . '/zapier/inbound' ),
            'secret_set'     => ! empty( get_option( self::SECRET_KEY, '' ) ),
            'recent_events'  => $this->get_log(),
        ] );
    }

    // ── Outbound: send result back to Zapier ──────────────────────────────────

    /**
     * Call this after a zapier_task completes to push result to Zapier.
     * Hook into the worker completion flow.
     */
    public static function notify_zapier( string $request_id, array $result, string $callback_url = '' ): bool {
        $url = $callback_url ?: get_option( self::WEBHOOK_URL, '' );
        if ( empty( $url ) ) return false;

        $secret  = get_option( self::SECRET_KEY, '' );
        $payload = wp_json_encode( [
            'request_id' => $request_id,
            'success'    => $result['success'] ?? false,
            'text'       => $result['text']    ?? '',
            'data'       => $result,
            'timestamp'  => time(),
        ] );

        $response = wp_remote_post( $url, [
            'timeout' => 10,
            'headers' => [
                'Content-Type'       => 'application/json',
                'X-NeuroLink-Secret' => $secret,
            ],
            'body' => $payload,
        ] );

        $ok = ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) < 300;

        ( new self() )->log_event( 'outbound', [
            'request_id' => $request_id,
            'url'        => $url,
            'success'    => $ok,
        ] );

        return $ok;
    }

    // ── Auth: verify shared secret ────────────────────────────────────────────

    public function verify_secret( \WP_REST_Request $r ): bool {
        $stored = get_option( self::SECRET_KEY, '' );

        // If no secret configured yet, allow (first-time setup).
        if ( empty( $stored ) ) return true;

        // Check header first, then query param.
        $incoming = $r->get_header( 'x-neurolink-secret' )
                 ?: $r->get_param( 'secret' )
                 ?: '';

        return hash_equals( $stored, $incoming );
    }

    // ── Event log ─────────────────────────────────────────────────────────────

    private function log_event( string $direction, array $data ): void {
        $log   = get_option( self::LOG_KEY, [] );
        $log[] = array_merge( $data, [ 'direction' => $direction, 'time' => current_time( 'mysql' ) ] );
        if ( count( $log ) > self::MAX_LOG ) {
            $log = array_slice( $log, - self::MAX_LOG );
        }
        update_option( self::LOG_KEY, $log );
    }

    private function get_log(): array {
        $log = get_option( self::LOG_KEY, [] );
        return array_reverse( $log ); // newest first
    }
}
