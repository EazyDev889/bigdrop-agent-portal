<?php
/**
 * Chat engine - events, cron jobs, escalation, stats, auto-reopen.
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
        add_action( 'bd_update_stats',   array( __CLASS__, 'update_daily_stats' ) );
    }

    /**
     * Handle any new message in the system.
     */
    public function on_message_sent( $chat_id, $msg_id, $sender_type ) {
        global $wpdb;
        $p = $wpdb->prefix;

        // Bump chat's updated_at.
        $wpdb->update(
            "{$p}bd_chats",
            array( 'updated_at' => current_time( 'mysql' ) ),
            array( 'id' => $chat_id )
        );

        if ( 'visitor' !== $sender_type ) {
            return;
        }

        $chat = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}bd_chats WHERE id = %d", $chat_id ) );
        if ( ! $chat ) return;

        // ---- AUTO-REOPEN ----------------------------------------
        // If chat was resolved/unresolved, reset it to the New queue.
        if ( in_array( $chat->status, array( 'resolved', 'unresolved' ), true ) ) {
            $wpdb->update( "{$p}bd_chats", array(
                'status'            => 'new',
                'priority'          => 1,
                'assigned_agent_id' => 0,
            ), array( 'id' => $chat_id ) );

            // System message.
            $wpdb->insert( "{$p}bd_messages", array(
                'chat_id'     => $chat_id,
                'sender_type' => 'system',
                'message'     => 'Visitor sent a new message - chat reopened.',
                'is_read'     => 1,
            ) );

            // Notify team leads + admins (not regular agents).
            $this->notify_supervisors_of_reopen( $chat_id, $chat );

            // Refresh local chat var.
            $chat->status = 'new';
            $chat->assigned_agent_id = 0;
        }

        // ---- NOTIFY ASSIGNED AGENT OR ALL AGENTS ---------------
        $title = 'New message from ' . ( $chat->visitor_name ?: 'Visitor' );
        $body  = mb_substr( $wpdb->get_var( $wpdb->prepare(
            "SELECT message FROM {$p}bd_messages WHERE id = %d", $msg_id
        ) ), 0, 120 );

        $agents = BD_Roles::get_all_agents();
        foreach ( $agents as $agent ) {
            // If chat has an assigned agent, notify only them.
            if ( $chat->assigned_agent_id && (int) $chat->assigned_agent_id !== (int) $agent->ID ) {
                continue;
            }

            $wpdb->insert( "{$p}bd_notifications", array(
                'user_id'      => $agent->ID,
                'type'         => 'new_message',
                'reference_id' => $chat_id,
                'title'        => $title,
                'body'         => $body,
            ) );

            BD_WebPush::send_to_user( $agent->ID, array(
                'title' => $title,
                'body'  => $body,
                'url'   => admin_url( 'admin.php?page=bd-portal&chat=' . $chat_id ),
                'tag'   => 'chat-' . $chat_id,
            ) );
        }
    }

    /**
     * Notify supervisors (TL + Admin) that a chat was reopened.
     */
    private function notify_supervisors_of_reopen( $chat_id, $chat ) {
        $supervisors = get_users( array(
            'role__in' => array( 'bd_team_lead', 'administrator' ),
        ) );

        foreach ( $supervisors as $agent ) {
            BD_Notifications::notify(
                $agent->ID,
                'chat_reopened',
                'Chat reopened by visitor',
                ( $chat->visitor_name ?: 'Visitor' ) . ' sent a new message on a closed chat.',
                $chat_id
            );
        }
    }

    /**
     * Handle a chat being resolved.
     */
    public function on_chat_resolved( $chat_id, $status ) {
        global $wpdb;
        $p = $wpdb->prefix;

        $chat = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}bd_chats WHERE id = %d", $chat_id ) );
        if ( ! $chat ) return;

        // Bump agent daily stats.
        if ( $chat->assigned_agent_id ) {
            self::bump_agent_stats( $chat->assigned_agent_id, $status, $chat );
        }
    }

    /* ============================================================
     *  CRON JOBS
     * ============================================================ */

    /**
     * Cron: escalate chats waiting 3+ minutes.
     */
    public static function escalate_stale_chats() {
        global $wpdb;
        $p = $wpdb->prefix;

        $rows = $wpdb->get_results( "
            SELECT id FROM {$p}bd_chats
            WHERE status = 'new'
              AND priority = 1
              AND created_at < DATE_SUB(NOW(), INTERVAL 3 MINUTE)
        " );

        if ( empty( $rows ) ) return;

        foreach ( $rows as $row ) {
            $wpdb->update( "{$p}bd_chats", array( 'priority' => 2 ), array( 'id' => $row->id ) );

            $agents = BD_Roles::get_all_agents();
            foreach ( $agents as $agent ) {
                $wpdb->insert( "{$p}bd_notifications", array(
                    'user_id'      => $agent->ID,
                    'type'         => 'chat_escalated',
                    'reference_id' => $row->id,
                    'title'        => 'Chat waiting 3+ minutes',
                    'body'         => 'A visitor has been waiting over 3 minutes. Please respond.',
                ) );

                BD_WebPush::send_to_user( $agent->ID, array(
                    'title' => 'High priority chat',
                    'body'  => 'A visitor has been waiting over 3 minutes.',
                    'url'   => admin_url( 'admin.php?page=bd-portal&chat=' . $row->id ),
                    'tag'   => 'chat-' . $row->id,
                ) );
            }
        }
    }

    /**
     * Cron: clean up chats older than 90 days.
     */
    public static function cleanup_old_chats() {
        global $wpdb;
        $p = $wpdb->prefix;

        $ids = $wpdb->get_col(
            "SELECT id FROM {$p}bd_chats WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)"
        );

        if ( empty( $ids ) ) return;

        $ids_sql = implode( ',', array_map( 'intval', $ids ) );

        $wpdb->query( "DELETE FROM {$p}bd_messages WHERE chat_id IN ({$ids_sql})" );
        $wpdb->query( "DELETE FROM {$p}bd_chats WHERE id IN ({$ids_sql})" );

        BD_Notifications::prune_old();
    }

    /**
     * Cron: recalculate daily stats for all agents.
     */
    public static function update_daily_stats() {
        global $wpdb;
        $p     = $wpdb->prefix;
        $today = current_time( 'Y-m-d' );

        $agents = BD_Roles::get_all_agents();
        foreach ( $agents as $agent ) {
            $stats = $wpdb->get_row( $wpdb->prepare(
                "SELECT
                    COUNT(*) AS taken,
                    SUM(CASE WHEN status = 'resolved'   THEN 1 ELSE 0 END) AS resolved,
                    SUM(CASE WHEN status = 'unresolved' THEN 1 ELSE 0 END) AS unresolved,
                    SUM(CASE WHEN resolution_time_seconds > 0 AND resolution_time_seconds <= 180 THEN 1 ELSE 0 END) AS fast,
                    AVG(CASE WHEN first_response_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND, created_at, first_response_at) ELSE NULL END) AS avg
                 FROM {$p}bd_chats
                 WHERE assigned_agent_id = %d AND DATE(created_at) = %s",
                $agent->ID,
                $today
            ) );

            $row = array(
                'agent_id'             => $agent->ID,
                'stat_date'            => $today,
                'chats_taken'          => (int) $stats->taken,
                'chats_resolved'       => (int) $stats->resolved,
                'chats_unresolved'     => (int) $stats->unresolved,
                'resolved_under_3min'  => (int) $stats->fast,
                'avg_response_seconds' => (int) $stats->avg,
            );

            $existing = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$p}bd_daily_stats WHERE agent_id = %d AND stat_date = %s",
                $agent->ID,
                $today
            ) );

            if ( $existing ) {
                $wpdb->update( "{$p}bd_daily_stats", $row, array( 'id' => $existing ) );
            } else {
                $wpdb->insert( "{$p}bd_daily_stats", $row );
            }
        }
    }

    /**
     * Bump a single agent's daily stats immediately.
     */
    private static function bump_agent_stats( $agent_id, $status, $chat ) {
        global $wpdb;
        $p     = $wpdb->prefix;
        $today = current_time( 'Y-m-d' );

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}bd_daily_stats WHERE agent_id = %d AND stat_date = %s",
            $agent_id,
            $today
        ) );

        $isFast = ( $chat->resolution_time_seconds > 0 && $chat->resolution_time_seconds <= 180 ) ? 1 : 0;

        if ( ! $row ) {
            $wpdb->insert( "{$p}bd_daily_stats", array(
                'agent_id'            => $agent_id,
                'stat_date'           => $today,
                'chats_taken'         => 1,
                'chats_resolved'      => 'resolved' === $status ? 1 : 0,
                'chats_unresolved'    => 'unresolved' === $status ? 1 : 0,
                'resolved_under_3min' => $isFast,
            ) );
            return;
        }

        $wpdb->update( "{$p}bd_daily_stats", array(
            'chats_resolved'      => $row->chats_resolved + ( 'resolved' === $status ? 1 : 0 ),
            'chats_unresolved'    => $row->chats_unresolved + ( 'unresolved' === $status ? 1 : 0 ),
            'resolved_under_3min' => $row->resolved_under_3min + $isFast,
        ), array( 'id' => $row->id ) );
    }

    public static function get_agent_recent_chats( $agent_id, $limit = 10 ) {
        global $wpdb;
        $p = $wpdb->prefix;

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}bd_chats
             WHERE assigned_agent_id = %d
             ORDER BY updated_at DESC
             LIMIT %d",
            $agent_id,
            $limit
        ) );
    }

    public static function get_chat( $chat_id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bd_chats WHERE id = %d",
            $chat_id
        ) );
    }
}