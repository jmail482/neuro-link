<?php
/**
 * REST API — Neuro Link v0.5.1
 * Fixes: token auth on all routes, /health endpoint, Groq in providers list.
 * File: includes/class-rest-api.php
 * @package NeuroLink
 */
namespace NeuroLink;
defined( 'ABSPATH' ) || exit;

class REST_API {

    const NAMESPACE = 'neuro-link/v1';

    public function register_routes(): void {
        add_action( 'rest_api_init', [ $this, 'register' ] );
    }

    public function register(): void {
        $auth = [ $this, 'auth' ];

        register_rest_route( self::NAMESPACE, '/health', [
            'methods' => 'GET', 'callback' => [ $this, 'handle_health' ],
            'permission_callback' => '__return_true', // public ping endpoint
        ] );

        register_rest_route( self::NAMESPACE, '/run', [
            'methods' => 'POST', 'callback' => [ $this, 'handle_run' ],
            'permission_callback' => $auth,
            'args' => [ 'input' => [ 'required' => true, 'sanitize_callback' => 'sanitize_textarea_field' ] ],
        ] );

        register_rest_route( self::NAMESPACE, '/status/(?P<request_id>[a-f0-9\-]{36})', [
            'methods' => 'GET', 'callback' => [ $this, 'handle_status' ],
            'permission_callback' => $auth,
        ] );

        register_rest_route( self::NAMESPACE, '/chat', [
            'methods' => 'POST', 'callback' => [ $this, 'handle_chat' ],
            'permission_callback' => $auth,
            'args' => [
                'input'    => [ 'required' => true,  'sanitize_callback' => 'sanitize_textarea_field' ],
                'provider' => [ 'required' => false, 'sanitize_callback' => 'sanitize_key', 'default' => 'ollama' ],
                'model'    => [ 'required' => false, 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/multi-chat', [
            'methods' => 'POST', 'callback' => [ $this, 'handle_multi_chat' ],
            'permission_callback' => $auth,
            'args' => [
                'input'     => [ 'required' => true, 'sanitize_callback' => 'sanitize_textarea_field' ],
                'providers' => [ 'required' => false, 'default' => [] ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/github-chat', [
            'methods' => 'POST', 'callback' => [ $this, 'handle_github_chat' ],
            'permission_callback' => $auth,
            'args' => [
                'messages' => [ 'required' => true ],
                'model'    => [ 'required' => false, 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/providers', [
            'methods' => 'GET', 'callback' => [ $this, 'handle_providers' ],
            'permission_callback' => $auth,
        ] );

        register_rest_route( self::NAMESPACE, '/ollama-models', [
            'methods' => 'GET', 'callback' => [ $this, 'handle_ollama_models' ],
            'permission_callback' => $auth,
        ] );

        register_rest_route( self::NAMESPACE, '/settings', [
            'methods' => 'POST', 'callback' => [ $this, 'handle_save_settings' ],
            'permission_callback' => $auth,
        ] );
    }

    // ── Health ping ───────────────────────────────────────────────────────────
    public function handle_health(): \WP_REST_Response {
        return rest_ensure_response( [
            'status'  => 'ok',
            'version' => NEURO_LINK_VERSION,
            'time'    => current_time( 'mysql' ),
        ] );
    }

    // ── Async queue ───────────────────────────────────────────────────────────
    public function handle_run( \WP_REST_Request $r ): \WP_REST_Response {
        $input = $r->get_param( 'input' );
        $id    = ( new Task_Queue() )->enqueue(
            [ 'input' => $input, 'source' => 'rest' ],
            'default',
            sanitize_key( md5( $input . get_current_user_id() ) )
        );
        return rest_ensure_response( [ 'request_id' => $id, 'status' => 'queued' ] );
    }

    public function handle_status( \WP_REST_Request $r ): \WP_REST_Response {
        $s = ( new Task_Queue() )->get_status( $r->get_param( 'request_id' ) );
        return $s
            ? rest_ensure_response( $s )
            : new \WP_REST_Response( [ 'error' => 'Not found.' ], 404 );
    }

    // ── Chat ──────────────────────────────────────────────────────────────────
    public function handle_chat( \WP_REST_Request $r ): \WP_REST_Response {
        $pid      = $r->get_param( 'provider' );
        $model    = $r->get_param( 'model' );
        $provider = $this->resolve_provider( $pid );
        if ( ! $provider ) {
            return new \WP_REST_Response( [ 'success' => false, 'error' => "Provider '$pid' not available." ], 400 );
        }
        $result = $provider->complete( $r->get_param( 'input' ), $model ? [ 'model' => $model ] : [] );
        return rest_ensure_response( array_merge( $result, [
            'provider' => $pid,
            'model'    => $model ?: Settings::get_provider_model( $pid ),
        ] ) );
    }

    public function handle_multi_chat( \WP_REST_Request $r ): \WP_REST_Response {
        $input   = $r->get_param( 'input' );
        $all     = $this->get_all_providers();
        $targets = $r->get_param( 'providers' ) ?: array_keys( $all );
        $results = [];
        foreach ( $targets as $pid ) {
            $p = $all[ $pid ] ?? null;
            if ( ! $p || ! $p->is_available() ) {
                $results[ $pid ] = [ 'success' => false, 'provider' => $pid, 'error' => 'Not available.', 'text' => '', 'latency_ms' => 0 ];
                continue;
            }
            $res = $p->complete( $input );
            $res['provider'] = $pid;
            $res['model']    = Settings::get_provider_model( $pid );
            $results[ $pid ] = $res;
        }
        return rest_ensure_response( [ 'results' => $results, 'input' => $input ] );
    }

    // ── GitHub tool-calling ───────────────────────────────────────────────────
    public function handle_github_chat( \WP_REST_Request $r ): \WP_REST_Response {
        $messages = $r->get_param( 'messages' );
        $model    = $r->get_param( 'model' );
        if ( ! is_array( $messages ) || empty( $messages ) ) {
            return new \WP_REST_Response( [ 'success' => false, 'error' => 'messages required.' ], 400 );
        }
        $clean = [];
        foreach ( $messages as $m ) {
            $role    = sanitize_key( $m['role'] ?? 'user' );
            $content = sanitize_textarea_field( $m['content'] ?? '' );
            if ( $role && $content ) $clean[] = [ 'role' => $role, 'content' => $content ];
        }
        $opts = $model ? [ 'model' => $model ] : [];
        return rest_ensure_response(
            ( new Provider_Ollama() )->chat_with_tools( $clean, Settings::get_github_token(), $opts )
        );
    }

    // ── Ollama model list ─────────────────────────────────────────────────────
    public function handle_ollama_models(): \WP_REST_Response {
        $url  = Settings::get_ollama_url() . '/api/tags';
        $resp = wp_remote_get( $url, [ 'timeout' => 5 ] );
        if ( is_wp_error( $resp ) ) {
            return rest_ensure_response( [ 'models' => [], 'error' => $resp->get_error_message() ] );
        }
        $body   = json_decode( wp_remote_retrieve_body( $resp ), true );
        $models = array_map( fn( $m ) => [
            'name'   => $m['name'],
            'size'   => $m['details']['parameter_size'] ?? '',
            'family' => $m['details']['family'] ?? '',
            'remote' => ! empty( $m['remote_model'] ),
        ], $body['models'] ?? [] );
        return rest_ensure_response( [ 'models' => $models ] );
    }

    // ── Providers list ────────────────────────────────────────────────────────
    public function handle_providers(): \WP_REST_Response {
        $out = [];
        foreach ( $this->get_all_providers() as $id => $p ) {
            $out[ $id ] = [
                'id'        => $id,
                'label'     => Settings::get_provider_label( $id ),
                'available' => $p->is_available(),
                'model'     => Settings::get_provider_model( $id ),
            ];
        }
        return rest_ensure_response( $out );
    }

    // ── Settings ──────────────────────────────────────────────────────────────
    public function handle_save_settings( \WP_REST_Request $r ): \WP_REST_Response {
        $body = $r->get_json_params();
        if ( empty( $body ) ) {
            return new \WP_REST_Response( [ 'success' => false, 'error' => 'No data.' ], 400 );
        }
        $allowed = [
            'ollama_url', 'ollama_model',
            'openai_api_key', 'openai_model',
            'anthropic_api_key', 'anthropic_model',
            'groq_api_key', 'groq_model',
            'github_token', 'freegpt35_url',
        ];
        foreach ( $allowed as $k ) {
            if ( isset( $body[ $k ] ) ) Settings::set( $k, sanitize_text_field( $body[ $k ] ) );
        }
        if ( isset( $body['providers_enabled'] ) && is_array( $body['providers_enabled'] ) ) {
            Settings::set( 'providers_enabled', array_map( 'sanitize_key', $body['providers_enabled'] ) );
        }
        return rest_ensure_response( [ 'success' => true ] );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** FIX: Include Groq in provider list */
    private function get_all_providers(): array {
        return [
            'ollama'    => new Provider_Ollama(),
            'openai'    => new Provider_OpenAI(),
            'anthropic' => new Provider_Anthropic(),
            'groq'      => new Provider_Groq(),
        ];
    }

    private function resolve_provider( string $id ): ?Provider {
        $p = $this->get_all_providers()[ $id ] ?? null;
        return ( $p && $p->is_available() ) ? $p : null;
    }

    /** FIX: Accept WP session OR Bearer token */
    public function auth( \WP_REST_Request $r ): bool {
        return API_Auth::rest_permission( $r );
    }
}
