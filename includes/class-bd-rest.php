<?php
/**
 * REST API endpoints for the Big Drop Agent Portal.
 *
 * @package BigDrop
 */

defined( 'ABSPATH' ) || exit;

class BD_REST {

    const NAMESPACE = 'bigdrop/v1';

    public function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );

        // Allow anonymous visitors to POST to /visitor/* endpoints.
        add_filter( 'rest_authentication_errors', array( $this, 'allow_public_visitor_routes' ), 99 );
    }

    public function allow_public_visitor_routes( $result ) {
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
        if ( strpos( $request_uri, '/wp-json/bigdrop/v1/visitor/' ) !== false ) {
            return true;
        }
        return $result;
    }

    /* ============================================================
     *  ROUTES
     * ============================================================ */

    public function register_routes() {
        $ns     = self::NAMESPACE;
        $agent  = array( 'BD_Roles', 'permission_any_agent' );
        $admin  = array( 'BD_Roles', 'permission_admin' );
        $client = array( 'BD_Roles', 'permission_client_viewer' );
        $public = '__return_true';
        $logged = function () { return is_user_logged_in(); };

        // ---- Chats ----
        register_rest_route( $ns, '/chats', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'list_chats' ),
            'permission_callback' => $agent,
        ) );

        register_rest_route( $ns, '/chats/(?P<id>\d+)', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_chat' ),
            'permission_callback' => $agent,
        ) );

        register_rest_route( $ns, '/chats/(?P<id>\d+)/messages', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'send_message' ),
            'permission_callback' => $agent,
        ) );

        register_rest_route( $ns, '/chats/(?P<id>\d+)/claim', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'claim_chat' ),
            'permission_callback' => $agent,
        ) );

        register_rest_route( $ns, '/chats/(?P<id>\d+)/resolve', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'resolve_chat' ),
            'permission_callback' => $agent,
        ) );

        register_rest_route( $ns, '/chats/(?P<id>\d+)/release', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'release_chat' ),
            'permission_callback' => $agent,
        ) );

        register_rest_route( $ns, '/chats/(?P<id>\d+)/reopen', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'reopen_chat' ),
            'permission_callback' => $agent,
        ) );

        register_rest_route( $ns, '/chats/(?P<id>\d+)/reassign', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'reassign_chat' ),
            'permission_callback' => $agent,
        ) );

        register_rest_route( $ns, '/chats/(?P<id>\d+)/poll', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'poll_chat' ),
            'permission_callback' => $agent,
        ) );

        // ---- Dashboard / roster ----
        register_rest_route( $ns, '/stats/dashboard', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'stats_dashboard' ),
            'permission_callback' => $agent,
        ) );

        register_rest_route( $ns, '/stats/performance', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'stats_performance' ),
            'permission_callback' => $agent,
        ) );

        register_rest_route( $ns, '/stats/agents-list', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'stats_agents_list' ),
            'permission_callback' => $agent,
        ) );

        register_rest_route( $ns, '/stats/summary', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'stats_summary' ),
            'permission_callback' => $agent,
        ) );

        register_rest_route( $ns, '/stats/chat-history', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'stats_chat_history' ),
            'permission_callback' => $agent,
        ) );

        register_rest_route( $ns, '/roster', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'roster' ),
            'permission_callback' => $agent,
        ) );

        register_rest_route( $ns, '/heartbeat', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'heartbeat' ),
            'permission_callback' => $agent,
        ) );

        // ---- Notifications ----
        register_rest_route( $ns, '/notifications', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'list_notifications' ),
            'permission_callback' => $logged,
        ) );

        register_rest_route( $ns, '/notifications/read', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'mark_notifications_read' ),
            'permission_callback' => $logged,
        ) );

        register_rest_route( $ns, '/notifications/poll', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'poll_notifications' ),
            'permission_callback' => $logged,
        ) );

        // ---- Canned replies ----
        register_rest_route( $ns, '/canned', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'list_canned' ),
            'permission_callback' => $agent,
        ) );

        register_rest_route( $ns, '/canned', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'save_canned' ),
            'permission_callback' => $admin,
        ) );

        register_rest_route( $ns, '/canned/(?P<id>\d+)', array(
            'methods'             => 'DELETE',
            'callback'            => array( $this, 'delete_canned' ),
            'permission_callback' => $admin,
        ) );

        // ---- Agents ----
        register_rest_route( $ns, '/agents', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'create_agent' ),
            'permission_callback' => $admin,
        ) );

        register_rest_route( $ns, '/agents', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'list_agents' ),
            'permission_callback' => $admin,
        ) );

        // ---- Clients ----
        register_rest_route( $ns, '/clients/search', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'search_clients' ),
            'permission_callback' => $client,
        ) );

        // ---- Push ----
        register_rest_route( $ns, '/push/subscribe', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'push_subscribe' ),
            'permission_callback' => $logged,
        ) );

        register_rest_route( $ns, '/push/key', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'push_key' ),
            'permission_callback' => $public,
        ) );

        register_rest_route( $ns, '/push/test', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'push_test' ),
            'permission_callback' => $logged,
        ) );

        // ---- Visitor ----
        register_rest_route( $ns, '/visitor/start', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'visitor_start' ),
            'permission_callback' => $public,
        ) );

        register_rest_route( $ns, '/visitor/(?P<vid>[a-zA-Z0-9]+)/poll', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'visitor_poll' ),
            'permission_callback' => $public,
        ) );

        register_rest_route( $ns, '/visitor/(?P<vid>[a-zA-Z0-9]+)/send', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'visitor_send' ),
            'permission_callback' => $public,
        ) );
    }

    /* ============================================================
     *  HELPERS
     * ============================================================ */

    private static function current_level() {
        if ( current_user_can( 'manage_options' ) ) return 'admin';
        if ( current_user_can( 'bd_manage_canned' ) ) return 'team_lead';
        return 'agent';
    }

    /**
     * Add agent name to system messages.
     */
    private function enrich_system_messages( $messages ) {
        foreach ( $messages as $m ) {
            if ( $m->sender_type === 'system' && ! empty( $m->sender_id ) ) {
                $user = get_userdata( $m->sender_id );
                $m->system_agent_name = $user ? $user->display_name : '';
            } else {
                $m->system_agent_name = '';
            }
        }
        return $messages;
    }

    /* ============================================================
     *  CHAT HANDLERS
     * ============================================================ */

    /**
     * List chats filtered by role and tab.
     */
    public function list_chats( WP_REST_Request $r ) {
        global $wpdb;
        $p     = $wpdb->prefix;
        $uid   = get_current_user_id();
        $level = self::current_level();

        $status = sanitize_key( $r->get_param( 'status' ) ?: 'new' );

        // Validate status.
        if ( ! in_array( $status, array( 'new', 'active', 'resolved', 'unresolved' ), true ) ) {
            $status = 'new';
        }

        // Role-based WHERE.
        if ( $level === 'agent' ) {
            if ( $status === 'new' ) {
                // Agents see unassigned new chats.
                $where = "status = 'new' AND ( assigned_agent_id = 0 OR assigned_agent_id IS NULL )";
            } else {
                // Agents see only their own chats in other tabs.
                $where = $wpdb->prepare(
                    "status = %s AND assigned_agent_id = %d",
                    $status,
                    $uid
                );
            }
        } else {
            // TL/Admin see everything.
            $where = $wpdb->prepare( "status = %s", $status );
        }

        $rows = $wpdb->get_results( "
            SELECT c.*,
                a.display_name AS agent_name,
                (SELECT COUNT(*) FROM {$p}bd_messages WHERE chat_id = c.id AND is_read = 0 AND sender_type = 'visitor') AS unread_count,
                (SELECT message FROM {$p}bd_messages WHERE chat_id = c.id ORDER BY id DESC LIMIT 1) AS last_message
            FROM {$p}bd_chats c
            LEFT JOIN {$wpdb->users} a ON a.ID = c.assigned_agent_id
            WHERE $where
            ORDER BY priority DESC, updated_at DESC
            LIMIT 100
        " );

        foreach ( $rows as $row ) {
            $row->waiting_seconds = max( 0, time() - strtotime( $row->created_at ) );
            $row->agent_initials = '';
            if ( $row->agent_name ) {
                $parts = preg_split( '/\s+/', trim( $row->agent_name ) );
                $row->agent_initials = count( $parts ) > 1
                    ? strtoupper( substr( $parts[0], 0, 1 ) . substr( end( $parts ), 0, 1 ) )
                    : strtoupper( substr( $parts[0], 0, 2 ) );
            }
        }

        // Also return per-tab counts so the UI can show badges.
        $counts = $this->get_tab_counts( $uid, $level );

        return rest_ensure_response( array(
            'rows'   => $rows,
            'counts' => $counts,
            'level'  => $level,
        ) );
    }

    /**
     * Get counts for each tab (respecting role visibility).
     */
    private function get_tab_counts( $uid, $level ) {
        global $wpdb;
        $p = $wpdb->prefix;

        if ( $level === 'agent' ) {
            $counts = array(
                'new'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}bd_chats WHERE status = 'new' AND ( assigned_agent_id = 0 OR assigned_agent_id IS NULL )" ),
                'active'     => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}bd_chats WHERE status = 'active' AND assigned_agent_id = %d", $uid ) ),
                'resolved'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}bd_chats WHERE status = 'resolved' AND assigned_agent_id = %d", $uid ) ),
                'unresolved' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}bd_chats WHERE status = 'unresolved' AND assigned_agent_id = %d", $uid ) ),
            );
        } else {
            $counts = array(
                'new'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}bd_chats WHERE status = 'new'" ),
                'active'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}bd_chats WHERE status = 'active'" ),
                'resolved'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}bd_chats WHERE status = 'resolved'" ),
                'unresolved' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}bd_chats WHERE status = 'unresolved'" ),
            );
        }

        return $counts;
    }

    /**
     * Get single chat with messages.
     */
    public function get_chat( WP_REST_Request $r ) {
        global $wpdb;
        $p  = $wpdb->prefix;
        $id = (int) $r['id'];

        $chat = $wpdb->get_row( $wpdb->prepare(
            "SELECT c.*, a.display_name AS agent_name
             FROM {$p}bd_chats c
             LEFT JOIN {$wpdb->users} a ON a.ID = c.assigned_agent_id
             WHERE c.id = %d",
            $id
        ) );

        if ( ! $chat ) {
            return new WP_Error( 'not_found', 'Chat not found', array( 'status' => 404 ) );
        }

        // Mark visitor messages as read.
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$p}bd_messages SET is_read = 1 WHERE chat_id = %d AND sender_type = 'visitor'",
            $id
        ) );

        $messages = $wpdb->get_results( $wpdb->prepare(
            "SELECT m.*, u.display_name AS sender_name
             FROM {$p}bd_messages m
             LEFT JOIN {$wpdb->users} u ON u.ID = m.sender_id
             WHERE m.chat_id = %d
             ORDER BY m.id ASC
             LIMIT 500",
            $id
        ) );

        $messages = $this->enrich_system_messages( $messages );

        return rest_ensure_response( array(
            'chat'     => $chat,
            'messages' => $messages,
            'level'    => self::current_level(),
        ) );
    }

    /**
     * Send an agent reply (auto-claims if unassigned).
     */
    public function send_message( WP_REST_Request $r ) {
        global $wpdb;
        $p   = $wpdb->prefix;
        $id  = (int) $r['id'];
        $msg = sanitize_textarea_field( $r->get_param( 'message' ) );

        if ( ! $msg ) {
            return new WP_Error( 'empty', 'Message cannot be empty', array( 'status' => 400 ) );
        }

        $agent_id = get_current_user_id();
        $chat     = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}bd_chats WHERE id = %d", $id ) );
        if ( ! $chat ) {
            return new WP_Error( 'not_found', 'Chat not found', array( 'status' => 404 ) );
        }

        // Auto-assign if unassigned.
        if ( ! $chat->assigned_agent_id ) {
            $wpdb->update(
                "{$p}bd_chats",
                array( 'assigned_agent_id' => $agent_id, 'status' => 'active' ),
                array( 'id' => $id )
            );
        }

        $wpdb->insert( "{$p}bd_messages", array(
            'chat_id'     => $id,
            'sender_type' => 'agent',
            'sender_id'   => $agent_id,
            'message'     => $msg,
            'is_read'     => 1,
        ) );

        $msg_id = $wpdb->insert_id;

        if ( ! $chat->first_response_at ) {
            $wpdb->update(
                "{$p}bd_chats",
                array( 'first_response_at' => current_time( 'mysql' ) ),
                array( 'id' => $id )
            );
        }

        do_action( 'bd_message_sent', $id, $msg_id, 'agent' );

        return rest_ensure_response( array(
            'success' => true,
            'id'      => $msg_id,
            'time'    => current_time( 'mysql' ),
        ) );
    }

    public function claim_chat( WP_REST_Request $r ) {
        global $wpdb;
        $wpdb->update(
            "{$wpdb->prefix}bd_chats",
            array( 'assigned_agent_id' => get_current_user_id(), 'status' => 'active' ),
            array( 'id' => (int) $r['id'] )
        );
        return rest_ensure_response( array( 'success' => true ) );
    }

    /**
     * Resolve or unresolve. Writes enriched system message with sender_id.
     */
    public function resolve_chat( WP_REST_Request $r ) {
        global $wpdb;
        $p      = $wpdb->prefix;
        $uid    = get_current_user_id();
        $level  = self::current_level();
        $id     = (int) $r['id'];
        $status = sanitize_key( $r->get_param( 'status' ) ?: 'resolved' );

        if ( ! in_array( $status, array( 'resolved', 'unresolved' ), true ) ) {
            $status = 'resolved';
        }

        $chat = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}bd_chats WHERE id = %d", $id ) );
        if ( ! $chat ) {
            return new WP_Error( 'not_found', 'Chat not found', array( 'status' => 404 ) );
        }

        // Agent can only resolve their own chats.
        if ( $level === 'agent' && (int) $chat->assigned_agent_id !== (int) $uid ) {
            return new WP_Error( 'forbidden', 'Not your chat', array( 'status' => 403 ) );
        }

        $seconds = time() - strtotime( $chat->created_at );

        $wpdb->update( "{$p}bd_chats", array(
            'status'                  => $status,
            'resolved_at'             => current_time( 'mysql' ),
            'resolution_time_seconds' => $seconds,
        ), array( 'id' => $id ) );

        // System message with sender_id for enriched rendering.
        $wpdb->insert( "{$p}bd_messages", array(
            'chat_id'     => $id,
            'sender_type' => 'system',
            'sender_id'   => $uid,
            'message'     => $status === 'resolved'
                ? 'Chat marked as resolved.'
                : 'Chat marked as unresolved.',
            'is_read'     => 1,
        ) );

        do_action( 'bd_chat_resolved', $id, $status );

        return rest_ensure_response( array( 'success' => true ) );
    }

    /**
     * Release chat back to the New queue.
     */
    public function release_chat( WP_REST_Request $r ) {
        global $wpdb;
        $p     = $wpdb->prefix;
        $uid   = get_current_user_id();
        $level = self::current_level();
        $id    = (int) $r['id'];

        $chat = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}bd_chats WHERE id = %d", $id ) );
        if ( ! $chat ) {
            return new WP_Error( 'not_found', 'Chat not found', array( 'status' => 404 ) );
        }

        // Agent can only release their own chat. TL/Admin can release any.
        if ( $level === 'agent' && (int) $chat->assigned_agent_id !== (int) $uid ) {
            return new WP_Error( 'forbidden', 'Not your chat', array( 'status' => 403 ) );
        }

        $wpdb->update( "{$p}bd_chats", array(
            'status'            => 'new',
            'assigned_agent_id' => 0,
            'priority'          => 1,
        ), array( 'id' => $id ) );

        $wpdb->insert( "{$p}bd_messages", array(
            'chat_id'     => $id,
            'sender_type' => 'system',
            'sender_id'   => $uid,
            'message'     => 'Chat released back to the queue.',
            'is_read'     => 1,
        ) );

        return rest_ensure_response( array( 'success' => true ) );
    }

    /**
     * Reopen a resolved/unresolved chat (TL/Admin only).
     * Optionally assign to a specific agent.
     */
    public function reopen_chat( WP_REST_Request $r ) {
        if ( ! BD_Roles::is_admin() && ! current_user_can( 'bd_manage_canned' ) ) {
            return new WP_Error( 'forbidden', 'Only team leads and admins can reopen chats.', array( 'status' => 403 ) );
        }

        global $wpdb;
        $p  = $wpdb->prefix;
        $uid = get_current_user_id();
        $id  = (int) $r['id'];

        $target_agent = (int) $r->get_param( 'agent_id' );

        // If no agent specified, assign to current user.
        if ( ! $target_agent ) {
            $target_agent = $uid;
        }

        // Validate target.
        $target = get_userdata( $target_agent );
        if ( ! $target ) {
            return new WP_Error( 'invalid_agent', 'Invalid agent', array( 'status' => 400 ) );
        }

        $chat = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}bd_chats WHERE id = %d", $id ) );
        if ( ! $chat ) {
            return new WP_Error( 'not_found', 'Chat not found', array( 'status' => 404 ) );
        }

        $wpdb->update( "{$p}bd_chats", array(
            'status'            => 'active',
            'assigned_agent_id' => $target_agent,
            'resolved_at'       => null,
            'priority'          => 1,
        ), array( 'id' => $id ) );

        $msg = ( $target_agent === $uid )
            ? 'Chat reopened.'
            : 'Chat reopened and assigned to ' . $target->display_name . '.';

        $wpdb->insert( "{$p}bd_messages", array(
            'chat_id'     => $id,
            'sender_type' => 'system',
            'sender_id'   => $uid,
            'message'     => $msg,
            'is_read'     => 1,
        ) );

        return rest_ensure_response( array(
            'success' => true,
            'agent_id' => $target_agent,
        ) );
    }

    /**
     * Reassign a chat to another agent (TL/Admin only).
     */
    public function reassign_chat( WP_REST_Request $r ) {
        if ( ! BD_Roles::is_admin() && ! current_user_can( 'bd_manage_canned' ) ) {
            return new WP_Error( 'forbidden', 'Not allowed', array( 'status' => 403 ) );
        }

        global $wpdb;
        $p  = $wpdb->prefix;
        $id = (int) $r['id'];

        $new_agent = (int) $r->get_param( 'agent_id' );
        if ( ! $new_agent ) {
            return new WP_Error( 'invalid', 'Missing agent_id', array( 'status' => 400 ) );
        }

        $target = get_userdata( $new_agent );
        if ( ! $target || ( ! in_array( 'bd_agent', (array) $target->roles, true )
            && ! in_array( 'bd_team_lead', (array) $target->roles, true )
            && ! in_array( 'administrator', (array) $target->roles, true ) ) ) {
            return new WP_Error( 'invalid_agent', 'Invalid agent', array( 'status' => 400 ) );
        }

        $chat = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}bd_chats WHERE id = %d", $id ) );
        if ( ! $chat ) {
            return new WP_Error( 'not_found', 'Chat not found', array( 'status' => 404 ) );
        }

        $old_agent = (int) $chat->assigned_agent_id;

        $wpdb->update( "{$p}bd_chats", array(
            'assigned_agent_id' => $new_agent,
            'status'            => 'active',
        ), array( 'id' => $id ) );

        $wpdb->insert( "{$p}bd_messages", array(
            'chat_id'     => $id,
            'sender_type' => 'system',
            'sender_id'   => get_current_user_id(),
            'message'     => 'Chat reassigned to ' . $target->display_name . '.',
            'is_read'     => 1,
        ) );

        if ( $new_agent !== $old_agent && $new_agent !== get_current_user_id() ) {
            if ( class_exists( 'BD_Notifications' ) ) {
                BD_Notifications::notify(
                    $new_agent,
                    'chat_assigned',
                    'New chat assigned to you',
                    'Chat with ' . ( $chat->visitor_name ?: 'Visitor' ) . ' has been assigned to you.',
                    $id
                );
            }
        }

        return rest_ensure_response( array(
            'success'    => true,
            'agent_id'   => $new_agent,
            'agent_name' => $target->display_name,
        ) );
    }

    /**
     * Poll for new messages since a message ID.
     */
    public function poll_chat( WP_REST_Request $r ) {
        global $wpdb;
        $p     = $wpdb->prefix;
        $id    = (int) $r['id'];
        $after = (int) $r->get_param( 'after' );

        $messages = $wpdb->get_results( $wpdb->prepare(
            "SELECT m.*, u.display_name AS sender_name
             FROM {$p}bd_messages m
             LEFT JOIN {$wpdb->users} u ON u.ID = m.sender_id
             WHERE m.chat_id = %d AND m.id > %d
             ORDER BY m.id ASC
             LIMIT 50",
            $id,
            $after
        ) );

        $messages = $this->enrich_system_messages( $messages );

        // Mark visitor messages as read.
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$p}bd_messages SET is_read = 1 WHERE chat_id = %d AND sender_type = 'visitor'",
            $id
        ) );

        // Also return the current chat row so the UI can reflect status changes.
        $chat = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}bd_chats WHERE id = %d", $id ) );

        return rest_ensure_response( array(
            'messages' => $messages,
            'chat'     => $chat,
        ) );
    }

    /* ============================================================
     *  DASHBOARD / STATS
     * ============================================================ */

    public function stats_dashboard() {
        global $wpdb;
        $p     = $wpdb->prefix;
        $today = current_time( 'Y-m-d' );

        $today_total      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}bd_chats WHERE DATE(created_at) = %s", $today ) );
        $today_resolved   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}bd_chats WHERE DATE(created_at) = %s AND status = 'resolved'", $today ) );
        $today_unresolved = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}bd_chats WHERE DATE(created_at) = %s AND status = 'unresolved'", $today ) );
        $today_pending    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}bd_chats WHERE DATE(created_at) = %s AND status IN ('new','active')", $today ) );

        $all_time       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}bd_chats" );
        $all_resolved   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}bd_chats WHERE status = 'resolved'" );
        $all_unresolved = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}bd_chats WHERE status = 'unresolved'" );
        $all_visitors   = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT visitor_id) FROM {$p}bd_chats" );

        $avg_response = (int) $wpdb->get_var(
            "SELECT AVG(TIMESTAMPDIFF(SECOND, created_at, first_response_at))
             FROM {$p}bd_chats WHERE first_response_at IS NOT NULL"
        );

        $resolution_rate = $all_time > 0 ? round( ( $all_resolved / $all_time ) * 100 ) : 0;

        $weekly = array();
        for ( $i = 6; $i >= 0; $i-- ) {
            $d        = date( 'Y-m-d', strtotime( "-{$i} days" ) );
            $weekly[] = array(
                'date'  => $d,
                'label' => date( 'D', strtotime( $d ) ),
                'count' => (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$p}bd_chats WHERE DATE(created_at) = %s", $d
                ) ),
            );
        }

        return rest_ensure_response( array(
            'today'            => $today_total,
            'today_resolved'   => $today_resolved,
            'today_unresolved' => $today_unresolved,
            'today_pending'    => $today_pending,
            'all_time'         => $all_time,
            'all_resolved'     => $all_resolved,
            'all_unresolved'   => $all_unresolved,
            'all_visitors'     => $all_visitors,
            'avg_response'     => $avg_response,
            'resolution_rate'  => $resolution_rate,
            'weekly'           => $weekly,
        ) );
    }

    public function stats_performance( WP_REST_Request $r ) {
        global $wpdb;
        $p = $wpdb->prefix;

        $level = self::current_level();

        $from = sanitize_text_field( $r->get_param( 'from' ) ?: date( 'Y-m-d', strtotime( '-7 days' ) ) );
        $to   = sanitize_text_field( $r->get_param( 'to' )   ?: date( 'Y-m-d' ) );

        $agent_id = (int) $r->get_param( 'agent_id' );
        if ( $level === 'agent' ) {
            $agent_id = get_current_user_id();
        }

        $where = $wpdb->prepare( 'DATE(created_at) BETWEEN %s AND %s', $from, $to );
        if ( $agent_id ) {
            $where .= $wpdb->prepare( ' AND assigned_agent_id = %d', $agent_id );
        }

        $rows = $wpdb->get_results( "
            SELECT
                assigned_agent_id AS agent_id,
                COUNT(*) AS chats_taken,
                SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) AS chats_resolved,
                SUM(CASE WHEN status = 'unresolved' THEN 1 ELSE 0 END) AS chats_unresolved,
                SUM(CASE WHEN status IN ('new','active') THEN 1 ELSE 0 END) AS chats_pending,
                SUM(CASE WHEN resolution_time_seconds > 0 AND resolution_time_seconds <= 180 THEN 1 ELSE 0 END) AS resolved_under_3min,
                AVG(CASE WHEN first_response_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND, created_at, first_response_at) ELSE NULL END) AS avg_response_seconds,
                AVG(CASE WHEN resolution_time_seconds > 0 THEN resolution_time_seconds ELSE NULL END) AS avg_resolution_seconds
             FROM {$p}bd_chats
             WHERE {$where}
             GROUP BY assigned_agent_id
             ORDER BY chats_taken DESC
        " );

        foreach ( $rows as $row ) {
            $user = $row->agent_id ? get_userdata( $row->agent_id ) : null;
            $row->agent_name = $user ? $user->display_name : 'Unassigned';
            $row->avg_response_seconds   = (int) $row->avg_response_seconds;
            $row->avg_resolution_seconds = (int) $row->avg_resolution_seconds;
            $total = (int) $row->chats_taken;
            $row->resolution_rate = $total > 0 ? round( ( (int) $row->chats_resolved / $total ) * 100 ) : 0;
            $row->is_best = false;
        }

        $best_id = 0;
        $best_score = -1;
        foreach ( $rows as $row ) {
            $score = (int) $row->chats_resolved * 2 + (int) $row->resolved_under_3min;
            if ( $score > $best_score && $row->agent_id ) {
                $best_score = $score;
                $best_id    = (int) $row->agent_id;
            }
        }
        foreach ( $rows as $row ) {
            if ( (int) $row->agent_id === $best_id ) {
                $row->is_best = true;
            }
        }

        return rest_ensure_response( array(
            'range' => array( 'from' => $from, 'to' => $to ),
            'level' => $level,
            'rows'  => $rows,
            'best'  => $best_id,
        ) );
    }

    public function stats_agents_list() {
        $level = self::current_level();
        if ( $level === 'agent' ) {
            return rest_ensure_response( array() );
        }

        $users = BD_Roles::get_all_agents();
        $out   = array();
        foreach ( $users as $u ) {
            $out[] = array( 'id' => (int) $u->ID, 'name' => $u->display_name );
        }
        return rest_ensure_response( $out );
    }

    public function stats_summary( WP_REST_Request $r ) {
        global $wpdb;
        $p = $wpdb->prefix;

        $level = self::current_level();

        $from = sanitize_text_field( $r->get_param( 'from' ) ?: date( 'Y-m-d', strtotime( '-7 days' ) ) );
        $to   = sanitize_text_field( $r->get_param( 'to' )   ?: date( 'Y-m-d' ) );

        $agent_id = (int) $r->get_param( 'agent_id' );
        if ( $level === 'agent' ) {
            $agent_id = get_current_user_id();
        }

        $where = $wpdb->prepare( 'DATE(created_at) BETWEEN %s AND %s', $from, $to );
        if ( $agent_id ) {
            $where .= $wpdb->prepare( ' AND assigned_agent_id = %d', $agent_id );
        }

        $row = $wpdb->get_row( "
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) AS resolved,
                SUM(CASE WHEN status = 'unresolved' THEN 1 ELSE 0 END) AS unresolved,
                SUM(CASE WHEN status IN ('new','active') THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN resolution_time_seconds > 0 AND resolution_time_seconds <= 180 THEN 1 ELSE 0 END) AS fast,
                AVG(CASE WHEN first_response_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND, created_at, first_response_at) ELSE NULL END) AS avg_response,
                AVG(CASE WHEN resolution_time_seconds > 0 THEN resolution_time_seconds ELSE NULL END) AS avg_resolution
            FROM {$p}bd_chats
            WHERE {$where}
        " );

        $total    = (int) $row->total;
        $resolved = (int) $row->resolved;

        return rest_ensure_response( array(
            'chats_taken'            => $total,
            'resolved'               => $resolved,
            'unresolved'             => (int) $row->unresolved,
            'pending'                => (int) $row->pending,
            'resolved_under_3min'    => (int) $row->fast,
            'avg_response_seconds'   => (int) $row->avg_response,
            'avg_resolution_seconds' => (int) $row->avg_resolution,
            'resolution_rate'        => $total > 0 ? round( ( $resolved / $total ) * 100 ) : 0,
        ) );
    }

    public function stats_chat_history( WP_REST_Request $r ) {
        global $wpdb;
        $p = $wpdb->prefix;

        $level = self::current_level();

        $from = sanitize_text_field( $r->get_param( 'from' ) ?: date( 'Y-m-d', strtotime( '-7 days' ) ) );
        $to   = sanitize_text_field( $r->get_param( 'to' )   ?: date( 'Y-m-d' ) );

        $agent_id = (int) $r->get_param( 'agent_id' );
        if ( $level === 'agent' ) {
            $agent_id = get_current_user_id();
        }

        $status = sanitize_key( $r->get_param( 'status' ) ?: 'all' );
        $search = sanitize_text_field( $r->get_param( 'search' ) ?: '' );

        $page     = max( 1, (int) $r->get_param( 'page' ) );
        $per_page = min( 100, max( 1, (int) ( $r->get_param( 'per_page' ) ?: 25 ) ) );
        $offset   = ( $page - 1 ) * $per_page;

        $where = $wpdb->prepare( 'DATE(c.created_at) BETWEEN %s AND %s', $from, $to );
        if ( $agent_id ) {
            $where .= $wpdb->prepare( ' AND c.assigned_agent_id = %d', $agent_id );
        }
        if ( in_array( $status, array( 'resolved', 'unresolved', 'new', 'active' ), true ) ) {
            $where .= $wpdb->prepare( ' AND c.status = %s', $status );
        }
        if ( $search ) {
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $where .= $wpdb->prepare(
                ' AND ( c.visitor_name LIKE %s OR c.visitor_phone LIKE %s OR c.visitor_email LIKE %s )',
                $like, $like, $like
            );
        }

        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}bd_chats c WHERE {$where}" );

        $chats = $wpdb->get_results( $wpdb->prepare( "
            SELECT
                c.id,
                c.visitor_name,
                c.visitor_phone,
                c.visitor_email,
                c.assigned_agent_id AS agent_id,
                c.status,
                c.created_at,
                c.first_response_at,
                c.resolved_at,
                c.resolution_time_seconds,
                (SELECT COUNT(*) FROM {$p}bd_messages m WHERE m.chat_id = c.id) AS message_count
            FROM {$p}bd_chats c
            WHERE {$where}
            ORDER BY c.created_at DESC
            LIMIT %d OFFSET %d
        ", $per_page, $offset ) );

        foreach ( $chats as $chat ) {
            $user = $chat->agent_id ? get_userdata( $chat->agent_id ) : null;
            $chat->agent_name = $user ? $user->display_name : '';
        }

        return rest_ensure_response( array(
            'chats'    => $chats,
            'total'    => $total,
            'page'     => $page,
            'pages'    => (int) ceil( $total / $per_page ),
            'per_page' => $per_page,
        ) );
    }

    public function roster() {
        $users = BD_Roles::get_all_agents();
        $out   = array();
        foreach ( $users as $u ) {
            $out[] = array(
                'id'       => $u->ID,
                'name'     => $u->display_name,
                'initials' => self::initials( $u->display_name ),
                'role'     => isset( $u->roles[0] ) ? $u->roles[0] : 'agent',
                'status'   => BD_Roles::get_agent_status( $u->ID ),
            );
        }
        return rest_ensure_response( $out );
    }

    public function heartbeat() {
        $user_id = get_current_user_id();
        update_user_meta( $user_id, 'bd_last_seen', time() );
        update_user_meta( $user_id, 'bd_status', 'online' );
        return rest_ensure_response( array( 'ok' => true ) );
    }

    /* ============================================================
     *  NOTIFICATIONS
     * ============================================================ */

    public function list_notifications() {
        global $wpdb;
        $p   = $wpdb->prefix;
        $uid = get_current_user_id();

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}bd_notifications WHERE user_id = %d ORDER BY id DESC LIMIT 30",
            $uid
        ) );

        $unread = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}bd_notifications WHERE user_id = %d AND is_read = 0",
            $uid
        ) );

        return rest_ensure_response( array( 'items' => $rows, 'unread' => $unread ) );
    }

    public function mark_notifications_read() {
        global $wpdb;
        $wpdb->update(
            "{$wpdb->prefix}bd_notifications",
            array( 'is_read' => 1 ),
            array( 'user_id' => get_current_user_id(), 'is_read' => 0 )
        );
        return rest_ensure_response( array( 'success' => true ) );
    }

    public function poll_notifications( WP_REST_Request $r ) {
        global $wpdb;
        $p     = $wpdb->prefix;
        $uid   = get_current_user_id();
        $since = (int) $r->get_param( 'since' );

        $notifications = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}bd_notifications WHERE user_id = %d AND id > %d ORDER BY id DESC LIMIT 20",
            $uid,
            $since
        ) );

        $waiting = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}bd_chats WHERE status = 'new'" );
        $unread  = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}bd_notifications WHERE user_id = %d AND is_read = 0",
            $uid
        ) );

        return rest_ensure_response( array(
            'notifications' => $notifications,
            'waiting_chats' => $waiting,
            'unread'        => $unread,
            'server_time'   => time(),
        ) );
    }

    /* ============================================================
     *  CANNED
     * ============================================================ */

    public function list_canned() {
        global $wpdb;
        return rest_ensure_response( $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}bd_canned_replies ORDER BY id DESC"
        ) );
    }

    public function save_canned( WP_REST_Request $r ) {
        global $wpdb;
        $id   = (int) $r->get_param( 'id' );
        $data = array(
            'header'         => sanitize_text_field( $r->get_param( 'header' ) ),
            'message'        => sanitize_textarea_field( $r->get_param( 'message' ) ),
            'attachment_url' => esc_url_raw( $r->get_param( 'attachment_url' ) ),
            'created_by'     => get_current_user_id(),
        );

        if ( ! $data['header'] || ! $data['message'] ) {
            return new WP_Error( 'invalid', 'Header and message are required', array( 'status' => 400 ) );
        }

        if ( $id ) {
            $wpdb->update( "{$wpdb->prefix}bd_canned_replies", $data, array( 'id' => $id ) );
        } else {
            $wpdb->insert( "{$wpdb->prefix}bd_canned_replies", $data );
            $id = $wpdb->insert_id;
        }

        return rest_ensure_response( array( 'success' => true, 'id' => $id ) );
    }

    public function delete_canned( WP_REST_Request $r ) {
        global $wpdb;
        $wpdb->delete( "{$wpdb->prefix}bd_canned_replies", array( 'id' => (int) $r['id'] ) );
        return rest_ensure_response( array( 'success' => true ) );
    }

    /* ============================================================
     *  AGENTS
     * ============================================================ */

    public function create_agent( WP_REST_Request $r ) {
        $username = sanitize_user( $r->get_param( 'username' ), true );
        $email    = sanitize_email( $r->get_param( 'email' ) );
        $password = (string) $r->get_param( 'password' );
        $name     = sanitize_text_field( $r->get_param( 'name' ) );
        $surname  = sanitize_text_field( $r->get_param( 'surname' ) );
        $position = sanitize_text_field( $r->get_param( 'position' ) ?: 'bd_agent' );

        if ( ! in_array( $position, array( 'bd_agent', 'bd_team_lead' ), true ) ) {
            $position = 'bd_agent';
        }

        if ( ! $username || ! $email || ! $password ) {
            return new WP_Error( 'invalid', 'Username, email and password are required', array( 'status' => 400 ) );
        }
        if ( username_exists( $username ) ) {
            return new WP_Error( 'exists', 'Username already exists', array( 'status' => 400 ) );
        }
        if ( email_exists( $email ) ) {
            return new WP_Error( 'email', 'Email already registered', array( 'status' => 400 ) );
        }
        if ( strlen( $password ) < 6 ) {
            return new WP_Error( 'weak', 'Password must be at least 6 characters', array( 'status' => 400 ) );
        }

        $uid = wp_create_user( $username, $password, $email );
        if ( is_wp_error( $uid ) ) return $uid;

        wp_update_user( array(
            'ID'           => $uid,
            'display_name' => trim( "{$name} {$surname}" ),
            'first_name'   => $name,
            'last_name'    => $surname,
            'role'         => $position,
        ) );

        return rest_ensure_response( array( 'success' => true, 'user_id' => $uid ) );
    }

    public function list_agents() {
        $users = BD_Roles::get_all_agents();
        $out   = array();
        foreach ( $users as $u ) {
            $out[] = array(
                'id'       => $u->ID,
                'username' => $u->user_login,
                'name'     => $u->display_name,
                'email'    => $u->user_email,
                'role'     => isset( $u->roles[0] ) ? $u->roles[0] : '',
                'status'   => BD_Roles::get_agent_status( $u->ID ),
            );
        }
        return rest_ensure_response( $out );
    }

    /* ============================================================
     *  CLIENTS
     * ============================================================ */

    public function search_clients( WP_REST_Request $r ) {
        global $wpdb;
        $q = sanitize_text_field( $r->get_param( 'q' ) );
        if ( strlen( $q ) < 2 ) {
            return rest_ensure_response( array() );
        }

        $like = '%' . $wpdb->esc_like( $q ) . '%';
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bd_clients
             WHERE account_number LIKE %s OR full_name LIKE %s OR phone LIKE %s
             LIMIT 20",
            $like, $like, $like
        ) );

        return rest_ensure_response( $rows );
    }

    /* ============================================================
     *  PUSH
     * ============================================================ */

    public function push_subscribe( WP_REST_Request $r ) {
        global $wpdb;
        $sub = $r->get_json_params();

        if ( empty( $sub['endpoint'] ) || empty( $sub['keys']['p256dh'] ) || empty( $sub['keys']['auth'] ) ) {
            return new WP_Error( 'bad', 'Malformed subscription', array( 'status' => 400 ) );
        }

        $wpdb->insert( "{$wpdb->prefix}bd_push_subscriptions", array(
            'user_id'  => get_current_user_id(),
            'endpoint' => esc_url_raw( $sub['endpoint'] ),
            'p256dh'   => sanitize_text_field( $sub['keys']['p256dh'] ),
            'auth'     => sanitize_text_field( $sub['keys']['auth'] ),
        ) );

        return rest_ensure_response( array( 'success' => true ) );
    }

    public function push_key() {
        return rest_ensure_response( array( 'key' => get_option( 'bd_vapid_public_key' ) ) );
    }

    public function push_test() {
        $user_id = get_current_user_id();
        $sent    = BD_WebPush::send_to_user( $user_id, array(
            'title' => 'Big Drop',
            'body'  => 'Test notification - you will receive alerts like this.',
            'url'   => admin_url( 'admin.php?page=bd-portal' ),
        ) );
        return rest_ensure_response( array( 'sent' => $sent ) );
    }

    /* ============================================================
     *  VISITOR
     * ============================================================ */

    public function visitor_start( WP_REST_Request $r ) {
        global $wpdb;
        $p   = $wpdb->prefix;
        $vid = 'v' . bin2hex( random_bytes( 8 ) );

        $wpdb->insert( "{$p}bd_chats", array(
            'visitor_id'    => $vid,
            'visitor_name'  => sanitize_text_field( $r->get_param( 'name' ) ?: 'Visitor' ),
            'visitor_email' => sanitize_email( $r->get_param( 'email' ) ?: '' ),
            'visitor_phone' => sanitize_text_field( $r->get_param( 'phone' ) ?: '' ),
            'status'        => 'new',
        ) );

        return rest_ensure_response( array(
            'visitor_id' => $vid,
            'chat_id'    => $wpdb->insert_id,
        ) );
    }

    public function visitor_poll( WP_REST_Request $r ) {
        global $wpdb;
        $p     = $wpdb->prefix;
        $vid   = sanitize_text_field( $r['vid'] );
        $after = (int) $r->get_param( 'after' );

        $chat = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}bd_chats WHERE visitor_id = %s ORDER BY id DESC LIMIT 1",
            $vid
        ) );

        if ( ! $chat ) {
            return new WP_Error( 'not_found', 'Chat not found', array( 'status' => 404 ) );
        }

        $messages = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}bd_messages WHERE chat_id = %d AND id > %d ORDER BY id ASC LIMIT 50",
            $chat->id,
            $after
        ) );

        return rest_ensure_response( array( 'chat' => $chat, 'messages' => $messages ) );
    }

    public function visitor_send( WP_REST_Request $r ) {
        global $wpdb;
        $p   = $wpdb->prefix;
        $vid = sanitize_text_field( $r['vid'] );
        $msg = sanitize_textarea_field( $r->get_param( 'message' ) );

        if ( ! $msg ) {
            return new WP_Error( 'empty', 'Message empty', array( 'status' => 400 ) );
        }

        $chat = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}bd_chats WHERE visitor_id = %s ORDER BY id DESC LIMIT 1",
            $vid
        ) );

        if ( ! $chat ) {
            return new WP_Error( 'not_found', 'Chat not found', array( 'status' => 404 ) );
        }

        $wpdb->insert( "{$p}bd_messages", array(
            'chat_id'     => $chat->id,
            'sender_type' => 'visitor',
            'message'     => $msg,
        ) );

        $msg_id = $wpdb->insert_id;

        $wpdb->update( "{$p}bd_chats", array(
            'updated_at' => current_time( 'mysql' ),
        ), array( 'id' => $chat->id ) );

        do_action( 'bd_message_sent', $chat->id, $msg_id, 'visitor' );

        return rest_ensure_response( array( 'success' => true, 'id' => $msg_id ) );
    }

    /* ============================================================
     *  HELPERS
     * ============================================================ */

    private static function initials( $name ) {
        $parts = preg_split( '/\s+/', trim( (string) $name ) );
        if ( empty( $parts ) ) return '?';
        if ( count( $parts ) === 1 ) return strtoupper( substr( $parts[0], 0, 2 ) );
        return strtoupper( substr( $parts[0], 0, 1 ) . substr( end( $parts ), 0, 1 ) );
    }
}