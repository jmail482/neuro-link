<?php
/**
 * Circuit Breaker — Neuro Link
 * File: includes/class-circuit-breaker.php
 *
 * Three states: closed (normal), open (blocked), half_open (probe allowed).
 * State is persisted in DB so it survives server restarts.
 *
 * @package NeuroLink
 */

namespace NeuroLink;

defined( 'ABSPATH' ) || exit;

class Circuit_Breaker {

    private string $table;
    private int    $failure_threshold = 3;
    private int    $cooldown_seconds  = 60;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'neuro_link_provider_health';
    }

    public function is_available( string $provider ): bool {
        $row = $this->get_row( $provider );
        if ( ! $row ) return true;

        if ( $row['state'] === 'open' ) {
            if ( $row['cooldown_until'] && strtotime( $row['cooldown_until'] ) < time() ) {
                $this->set_state( $provider, 'half_open' );
                return true; // Allow one probe.
            }
            return false;
        }

        return true; // closed or half_open.
    }

    public function record_success( string $provider ): void {
        global $wpdb;
        $wpdb->replace( $this->table, [
            'provider'        => $provider,
            'state'           => 'closed',
            'failure_count'   => 0,
            'last_success_at' => current_time( 'mysql' ),
        ] );
    }

    public function record_failure( string $provider ): void {
        global $wpdb;
        $row   = $this->get_row( $provider );
        $count = ( $row ? (int) $row['failure_count'] : 0 ) + 1;
        $now   = current_time( 'mysql' );
        $state = $count >= $this->failure_threshold ? 'open' : 'closed';
        $until = $state === 'open'
            ? gmdate( 'Y-m-d H:i:s', time() + $this->cooldown_seconds )
            : null;

        $wpdb->replace( $this->table, [
            'provider'        => $provider,
            'state'           => $state,
            'failure_count'   => $count,
            'last_failure_at' => $now,
            'cooldown_until'  => $until,
        ] );
    }

    public function get_all_states(): array {
        global $wpdb;
        return $wpdb->get_results( "SELECT * FROM {$this->table}", ARRAY_A ) ?: [];
    }

    private function get_row( string $provider ): ?array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE provider = %s",
            $provider
        ), ARRAY_A );
        return $row ?: null;
    }

    private function set_state( string $provider, string $state ): void {
        global $wpdb;
        $wpdb->update( $this->table, [ 'state' => $state ], [ 'provider' => $provider ] );
    }
}
