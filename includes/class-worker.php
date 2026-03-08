<?php
/**
 * Worker — Neuro Link v0.5.1
 * FIX: DOING_CRON constant conflict, prompt→input fallback, worker log guard.
 * File: includes/class-worker.php
 * @package NeuroLink
 */
namespace NeuroLink;
defined( 'ABSPATH' ) || exit;

class Worker {

    const CRON_HOOK     = 'neuro_link_process_queue';
    const CRON_SCHEDULE = 'neuro_link_interval';
    const WORKER_PREFIX = 'nl-worker-';

    public static function register(): void {
        add_filter( 'cron_schedules', [ __CLASS__, 'add_schedule' ] );
        add_action( self::CRON_HOOK, [ __CLASS__, 'run' ] );
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), self::CRON_SCHEDULE, self::CRON_HOOK );
        }
    }

    public static function deregister(): void {
        $ts = wp_next_scheduled( self::CRON_HOOK );
        if ( $ts ) wp_unschedule_event( $ts, self::CRON_HOOK );
    }

    public static function add_schedule( array $schedules ): array {
        $interval = max( 30, (int) Settings::get( 'cron_interval', 60 ) );
        $schedules[ self::CRON_SCHEDULE ] = [
            'interval' => $interval,
            'display'  => "Neuro Link every {$interval}s",
        ];
        return $schedules;
    }

    public static function run(): void {
        // Context guard — only run from cron, CLI, or explicit AJAX trigger.
        $is_cron  = defined( 'DOING_CRON' )  && DOING_CRON;
        $is_cli   = defined( 'WP_CLI' )       && WP_CLI;
        $is_ajax  = defined( 'DOING_AJAX' )   && DOING_AJAX;
        $is_manual = defined( 'NL_MANUAL_WORKER' ) && NL_MANUAL_WORKER;

        if ( ! $is_cron && ! $is_cli && ! $is_ajax && ! $is_manual ) {
            return;
        }

        $start     = microtime( true );
        $worker_id = self::WORKER_PREFIX . substr( md5( gethostname() . getmypid() ), 0, 8 );
        $batch     = max( 1, (int) Settings::get( 'batch_size', 5 ) );
        $lease     = max( 30, (int) Settings::get( 'lease_seconds', 120 ) );

        $queue  = new Task_Queue();
        $engine = self::build_engine();
        $tasks  = $queue->lease( $worker_id, $lease, $batch );
        $ran    = 0;

        foreach ( $tasks as $task ) {
            $request_id = $task['request_id'];
            $queue->mark_running( $request_id );
            $ran++;

            try {
                $payload = json_decode( $task['payload_json'] ?? '{}', true ) ?: [];
                // Support both 'input' and 'prompt' payload keys.
                $input = sanitize_textarea_field(
                    $payload['input'] ?? $payload['prompt'] ?? ''
                );

                if ( empty( $input ) ) {
                    $queue->fail( $request_id, 'empty_input', 'Payload missing input/prompt field.' );
                    continue;
                }

                $result = $engine->run( $input, $payload['context'] ?? [], $request_id );

                if ( $result['success'] ?? false ) {
                    $queue->complete( $request_id, $result );
                    Memory::store( $input, substr( $result['text'] ?? '', 0, 500 ), $request_id );
                    if ( ( $payload['source'] ?? '' ) === 'zapier' ) {
                        Zapier_Webhook::notify_zapier( $request_id, $result, $payload['callback_url'] ?? '' );
                    }
                    do_action( 'neuro_link_task_completed', $request_id, $result, $payload );
                } else {
                    $queue->fail( $request_id, $result['error_code'] ?? 'engine_error', $result['error_message'] ?? 'Unknown.' );
                }
            } catch ( \Throwable $e ) {
                $queue->fail( $request_id, 'exception', $e->getMessage() );
            }
        }

        self::log_run( $worker_id, $ran, (int) round( ( microtime( true ) - $start ) * 1000 ) );
    }

    /** Manual trigger — admin AJAX only. Uses NL_MANUAL_WORKER constant to pass context guard. */
    public static function run_manual(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.', 403 );
        }
        if ( ! defined( 'NL_MANUAL_WORKER' ) ) {
            define( 'NL_MANUAL_WORKER', true );
        }
        self::run();
    }

    private static function build_engine(): Engine {
        $providers = [
            'ollama'    => new Provider_Ollama(),
            'groq'      => new Provider_Groq(),
            'openai'    => new Provider_OpenAI(),
            'anthropic' => new Provider_Anthropic(),
        ];
        return new Engine(
            new Planner(),
            new Policy_Validator(),
            new Executor( $providers ),
            new Circuit_Breaker(),
            new Metrics(),
            $providers
        );
    }

    private static function log_run( string $worker_id, int $tasks_run, int $duration_ms ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'neuro_link_worker_log';
        // Silently skip if table doesn't exist yet (first activation).
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) !== $table ) return;
        $wpdb->insert( $table, [
            'worker_id'   => $worker_id,
            'tasks_run'   => $tasks_run,
            'duration_ms' => $duration_ms,
            'run_at'      => current_time( 'mysql' ),
        ] );
        // Keep last 500 rows.
        $wpdb->query( "DELETE FROM $table WHERE id NOT IN (SELECT id FROM (SELECT id FROM $table ORDER BY run_at DESC LIMIT 500) t)" );
    }
}
