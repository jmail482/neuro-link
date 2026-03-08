<?php
/**
 * OpenAI Provider — Neuro Link
 * File: includes/providers/class-provider-openai.php
 *
 * @package NeuroLink
 */

namespace NeuroLink;

defined( 'ABSPATH' ) || exit;

class Provider_OpenAI implements Provider {

    public function get_id(): string { return 'openai'; }

    public function is_available(): bool {
        return Permissions::is_enabled( 'openai' ) && ! empty( Settings::get_openai_key() );
    }

    public function complete( string $prompt, array $options = [] ): array {
        $start = microtime( true );
        $model = $options['model'] ?? 'gpt-4o-mini';

        $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
            'timeout' => 30,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . Settings::get_openai_key(),
            ],
            'body' => wp_json_encode( [
                'model'    => $model,
                'messages' => [ [ 'role' => 'user', 'content' => $prompt ] ],
            ] ),
        ] );

        $ms = (int) round( ( microtime( true ) - $start ) * 1000 );

        if ( is_wp_error( $response ) ) {
            return $this->error( $response->get_error_message(), $ms );
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            return $this->error( "HTTP $code", $ms );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        return [
            'success'       => true,
            'text'          => trim( $body['choices'][0]['message']['content'] ?? '' ),
            'input_tokens'  => $body['usage']['prompt_tokens'] ?? null,
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
            'error_code'    => 'openai_error',
            'error_message' => $msg,
        ];
    }
}
