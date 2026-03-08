<?php
/**
 * Settings — Neuro Link v0.5.0
 * File: includes/class-settings.php
 * @package NeuroLink
 */
namespace NeuroLink;
defined( 'ABSPATH' ) || exit;

class Settings {

    const OPTION_KEY = 'neuro_link_settings';

    public static function get( string $key, $default = null ) {
        $settings = get_option( self::OPTION_KEY, [] );
        return $settings[ $key ] ?? $default;
    }

    public static function set( string $key, $value ): void {
        $settings         = get_option( self::OPTION_KEY, [] );
        $settings[ $key ] = $value;
        update_option( self::OPTION_KEY, $settings );
    }

    public static function all(): array { return get_option( self::OPTION_KEY, [] ); }

    // ── Provider helpers ──────────────────────────────────────────────────────
    public static function get_ollama_url(): string     { return self::get( 'ollama_url',       'http://localhost:11434' ); }
    public static function get_ollama_model(): string   { return self::get( 'ollama_model',      'qwen2.5:7b' ); }
    public static function get_openai_key(): string     { return self::get( 'openai_api_key',    '' ); }
    public static function get_openai_model(): string   { return self::get( 'openai_model',      'gpt-4o-mini' ); }
    public static function get_anthropic_key(): string  { return self::get( 'anthropic_api_key', '' ); }
    public static function get_anthropic_model(): string{ return self::get( 'anthropic_model',   'claude-haiku-4-5-20251001' ); }
    public static function get_groq_key(): string       { return self::get( 'groq_api_key',      '' ); }
    public static function get_groq_model(): string     { return self::get( 'groq_model',        'llama-3.3-70b-versatile' ); }
    public static function get_github_token(): string   { return self::get( 'github_token',      '' ); }

    // ── Worker / queue helpers ────────────────────────────────────────────────
    public static function get_cron_interval(): int  { return (int) self::get( 'cron_interval',  60 ); }
    public static function get_lease_seconds(): int  { return (int) self::get( 'lease_seconds',  120 ); }
    public static function get_batch_size(): int     { return (int) self::get( 'batch_size',     5 ); }

    // ── Provider enable/disable ───────────────────────────────────────────────
    public static function get_providers_enabled(): array {
        return self::get( 'providers_enabled', [ 'ollama' ] );
    }

    public static function is_provider_enabled( string $id ): bool {
        return in_array( $id, self::get_providers_enabled(), true );
    }

    public static function get_provider_model( string $id ): string {
        return match( $id ) {
            'ollama'    => self::get_ollama_model(),
            'openai'    => self::get_openai_model(),
            'anthropic' => self::get_anthropic_model(),
            'groq'      => self::get_groq_model(),
            default     => '',
        };
    }

    public static function get_provider_label( string $id ): string {
        return match( $id ) {
            'ollama'    => 'Ollama',
            'openai'    => 'OpenAI',
            'anthropic' => 'Anthropic',
            'groq'      => 'Groq',
            default     => ucfirst( $id ),
        };
    }
}
