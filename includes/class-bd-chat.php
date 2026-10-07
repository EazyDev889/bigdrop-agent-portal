<?php
/**
 * Chat engine - events, cron jobs, escalation, stats, auto-reopen, archiving.
 *
 * @package BigDrop
 */

defined( 'ABSPATH' ) || exit;

class BD_Chat {

    public function __construct() {
        add_action( 'bd_message_sent',   array( $this, 'on_message_sent' ), 10, 3 );
        add_action( 'bd_chat_resolved',  array( $this, 'on_chat_resolved' ), 10, 2 );
        add_action( 'bd_escalate_chats', array( __CLASS__, 'escalate_stale_chats' ) );
        add_action( 'bd_cleanup_old',    array( __CLASS__, 'cleanup_old_chats' ) );
        add_action( 'bd_archive_chats',  array( __CLASS__, 'archive_old_chats' ) );
        add_action( 'bd_update_stats',   array( __CLASS__, 'update_daily_stats' ) );
    }

    public function on_message_sent( $chat_id, $msg_id, $sender_type ) {
        global $wpdb;
        $p = $wpdb->prefix;

        $wpdb->update( "{$p}bd_chats", array( 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $chat_id ) );

        if ( 'visitor' !== $sender_type ) return;

        $chat = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}bd_chats WHERE id = %d", $chat_id ) );
        if ( ! $chat ) return;

        if ( in_array( $chat->status, array( 'resolved', 'unresolved' ), true ) ) {
            $wpdb->update( "{$p}bd_chats", array( 'status' => 'new', 'priority' => 1, 'assigned_agent_id' => 0 ), array( 'id' => $chat_id ) );
            $wpdb->insert( "{$p}bd_messages", array( 'chat_id' => $chat_id, 'sender_type' => 'system', 'message' => 'Visitor sent a new message - chat reopened.', 'is_read' => 1 ) );
            $this->notify_supervisors_of_reopen( $chat_id, $chat );
            $chat->status = 'new';
            $chat->assigned_agent_id = 0;
        }

        $title = 'New message from ' . ( $chat->visitor_name ?: 'Visitor' );
        $body  = mb_substr( $wpdb->get_var( $wpdb->prepare( "SELECT message FROM {$p}bd_messages WHERE id = %d", $msg_id ) ), 0, 120 );

