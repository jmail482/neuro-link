<?php
/**
 * SEO Pipeline — Neuro Link Layer 6
 * File: includes/class-seo-pipeline.php
 *
 * Accepts a CSV export (Screaming Frog, manual, or any crawl tool)
 * and runs Ollama analysis to produce SEO reports as WP drafts.
 *
 * Works with:
 *   - Screaming Frog free (500 URLs)
 *   - Any crawler CSV with: URL, Title, Meta Desc, H1, Status Code
 *   - Manual paste via admin UI
 *
 * @package NeuroLink
 */

namespace NeuroLink;

defined( 'ABSPATH' ) || exit;

class SEO_Pipeline {

    /**
     * Process a CSV string and generate a WP draft report.
     *
     * @param string $csv_content  Raw CSV text.
     * @param string $site_name    Client name e.g. "Tetrault Wealth".
     * @param string $provider_id  Which provider to use.
     * @return array Result with post_id or error.
     */
    public static function process_csv( string $csv_content, string $site_name, string $provider_id = 'ollama' ): array {
        $rows   = self::parse_csv( $csv_content );
        $issues = self::find_issues( $rows );

        if ( empty( $rows ) ) {
            return [ 'success' => false, 'error' => 'No rows parsed from CSV.' ];
        }

        // Build analysis prompt
        $summary_lines = self::build_summary( $rows, $issues );
        $prompt        = self::build_prompt( $site_name, $summary_lines, $issues );

        // Call Ollama (or configured provider)
        $provider = self::get_provider( $provider_id );
        if ( ! $provider ) return [ 'success' => false, 'error' => "Provider not found: $provider_id" ];

        $result = $provider->complete( $prompt, [ 'max_tokens' => 2048 ] );
        if ( ! $result['success'] ) return [ 'success' => false, 'error' => $result['error_message'] ?? 'LLM failed' ];

        // Save as WP draft
        $title   = "SEO Report: $site_name — " . date( 'Y-m-d' );
        $post_id = wp_insert_post( [
            'post_title'   => $title,
            'post_content' => wp_kses_post( $result['text'] ),
            'post_status'  => 'draft',
            'post_author'  => 1,
            'post_type'    => 'post',
        ] );

        if ( is_wp_error( $post_id ) ) return [ 'success' => false, 'error' => $post_id->get_error_message() ];

        // Store in memory
        Memory::store( "SEO report for $site_name", "Analyzed {$rows['count']} URLs, found {$issues['total']} issues.", '' );

        return [
            'success'    => true,
            'post_id'    => $post_id,
            'post_title' => $title,
            'url_count'  => $rows['count'],
            'issues'     => $issues,
            'edit_url'   => get_edit_post_link( $post_id, 'raw' ),
        ];
    }

    /** Parse CSV — flexible column detection. */
    private static function parse_csv( string $csv ): array {
        $lines = array_filter( explode( "\n", trim( $csv ) ) );
        if ( count( $lines ) < 2 ) return [ 'count' => 0, 'rows' => [] ];

        $headers = array_map( 'trim', str_getcsv( array_shift( $lines ) ) );
        $headers = array_map( 'strtolower', $headers );

        $rows = [];
        foreach ( $lines as $line ) {
            $cols = str_getcsv( $line );
            if ( count( $cols ) < 2 ) continue;
            $row = [];
            foreach ( $headers as $i => $h ) {
                $row[ $h ] = trim( $cols[ $i ] ?? '' );
            }
            $rows[] = $row;
        }

        return [ 'count' => count( $rows ), 'rows' => $rows, 'headers' => $headers ];
    }

    /** Find common SEO issues in parsed rows. */
    private static function find_issues( array $parsed ): array {
        $issues = [
            'missing_title'    => [],
            'missing_meta'     => [],
            'missing_h1'       => [],
            'duplicate_title'  => [],
            'title_too_long'   => [],
            'meta_too_long'    => [],
            '4xx_errors'       => [],
            '3xx_redirects'    => [],
            'total'            => 0,
        ];

        $titles_seen = [];
        foreach ( $parsed['rows'] ?? [] as $row ) {
            $url    = $row['address'] ?? $row['url'] ?? '';
            $title  = $row['title 1'] ?? $row['title'] ?? '';
            $meta   = $row['meta description 1'] ?? $row['meta description'] ?? $row['meta'] ?? '';
            $h1     = $row['h1-1'] ?? $row['h1'] ?? '';
            $status = (int) ( $row['status code'] ?? $row['status'] ?? 200 );

            if ( empty( $title ) )                   $issues['missing_title'][]   = $url;
            if ( empty( $meta ) )                    $issues['missing_meta'][]    = $url;
            if ( empty( $h1 ) )                      $issues['missing_h1'][]      = $url;
            if ( strlen( $title ) > 60 )             $issues['title_too_long'][]  = $url;
            if ( strlen( $meta ) > 160 )             $issues['meta_too_long'][]   = $url;
            if ( $status >= 400 && $status < 500 )   $issues['4xx_errors'][]      = $url;
            if ( $status >= 300 && $status < 400 )   $issues['3xx_redirects'][]   = $url;

            if ( $title ) {
                if ( isset( $titles_seen[ $title ] ) ) $issues['duplicate_title'][] = $url;
                $titles_seen[ $title ] = true;
            }
        }

        $issues['total'] = array_sum( array_map( 'count', array_filter( $issues, 'is_array' ) ) );
        return $issues;
    }

    private static function build_summary( array $parsed, array $issues ): string {
        $lines = [
            "Total URLs crawled: {$parsed['count']}",
            "Missing titles: "    . count( $issues['missing_title'] ),
            "Missing meta desc: " . count( $issues['missing_meta'] ),
            "Missing H1: "        . count( $issues['missing_h1'] ),
            "Duplicate titles: "  . count( $issues['duplicate_title'] ),
            "Title too long: "    . count( $issues['title_too_long'] ),
            "Meta too long: "     . count( $issues['meta_too_long'] ),
            "4xx errors: "        . count( $issues['4xx_errors'] ),
            "Redirects: "         . count( $issues['3xx_redirects'] ),
        ];
        // Add up to 10 example broken URLs
        if ( ! empty( $issues['4xx_errors'] ) ) {
            $lines[] = "404 examples: " . implode( ', ', array_slice( $issues['4xx_errors'], 0, 5 ) );
        }
        return implode( "\n", $lines );
    }

    private static function build_prompt( string $site, string $summary, array $issues ): string {
        return <<<PROMPT
You are an SEO analyst. Write a professional SEO audit report for the website: $site

CRAWL DATA SUMMARY:
$summary

Instructions:
- Write in clear sections: Executive Summary, Critical Issues, Recommendations, Priority Actions
- Be specific — reference actual numbers from the data
- Priority order: 4xx errors first, then missing titles/meta, then duplicates
- End with a numbered action list sorted by impact
- Format for WordPress (use headings like ## and bullet points)
- Keep it under 800 words
PROMPT;
    }

    private static function get_provider( string $id ): ?Provider {
        $map = [
            'ollama'    => new Provider_Ollama(),
            'groq'      => new Provider_Groq(),
            'openai'    => new Provider_OpenAI(),
            'anthropic' => new Provider_Anthropic(),
        ];
        return $map[ $id ] ?? null;
    }
}
