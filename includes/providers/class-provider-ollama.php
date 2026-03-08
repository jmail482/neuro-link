<?php
/**
 * Ollama Provider — Neuro Link
 * File: includes/providers/class-provider-ollama.php
 * Supports: plain generate + tool-use chat (GitHub fetch)
 *
 * @package NeuroLink
 */

namespace NeuroLink;

defined( 'ABSPATH' ) || exit;

class Provider_Ollama implements Provider {

    public function get_id(): string { return 'ollama'; }

    public function is_available(): bool {
        return Permissions::is_enabled( 'ollama' );
    }

    /** Plain generate — single turn. */
    public function complete( string $prompt, array $options = [] ): array {
        $url   = Settings::get_ollama_url() . '/api/generate';
        $model = $options['model'] ?? Settings::get_ollama_model();
        $start = microtime( true );

        $response = wp_remote_post( $url, [
            'timeout' => 120,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( [ 'model' => $model, 'prompt' => $prompt, 'stream' => false ] ),
        ] );

        $ms = (int) round( ( microtime( true ) - $start ) * 1000 );
        if ( is_wp_error( $response ) ) return $this->error( $response->get_error_message(), $ms );
        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) return $this->error( "HTTP $code", $ms );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        return [
            'success' => true, 'text' => trim( $body['response'] ?? '' ),
            'input_tokens' => null, 'output_tokens' => null,
            'latency_ms' => $ms, 'error_code' => '', 'error_message' => '',
        ];
    }

    /**
     * Multi-turn chat with GitHub tool calling.
     *
     * Ollama tool definition: fetch_github_file(owner, repo, path, ref?)
     * PHP middleware fetches the file from GitHub and loops back.
     *
     * @param array  $messages  [['role'=>'user'|'assistant'|'tool', 'content'=>'...']]
     * @param string $gh_token  Optional GitHub PAT for private repos.
     * @param array  $options   model, max_loops
     */
    public function chat_with_tools( array $messages, string $gh_token = '', array $options = [] ): array {
        $url        = Settings::get_ollama_url() . '/api/chat';
        $model      = $options['model'] ?? Settings::get_ollama_model();
        $max_loops  = $options['max_loops'] ?? 6;
        $total_ms   = 0;
        $tool_calls_log = [];

        $tools = [ $this->github_tool_definition() ];

        for ( $i = 0; $i < $max_loops; $i++ ) {
            $start = microtime( true );

            $response = wp_remote_post( $url, [
                'timeout' => 120,
                'headers' => [ 'Content-Type' => 'application/json' ],
                'body'    => wp_json_encode( [
                    'model'    => $model,
                    'messages' => $messages,
                    'tools'    => $tools,
                    'stream'   => false,
                ] ),
            ] );

            $ms        = (int) round( ( microtime( true ) - $start ) * 1000 );
            $total_ms += $ms;

            if ( is_wp_error( $response ) ) return $this->error( $response->get_error_message(), $total_ms );
            $code = wp_remote_retrieve_response_code( $response );
            if ( $code !== 200 ) return $this->error( "HTTP $code", $total_ms );

            $body    = json_decode( wp_remote_retrieve_body( $response ), true );
            $msg     = $body['message'] ?? [];
            $content = $msg['content'] ?? '';
            $calls   = $msg['tool_calls'] ?? [];

            // No tool calls — final answer.
            if ( empty( $calls ) ) {
                return [
                    'success'        => true,
                    'text'           => trim( $content ),
                    'tool_calls_log' => $tool_calls_log,
                    'input_tokens'   => null,
                    'output_tokens'  => null,
                    'latency_ms'     => $total_ms,
                    'error_code'     => '',
                    'error_message'  => '',
                ];
            }

            // Append assistant message with tool calls.
            $messages[] = [ 'role' => 'assistant', 'content' => $content, 'tool_calls' => $calls ];

            // Execute each tool call.
            foreach ( $calls as $call ) {
                $fn_name = $call['function']['name'] ?? '';
                $args    = $call['function']['arguments'] ?? [];
                if ( is_string( $args ) ) $args = json_decode( $args, true ) ?: [];

                $tool_result = $this->execute_tool( $fn_name, $args, $gh_token );
                $tool_calls_log[] = [ 'tool' => $fn_name, 'args' => $args, 'ok' => $tool_result['success'] ];

                $messages[] = [
                    'role'    => 'tool',
                    'content' => $tool_result['content'],
                ];
            }
        }

        return $this->error( 'Max tool loops reached without final answer.', $total_ms );
    }

    // ── Tool definitions ─────────────────────────────────────────────────────

    private function github_tool_definition(): array {
        return [
            'type'     => 'function',
            'function' => [
                'name'        => 'fetch_github_file',
                'description' => 'Fetch the raw content of a file from a GitHub repository. Use this whenever you need to read code, config, or documentation from GitHub.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'owner' => [ 'type' => 'string', 'description' => 'GitHub username or org' ],
                        'repo'  => [ 'type' => 'string', 'description' => 'Repository name' ],
                        'path'  => [ 'type' => 'string', 'description' => 'File path within the repo, e.g. src/index.php' ],
                        'ref'   => [ 'type' => 'string', 'description' => 'Branch, tag, or commit SHA. Defaults to main.' ],
                    ],
                    'required' => [ 'owner', 'repo', 'path' ],
                ],
            ],
        ];
    }

    // ── Tool execution ────────────────────────────────────────────────────────

    private function execute_tool( string $name, array $args, string $gh_token ): array {
        if ( $name === 'fetch_github_file' ) {
            return $this->fetch_github_file(
                $args['owner'] ?? '',
                $args['repo']  ?? '',
                $args['path']  ?? '',
                $args['ref']   ?? 'main',
                $gh_token
            );
        }
        return [ 'success' => false, 'content' => "Unknown tool: $name" ];
    }

    private function fetch_github_file( string $owner, string $repo, string $path, string $ref, string $token ): array {
        if ( ! $owner || ! $repo || ! $path ) {
            return [ 'success' => false, 'content' => 'fetch_github_file: missing owner, repo, or path.' ];
        }

        $api_url = "https://api.github.com/repos/{$owner}/{$repo}/contents/{$path}?ref={$ref}";
        $headers = [
            'User-Agent' => 'NeuroLink-WP/' . NEURO_LINK_VERSION,
            'Accept'     => 'application/vnd.github.v3+json',
        ];
        if ( $token ) $headers['Authorization'] = "Bearer {$token}";

        $resp = wp_remote_get( $api_url, [ 'timeout' => 15, 'headers' => $headers ] );
        if ( is_wp_error( $resp ) ) return [ 'success' => false, 'content' => 'GitHub error: ' . $resp->get_error_message() ];

        $code = wp_remote_retrieve_response_code( $resp );
        if ( $code === 404 ) return [ 'success' => false, 'content' => "File not found: {$owner}/{$repo}/{$path}@{$ref}" ];
        if ( $code !== 200 ) return [ 'success' => false, 'content' => "GitHub HTTP {$code}" ];

        $data    = json_decode( wp_remote_retrieve_body( $resp ), true );
        $content = base64_decode( str_replace( "\n", '', $data['content'] ?? '' ) );

        if ( ! $content ) return [ 'success' => false, 'content' => 'Could not decode file content.' ];

        // Truncate to 8000 chars to stay within context.
        if ( strlen( $content ) > 8000 ) {
            $content = substr( $content, 0, 8000 ) . "\n\n[...truncated to 8000 chars]";
        }

        return [
            'success' => true,
            'content' => "=== {$owner}/{$repo}/{$path} (ref: {$ref}) ===\n\n{$content}",
        ];
    }

    private function error( string $msg, int $ms ): array {
        return [ 'success' => false, 'text' => '', 'latency_ms' => $ms, 'error_code' => 'ollama_error', 'error_message' => $msg ];
    }
}
