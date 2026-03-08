<?php
/**
 * FreeGPT35 Provider — Neuro Link
 * File: includes/providers/class-provider-freegpt35.php
 *
 * DEV-ONLY provider. Connects to a local FreeGPT35 instance
 * (https://github.com/ramonvc/freegpt-webui) running on port 3040.
 *
 * NEVER enable in production. Controlled via Settings toggle.
 *
 * @package NeuroLink
 */

namespace NeuroLink;

defined( 'ABSPATH' ) || exit;

class Provider_FreeGPT35 implements Provider {

    /** Default local endpoint — matches FreeGPT35 default port. */
    const DEFAULT_URL = 'http://localhost:3040';

    public function get_id(): string { return 'freegpt35'; }

    /**
     * Only available when:
     * 1. Dev mode is explicitly enabled in Settings.
     * 2. The local endpoint responds to a HEAD ping.
     */
    public function is_available(): bool {
        if ( ! Settings::get( 'freegpt35_enabled', false ) ) return false;
        return $this->ping();
    }

    /**
     * Send a completion request to the local FreeGPT35 instance.
     * Uses OpenAI-compatible /v1/chat/completions endpoint.
     */
    public function complete( string $prompt, array $options = [] ): array {
        $start = microtime( true );
        $url   = rtrim( Settings::get( 'freegpt35_url', self::DEFAULT_URL ), '/' ) . '/v1/chat/completions';

        // PII guard — never send sensitive data.
        if ( $this->contains_sensitive_data( $prompt ) ) {
            return $this->error( 'Prompt blocked: contains potentially sensitive data.', 0 );
        }

        $response = wp_remote_post( $url, [
            'timeout' => 30,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer gptyyds', // Any string accepted by FreeGPT35
            ],
            'body' => wp_json_encode( [
                'model'       => 'gpt-3.5-turbo',
                'messages'    => [ [ 'role' => 'user', 'content' => $prompt ] ],
                'temperature' => $options['temperature'] ?? 0.7,
            ] ),
        ] );

        $ms = (int) round( ( microtime( true ) - $start ) * 1000 );

        if ( is_wp_error( $response ) ) return $this->error( $response->get_error_message(), $ms );

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) return $this->error( "HTTP $code", $ms );

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $text = trim( $body['choices'][0]['message']['content'] ?? '' );

        if ( empty( $text ) ) return $this->error( 'Empty response from FreeGPT35.', $ms );

        return [
            'success'       => true,
            'text'          => $text,
            'input_tokens'  => null,
            'output_tokens' => null,
            'latency_ms'    => $ms,
            'error_code'    => '',
            'error_message' => '',
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Quick HEAD ping to check if local server is up. Cached for 60s. */
    private function ping(): bool {
        $cache_key = 'neuro_link_freegpt35_ping';
        $cached    = get_transient( $cache_key );
        if ( $cached !== false ) return (bool) $cached;

        $url      = rtrim( Settings::get( 'freegpt35_url', self::DEFAULT_URL ), '/' );
        $response = wp_remote_head( $url, [ 'timeout' => 3 ] );
        $alive    = ! is_wp_error( $response );

        set_transient( $cache_key, $alive ? 1 : 0, 60 );
        return $alive;
    }

    /**
     * Basic PII / credential guard.
     * Blocks prompts containing patterns like API keys, emails, passwords.
     */
    private function contains_sensitive_data( string $text ): bool {
        $patterns = [
            '/\bsk-[A-Za-z0-9]{20,}\b/',      // OpenAI-style keys
            '/\bghp_[A-Za-z0-9]{30,}\b/',      // GitHub tokens
            '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z]{2,}\b/i', // Emails
            '/password\s*[:=]\s*\S+/i',         // password = ...
            '/api[_-]?key\s*[:=]\s*\S+/i',      // api_key = ...
        ];
        foreach ( $patterns as $pattern ) {
            if ( preg_match( $pattern, $text ) ) return true;
        }
        return false;
    }

    private function error( string $msg, int $ms ): array {
        return [
            'success'       => false,
            'text'          => '',
            'latency_ms'    => $ms,
            'error_code'    => 'freegpt35_error',
            'error_message' => $msg,
        ];
    }
}