        foreach ( BD_Roles::get_all_agents() as $agent ) {
            if ( $chat->assigned_agent_id && (int) $chat->assigned_agent_id !== (int) $agent->ID ) continue;

            $wpdb->insert( "{$p}bd_notifications", array( 'user_id' => $agent->ID, 'type' => 'new_message', 'reference_id' => $chat_id, 'title' => $title, 'body' => $body ) );
            BD_WebPush::send_to_user( $agent->ID, array( 'title' => $title, 'body' => $body, 'url' => admin_url( 'admin.php?page=bd-portal&chat=' . $chat_id ), 'tag' => 'chat-' . $chat_id ) );
        }
    }

    private function notify_supervisors_of_reopen( $chat_id, $chat ) {
        foreach ( get_users( array( 'role__in' => array( 'bd_team_lead', 'administrator' ) ) ) as $agent ) {
            BD_Notifications::notify( $agent->ID, 'chat_reopened', 'Chat reopened by visitor', ( $chat->visitor_name ?: 'Visitor' ) . ' sent a new message on a closed chat.', $chat_id );
        }
    }

    public function on_chat_resolved( $chat_id, $status ) {
        global $wpdb;
        $chat = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bd_chats WHERE id = %d", $chat_id ) );
        if ( $chat && $chat->assigned_agent_id ) {
            self::bump_agent_stats( $chat->assigned_agent_id, $status, $chat );
        }
    }

    public static function escalate_stale_chats() {
        global $wpdb;
        $p = $wpdb->prefix;

        $wpdb->query( "UPDATE {$p}bd_chats SET priority = 2 WHERE status = 'new' AND priority = 1 AND created_at < DATE_SUB(NOW(), INTERVAL 3 MINUTE)" );
        $escalated_ids = $wpdb->get_col( "SELECT id FROM {$p}bd_chats WHERE status = 'new' AND priority = 2 AND created_at < DATE_SUB(NOW(), INTERVAL 3 MINUTE)" );
        if ( empty( $escalated_ids ) ) return;

        $agents = BD_Roles::get_all_agents();
        if ( empty( $agents ) ) return;

        $values = array();
        foreach ( $agents as $agent ) {
            foreach ( $escalated_ids as $chat_id ) {
                $values[] = $wpdb->prepare( '(%d, %s, %d, %s, %s)', $agent->ID, 'chat_escalated', $chat_id, 'High Priority Chat', 'A visitor has been waiting over 3 minutes.' );
                if ( count( $values ) >= 100 ) {
                    $wpdb->query( "INSERT INTO {$p}bd_notifications (user_id, type, reference_id, title, body) VALUES " . implode( ',', $values ) );
                    $values = array();
                }
            }
        }
        if ( ! empty( $values ) ) {
            $wpdb->query( "INSERT INTO {$p}bd_notifications (user_id, type, reference_id, title, body) VALUES " . implode( ',', $values ) );
        }
    }

    public static function cleanup_old_chats() {
        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->query( "DELETE m FROM {$p}bd_messages m INNER JOIN {$p}bd_chats c ON m.chat_id = c.id WHERE c.created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)" );
        $wpdb->query( "DELETE FROM {$p}bd_chats WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)" );
        BD_Notifications::prune_old();
    }

    /**
     * PERFORMANCE: Archive chats older than 30 days to keep active tables tiny.
     */
    public static function archive_old_chats() {
        global $wpdb;
        $p = $wpdb->prefix;

        // 1. Move messages to archive
        $wpdb->query( "
            INSERT INTO {$p}bd_messages_archive (chat_id, sender_type, sender_id, message, created_at)
            SELECT chat_id, sender_type, sender_id, message, created_at 
            FROM {$p}bd_messages m
            INNER JOIN {$p}bd_chats c ON m.chat_id = c.id
            WHERE c.created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
        " );

        // 2. Move chats to archive
        $wpdb->query( "
            INSERT INTO {$p}bd_chats_archive (id, visitor_id, visitor_name, visitor_email, visitor_phone, assigned_agent_id, status, resolution_time_seconds, created_at, resolved_at)
            SELECT id, visitor_id, visitor_name, visitor_email, visitor_phone, assigned_agent_id, status, resolution_time_seconds, created_at, resolved_at
            FROM {$p}bd_chats
            WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
        " );

        // 3. Delete from active tables
        $wpdb->query( "DELETE m FROM {$p}bd_messages m INNER JOIN {$p}bd_chats c ON m.chat_id = c.id WHERE c.created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)" );
        $wpdb->query( "DELETE FROM {$p}bd_chats WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)" );
    }

    public static function update_daily_stats() {
        global $wpdb;
        $p = $wpdb->prefix;
        $today = current_time( 'Y-m-d' );

        foreach ( BD_Roles::get_all_agents() as $agent ) {
            $stats = $wpdb->get_row( $wpdb->prepare(
                "SELECT COUNT(*) AS taken, SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) AS resolved, SUM(CASE WHEN status = 'unresolved' THEN 1 ELSE 0 END) AS unresolved, SUM(CASE WHEN resolution_time_seconds > 0 AND resolution_time_seconds <= 180 THEN 1 ELSE 0 END) AS fast, AVG(CASE WHEN first_response_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND, created_at, first_response_at) ELSE NULL END) AS avg FROM {$p}bd_chats WHERE assigned_agent_id = %d AND DATE(created_at) = %s",
                $agent->ID, $today
            ) );

            $row = array( 'agent_id' => $agent->ID, 'stat_date' => $today, 'chats_taken' => (int) $stats->taken, 'chats_resolved' => (int) $stats->resolved, 'chats_unresolved' => (int) $stats->unresolved, 'resolved_under_3min' => (int) $stats->fast, 'avg_response_seconds' => (int) $stats->avg );
            $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$p}bd_daily_stats WHERE agent_id = %d AND stat_date = %s", $agent->ID, $today ) );
            
            if ( $existing ) $wpdb->update( "{$p}bd_daily_stats", $row, array( 'id' => $existing ) );
            else $wpdb->insert( "{$p}bd_daily_stats", $row );
        }
    }

    private static function bump_agent_stats( $agent_id, $status, $chat ) {
        global $wpdb;
        $p = $wpdb->prefix;
        $today = current_time( 'Y-m-d' );
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}bd_daily_stats WHERE agent_id = %d AND stat_date = %s", $agent_id, $today ) );
        $isFast = ( $chat->resolution_time_seconds > 0 && $chat->resolution_time_seconds <= 180 ) ? 1 : 0;

        if ( ! $row ) {
            $wpdb->insert( "{$p}bd_daily_stats", array( 'agent_id' => $agent_id, 'stat_date' => $today, 'chats_taken' => 1, 'chats_resolved' => 'resolved' === $status ? 1 : 0, 'chats_unresolved' => 'unresolved' === $status ? 1 : 0, 'resolved_under_3min' => $isFast ) );
            return;
        }
        $wpdb->update( "{$p}bd_daily_stats", array( 'chats_resolved' => $row->chats_resolved + ( 'resolved' === $status ? 1 : 0 ), 'chats_unresolved' => $row->chats_unresolved + ( 'unresolved' === $status ? 1 : 0 ), 'resolved_under_3min' => $row->resolved_under_3min + $isFast ), array( 'id' => $row->id ) );
    }

    public static function get_agent_recent_chats( $agent_id, $limit = 10 ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bd_chats WHERE assigned_agent_id = %d ORDER BY updated_at DESC LIMIT %d", $agent_id, $limit ) );
    }

    public static function get_chat( $chat_id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bd_chats WHERE id = %d", $chat_id ) );
    }
}