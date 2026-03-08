<?php
/**
 * Memory — Neuro Link Layer 4
 * File: includes/class-memory.php
 *
 * Vector-based memory using Ollama embeddings + cosine similarity.
 * Stores task summaries so the pipeline remembers past work.
 *
 * Table: wp_neuro_link_memory
 *
 * @package NeuroLink
 */

namespace NeuroLink;

defined( 'ABSPATH' ) || exit;

class Memory {

    const OLLAMA_EMBED_URL = 'http://localhost:11434/api/embeddings';
    const EMBED_MODEL      = 'nomic-embed-text'; // best local embed model
    const FALLBACK_MODEL   = 'qwen2.5:7b';       // fallback if nomic not pulled

    /** Store a task + summary with its embedding vector. */
    public static function store( string $input, string $summary, string $request_id = '' ): bool {
        global $wpdb;
        $table     = $wpdb->prefix . 'neuro_link_memory';
        $embedding = self::embed( $summary ?: $input );

        $wpdb->insert( $table, [
            'request_id'     => $request_id ?: wp_generate_uuid4(),
            'input_text'     => substr( $input, 0, 2000 ),
            'summary'        => substr( $summary, 0, 500 ),
            'embedding_json' => $embedding ? wp_json_encode( $embedding ) : null,
            'created_at'     => current_time( 'mysql' ),
        ] );

        return (bool) $wpdb->insert_id;
    }

    /**
     * Search memory by semantic similarity.
     * Falls back to keyword search if embeddings unavailable.
     */
    public static function search( string $query, int $limit = 5 ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'neuro_link_memory';

        $query_vec = self::embed( $query );

        if ( $query_vec ) {
            // Semantic search — load recent 200, rank by cosine similarity
            $rows = $wpdb->get_results(
                "SELECT id, request_id, summary, input_text, embedding_json
                 FROM $table
                 WHERE embedding_json IS NOT NULL
                 ORDER BY created_at DESC LIMIT 200",
                ARRAY_A
            );

            $scored = [];
            foreach ( $rows as $row ) {
                $vec   = json_decode( $row['embedding_json'], true );
                $score = $vec ? self::cosine_similarity( $query_vec, $vec ) : 0;
                $scored[] = array_merge( $row, [ 'score' => $score ] );
            }

            usort( $scored, fn($a, $b) => $b['score'] <=> $a['score'] );
            return array_slice( $scored, 0, $limit );
        }

        // Fallback: keyword search
        $like = '%' . $wpdb->esc_like( $query ) . '%';
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT id, request_id, summary, input_text, 0 as score
             FROM $table WHERE summary LIKE %s OR input_text LIKE %s
             ORDER BY created_at DESC LIMIT %d",
            $like, $like, $limit
        ), ARRAY_A ) ?: [];
    }

    /** Get recent N memories. */
    public static function recent( int $limit = 10 ): array {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, request_id, summary, created_at FROM {$wpdb->prefix}neuro_link_memory ORDER BY created_at DESC LIMIT %d",
                $limit
            ), ARRAY_A
        ) ?: [];
    }

    /** Call Ollama embeddings endpoint. Returns float[] or null on failure. */
    private static function embed( string $text ): ?array {
        foreach ( [ self::EMBED_MODEL, self::FALLBACK_MODEL ] as $model ) {
            $r = wp_remote_post( self::OLLAMA_EMBED_URL, [
                'timeout' => 15,
                'headers' => [ 'Content-Type' => 'application/json' ],
                'body'    => wp_json_encode( [ 'model' => $model, 'prompt' => substr( $text, 0, 512 ) ] ),
            ] );
            if ( is_wp_error( $r ) ) continue;
            $body = json_decode( wp_remote_retrieve_body( $r ), true );
            if ( ! empty( $body['embedding'] ) && is_array( $body['embedding'] ) ) {
                return $body['embedding'];
            }
        }
        return null;
    }

    /** Cosine similarity between two float vectors. */
    private static function cosine_similarity( array $a, array $b ): float {
        $dot = 0.0; $ma = 0.0; $mb = 0.0;
        $len = min( count( $a ), count( $b ) );
        for ( $i = 0; $i < $len; $i++ ) {
            $dot += $a[$i] * $b[$i];
            $ma  += $a[$i] * $a[$i];
            $mb  += $b[$i] * $b[$i];
        }
        $denom = sqrt( $ma ) * sqrt( $mb );
        return $denom > 0 ? (float)( $dot / $denom ) : 0.0;
    }
}
