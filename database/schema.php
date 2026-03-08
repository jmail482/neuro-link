<?php
/**
 * Database Schema — Neuro Link v0.5.0
 * Adds: memory table, api_tokens table, worker_log table
 * File: database/schema.php
 * @package NeuroLink
 */
defined( 'ABSPATH' ) || exit;

function neuro_link_install_schema() {
    global $wpdb;
    $c = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    dbDelta( "CREATE TABLE {$wpdb->prefix}neuro_link_tasks (
        id                  BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        request_id          VARCHAR(36)         NOT NULL,
        parent_request_id   VARCHAR(36)         DEFAULT NULL,
        task_type           VARCHAR(64)         NOT NULL DEFAULT 'default',
        intent              VARCHAR(128)        DEFAULT NULL,
        status              ENUM('pending','leased','running','completed','failed','dead_letter','cancelled') NOT NULL DEFAULT 'pending',
        priority            TINYINT(3) UNSIGNED NOT NULL DEFAULT 5,
        payload_json        LONGTEXT            NOT NULL,
        planner_output_json LONGTEXT            DEFAULT NULL,
        result_json         LONGTEXT            DEFAULT NULL,
        error_code          VARCHAR(64)         DEFAULT NULL,
        error_message       TEXT                DEFAULT NULL,
        provider_used       VARCHAR(64)         DEFAULT NULL,
        model_used          VARCHAR(128)        DEFAULT NULL,
        attempt_count       TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
        max_attempts        TINYINT(3) UNSIGNED NOT NULL DEFAULT 3,
        lease_until         DATETIME            DEFAULT NULL,
        worker_id           VARCHAR(64)         DEFAULT NULL,
        dedupe_key          VARCHAR(128)        DEFAULT NULL,
        created_at          DATETIME            NOT NULL,
        updated_at          DATETIME            NOT NULL,
        started_at          DATETIME            DEFAULT NULL,
        finished_at         DATETIME            DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY request_id (request_id),
        KEY status (status),
        KEY lease_until (lease_until),
        KEY dedupe_key (dedupe_key)
    ) $c;" );

    dbDelta( "CREATE TABLE {$wpdb->prefix}neuro_link_metrics (
        id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        request_id    VARCHAR(36)         NOT NULL,
        provider      VARCHAR(64)         NOT NULL,
        model         VARCHAR(128)        DEFAULT NULL,
        tool_name     VARCHAR(64)         DEFAULT NULL,
        latency_ms    INT(10) UNSIGNED    NOT NULL DEFAULT 0,
        input_tokens  INT(10) UNSIGNED    DEFAULT NULL,
        output_tokens INT(10) UNSIGNED    DEFAULT NULL,
        success       TINYINT(1)          NOT NULL DEFAULT 1,
        fallback_used TINYINT(1)          NOT NULL DEFAULT 0,
        created_at    DATETIME            NOT NULL,
        PRIMARY KEY (id),
        KEY provider (provider),
        KEY created_at (created_at)
    ) $c;" );

    dbDelta( "CREATE TABLE {$wpdb->prefix}neuro_link_provider_health (
        id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        provider        VARCHAR(64)         NOT NULL,
        state           ENUM('closed','open','half_open') NOT NULL DEFAULT 'closed',
        failure_count   INT(10) UNSIGNED    NOT NULL DEFAULT 0,
        last_failure_at DATETIME            DEFAULT NULL,
        cooldown_until  DATETIME            DEFAULT NULL,
        last_success_at DATETIME            DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY provider (provider)
    ) $c;" );

    dbDelta( "CREATE TABLE {$wpdb->prefix}neuro_link_audit (
        id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        request_id  VARCHAR(36)         DEFAULT NULL,
        user_id     BIGINT(20) UNSIGNED DEFAULT NULL,
        action      VARCHAR(128)        NOT NULL,
        target_type VARCHAR(64)         DEFAULT NULL,
        target_id   VARCHAR(128)        DEFAULT NULL,
        decision    VARCHAR(32)         DEFAULT NULL,
        reason      TEXT                DEFAULT NULL,
        created_at  DATETIME            NOT NULL,
        PRIMARY KEY (id),
        KEY request_id (request_id),
        KEY created_at (created_at)
    ) $c;" );

    // ── FIX: Memory table (was missing) ──────────────────────────────────────
    dbDelta( "CREATE TABLE {$wpdb->prefix}neuro_link_memory (
        id             BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        request_id     VARCHAR(36)         NOT NULL,
        input_text     TEXT                DEFAULT NULL,
        summary        VARCHAR(500)        DEFAULT NULL,
        embedding_json LONGTEXT            DEFAULT NULL,
        created_at     DATETIME            NOT NULL,
        PRIMARY KEY (id),
        KEY request_id (request_id),
        KEY created_at (created_at)
    ) $c;" );

    // ── FIX: API tokens for external clients (desktop assistant, Zapier) ─────
    dbDelta( "CREATE TABLE {$wpdb->prefix}neuro_link_api_tokens (
        id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        token_hash  VARCHAR(64)         NOT NULL,
        label       VARCHAR(128)        NOT NULL DEFAULT 'API Token',
        scope       VARCHAR(255)        NOT NULL DEFAULT 'read,write',
        last_used   DATETIME            DEFAULT NULL,
        created_at  DATETIME            NOT NULL,
        expires_at  DATETIME            DEFAULT NULL,
        revoked     TINYINT(1)          NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        UNIQUE KEY token_hash (token_hash)
    ) $c;" );

    // ── Worker execution log (scalability tracking) ───────────────────────────
    dbDelta( "CREATE TABLE {$wpdb->prefix}neuro_link_worker_log (
        id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        worker_id   VARCHAR(64)         NOT NULL,
        tasks_run   TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
        duration_ms INT(10) UNSIGNED    NOT NULL DEFAULT 0,
        run_at      DATETIME            NOT NULL,
        PRIMARY KEY (id),
        KEY run_at (run_at)
    ) $c;" );

    // ── Comparisons (adaptive module) ─────────────────────────────────────────
    dbDelta( "CREATE TABLE {$wpdb->prefix}neuro_link_comparisons (
        id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        request_id       VARCHAR(36)     DEFAULT NULL,
        primary_provider VARCHAR(64)     NOT NULL,
        primary_text     TEXT            DEFAULT NULL,
        ref_text         TEXT            DEFAULT NULL,
        divergence_score FLOAT           DEFAULT NULL,
        primary_latency  INT             DEFAULT NULL,
        ref_latency      INT             DEFAULT NULL,
        ref_success      TINYINT(1)      NOT NULL DEFAULT 0,
        created_at       DATETIME        NOT NULL,
        PRIMARY KEY (id),
        KEY created_at (created_at)
    ) $c;" );

    update_option( 'neuro_link_db_version', '2.0.0' );
}

function neuro_link_maybe_upgrade_schema() {
    if ( version_compare( get_option( 'neuro_link_db_version', '0' ), '2.0.0', '<' ) ) {
        neuro_link_install_schema();
    }
}
