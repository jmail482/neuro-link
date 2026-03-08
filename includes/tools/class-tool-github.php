<?php
/**
 * GitHub Tool — Neuro Link
 * File: includes/tools/class-tool-github.php
 *
 * Direct GitHub REST API v3 integration.
 * Tools: github_read_file, github_list_files, github_create_file,
 *        github_search_code, github_create_issue
 *
 * @package NeuroLink
 */

namespace NeuroLink;

defined( 'ABSPATH' ) || exit;

class Tool_GitHub {

    const API_BASE = 'https://api.github.com';

    private static function token(): string {
        return get_option( 'nl_github_token', '' );
    }

    private static function headers(): array {
        return [
            'Authorization'        => 'Bearer ' . self::token(),
            'Accept'               => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
            'Content-Type'         => 'application/json',
        ];
    }

    public static function read_file( string $owner, string $repo, string $path, string $ref = 'HEAD' ): array {
        $url = self::API_BASE . "/repos/$owner/$repo/contents/" . ltrim( $path, '/' ) . "?ref=$ref";
        $r   = wp_remote_get( $url, [ 'headers' => self::headers(), 'timeout' => 20 ] );
        if ( is_wp_error( $r ) ) return [ 'success' => false, 'error' => $r->get_error_message() ];
        $body = json_decode( wp_remote_retrieve_body( $r ), true );
        if ( empty( $body['content'] ) ) return [ 'success' => false, 'error' => 'No content', 'raw' => $body ];
        return [
            'success'  => true,
            'content'  => base64_decode( str_replace( "\n", '', $body['content'] ) ),
            'sha'      => $body['sha']      ?? '',
            'html_url' => $body['html_url'] ?? '',
        ];
    }

    public static function list_files( string $owner, string $repo, string $path = '' ): array {
        $url = self::API_BASE . "/repos/$owner/$repo/contents/" . ltrim( $path, '/' );
        $r   = wp_remote_get( $url, [ 'headers' => self::headers(), 'timeout' => 20 ] );
        if ( is_wp_error( $r ) ) return [ 'success' => false, 'error' => $r->get_error_message() ];
        $body = json_decode( wp_remote_retrieve_body( $r ), true );
        if ( ! is_array( $body ) ) return [ 'success' => false, 'error' => 'Not a directory' ];
        return [
            'success' => true,
            'files'   => array_map( fn($f) => [
                'name' => $f['name'], 'type' => $f['type'], 'path' => $f['path'],
            ], $body ),
        ];
    }

    public static function create_file( string $owner, string $repo, string $path, string $content, string $message, string $sha = '' ): array {
        $url     = self::API_BASE . "/repos/$owner/$repo/contents/" . ltrim( $path, '/' );
        $payload = [ 'message' => $message, 'content' => base64_encode( $content ) ];
        if ( $sha ) $payload['sha'] = $sha;
        $r = wp_remote_request( $url, [
            'method'  => 'PUT',
            'headers' => self::headers(),
            'body'    => wp_json_encode( $payload ),
            'timeout' => 20,
        ] );
        if ( is_wp_error( $r ) ) return [ 'success' => false, 'error' => $r->get_error_message() ];
        $code = wp_remote_retrieve_response_code( $r );
        $body = json_decode( wp_remote_retrieve_body( $r ), true );
        return [
            'success'   => in_array( $code, [ 200, 201 ], true ),
            'html_url'  => $body['content']['html_url'] ?? '',
            'sha'       => $body['content']['sha']      ?? '',
            'http_code' => $code,
        ];
    }

    public static function search_code( string $query, int $per_page = 10 ): array {
        $url = self::API_BASE . '/search/code?q=' . urlencode( $query ) . "&per_page=$per_page";
        $r   = wp_remote_get( $url, [ 'headers' => self::headers(), 'timeout' => 20 ] );
        if ( is_wp_error( $r ) ) return [ 'success' => false, 'error' => $r->get_error_message() ];
        $body = json_decode( wp_remote_retrieve_body( $r ), true );
        return [
            'success' => true,
            'total'   => $body['total_count'] ?? 0,
            'items'   => array_map( fn($i) => [
                'path' => $i['path'],
                'repo' => $i['repository']['full_name'],
                'url'  => $i['html_url'],
            ], $body['items'] ?? [] ),
        ];
    }

    public static function create_issue( string $owner, string $repo, string $title, string $body_text, array $labels = [] ): array {
        $url = self::API_BASE . "/repos/$owner/$repo/issues";
        $r   = wp_remote_post( $url, [
            'headers' => self::headers(),
            'body'    => wp_json_encode( [ 'title' => $title, 'body' => $body_text, 'labels' => $labels ] ),
            'timeout' => 20,
        ] );
        if ( is_wp_error( $r ) ) return [ 'success' => false, 'error' => $r->get_error_message() ];
        $body = json_decode( wp_remote_retrieve_body( $r ), true );
        return [
            'success'   => ( wp_remote_retrieve_response_code( $r ) === 201 ),
            'issue_url' => $body['html_url'] ?? '',
            'number'    => $body['number']   ?? 0,
        ];
    }
}
