<?php
/**
 * Uninstall script — runs when the plugin is deleted from WordPress.
 *
 * Removes: custom tables, options, roles, and scheduled events.
 * Does NOT remove: users, pages, or uploaded files (safer default).
 *
 * @package BigDrop
 */

// If uninstall is not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// ------------------------------------------------------------------
// 1. Drop custom database tables.
// ------------------------------------------------------------------
$bd_tables = array(
    'bd_chats',
    'bd_messages',
    'bd_internal_messages',
    'bd_canned_replies',
    'bd_notifications',
    'bd_push_subscriptions',
    'bd_daily_stats',
    'bd_clients',
);

foreach ( $bd_tables as $table ) {
    $full = $wpdb->prefix . $table;
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $wpdb->query( "DROP TABLE IF EXISTS `{$full}`" );
}

// ------------------------------------------------------------------
// 2. Delete plugin options.
// ------------------------------------------------------------------
$bd_options = array(
    'bd_portal_page_id',
    'bd_vapid_public_key',
    'bd_vapid_private_key',
    'bd_settings',
    'bd_version',
    'bd_widget_enabled',
    'bd_widget_greeting',
    'bd_widget_color',
);

foreach ( $bd_options as $option ) {
    delete_option( $option );
}

// ------------------------------------------------------------------
// 3. Remove custom user roles and capabilities.
// ------------------------------------------------------------------
remove_role( 'bd_agent' );
remove_role( 'bd_team_lead' );

$admin = get_role( 'administrator' );
if ( $admin ) {
    $caps = array(
        'bd_access_portal',
        'bd_take_chats',
        'bd_view_all_agents',
        'bd_manage_canned',
        'bd_view_clients',
    );
    foreach ( $caps as $cap ) {
        $admin->remove_cap( $cap );
    }
}

// ------------------------------------------------------------------
// 4. Clear scheduled events.
// ------------------------------------------------------------------
wp_clear_scheduled_hook( 'bd_escalate_chats' );
wp_clear_scheduled_hook( 'bd_cleanup_old' );

// ------------------------------------------------------------------
// 5. Clean up user meta for all agents.
// ------------------------------------------------------------------
$meta_keys = array(
    'bd_status',
    'bd_last_seen',
    'bd_chat_sound',
    'bd_push_enabled',
);

foreach ( $meta_keys as $key ) {
    $wpdb->delete( $wpdb->usermeta, array( 'meta_key' => $key ) );
}

// ------------------------------------------------------------------
// 6. Flush rewrite rules.
// ------------------------------------------------------------------
flush_rewrite_rules();