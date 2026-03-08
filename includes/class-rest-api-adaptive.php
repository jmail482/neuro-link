<?php
/**
 * Adaptive REST Routes — Neuro Link
 * File: includes/class-rest-api-adaptive.php
 *
 * REST endpoints for FreeGPT35 dev module:
 *   POST /freegpt35/toggle
 *   GET  /freegpt35/status
 *   POST /freegpt35/compare
 *   POST /freegpt35/synthetic
 *   GET  /freegpt35/comparisons
 *
 * @package NeuroLink
 */

namespace NeuroLink;

defined( 'ABSPATH' ) || exit;

class REST_API_Adaptive {

    const NS = 'neuro-link/v1';

    public function register_routes(): void {
        add_action( 'rest_api_init', [ $this, 'register' ] );
    }

    public function register(): void {
        register_rest_route( self::NS, '/freegpt35/toggle', [
            'methods' => 'POST', 'callback' => [ $this, 'toggle_freegpt35' ],
            'permission_callback' => [ $this, 'auth' ],
        ] );
        register_rest_route( self::NS, '/freegpt35/status', [
            'methods' => 'GET', 'callback' => [ $this, 'freegpt35_status' ],
            'permission_callback' => [ $this, 'auth' ],
        ] );
        register_rest_route( self::NS, '/freegpt35/compare', [
            'methods' => 'POST', 'callback' => [ $this, 'run_comparison' ],
            'permission_callback' => [ $this, 'auth' ],
            'args' => [
                'prompt'   => [ 'required' => true,  'sanitize_callback' => 'sanitize_textarea_field' ],
                'provider' => [ 'required' => false, 'sanitize_callback' => 'sanitize_key', 'default' => 'ollama' ],
            ],
        ] );
        register_rest_route( self::NS, '/freegpt35/synthetic', [
            'methods' => 'POST', 'callback' => [ $this, 'generate_synthetic' ],
            'permission_callback' => [ $this, 'auth' ],
            'args' => [
                'topic' => [ 'required' => true,  'sanitize_callback' => 'sanitize_text_field' ],
                'count' => [ 'required' => false, 'default' => 5 ],
            ],
        ] );
        register_rest_route( self::NS, '/freegpt35/comparisons', [
            'methods' => 'GET', 'callback' => [ $this, 'get_comparisons' ],
            'permission_callback' => [ $this, 'auth' ],
        ] );
    }

    public function toggle_freegpt35( \WP_REST_Request $r ): \WP_REST_Response {
        $body    = $r->get_json_params();
        $enabled = ! empty( $body['enabled'] );
        Settings::set( 'freegpt35_enabled', $enabled );
        delete_transient( 'neuro_link_freegpt35_ping' );
        return rest_ensure_response( [ 'success' => true, 'freegpt35_enabled' => $enabled ] );
    }

    public function freegpt35_status(): \WP_REST_Response {
        $comparator = new Adaptive_Comparator( new Circuit_Breaker(), new Metrics() );
        $provider   = new Provider_FreeGPT35();
        return rest_ensure_response( [
            'enabled'     => (bool) Settings::get( 'freegpt35_enabled', false ),
            'available'   => $provider->is_available(),
            'url'         => Settings::get( 'freegpt35_url', Provider_FreeGPT35::DEFAULT_URL ),
            'reliability' => $comparator->get_freegpt35_reliability(),
        ] );
    }

    public function run_comparison( \WP_REST_Request $r ): \WP_REST_Response {
        $pid        = $r->get_param( 'provider' );
        $prompt     = $r->get_param( 'prompt' );
        $comparator = new Adaptive_Comparator( new Circuit_Breaker(), new Metrics() );
        $map        = [ 'ollama' => new Provider_Ollama(), 'openai' => new Provider_OpenAI(), 'anthropic' => new Provider_Anthropic() ];
        $primary    = $map[ $pid ] ?? new Provider_Ollama();
        return rest_ensure_response( $comparator->compare( $prompt, $primary ) );
    }

    public function generate_synthetic( \WP_REST_Request $r ): \WP_REST_Response {
        $comparator = new Adaptive_Comparator( new Circuit_Breaker(), new Metrics() );
        return rest_ensure_response( $comparator->generate_synthetic_data(
            $r->get_param( 'topic' ),
            (int) $r->get_param( 'count' )
        ) );
    }

    public function get_comparisons(): \WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'neuro_link_comparisons';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) !== $table ) return rest_ensure_response( [] );
        return rest_ensure_response( $wpdb->get_results(
            "SELECT request_id, primary_provider, divergence_score, primary_latency, ref_latency, ref_success, created_at
             FROM $table ORDER BY created_at DESC LIMIT 50",
            ARRAY_A
        ) ?: [] );
    }

    public function auth(): bool { return current_user_can( 'manage_options' ); }
}
