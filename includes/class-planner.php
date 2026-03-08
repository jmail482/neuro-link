<?php
/**
 * Planner — Neuro Link
 * File: includes/class-planner.php
 *
 * Classifies intent and selects tool + provider using deterministic rules.
 * The planner NEVER executes — it only produces a structured plan.
 * Adaptive routing deferred to Phase 5.
 *
 * @package NeuroLink
 */

namespace NeuroLink;

defined( 'ABSPATH' ) || exit;

class Planner {

    /** Deterministic routing: task_type => preferred provider. */
    private array $routing_map = [
        'classify'           => 'ollama',
        'summarize_text'     => 'ollama',
        'extract_structured' => 'ollama',
        'wp_post_create_draft' => 'ollama',
        'readonly_lookup'    => 'ollama',
        'default'            => 'ollama',
    ];

    /**
     * Produce a structured plan from raw user input.
     *
     * @param string $input    Raw user prompt.
     * @param array  $context  Optional additional context.
     * @return array Structured plan.
     */
    public function plan( string $input, array $context = [] ): array {
        $intent    = $this->classify_intent( $input );
        $tool      = $this->select_tool( $intent );
        $provider  = $this->routing_map[ $tool ] ?? $this->routing_map['default'];

        return [
            'intent'              => $intent,
            'tool'                => $tool,
            'provider_preference' => $provider,
            'arguments'           => [
                'text' => sanitize_textarea_field( $input ),
            ],
            'context'             => $context,
        ];
    }

    private function classify_intent( string $input ): string {
        $lower = strtolower( $input );

        if ( str_contains( $lower, 'summarize' ) || str_contains( $lower, 'summary' ) ) {
            return 'summarize_text';
        }
        if ( str_contains( $lower, 'classify' ) || str_contains( $lower, 'categorize' ) ) {
            return 'classify';
        }
        if ( str_contains( $lower, 'extract' ) || str_contains( $lower, 'pull out' ) ) {
            return 'extract_structured';
        }
        if ( str_contains( $lower, 'draft' ) || str_contains( $lower, 'write a post' ) ) {
            return 'wp_post_create_draft';
        }
        if ( str_contains( $lower, 'find' ) || str_contains( $lower, 'lookup' ) || str_contains( $lower, 'search' ) ) {
            return 'readonly_lookup';
        }

        return 'classify'; // Safe default.
    }

    private function select_tool( string $intent ): string {
        $map = [
            'summarize_text'       => 'summarize_text',
            'classify'             => 'classify',
            'extract_structured'   => 'extract_structured',
            'wp_post_create_draft' => 'wp_post_create_draft',
            'readonly_lookup'      => 'readonly_lookup',
        ];
        return $map[ $intent ] ?? 'classify';
    }
}
