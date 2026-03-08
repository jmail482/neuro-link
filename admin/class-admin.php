<?php
/**
 * Admin â€” Neuro Link v0.5.0
 * Merged GUI dashboard + token management into core plugin.
 * File: admin/class-admin.php
 * @package NeuroLink
 */
namespace NeuroLink;
defined( 'ABSPATH' ) || exit;

class Admin {

    public function init(): void {
        add_action( 'admin_menu',            [ $this, 'register_menus' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    public function register_menus(): void {
        add_menu_page(
            'Neuro Link', 'Neuro Link', 'manage_options',
            'neuro-link-chat', [ $this, 'page_chat' ],
            'dashicons-rest-api', 80
        );
        add_submenu_page( 'neuro-link-chat', 'âš¡ LLM Chat',      'âš¡ LLM Chat',      'manage_options', 'neuro-link-chat',        [ $this, 'page_chat' ] );
        add_submenu_page( 'neuro-link-chat', 'ðŸ“‹ Task Queue',    'ðŸ“‹ Task Queue',    'manage_options', 'neuro-link-queue',       [ $this, 'page_queue' ] );
        add_submenu_page( 'neuro-link-chat', 'ðŸ”Œ Provider Health','ðŸ”Œ Provider Health','manage_options','neuro-link-health',     [ $this, 'page_provider_health' ] );
        add_submenu_page( 'neuro-link-chat', 'ðŸ“Š Metrics',       'ðŸ“Š Metrics',       'manage_options', 'neuro-link-metrics',     [ $this, 'page_metrics' ] );
        add_submenu_page( 'neuro-link-chat', 'ðŸŒ Web Fetch',     'ðŸŒ Web Fetch',     'manage_options', 'neuro-link-webfetch',    [ $this, 'page_webfetch' ] );
        add_submenu_page( 'neuro-link-chat', 'ðŸ§  Adaptive',      'ðŸ§  Adaptive',      'manage_options', 'neuro-link-adaptive',    [ $this, 'page_adaptive' ] );
        add_submenu_page( 'neuro-link-chat', 'âš¡ Zapier',        'âš¡ Zapier',        'manage_options', 'neuro-link-zapier',      [ $this, 'page_zapier' ] );
        add_submenu_page( 'neuro-link-chat', 'ðŸ”‘ API Tokens',    'ðŸ”‘ API Tokens',    'manage_options', 'neuro-link-tokens',      [ $this, 'page_tokens' ] );
        add_submenu_page( 'neuro-link-chat', 'â˜  Dead Letter',    'â˜  Dead Letter',    'manage_options', 'neuro-link-dead-letter', [ $this, 'page_dead_letter' ] );
        add_submenu_page( 'neuro-link-chat', 'ðŸ”’ Permissions',   'ðŸ”’ Permissions',   'manage_options', 'neuro-link-permissions', [ $this, 'page_permissions' ] );
        add_submenu_page( 'neuro-link-chat', 'âš™ Settings',       'âš™ Settings',       'manage_options', 'neuro-link-settings',    [ $this, 'page_settings' ] );
    }

    public function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, 'neuro-link' ) === false ) return;
        wp_enqueue_style(  'neuro-link-admin', NEURO_LINK_URL . 'admin/assets/admin.css', [], NEURO_LINK_VERSION );
        wp_enqueue_script( 'neuro-link-admin', NEURO_LINK_URL . 'admin/assets/admin.js',  [], NEURO_LINK_VERSION, true );
        wp_enqueue_script( 'neuro-link-async', NEURO_LINK_URL . 'admin/js/nl-chat-async.js', [ 'neuro-link-admin' ], NEURO_LINK_VERSION, true );
        wp_localize_script( 'neuro-link-async', 'nlData', [
            'restBase'    => esc_url_raw( rest_url( 'neuro-link/v1' ) ),
            'nonce'       => wp_create_nonce( 'wp_rest' ),
            'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
            'workerNonce' => wp_create_nonce( 'nl_worker_nonce' ),
        ] );
        wp_localize_script( 'neuro-link-admin', 'NeuroLink', [
            'root'         => esc_url_raw( rest_url( 'neuro-link/v1' ) ),
            'nonce'        => wp_create_nonce( 'wp_rest' ),
            'ajaxNonce'    => wp_create_nonce( 'nl_admin_action' ),
            'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
            'version'      => NEURO_LINK_VERSION,
            'defaultModel' => Settings::get_ollama_model(),
        ] );
    }

    public function page_chat():            void { require NEURO_LINK_DIR . 'admin/views/page-chat.php'; }
    public function page_queue():           void { require NEURO_LINK_DIR . 'admin/views/page-queue.php'; }
    public function page_provider_health(): void { require NEURO_LINK_DIR . 'admin/views/page-provider-health.php'; }
    public function page_metrics():         void { require NEURO_LINK_DIR . 'admin/views/page-metrics.php'; }
    public function page_webfetch():        void { require NEURO_LINK_DIR . 'admin/views/page-webfetch.php'; }
    public function page_adaptive():        void { require NEURO_LINK_DIR . 'admin/views/page-adaptive.php'; }
    public function page_zapier():          void { require NEURO_LINK_DIR . 'admin/views/page-zapier.php'; }
    public function page_tokens():          void { require NEURO_LINK_DIR . 'admin/views/page-tokens.php'; }
    public function page_dead_letter():     void { require NEURO_LINK_DIR . 'admin/views/page-dead-letter.php'; }
    public function page_permissions():     void { require NEURO_LINK_DIR . 'admin/views/page-permissions.php'; }
    public function page_settings():        void { require NEURO_LINK_DIR . 'admin/views/page-settings.php'; }
}

