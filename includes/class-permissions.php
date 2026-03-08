<?php
/**
 * Permissions — Neuro Link
 * File: includes/class-permissions.php
 *
 * @package NeuroLink
 */

namespace NeuroLink;

defined( 'ABSPATH' ) || exit;

class Permissions {

    public static function is_enabled( string $provider_id ): bool {
        return Settings::is_provider_enabled( $provider_id );
    }

    public static function can_use_plugin(): bool {
        return current_user_can( 'manage_options' );
    }
}
