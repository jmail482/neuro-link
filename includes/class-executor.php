<?php
/**
 * Executor — Neuro Link
 * File: includes/class-executor.php
 *
 * Gate C: runs ONLY pre-approved wrapper functions.
 * No arbitrary PHP, no raw SQL, no shell execution.
 *
 * @package NeuroLink
 */

namespace NeuroLink;

defined( 'ABSPATH' ) || exit;

class Executor {

    private array $providers;

    public function __construct( array $providers ) {
        $this->providers = $providers; // keyed by provider id.
    }

    /**
     * Execute a validated plan.
     *
     * @param array  $plan      Validated plan from Planner.
     * @param string $provider  Resolved provider id.
     * @return array Result array.
     */
    public function execute( array $plan, string $provider_id ): array {
        $provider = $this->providers[ $provider_id ] ?? null;

        if ( ! $provider ) {
            return $this->error( 'provider_not_found', "Provider not found: $provider_id" );
        }

        $tool = $plan['tool'];
        $args = $plan['arguments'];

        switch ( $tool ) {
            case 'classify':
            case 'summarize_text':
            case 'extract_structured':
                return $this->tool_llm_prompt( $provider, $tool, $args );

            case 'wp_post_create_draft':
                return $this->tool_wp_post_create_draft( $provider, $args );

            case 'readonly_lookup':
                return $this->tool_readonly_lookup( $args );

            default:
                return $this->error( 'unknown_tool', "Unknown tool: $tool" );
        }
    }

    private function tool_llm_prompt( Provider $provider, string $tool, array $args ): array {
        $prompts = [
            'classify'           => "Classify the following text into a single category. Return JSON: {\"category\":\"...\",\"confidence\":0.0}\n\n{text}",
            'summarize_text'     => "Summarize the following text in 2-3 sentences.\n\n{text}",
            'extract_structured' => "Extract key structured data from the following text. Return JSON.\n\n{text}",
        ];

        $prompt = str_replace( '{text}', $args['text'] ?? '', $prompts[ $tool ] ?? $prompts['summarize_text'] );
        return $provider->complete( $prompt );
    }

    private function tool_wp_post_create_draft( Provider $provider, array $args ): array {
        $title_prompt = "Generate a short blog post title for: " . ( $args['text'] ?? '' );
        $title_result = $provider->complete( $title_prompt );
        $title        = sanitize_text_field( $title_result['text'] ?? 'Untitled Draft' );

        $post_id = wp_insert_post( [
            'post_title'  => $title,
            'post_status' => 'draft',
            'post_author' => get_current_user_id(),
        ] );

        if ( is_wp_error( $post_id ) ) {
            return $this->error( 'wp_post_error', $post_id->get_error_message() );
        }

        return [ 'success' => true, 'post_id' => $post_id, 'title' => $title ];
    }

    private function tool_readonly_lookup( array $args ): array {
        // Safe WP query only — no raw SQL.
        $query = new \WP_Query( [
            's'              => sanitize_text_field( $args['text'] ?? '' ),
            'post_status'    => 'publish',
            'posts_per_page' => 5,
        ] );

        $results = [];
        foreach ( $query->posts as $post ) {
            $results[] = [ 'id' => $post->ID, 'title' => $post->post_title ];
        }

        return [ 'success' => true, 'results' => $results ];
    }

    private function error( string $code, string $message ): array {
        return [ 'success' => false, 'error_code' => $code, 'error_message' => $message ];
    }
}
