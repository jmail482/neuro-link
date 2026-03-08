<?php
/**
 * Engine — Neuro Link
 * File: includes/class-engine.php
 *
 * Orchestrates the full pipeline: Planner → Validator → Provider selection
 * → Executor. Routing lives here (deterministic, v1).
 *
 * @package NeuroLink
 */

namespace NeuroLink;

defined( 'ABSPATH' ) || exit;

class Engine {

    private Planner          $planner;
    private Policy_Validator $validator;
    private Executor         $executor;
    private Circuit_Breaker  $breaker;
    private Metrics          $metrics;
    private array            $providers;

    /** Fallback chain per provider. */
    private array $fallback_order = [ 'ollama', 'openai', 'anthropic' ];

    public function __construct(
        Planner $planner,
        Policy_Validator $validator,
        Executor $executor,
        Circuit_Breaker $breaker,
        Metrics $metrics,
        array $providers
    ) {
        $this->planner   = $planner;
        $this->validator = $validator;
        $this->executor  = $executor;
        $this->breaker   = $breaker;
        $this->metrics   = $metrics;
        $this->providers = $providers;
    }

    /**
     * Run full pipeline for a given input.
     * Called synchronously for now; queue wraps this for async work.
     *
     * @param string $input      Raw user input.
     * @param array  $context    Optional request context.
     * @param string $request_id For metrics/audit correlation.
     * @return array Result.
     */
    public function run( string $input, array $context = [], string $request_id = '' ): array {
        // 1. Plan.
        $plan = $this->planner->plan( $input, $context );

        // 2. Validate.
        $validation = $this->validator->validate( $plan );
        if ( ! $validation['valid'] ) {
            return [ 'success' => false, 'error_code' => 'policy_denied', 'error_message' => $validation['reason'] ];
        }

        // 3. Resolve provider with fallback.
        $provider_id = $this->resolve_provider( $plan['provider_preference'] );
        if ( ! $provider_id ) {
            return [ 'success' => false, 'error_code' => 'no_provider', 'error_message' => 'No available provider.' ];
        }

        // 4. Execute.
        $start  = microtime( true );
        $result = $this->executor->execute( $plan, $provider_id );
        $ms     = (int) round( ( microtime( true ) - $start ) * 1000 );

        // 5. Record circuit breaker + metrics.
        if ( $result['success'] ?? false ) {
            $this->breaker->record_success( $provider_id );
        } else {
            $this->breaker->record_failure( $provider_id );
        }

        $this->metrics->record( [
            'request_id'    => $request_id,
            'provider'      => $provider_id,
            'tool_name'     => $plan['tool'],
            'latency_ms'    => $ms,
            'success'       => $result['success'] ?? false,
            'fallback_used' => $provider_id !== $plan['provider_preference'] ? 1 : 0,
        ] );

        return $result;
    }

    private function resolve_provider( string $preferred ): ?string {
        $chain = array_unique( array_merge( [ $preferred ], $this->fallback_order ) );

        foreach ( $chain as $id ) {
            if ( isset( $this->providers[ $id ] ) && $this->breaker->is_available( $id ) ) {
                $provider = $this->providers[ $id ];
                if ( $provider->is_available() ) {
                    return $id;
                }
            }
        }

        return null;
    }
}
