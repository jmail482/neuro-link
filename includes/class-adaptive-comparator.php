<?php
/**
 * Adaptive Comparator — Neuro Link
 * File: includes/class-adaptive-comparator.php
 *
 * Routes the same prompt to multiple providers, scores responses,
 * logs divergence, and uses results to inform adaptive routing.
 *
 * Roles FreeGPT35 can play (all dev-only):
 *   - ground_truth  : compare local model vs FreeGPT35 reference
 *   - fallback      : use FreeGPT35 when primary provider fails
 *   - synthetic     : generate training/benchmark data
 *
 * @package NeuroLink
 */

namespace NeuroLink;

defined( 'ABSPATH' ) || exit;

class Adaptive_Comparator {

    private Circuit_Breaker $breaker;
    private Metrics         $metrics;

    public function __construct( Circuit_Breaker $breaker, Metrics $metrics ) {
        $this->breaker = $breaker;
        $this->metrics = $metrics;
    }

    // ── 1. Ground Truth Comparison ────────────────────────────────────────────

    /**
     * Run prompt through primary provider AND FreeGPT35, score both,
     * log divergence. Returns primary result; comparison stored in metrics.
     */
    public function compare( string $prompt, Provider $primary, string $request_id = '' ): array {
        $primary_result = $primary->complete( $prompt );
        $ref_result     = null;
        $divergence     = null;

        $ref = new Provider_FreeGPT35();
        if ( $ref->is_available() ) {
            $ref_result = $ref->complete( $prompt );

            if ( $primary_result['success'] && $ref_result['success'] ) {
                $divergence = $this->score_divergence(
                    $primary_result['text'],
                    $ref_result['text']
                );

                // Log to DB for analysis.
                $this->log_comparison( [
                    'request_id'       => $request_id,
                    'primary_provider' => $primary->get_id(),
                    'primary_text'     => substr( $primary_result['text'], 0, 500 ),
                    'ref_text'         => substr( $ref_result['text'], 0, 500 ),
                    'divergence_score' => $divergence,
                    'primary_latency'  => $primary_result['latency_ms'],
                    'ref_latency'      => $ref_result['latency_ms'],
                ] );
            }
        }

        // Attach comparison metadata to result (non-breaking).
        $primary_result['comparison'] = [
            'ref_available'  => $ref_result !== null,
            'divergence'     => $divergence,
            'ref_latency_ms' => $ref_result['latency_ms'] ?? null,
        ];

        return $primary_result;
    }

    // ── 2. Fallback Augmentation ───────────────────────────────────────────────

    /**
     * Try primary provider; if it fails AND FreeGPT35 is enabled,
     * fall back to FreeGPT35 and cache the result.
     */
    public function complete_with_fallback( string $prompt, Provider $primary, string $request_id = '' ): array {
        $result = $primary->complete( $prompt );

        if ( $result['success'] ) return $result;

        $ref = new Provider_FreeGPT35();
        if ( ! $ref->is_available() ) return $result; // Nothing to fall back to.

        $fb = $ref->complete( $prompt );
        if ( ! $fb['success'] ) return $result; // Both failed.

        // Cache for future reuse (1 hour).
        $cache_key = 'nl_fb_' . md5( $prompt );
        set_transient( $cache_key, $fb['text'], HOUR_IN_SECONDS );

        $fb['fallback_used']     = true;
        $fb['fallback_provider'] = 'freegpt35';
        $this->metrics->record( [
            'request_id'    => $request_id,
            'provider'      => 'freegpt35',
            'tool_name'     => 'fallback',
            'latency_ms'    => $fb['latency_ms'],
            'success'       => 1,
            'fallback_used' => 1,
        ] );

        return $fb;
    }

    // ── 3. Synthetic Data Generator ───────────────────────────────────────────

