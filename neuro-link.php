<?php
/**
 * Plugin Name: Neuro Link
 * Plugin URI:  https://github.com/jmail482/neuro-link
 * Description: Multi-LLM orchestration â€” async queue, circuit breaker, memory, adaptive comparator, Zapier, token auth, built-in dashboard.
 * Version:     0.5.1
 * Author:      jmail482
 * License:     GPL-2.0-or-later
 * Text Domain: neuro-link
 * @package NeuroLink
 */
defined( 'ABSPATH' ) || exit;

define( 'NEURO_LINK_VERSION', '0.5.1' );
define( 'NEURO_LINK_FILE',    __FILE__ );
define( 'NEURO_LINK_DIR',     plugin_dir_path( __FILE__ ) );
define( 'NEURO_LINK_URL',     plugin_dir_url( __FILE__ ) );

require_once NEURO_LINK_DIR . 'includes/interface-provider.php';
require_once NEURO_LINK_DIR . 'includes/interface-tool.php';
require_once NEURO_LINK_DIR . 'includes/class-settings.php';
require_once NEURO_LINK_DIR . 'includes/class-api-auth.php';
require_once NEURO_LINK_DIR . 'includes/class-task-queue.php';
require_once NEURO_LINK_DIR . 'includes/class-circuit-breaker.php';
require_once NEURO_LINK_DIR . 'includes/class-policy-validator.php';
require_once NEURO_LINK_DIR . 'includes/class-planner.php';
require_once NEURO_LINK_DIR . 'includes/class-executor.php';
require_once NEURO_LINK_DIR . 'includes/class-engine.php';
require_once NEURO_LINK_DIR . 'includes/class-metrics.php';
require_once NEURO_LINK_DIR . 'includes/class-permissions.php';
require_once NEURO_LINK_DIR . 'includes/class-memory.php';
require_once NEURO_LINK_DIR . 'includes/class-worker.php';
require_once NEURO_LINK_DIR . 'includes/class-ajax-worker.php';
require_once NEURO_LINK_DIR . 'includes/class-adaptive-comparator.php';
require_once NEURO_LINK_DIR . 'includes/class-rest-api.php';
require_once NEURO_LINK_DIR . 'includes/class-rest-api-gui.php';
require_once NEURO_LINK_DIR . 'includes/class-rest-api-adaptive.php';
require_once NEURO_LINK_DIR . 'includes/class-zapier-webhook.php';
require_once NEURO_LINK_DIR . 'includes/providers/class-provider-ollama.php';
require_once NEURO_LINK_DIR . 'includes/providers/class-provider-openai.php';
require_once NEURO_LINK_DIR . 'includes/providers/class-provider-anthropic.php';
require_once NEURO_LINK_DIR . 'includes/providers/class-provider-groq.php';
require_once NEURO_LINK_DIR . 'includes/providers/class-provider-freegpt35.php';
require_once NEURO_LINK_DIR . 'includes/tools/class-tool-web-fetch.php';
require_once NEURO_LINK_DIR . 'includes/tools/class-tool-github.php';
require_once NEURO_LINK_DIR . 'database/schema.php';

// â”€â”€ Activation / deactivation â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
register_activation_hook( __FILE__, function() {
    neuro_link_install_schema();
    NeuroLink\Worker::register();
} );
register_deactivation_hook( __FILE__, function() {
    NeuroLink\Worker::deregister();
} );

// â”€â”€ Bootstrap â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
add_action( 'plugins_loaded', function() {
    neuro_link_maybe_upgrade_schema();
    if ( is_admin() ) {
        require_once NEURO_LINK_DIR . 'admin/class-admin.php';
        ( new NeuroLink\Admin() )->init();
    }
    ( new NeuroLink\REST_API() )->register_routes();
    ( new NeuroLink\REST_API_GUI() )->register_routes();
    ( new NeuroLink\REST_API_Adaptive() )->register_routes();
    ( new NeuroLink\Zapier_Webhook() )->register_routes();
    NeuroLink\AJAX_Worker::register();
} );

// â”€â”€ Cron schedule â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
add_filter( 'cron_schedules', function( $s ) {
    $interval = max( 30, (int) NeuroLink\Settings::get( 'cron_interval', 60 ) );
    $s['neuro_link_interval'] = [ 'interval' => $interval, 'display' => "Neuro Link every {$interval}s" ];
    return $s;
} );

add_action( 'plugins_loaded', function() {
    if ( ! wp_next_scheduled( 'neuro_link_process_queue' ) )
        wp_schedule_event( time(), 'neuro_link_interval', 'neuro_link_process_queue' );
    if ( ! wp_next_scheduled( 'neuro_link_reliability_check' ) )
        wp_schedule_event( time(), 'neuro_link_interval', 'neuro_link_reliability_check' );
} );

add_action( 'neuro_link_process_queue', [ 'NeuroLink\\Worker', 'run' ] );

add_action( 'neuro_link_reliability_check', function() {
    ( new NeuroLink\Adaptive_Comparator( new NeuroLink\Circuit_Breaker(), new NeuroLink\Metrics() ) )
        ->enforce_reliability_threshold( 0.5 );
} );

// â”€â”€ Manual worker trigger (admin AJAX) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
add_action( 'wp_ajax_neuro_link_run_worker', function() {
    check_ajax_referer( 'nl_worker_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );
    NeuroLink\Worker::run_manual();
    wp_send_json_success( [ 'message' => 'Worker ran.' ] );
} );


