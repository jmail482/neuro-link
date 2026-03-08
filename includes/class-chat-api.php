<?php
/**
 * Chat REST API — Neuro Link Layer 7
 * File: includes/class-chat-api.php
 */

namespace NeuroLink;

defined( 'ABSPATH' ) || exit;

class Chat_API {

    public function register_routes(): void {
        // Guard: skip if WP rewrite isn't ready (CLI / early boot)
        if ( ! did_action( 'wp_loaded' ) && ! function_exists( 'rest_url' ) ) return;

        add_action( 'rest_api_init', function() {
            register_rest_route( 'neuro-link/v1', '/chat', [
                'methods'             => 'POST',
                'callback'            => [ $this, 'send_message' ],
                'permission_callback' => '__return_true',
            ] );
            register_rest_route( 'neuro-link/v1', '/chat/history', [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_history' ],
                'permission_callback' => '__return_true',
            ] );
            register_rest_route( 'neuro-link/v1', '/chat/(?P<id>[a-zA-Z0-9\-]+)', [
                'methods'             => 'GET',
                'callback'            => [ $this, 'poll_message' ],
                'permission_callback' => '__return_true',
            ] );
        } );
    }

    public function send_message( \WP_REST_Request $r ): \WP_REST_Response {
        $body     = $r->get_json_params() ?: [];
        $message  = sanitize_textarea_field( $body['message'] ?? $r->get_param('message') ?? '' );
        $provider = sanitize_text_field( $body['provider'] ?? 'ollama' );

        if ( empty( $message ) ) {
            return new \WP_REST_Response( [ 'error' => 'Message is required.' ], 400 );
        }

        $queue      = new Task_Queue();
        $request_id = $queue->enqueue( [
            'input'   => $message,
            'context' => [
                'source'        => 'zeus_chat',
                'provider_hint' => $provider,
                'timestamp'     => current_time( 'mysql' ),
            ],
        ] );

        return new \WP_REST_Response( [
            'request_id' => $request_id,
            'status'     => 'queued',
            'message'    => $message,
        ], 202 );
    }

    public function poll_message( \WP_REST_Request $r ): \WP_REST_Response {
        $id    = sanitize_text_field( $r['id'] );
        $queue = new Task_Queue();
        $task  = $queue->get_by_request_id( $id );

        if ( ! $task ) return new \WP_REST_Response( [ 'error' => 'Not found.' ], 404 );

        $result_text = '';
        if ( $task['status'] === 'completed' && ! empty( $task['result_json'] ) ) {
            $result      = json_decode( $task['result_json'], true );
            $result_text = $result['text'] ?? wp_json_encode( $result );
        }

        return new \WP_REST_Response( [
            'request_id' => $id,
            'status'     => $task['status'],
            'result'     => $result_text,
            'provider'   => $task['provider_used']  ?? '',
            'latency_ms' => $task['latency_ms']     ?? 0,
            'error'      => $task['error_message']  ?? '',
        ] );
    }

    public function get_history( \WP_REST_Request $r ): \WP_REST_Response {
        global $wpdb;
        $limit = min( (int) ( $r->get_param('limit') ?? 20 ), 100 );
        $rows  = $wpdb->get_results( $wpdb->prepare(
            "SELECT request_id, payload_json, result_json, status, provider_used, created_at
             FROM {$wpdb->prefix}neuro_link_tasks
             WHERE payload_json LIKE %s
             ORDER BY created_at DESC LIMIT %d",
            '%zeus_chat%', $limit
        ), ARRAY_A );

        $history = array_map( function( $row ) {
            $payload = json_decode( $row['payload_json'], true );
            $result  = json_decode( $row['result_json'],  true );
            return [
                'request_id' => $row['request_id'],
                'message'    => $payload['input'] ?? '',
                'result'     => $result['text']   ?? '',
                'status'     => $row['status'],
                'provider'   => $row['provider_used'],
                'created_at' => $row['created_at'],
            ];
        }, $rows ?: [] );

        return new \WP_REST_Response( [ 'history' => $history ] );
    }
}