    /**
     * Use FreeGPT35 to generate synthetic Q&A pairs for benchmarking.
     * Returns array of { question, answer } objects.
     *
     * @param string $topic   e.g. "WordPress plugin development"
     * @param int    $count   Number of pairs to generate (max 10).
     */
    public function generate_synthetic_data( string $topic, int $count = 5 ): array {
        $count = min( $count, 10 );
        $ref   = new Provider_FreeGPT35();

        if ( ! $ref->is_available() ) {
            return [ 'success' => false, 'error' => 'FreeGPT35 not available.' ];
        }

        $prompt = "Generate exactly {$count} diverse user questions about \"{$topic}\" paired with expert, detailed answers. "
                . "Return ONLY a JSON array, no markdown, no preamble. Format: "
                . '[{"question":"...","answer":"..."}]';

        $result = $ref->complete( $prompt, [ 'temperature' => 0.9 ] );
        if ( ! $result['success'] ) return [ 'success' => false, 'error' => $result['error_message'] ];

        // Strip any markdown fences before parsing.
        $json = preg_replace( '/```json|```/i', '', $result['text'] );
        $data = json_decode( trim( $json ), true );

        if ( ! is_array( $data ) ) {
            return [ 'success' => false, 'error' => 'Could not parse JSON from FreeGPT35 response.' ];
        }

        return [
            'success' => true,
            'topic'   => $topic,
            'count'   => count( $data ),
            'pairs'   => $data,
        ];
    }

    // ── 4. Adaptive Routing Signal ─────────────────────────────────────────────

    /**
     * Check FreeGPT35 reliability over last N comparisons.
     * Returns a score 0.0–1.0. Below threshold → disable automatically.
     */
    public function get_freegpt35_reliability( int $window = 20 ): float {
        global $wpdb;
        $table = $wpdb->prefix . 'neuro_link_comparisons';

        // Table may not exist yet; return 1.0 (assume ok) if so.
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) !== $table ) return 1.0;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT ref_success FROM $table ORDER BY created_at DESC LIMIT %d",
            $window
        ), ARRAY_A );

        if ( empty( $rows ) ) return 1.0;

        $successes = array_sum( array_column( $rows, 'ref_success' ) );
        return round( $successes / count( $rows ), 2 );
    }

    /**
     * Auto-disable FreeGPT35 if reliability drops below threshold.
     * Call from cron or worker.
     */
    public function enforce_reliability_threshold( float $threshold = 0.5 ): void {
        $score = $this->get_freegpt35_reliability();
        if ( $score < $threshold ) {
            Settings::set( 'freegpt35_enabled', false );
            // Log the auto-disable.
            error_log( "[NeuroLink] FreeGPT35 auto-disabled: reliability {$score} < threshold {$threshold}" );
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Score divergence between two text responses.
     * 0.0 = identical, 1.0 = completely different.
     * Uses simple word-overlap (Jaccard distance).
     */
    private function score_divergence( string $a, string $b ): float {
        $words_a = array_unique( str_word_count( strtolower( $a ), 1 ) );
        $words_b = array_unique( str_word_count( strtolower( $b ), 1 ) );

        if ( empty( $words_a ) && empty( $words_b ) ) return 0.0;

        $intersection = count( array_intersect( $words_a, $words_b ) );
        $union        = count( array_unique( array_merge( $words_a, $words_b ) ) );

        return $union > 0 ? round( 1 - ( $intersection / $union ), 3 ) : 1.0;
    }

    /** Persist comparison record. Creates table on first use. */
    private function log_comparison( array $data ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'neuro_link_comparisons';

        // Create table if missing.
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) !== $table ) {
            $this->create_comparisons_table();
        }

        $wpdb->insert( $table, array_merge( $data, [
            'ref_success' => isset( $data['ref_text'] ) && ! empty( $data['ref_text'] ) ? 1 : 0,
            'created_at'  => current_time( 'mysql' ),
        ] ) );
    }

    private function create_comparisons_table(): void {
        global $wpdb;
        $c = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( "CREATE TABLE {$wpdb->prefix}neuro_link_comparisons (
            id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            request_id        VARCHAR(36)     DEFAULT NULL,
            primary_provider  VARCHAR(64)     NOT NULL,
            primary_text      TEXT            DEFAULT NULL,
            ref_text          TEXT            DEFAULT NULL,
            divergence_score  FLOAT           DEFAULT NULL,
            primary_latency   INT             DEFAULT NULL,
            ref_latency       INT             DEFAULT NULL,
            ref_success       TINYINT(1)      NOT NULL DEFAULT 0,
            created_at        DATETIME        NOT NULL,
            PRIMARY KEY (id),
            KEY created_at (created_at)
        ) $c;" );
    }
}
