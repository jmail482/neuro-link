<?php
/**
 * GUI REST Routes — Neuro Link v0.5.1
 * Fixes: auth uses API_Auth, added /permissions/check, purge endpoint.
 * File: includes/class-rest-api-gui.php
 * @package NeuroLink
 */
namespace NeuroLink;
defined( 'ABSPATH' ) || exit;

class REST_API_GUI {

    const NS = 'neuro-link/v1';

    public function register_routes(): void {
        add_action( 'rest_api_init', [ $this, 'register' ] );
    }

    public function register(): void {
        $auth = [ $this, 'auth' ];

        register_rest_route( self::NS, '/tasks', [
            'methods' => 'GET', 'callback' => [ $this, 'get_tasks' ], 'permission_callback' => $auth,
        ] );
        register_rest_route( self::NS, '/tasks/(?P<id>[a-f0-9\-]{36})/retry', [
            'methods' => 'POST', 'callback' => [ $this, 'retry_task' ], 'permission_callback' => $auth,
        ] );
        register_rest_route( self::NS, '/tasks/(?P<id>[a-f0-9\-]{36})/cancel', [
            'methods' => 'POST', 'callback' => [ $this, 'cancel_task' ], 'permission_callback' => $auth,
        ] );
        register_rest_route( self::NS, '/tasks/purge-dead-letter', [
            'methods' => 'POST', 'callback' => [ $this, 'purge_dead_letter' ], 'permission_callback' => $auth,
        ] );
        register_rest_route( self::NS, '/provider-health', [
            'methods' => 'GET', 'callback' => [ $this, 'get_provider_health' ], 'permission_callback' => $auth,
        ] );
        register_rest_route( self::NS, '/metrics-summary', [
            'methods' => 'GET', 'callback' => [ $this, 'get_metrics_summary' ], 'permission_callback' => $auth,
        ] );
        register_rest_route( self::NS, '/web-fetch', [
            'methods' => 'POST', 'callback' => [ $this, 'web_fetch' ], 'permission_callback' => $auth,
            'args' => [
                'url'    => [ 'required' => true,  'sanitize_callback' => 'esc_url_raw' ],
                'prompt' => [ 'required' => false, 'sanitize_callback' => 'sanitize_textarea_field' ],
            ],
        ] );
        register_rest_route( self::NS, '/permissions/check', [
            'methods' => 'GET', 'callback' => [ $this, 'check_permission' ], 'permission_callback' => $auth,
        ] );
    }

    public function get_tasks( \WP_REST_Request $r ): \WP_REST_Response {
        global $wpdb;
        $table  = $wpdb->prefix . 'neuro_link_tasks';
        $status = sanitize_key( $r->get_param( 'status' ) ?: '' );
        $limit  = min( (int) ( $r->get_param( 'limit' ) ?: 100 ), 500 );
        $where  = $status ? $wpdb->prepare( 'WHERE status = %s', $status ) : '';
        $rows   = $wpdb->get_results(
            "SELECT request_id, task_type, status, attempt_count, max_attempts,
                    error_code, error_message, provider_used, model_used,
                    created_at, updated_at, started_at, finished_at
             FROM $table $where ORDER BY created_at DESC LIMIT $limit",
            ARRAY_A
        ) ?: [];
        return rest_ensure_response( [ 'tasks' => $rows ] );
    }

    public function retry_task( \WP_REST_Request $r ): \WP_REST_Response {
        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'neuro_link_tasks', [
            'status'        => 'pending',
            'attempt_count' => 0,
            'error_code'    => null,
            'error_message' => null,
            'worker_id'     => null,
            'lease_until'   => null,
            'updated_at'    => current_time( 'mysql' ),
        ], [ 'request_id' => $r->get_param( 'id' ) ] );
        return rest_ensure_response( [ 'success' => true ] );
    }

    public function cancel_task( \WP_REST_Request $r ): \WP_REST_Response {
        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'neuro_link_tasks', [
            'status'     => 'cancelled',
            'updated_at' => current_time( 'mysql' ),
        ], [ 'request_id' => $r->get_param( 'id' ) ] );
        return rest_ensure_response( [ 'success' => true ] );
    }

    public function purge_dead_letter(): \WP_REST_Response {
        global $wpdb;
        $deleted = $wpdb->delete( $wpdb->prefix . 'neuro_link_tasks', [ 'status' => 'dead_letter' ] );
        return rest_ensure_response( [ 'success' => true, 'deleted' => (int) $deleted ] );
    }

    public function get_provider_health(): \WP_REST_Response {
        return rest_ensure_response( [ 'health' => ( new Circuit_Breaker() )->get_all_states() ] );
    }

    public function get_metrics_summary(): \WP_REST_Response {
        return rest_ensure_response( [ 'summary' => ( new Metrics() )->get_provider_summary() ] );
    }

    public function web_fetch( \WP_REST_Request $r ): \WP_REST_Response {
        $tool = new Tool_Web_Fetch( [], null );
        return rest_ensure_response( $tool->execute( [
            'url'    => $r->get_param( 'url' ),
            'prompt' => $r->get_param( 'prompt' ) ?: '',
        ] ) );
    }

    public function check_permission( \WP_REST_Request $r ): \WP_REST_Response {
        $cap = sanitize_key( $r->get_param( 'cap' ) ?: '' );
        return rest_ensure_response( [
            'cap'     => $cap,
            'has_cap' => $cap ? current_user_can( $cap ) : false,
        ] );
    }

    public function auth( \WP_REST_Request $r ): bool {
        return API_Auth::rest_permission( $r );
    }
}
