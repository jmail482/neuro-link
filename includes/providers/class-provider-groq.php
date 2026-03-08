<?php
/**
 * Groq Provider — Neuro Link
 * File: includes/providers/class-provider-groq.php
 * Model: llama-3.3-70b-versatile (fast route per OMEGA)
 *
 * @package NeuroLink
 */

namespace NeuroLink;

defined( 'ABSPATH' ) || exit;

class Provider_Groq implements Provider {

    const API_URL = 'https://api.groq.com/openai/v1/chat/completions';
    const MODEL   = 'llama-3.3-70b-versatile';

    public function get_id(): string { return 'groq'; }

    public function is_available(): bool {
        return Permissions::is_enabled( 'groq' ) && ! empty( Settings::get_groq_key() );
    }

    public function complete( string $prompt, array $options = [] ): array {
        $start = microtime( true );
        $model = $options['model'] ?? self::MODEL;

        $response = wp_remote_post( self::API_URL, [
            'timeout' => 30,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . Settings::get_groq_key(),
            ],
            'body' => wp_json_encode( [
                'model'       => $model,
                'messages'    => [ [ 'role' => 'user', 'content' => $prompt ] ],
                'temperature' => $options['temperature'] ?? 0.3,
                'max_tokens'  => $options['max_tokens']  ?? 1024,
            ] ),
        ] );

        $ms = (int) round( ( microtime( true ) - $start ) * 1000 );

        if ( is_wp_error( $response ) ) {
            return $this->error( $response->get_error_message(), $ms );
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            $msg  = $body['error']['message'] ?? "HTTP $code";
            return $this->error( $msg, $ms );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        return [
            'success'       => true,
            'text'          => trim( $body['choices'][0]['message']['content'] ?? '' ),
            'input_tokens'  => $body['usage']['prompt_tokens']     ?? null,
            'output_tokens' => $body['usage']['completion_tokens'] ?? null,
            'latency_ms'    => $ms,
            'error_code'    => '',
            'error_message' => '',
        ];
    }

    private function error( string $msg, int $ms ): array {
        return [
            'success'       => false,
            'text'          => '',
            'latency_ms'    => $ms,
            'error_code'    => 'groq_error',
            'error_message' => $msg,
        ];
    }
}
