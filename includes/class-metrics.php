<?php
/**
 * Metrics — Neuro Link v0.5.1
 * FIX: column name alignment (success_count, fallback_count), added failure_count.
 * File: includes/class-metrics.php
 * @package NeuroLink
 */
namespace NeuroLink;
defined( 'ABSPATH' ) || exit;

class Metrics {

    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'neuro_link_metrics';
    }

    public function record( array $data ): void {
        global $wpdb;
        $wpdb->insert( $this->table, array_merge( [
            'request_id'    => '',
            'provider'      => '',
            'latency_ms'    => 0,
            'success'       => 1,
            'fallback_used' => 0,
        ], $data, [ 'created_at' => current_time( 'mysql' ) ] ) );
    }

    public function get_provider_summary(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT
                provider,
                COUNT(*)                    AS total,
                SUM(success)                AS success_count,
                SUM(1 - success)            AS failure_count,
                SUM(fallback_used)          AS fallback_count,
                ROUND(AVG(latency_ms))      AS avg_latency_ms,
                MAX(created_at)             AS last_request_at
             FROM {$this->table}
             GROUP BY provider
             ORDER BY total DESC",
            ARRAY_A
        ) ?: [];
    }
}
