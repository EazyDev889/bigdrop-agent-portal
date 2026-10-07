<?php
/**
 * Notification system — in-app notifications and helpers.
 *
 * @package BigDrop
 */

defined( 'ABSPATH' ) || exit;

class BD_Notifications {

    public function __construct() {
        // Cleanup cron for old notifications.
        if ( ! wp_next_scheduled( 'bd_prune_notifications' ) ) {
            wp_schedule_event( time() + 3600, 'daily', 'bd_prune_notifications' );
        }

        add_action( 'bd_prune_notifications', array( __CLASS__, 'prune_old' ) );
    }

    /**
     * Insert a notification for a specific user.
     *
     * @param int    $user_id
     * @param string $type
     * @param string $title
     * @param string $body
     * @param int    $reference_id
     * @return int|false  Inserted ID or false.
     */
    public static function notify( $user_id, $type, $title, $body = '', $reference_id = 0 ) {
        global $wpdb;

        if ( ! $user_id ) {
            return false;
        }

        $inserted = $wpdb->insert(
            "{$wpdb->prefix}bd_notifications",
            array(
                'user_id'      => (int) $user_id,
                'type'         => sanitize_key( $type ),
                'title'        => sanitize_text_field( $title ),
                'body'         => wp_kses_post( $body ),
                'reference_id' => (int) $reference_id,
                'is_read'      => 0,
            ),
            array( '%d', '%s', '%s', '%s', '%d', '%d' )
        );

        return $inserted ? $wpdb->insert_id : false;
    }

    /**
     * Notify all agents at once.
     */
    public static function notify_all_agents( $type, $title, $body = '', $reference_id = 0 ) {
        $agents = BD_Roles::get_all_agents();
        foreach ( $agents as $agent ) {
            self::notify( $agent->ID, $type, $title, $body, $reference_id );
        }
    }

    /**
     * Mark a single notification as read.
     */
    public static function mark_read( $notification_id, $user_id = null ) {
        global $wpdb;

        $user_id = $user_id ?: get_current_user_id();
        if ( ! $user_id ) {
            return false;
        }

        return false !== $wpdb->update(
            "{$wpdb->prefix}bd_notifications",
            array( 'is_read' => 1 ),
            array( 'id' => (int) $notification_id, 'user_id' => (int) $user_id ),
            array( '%d' ),
            array( '%d', '%d' )
        );
    }

    /**
     * Mark all notifications for a user as read.
     */
    public static function mark_all_read( $user_id = null ) {
        global $wpdb;

        $user_id = $user_id ?: get_current_user_id();
        if ( ! $user_id ) {
            return false;
        }

        return $wpdb->update(
            "{$wpdb->prefix}bd_notifications",
            array( 'is_read' => 1 ),
            array( 'user_id' => (int) $user_id, 'is_read' => 0 ),
            array( '%d' ),
            array( '%d', '%d' )
        );
    }

    /**
     * Get unread count for a user.
     */
    public static function unread_count( $user_id = null ) {
        global $wpdb;

        $user_id = $user_id ?: get_current_user_id();
        if ( ! $user_id ) {
            return 0;
        }

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}bd_notifications WHERE user_id = %d AND is_read = 0",
            $user_id
        ) );
    }

    /**
     * Get recent notifications for a user.
     */
    public static function get_recent( $user_id = null, $limit = 20 ) {
        global $wpdb;

        $user_id = $user_id ?: get_current_user_id();
        if ( ! $user_id ) {
            return array();
        }

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bd_notifications
             WHERE user_id = %d
             ORDER BY id DESC
             LIMIT %d",
            $user_id,
            $limit
        ) );
    }

    /**
     * Delete notifications older than 30 days.
     */
    public static function prune_old() {
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->prefix}bd_notifications
             WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
    }

    /**
     * Delete all notifications for a specific chat.
     */
    public static function clear_for_chat( $chat_id ) {
        global $wpdb;
        $wpdb->delete(
            "{$wpdb->prefix}bd_notifications",
            array( 'type' => 'new_message', 'reference_id' => (int) $chat_id ),
            array( '%s', '%d' )
        );
    }
}