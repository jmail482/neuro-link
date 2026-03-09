<?php
/**
 * API Token Auth — Neuro Link
 * File: includes/class-api-auth.php
 *
 * Handles token-based auth for external clients:
 * desktop assistant, Zapier, any non-browser caller.
 *
 * Usage: send header  Authorization: Bearer <token>
 *        or query param  ?nl_token=<token>
 *
 * @package NeuroLink
 */
namespace NeuroLink;
defined( 'ABSPATH' ) || exit;

class API_Auth {

    /**
     * Generate a new token, store its hash, return the plain token.
     * Only show plain token once — never stored.
     */
    public static function create_token( string $label = 'API Token', string $scope = 'read,write', ?string $expires_at = null ): string {
        global $wpdb;
        $token = 'nlk_' . bin2hex( random_bytes( 24 ) );
        $hash  = hash( 'sha256', $token );
        $wpdb->insert( $wpdb->prefix . 'neuro_link_api_tokens', [
            'token_hash' => $hash,
            'label'      => sanitize_text_field( $label ),
            'scope'      => sanitize_text_field( $scope ),
            'created_at' => current_time( 'mysql' ),
            'expires_at' => $expires_at,
        ] );
        return $token;
    }

    /** Verify a raw token from a request. Returns token row or null. */
    public static function verify( string $token ): ?array {
        if ( empty( $token ) || ! str_starts_with( $token, 'nlk_' ) ) return null;
        global $wpdb;
        $hash = hash( 'sha256', $token );
        $row  = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}neuro_link_api_tokens
             WHERE token_hash = %s AND revoked = 0
             AND (expires_at IS NULL OR expires_at > %s)",
            $hash, current_time( 'mysql' )
        ), ARRAY_A );
        if ( ! $row ) return null;
        // Update last_used.
        $wpdb->update( $wpdb->prefix . 'neuro_link_api_tokens',
            [ 'last_used' => current_time( 'mysql' ) ],
            [ 'id' => $row['id'] ]
        );
        return $row;
    }

    /** Revoke a token by its hash prefix or label. */
    public static function revoke( int $id ): bool {
        global $wpdb;
        return (bool) $wpdb->update(
            $wpdb->prefix . 'neuro_link_api_tokens',
            [ 'revoked' => 1 ],
            [ 'id' => $id ]
        );
    }

    /** List all tokens (hashes only, never plain). */
    public static function list_tokens(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT id, label, scope, last_used, created_at, expires_at, revoked
             FROM {$wpdb->prefix}neuro_link_api_tokens ORDER BY created_at DESC",
            ARRAY_A
        ) ?: [];
    }

    /**
     * WP REST permission callback for external clients.
     * Accepts EITHER:
     *   - Logged-in WP user with manage_options
     *   - Valid Bearer token in Authorization header
     *   - Valid nl_token query param
     */
    public static function rest_permission( \WP_REST_Request $r ): bool {
        // Standard WP session auth.
        if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) return true;

        // WP nonce auth — covers browser GUI sending X-WP-Nonce header.
        $nonce = $r->get_header( 'x-wp-nonce' ) ?: $r->get_param( '_wpnonce' ) ?: '';
        if ( $nonce && wp_verify_nonce( $nonce, 'wp_rest' ) ) return true;

        // Token auth.
        $token = self::extract_token( $r );
        if ( $token && self::verify( $token ) ) return true;

        return false;
    }

    public static function extract_token( \WP_REST_Request $r ): string {
        $auth = $r->get_header( 'authorization' ) ?: '';
        if ( preg_match( '/^Bearer\s+(nlk_\S+)$/i', $auth, $m ) ) return $m[1];
        return sanitize_text_field( $r->get_param( 'nl_token' ) ?: '' );
    }
}
