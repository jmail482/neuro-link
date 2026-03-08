<?php
/**
 * Anthropic Provider — Neuro Link
 * File: includes/providers/class-provider-anthropic.php
 *
 * @package NeuroLink
 */

namespace NeuroLink;

defined( 'ABSPATH' ) || exit;

class Provider_Anthropic implements Provider {

    public function get_id(): string { return 'anthropic'; }

    public function is_available(): bool {
        return Permissions::is_enabled( 'anthropic' ) && ! empty( Settings::get_anthropic_key() );
    }

    public function complete( string $prompt, array $options = [] ): array {
        $start = microtime( true );
        $model = $options['model'] ?? 'claude-haiku-4-5-20251001';

        $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
            'timeout' => 30,
            'headers' => [
                'Content-Type'      => 'application/json',
                'x-api-key'         => Settings::get_anthropic_key(),
                'anthropic-version' => '2023-06-01',
            ],
            'body' => wp_json_encode( [
                'model'      => $model,
                'max_tokens' => 1024,
                'messages'   => [ [ 'role' => 'user', 'content' => $prompt ] ],
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
            'text'          => trim( $body['content'][0]['text'] ?? '' ),
            'input_tokens'  => $body['usage']['input_tokens'] ?? null,
            'output_tokens' => $body['usage']['output_tokens'] ?? null,
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
            'error_code'    => 'anthropic_error',
            'error_message' => $msg,
        ];
    }
}
