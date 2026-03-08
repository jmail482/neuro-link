<?php
/**
 * Task Queue — Neuro Link
 * File: includes/class-task-queue.php
 *
 * Lease-based async queue. Workers atomically claim tasks with an expiry.
 * Crashed workers release tasks automatically when lease expires.
 *
 * @package NeuroLink
 */

namespace NeuroLink;

defined( 'ABSPATH' ) || exit;

class Task_Queue {

    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'neuro_link_tasks';
    }

    /**
     * Enqueue a new task. Returns request_id.
     * If dedupe_key already has a pending/leased/running task, returns existing request_id.
     */
    public function enqueue( array $payload, string $task_type = 'default', string $dedupe_key = '' ): string {
        global $wpdb;

        if ( $dedupe_key ) {
            $existing = $wpdb->get_var( $wpdb->prepare(
                "SELECT request_id FROM {$this->table}
                 WHERE dedupe_key = %s AND status IN ('pending','leased','running')
                 LIMIT 1",
                $dedupe_key
            ) );
            if ( $existing ) return $existing;
        }

        $request_id = wp_generate_uuid4();
        $now        = current_time( 'mysql' );

        $wpdb->insert( $this->table, [
            'request_id'   => $request_id,
            'task_type'    => $task_type,
            'status'       => 'pending',
            'payload_json' => wp_json_encode( $payload ),
            'dedupe_key'   => $dedupe_key ?: null,
            'created_at'   => $now,
            'updated_at'   => $now,
        ] );

        return $request_id;
    }

    /**
     * Atomically lease up to $limit pending tasks for this worker.
     */
    public function lease( string $worker_id, int $lease_seconds = 60, int $limit = 5 ): array {
        global $wpdb;

        $now        = current_time( 'mysql' );
        $lease_until = gmdate( 'Y-m-d H:i:s', time() + $lease_seconds );

        // Reclaim expired leases first.
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$this->table}
             SET status = 'pending', worker_id = NULL, lease_until = NULL, updated_at = %s
             WHERE status = 'leased' AND lease_until < %s",
            $now, $now
        ) );

        // Fetch and lease pending tasks.
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$this->table}
             WHERE status = 'pending'
             ORDER BY priority ASC, created_at ASC
             LIMIT %d",
            $limit
        ) );

        if ( empty( $ids ) ) return [];

        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$this->table}
             SET status = 'leased', worker_id = %s, lease_until = %s, updated_at = %s
             WHERE id IN ($placeholders)",
            array_merge( [ $worker_id, $lease_until, $now ], $ids )
        ) );

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE id IN ($placeholders)",
            $ids
        ), ARRAY_A );
    }

    /** Mark task as running. */
    public function mark_running( string $request_id ): void {
        global $wpdb;
        $now = current_time( 'mysql' );
        $wpdb->update( $this->table, [
            'status'     => 'running',
            'started_at' => $now,
            'updated_at' => $now,
        ], [ 'request_id' => $request_id ] );
    }

    /** Mark task completed with result. */
    public function complete( string $request_id, array $result ): void {
        global $wpdb;
        $now = current_time( 'mysql' );
        $wpdb->update( $this->table, [
            'status'      => 'completed',
            'result_json' => wp_json_encode( $result ),
            'finished_at' => $now,
            'updated_at'  => $now,
        ], [ 'request_id' => $request_id ] );
    }

    /** Mark task failed. Moves to dead_letter after max_attempts. */
    public function fail( string $request_id, string $error_code, string $error_message ): void {
        global $wpdb;
        $now  = current_time( 'mysql' );
        $task = $wpdb->get_row( $wpdb->prepare(
            "SELECT attempt_count, max_attempts FROM {$this->table} WHERE request_id = %s",
            $request_id
        ) );

        $attempts = (int) $task->attempt_count + 1;
        $status   = $attempts >= (int) $task->max_attempts ? 'dead_letter' : 'pending';

        $wpdb->update( $this->table, [
            'status'        => $status,
            'attempt_count' => $attempts,
            'error_code'    => $error_code,
            'error_message' => $error_message,
            'worker_id'     => null,
            'lease_until'   => null,
            'updated_at'    => $now,
        ], [ 'request_id' => $request_id ] );
    }

    /** Get task status by request_id. */
    public function get_status( string $request_id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE request_id = %s",
            $request_id
        ), ARRAY_A );
        return $row ?: null;
    }
}
