<?php
/**
 * Web Fetch Tool — Neuro Link
 * File: includes/tools/class-tool-web-fetch.php
 *
 * Fetches a URL, converts HTML → plain text, optionally sends to an LLM.
 *
 * @package NeuroLink
 */

namespace NeuroLink;

defined( 'ABSPATH' ) || exit;

class Tool_Web_Fetch implements Tool {

    /** @var array Allowed URL prefixes. Empty = all allowed. */
    private array $allowed_prefixes;

    /** @var Provider|null Optional LLM provider for analysis. */
    private ?Provider $llm_provider;

    public function __construct( array $allowed_prefixes = [], ?Provider $llm_provider = null ) {
        $this->allowed_prefixes = $allowed_prefixes;
        $this->llm_provider     = $llm_provider;
    }

    public function name(): string { return 'web_fetch'; }

    public function description(): string {
        return 'Fetch a URL and return its text content. Params: url (string, required), prompt (string, optional — if set, content is analysed by LLM), max_chars (int, optional, default 8000).';
    }

    /**
     * Execute.
     *
     * Params:
     *   url       string  Required.
     *   prompt    string  Optional. Sends content + prompt to LLM.
     *   max_chars int     Optional. Truncate content (default 8000).
     */
    public function execute( array $params ): array {
        $url = trim( $params['url'] ?? '' );
        if ( empty( $url ) ) {
            return [ 'success' => false, 'error' => 'url param is required.' ];
        }

        if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
            return [ 'success' => false, 'error' => 'Invalid URL.' ];
        }

        // Security: URL allowlist check.
        if ( ! $this->is_url_allowed( $url ) ) {
            return [ 'success' => false, 'error' => 'URL not allowed by configuration.' ];
        }

        $max_chars = (int) ( $params['max_chars'] ?? 8000 );

        // Fetch.
        $response = wp_remote_get( $url, [
            'timeout'    => 30,
            'user-agent' => 'NeuroLink/1.0 (+https://github.com/jmail482/neuro-link)',
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'error' => 'Fetch failed: ' . $response->get_error_message() ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            return [ 'success' => false, 'error' => "HTTP $code" ];
        }

        $html    = wp_remote_retrieve_body( $response );
        $content = $this->html_to_text( $html, $max_chars );

        $result = [
            'success' => true,
            'url'     => $url,
            'content' => $content,
            'chars'   => strlen( $content ),
        ];

        // Optional LLM analysis.
        if ( ! empty( $params['prompt'] ) && $this->llm_provider ) {
            $full_prompt = "Content fetched from {$url}:\n\n{$content}\n\n---\n{$params['prompt']}";
            $llm_result  = $this->llm_provider->complete( $full_prompt );
            $result['analysis'] = $llm_result['text'] ?? '';
        }

        return $result;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function is_url_allowed( string $url ): bool {
        if ( empty( $this->allowed_prefixes ) ) return true;
        foreach ( $this->allowed_prefixes as $prefix ) {
            if ( str_starts_with( $url, $prefix ) ) return true;
        }
        return false;
    }

    private function html_to_text( string $html, int $max_chars ): string {
        // Strip scripts, styles, nav, footer elements.
        $html = preg_replace( '/<(script|style|nav|footer|header|noscript)[^>]*>.*?<\/\1>/is', '', $html );

        // Strip all remaining tags.
        $text = wp_strip_all_tags( $html );

        // Decode HTML entities.
        $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

        // Collapse whitespace.
        $text = preg_replace( '/[ \t]+/', ' ', $text );
        $text = preg_replace( '/\n{3,}/', "\n\n", $text );
        $text = trim( $text );

        // Truncate.
        if ( strlen( $text ) > $max_chars ) {
            $text = substr( $text, 0, $max_chars ) . "\n\n[truncated]";
        }

        return $text;
    }
}
