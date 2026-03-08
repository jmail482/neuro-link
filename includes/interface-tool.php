<?php
/**
 * Tool Interface — Neuro Link
 * File: includes/interface-tool.php
 *
 * @package NeuroLink
 */

namespace NeuroLink;

defined( 'ABSPATH' ) || exit;

interface Tool {
    /**
     * Execute the tool.
     *
     * @param array $params Parameters for the tool.
     * @return array Result array with at minimum 'success' => bool.
     * @throws \Exception On unrecoverable error.
     */
    public function execute( array $params ): array;

    /** Human-readable name for the planner. */
    public function name(): string;

    /** One-line description for the planner system prompt. */
    public function description(): string;
}
