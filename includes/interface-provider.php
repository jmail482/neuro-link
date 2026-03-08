<?php
/**
 * Provider Interface — Neuro Link
 * File: includes/interface-provider.php
 *
 * All provider adapters must implement this contract.
 *
 * @package NeuroLink
 */

namespace NeuroLink;

defined( 'ABSPATH' ) || exit;

interface Provider {

    /**
     * Return a unique machine-readable identifier for this provider.
     * e.g. 'ollama', 'openai', 'anthropic'
     */
    public function get_id(): string;

    /**
     * Return whether this provider is currently enabled and configured.
     */
    public function is_available(): bool;

    /**
     * Execute a prompt and return a normalized response array.
     *
     * @param string $prompt     The user/system prompt.
     * @param array  $options    Optional: model, temperature, max_tokens, etc.
     *
     * @return array {
     *     @type string $text          Raw text output.
     *     @type int    $input_tokens  Tokens consumed (if available).
     *     @type int    $output_tokens Tokens generated (if available).
     *     @type int    $latency_ms    Wall-clock ms for the call.
     *     @type bool   $success       Whether the call succeeded.
     *     @type string $error_code    Empty string on success.
     *     @type string $error_message Empty string on success.
     * }
     */
    public function complete( string $prompt, array $options = [] ): array;
}
