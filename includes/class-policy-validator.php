<?php
/**
 * Policy Validator — Neuro Link
 * File: includes/class-policy-validator.php
 *
 * Gate B: validates planner output against permissions, capability,
 * content limits, and provider availability before any execution.
 *
 * @package NeuroLink
 */

namespace NeuroLink;

defined( 'ABSPATH' ) || exit;

class Policy_Validator {

    /** Tools allowed in v1 and the WP capability required. */
    private array $allowed_tools = [
        'classify'              => 'read',
        'summarize_text'        => 'read',
        'extract_structured'    => 'read',
        'wp_post_create_draft'  => 'edit_posts',
        'readonly_lookup'       => 'read',
    ];

    private int $max_content_bytes = 51200; // 50 KB.

    /**
     * Validate a planner output array.
     *
     * @param array $plan      Structured plan from Planner.
     * @return array { valid: bool, reason: string }
     */
    public function validate( array $plan ): array {
        // Required keys.
        foreach ( [ 'intent', 'tool', 'provider_preference', 'arguments' ] as $key ) {
            if ( empty( $plan[ $key ] ) ) {
                return $this->fail( "Missing required plan key: $key" );
            }
        }

        // Tool allowlist.
        if ( ! isset( $this->allowed_tools[ $plan['tool'] ] ) ) {
            return $this->fail( "Tool not in allowlist: {$plan['tool']}" );
        }

        // WP capability check.
        $required_cap = $this->allowed_tools[ $plan['tool'] ];
        if ( $required_cap !== 'read' && ! current_user_can( $required_cap ) ) {
            return $this->fail( "Insufficient capability for tool: {$plan['tool']}" );
        }

        // Content size guard.
        $content = $plan['arguments']['text'] ?? $plan['arguments']['content'] ?? '';
        if ( strlen( $content ) > $this->max_content_bytes ) {
            return $this->fail( 'Content exceeds maximum allowed size.' );
        }

        return [ 'valid' => true, 'reason' => '' ];
    }

    private function fail( string $reason ): array {
        return [ 'valid' => false, 'reason' => $reason ];
    }
}
