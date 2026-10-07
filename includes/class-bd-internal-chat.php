<?php
/**
 * Internal Team Chat - company updates + support tickets
 * with role-based permissions and media attachments.
 *
 * @package BigDrop
 */

defined( 'ABSPATH' ) || exit;

class BD_Internal_Chat {

    const CHANNEL_UPDATES = 'updates';
    const CHANNEL_TICKETS = 'tickets';

    public function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );

        // AJAX handlers - respond to admin-ajax.php?action=bd_upload_media
        add_action( 'wp_ajax_bd_upload_media',        array( $this, 'handle_media_upload' ) );
        add_action( 'wp_ajax_nopriv_bd_upload_media', array( $this, 'handle_media_upload' ) );

        // Legacy admin-post handlers (kept as fallback for direct form posts).
        add_action( 'admin_post_bd_upload_media',        array( $this, 'handle_media_upload' ) );
        add_action( 'admin_post_nopriv_bd_upload_media', array( $this, 'handle_media_upload' ) );
    }

    /* ============================================================
     *  DATABASE TABLES
     * ============================================================ */

    public static function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $p       = $wpdb->prefix;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta( "CREATE TABLE {$p}bd_internal_threads (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            channel VARCHAR(20) NOT NULL,
            title VARCHAR(200) DEFAULT '',
            priority VARCHAR(20) DEFAULT 'normal',
            status VARCHAR(20) DEFAULT 'open',
            created_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_channel (channel, status, updated_at),
            INDEX idx_created_by (created_by)
        ) {$charset};" );

        dbDelta( "CREATE TABLE {$p}bd_internal_messages_v2 (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            thread_id BIGINT UNSIGNED NOT NULL,
            sender_id BIGINT UNSIGNED NOT NULL,
            message LONGTEXT,
            attachment_id BIGINT UNSIGNED DEFAULT 0,
            attachment_url VARCHAR(500) DEFAULT '',
            attachment_name VARCHAR(255) DEFAULT '',
            attachment_type VARCHAR(50) DEFAULT '',
            attachment_size BIGINT UNSIGNED DEFAULT 0,
            is_system TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_thread (thread_id, created_at)
        ) {$charset};" );

        dbDelta( "CREATE TABLE {$p}bd_internal_participants (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            thread_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            role VARCHAR(30) DEFAULT 'watcher',
            last_read_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_thread_user (thread_id, user_id),
            INDEX idx_user (user_id)
        ) {$charset};" );
    }

    public static function drop_tables() {
        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->query( "DROP TABLE IF EXISTS {$p}bd_internal_participants" );
        $wpdb->query( "DROP TABLE IF EXISTS {$p}bd_internal_messages_v2" );
        $wpdb->query( "DROP TABLE IF EXISTS {$p}bd_internal_threads" );
    }

    private static function tables_exist() {
        global $wpdb;
        $p = $wpdb->prefix;
        $t = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $p . 'bd_internal_threads' ) );
        return (bool) $t;
    }

    /* ============================================================
     *  REST ROUTES
     * ============================================================ */

    public function register_routes() {
        $ns = 'bigdrop/v1';

        register_rest_route( $ns, '/internal/threads', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'list_threads' ),
            'permission_callback' => array( 'BD_Roles', 'permission_any_agent' ),
        ) );

        register_rest_route( $ns, '/internal/threads/(?P<id>\d+)', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_thread' ),
            'permission_callback' => array( 'BD_Roles', 'permission_any_agent' ),
        ) );

        register_rest_route( $ns, '/internal/threads', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'create_thread' ),
            'permission_callback' => array( 'BD_Roles', 'permission_any_agent' ),
        ) );

        register_rest_route( $ns, '/internal/threads/(?P<id>\d+)/messages', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'post_message' ),
            'permission_callback' => array( 'BD_Roles', 'permission_any_agent' ),
        ) );

        register_rest_route( $ns, '/internal/threads/(?P<id>\d+)/status', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'set_status' ),
            'permission_callback' => array( 'BD_Roles', 'permission_any_agent' ),
        ) );

        register_rest_route( $ns, '/internal/threads/(?P<id>\d+)/poll', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'poll_thread' ),
            'permission_callback' => array( 'BD_Roles', 'permission_any_agent' ),
        ) );

        register_rest_route( $ns, '/internal/unread', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_unread' ),
            'permission_callback' => array( 'BD_Roles', 'permission_any_agent' ),
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

    private static function can_view_thread( $thread, $user_id, $level ) {
        if ( $level === 'admin' || $level === 'team_lead' ) return true;
        if ( $thread->channel === self::CHANNEL_UPDATES ) return true;
        return ( (int) $thread->created_by === (int) $user_id );
    }

    private static function user_level_label( $user_id ) {
        $user = get_userdata( (int) $user_id );
        if ( ! $user ) return '';
        if ( in_array( 'administrator', (array) $user->roles, true ) ) return 'admin';
        if ( in_array( 'bd_team_lead', (array) $user->roles, true ) ) return 'team_lead';
        if ( in_array( 'bd_agent', (array) $user->roles, true ) ) return 'agent';
        return '';
    }

    /* ============================================================
     *  LIST THREADS
     * ============================================================ */

    public function list_threads( WP_REST_Request $r ) {
        if ( ! self::tables_exist() ) {
            return new WP_Error( 'missing_tables', 'Internal chat tables are missing. Deactivate and reactivate the plugin.', array( 'status' => 500 ) );
        }

        global $wpdb;
        $p       = $wpdb->prefix;
        $uid     = get_current_user_id();
        $level   = self::current_level();
        $channel = sanitize_key( $r->get_param( 'channel' ) ?: self::CHANNEL_UPDATES );

        if ( ! in_array( $channel, array( self::CHANNEL_UPDATES, self::CHANNEL_TICKETS ), true ) ) {
            return new WP_Error( 'bad_channel', 'Invalid channel', array( 'status' => 400 ) );
        }

        $where = $wpdb->prepare( 'channel = %s', $channel );
        if ( $channel === self::CHANNEL_TICKETS && $level === 'agent' ) {
            $where .= $wpdb->prepare( ' AND created_by = %d', $uid );
        }

        $threads = $wpdb->get_results( "
            SELECT t.*,
                u.display_name AS author_name,
                (SELECT COUNT(*) FROM {$p}bd_internal_messages_v2 m WHERE m.thread_id = t.id) AS message_count,
                (SELECT m.message FROM {$p}bd_internal_messages_v2 m WHERE m.thread_id = t.id ORDER BY m.id DESC LIMIT 1) AS last_message,
                (SELECT m.created_at FROM {$p}bd_internal_messages_v2 m WHERE m.thread_id = t.id ORDER BY m.id DESC LIMIT 1) AS last_at
            FROM {$p}bd_internal_threads t
            LEFT JOIN {$wpdb->users} u ON u.ID = t.created_by
            WHERE $where
            ORDER BY t.updated_at DESC
            LIMIT 100
        " );

        return rest_ensure_response( array(
            'channel' => $channel,
            'level'   => $level,
            'threads' => $threads,
        ) );
    }

    /* ============================================================
     *  GET SINGLE THREAD
     * ============================================================ */

    public function get_thread( WP_REST_Request $r ) {
        if ( ! self::tables_exist() ) {
            return new WP_Error( 'missing_tables', 'Tables missing.', array( 'status' => 500 ) );
        }

        global $wpdb;
        $p       = $wpdb->prefix;
        $uid     = get_current_user_id();
        $level   = self::current_level();
        $id      = (int) $r['id'];

        $thread = $wpdb->get_row( $wpdb->prepare( "
            SELECT t.*, u.display_name AS author_name
            FROM {$p}bd_internal_threads t
            LEFT JOIN {$wpdb->users} u ON u.ID = t.created_by
            WHERE t.id = %d
        ", $id ) );

        if ( ! $thread ) {
            return new WP_Error( 'not_found', 'Thread not found', array( 'status' => 404 ) );
        }

        if ( ! self::can_view_thread( $thread, $uid, $level ) ) {
            return new WP_Error( 'forbidden', 'You cannot view this thread', array( 'status' => 403 ) );
        }

        $messages = $wpdb->get_results( $wpdb->prepare( "
            SELECT m.*, u.display_name AS sender_name
            FROM {$p}bd_internal_messages_v2 m
            LEFT JOIN {$wpdb->users} u ON u.ID = m.sender_id
            WHERE m.thread_id = %d
            ORDER BY m.id ASC
            LIMIT 500
        ", $id ) );

        foreach ( $messages as $m ) {
            $m->sender_role = self::user_level_label( $m->sender_id );
        }

        $wpdb->replace( "{$p}bd_internal_participants", array(
            'thread_id'    => $id,
            'user_id'      => $uid,
            'role'         => $level,
            'last_read_at' => current_time( 'mysql' ),
        ) );

        return rest_ensure_response( array(
            'thread'   => $thread,
            'messages' => $messages,
            'level'    => $level,
        ) );
    }

    /* ============================================================
     *  CREATE THREAD
     * ============================================================ */

    public function create_thread( WP_REST_Request $r ) {
        if ( ! self::tables_exist() ) {
            return new WP_Error( 'missing_tables', 'Tables missing. Deactivate and reactivate the plugin.', array( 'status' => 500 ) );
        }

        global $wpdb;
        $p       = $wpdb->prefix;
        $uid     = get_current_user_id();
        $level   = self::current_level();

        $channel  = sanitize_key( $r->get_param( 'channel' ) ?: self::CHANNEL_UPDATES );
        $title    = sanitize_text_field( $r->get_param( 'title' ) ?: '' );
        $message  = wp_kses_post( $r->get_param( 'message' ) ?: '' );
        $priority = sanitize_key( $r->get_param( 'priority' ) ?: 'normal' );

        if ( ! in_array( $channel, array( self::CHANNEL_UPDATES, self::CHANNEL_TICKETS ), true ) ) {
            return new WP_Error( 'bad_channel', 'Invalid channel', array( 'status' => 400 ) );
        }

        if ( $channel === self::CHANNEL_UPDATES && $level === 'agent' ) {
            return new WP_Error( 'forbidden', 'Only team leads and admins can post updates.', array( 'status' => 403 ) );
        }

        if ( ! $title ) {
            $title = $channel === self::CHANNEL_TICKETS ? 'Support Ticket' : 'Company Update';
        }

        // Attachment fields.
        $attachment_id   = (int) $r->get_param( 'attachment_id' );
        $attachment_url  = esc_url_raw( $r->get_param( 'attachment_url' ) ?: '' );
        $attachment_name = sanitize_text_field( $r->get_param( 'attachment_name' ) ?: '' );
        $attachment_type = sanitize_text_field( $r->get_param( 'attachment_type' ) ?: '' );
        $attachment_size = (int) $r->get_param( 'attachment_size' );

        $inserted = $wpdb->insert( "{$p}bd_internal_threads", array(
            'channel'    => $channel,
            'title'      => $title,
            'priority'   => $priority,
            'status'     => 'open',
            'created_by' => $uid,
        ), array( '%s', '%s', '%s', '%s', '%d' ) );

        if ( false === $inserted ) {
            return new WP_Error( 'db_error', 'Could not create thread: ' . $wpdb->last_error, array( 'status' => 500 ) );
        }

        $thread_id = (int) $wpdb->insert_id;
        if ( ! $thread_id ) {
            return new WP_Error( 'db_error', 'Could not create thread (no insert id)', array( 'status' => 500 ) );
        }

        if ( $message || $attachment_url ) {
            $wpdb->insert( "{$p}bd_internal_messages_v2", array(
                'thread_id'       => $thread_id,
                'sender_id'       => $uid,
                'message'         => $message,
                'attachment_id'   => $attachment_id,
                'attachment_url'  => $attachment_url,
                'attachment_name' => $attachment_name,
                'attachment_type' => $attachment_type,
                'attachment_size' => $attachment_size,
            ) );
        }

        $wpdb->replace( "{$p}bd_internal_participants", array(
            'thread_id'    => $thread_id,
            'user_id'      => $uid,
            'role'         => $level,
            'last_read_at' => current_time( 'mysql' ),
        ) );

        if ( $channel === self::CHANNEL_TICKETS ) {
            $this->notify_supervisors( $thread_id, $title, $level );
        }

        return rest_ensure_response( array(
            'success'   => true,
            'thread_id' => $thread_id,
        ) );
    }

    /* ============================================================
     *  POST MESSAGE
     * ============================================================ */

    public function post_message( WP_REST_Request $r ) {
        if ( ! self::tables_exist() ) {
            return new WP_Error( 'missing_tables', 'Tables missing.', array( 'status' => 500 ) );
        }

        global $wpdb;
        $p       = $wpdb->prefix;
        $uid     = get_current_user_id();
        $level   = self::current_level();
        $id      = (int) $r['id'];

        $thread = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}bd_internal_threads WHERE id = %d", $id ) );
        if ( ! $thread ) {
            return new WP_Error( 'not_found', 'Thread not found', array( 'status' => 404 ) );
        }
        if ( ! self::can_view_thread( $thread, $uid, $level ) ) {
            return new WP_Error( 'forbidden', 'Cannot post here', array( 'status' => 403 ) );
        }

        if ( $thread->channel === self::CHANNEL_UPDATES && $level === 'agent' ) {
            return new WP_Error( 'forbidden', 'Agents cannot reply to company updates.', array( 'status' => 403 ) );
        }
        if ( $thread->channel === self::CHANNEL_TICKETS ) {
            if ( $level === 'agent' && (int) $thread->created_by !== (int) $uid ) {
                return new WP_Error( 'forbidden', 'Not your ticket', array( 'status' => 403 ) );
            }
        }

        $message         = wp_kses_post( $r->get_param( 'message' ) ?: '' );
        $attachment_id   = (int) $r->get_param( 'attachment_id' );
        $attachment_url  = esc_url_raw( $r->get_param( 'attachment_url' ) ?: '' );
        $attachment_name = sanitize_text_field( $r->get_param( 'attachment_name' ) ?: '' );
        $attachment_type = sanitize_text_field( $r->get_param( 'attachment_type' ) ?: '' );
        $attachment_size = (int) $r->get_param( 'attachment_size' );

        if ( ! $message && ! $attachment_url ) {
            return new WP_Error( 'empty', 'Message or attachment required', array( 'status' => 400 ) );
        }

        $wpdb->insert( "{$p}bd_internal_messages_v2", array(
            'thread_id'       => $id,
            'sender_id'       => $uid,
            'message'         => $message,
            'attachment_id'   => $attachment_id,
            'attachment_url'  => $attachment_url,
            'attachment_name' => $attachment_name,
            'attachment_type' => $attachment_type,
            'attachment_size' => $attachment_size,
        ) );
        $msg_id = (int) $wpdb->insert_id;

        $wpdb->update( "{$p}bd_internal_threads",
            array( 'updated_at' => current_time( 'mysql' ) ),
            array( 'id' => $id )
        );

        $this->notify_thread_participants( $thread, $uid, $level );

        return rest_ensure_response( array(
            'success'         => true,
            'id'              => $msg_id,
            'time'            => current_time( 'mysql' ),
            'attachment_url'  => $attachment_url,
            'attachment_name' => $attachment_name,
            'attachment_type' => $attachment_type,
            'attachment_size' => $attachment_size,
        ) );
    }

    /* ============================================================
     *  CHANGE STATUS
     * ============================================================ */

    public function set_status( WP_REST_Request $r ) {
        if ( ! self::tables_exist() ) {
            return new WP_Error( 'missing_tables', 'Tables missing.', array( 'status' => 500 ) );
        }

        global $wpdb;
        $p      = $wpdb->prefix;
        $uid    = get_current_user_id();
        $level  = self::current_level();
        $id     = (int) $r['id'];
        $status = sanitize_key( $r->get_param( 'status' ) ?: 'closed' );

        if ( ! in_array( $status, array( 'open', 'closed' ), true ) ) {
            return new WP_Error( 'bad_status', 'Invalid status', array( 'status' => 400 ) );
        }

        $thread = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}bd_internal_threads WHERE id = %d", $id ) );
        if ( ! $thread ) {
            return new WP_Error( 'not_found', 'Thread not found', array( 'status' => 404 ) );
        }

        $can = ( $level === 'admin' || $level === 'team_lead' )
            || ( (int) $thread->created_by === (int) $uid );
        if ( ! $can ) {
            return new WP_Error( 'forbidden', 'Not allowed', array( 'status' => 403 ) );
        }

        $wpdb->update( "{$p}bd_internal_threads",
            array( 'status' => $status, 'updated_at' => current_time( 'mysql' ) ),
            array( 'id' => $id )
        );

        $wpdb->insert( "{$p}bd_internal_messages_v2", array(
            'thread_id' => $id,
            'sender_id' => $uid,
            'message'   => $status === 'closed' ? 'Thread marked as resolved.' : 'Thread reopened.',
            'is_system' => 1,
        ) );

        return rest_ensure_response( array( 'success' => true ) );
    }

    /* ============================================================
     *  POLL
     * ============================================================ */

    public function poll_thread( WP_REST_Request $r ) {
        if ( ! self::tables_exist() ) {
            return new WP_Error( 'missing_tables', 'Tables missing.', array( 'status' => 500 ) );
        }

        global $wpdb;
        $p     = $wpdb->prefix;
        $id    = (int) $r['id'];
        $after = (int) $r->get_param( 'after' );

        $messages = $wpdb->get_results( $wpdb->prepare( "
            SELECT m.*, u.display_name AS sender_name
            FROM {$p}bd_internal_messages_v2 m
            LEFT JOIN {$wpdb->users} u ON u.ID = m.sender_id
            WHERE m.thread_id = %d AND m.id > %d
            ORDER BY m.id ASC
            LIMIT 50
        ", $id, $after ) );

        foreach ( $messages as $m ) {
            $m->sender_role = self::user_level_label( $m->sender_id );
        }

        return rest_ensure_response( array( 'messages' => $messages ) );
    }

    /* ============================================================
     *  UNREAD
     * ============================================================ */

    public function get_unread() {
        if ( ! self::tables_exist() ) {
            return rest_ensure_response( array( 'updates' => 0, 'tickets' => 0, 'total' => 0 ) );
        }

        global $wpdb;
        $p     = $wpdb->prefix;
        $uid   = get_current_user_id();
        $level = self::current_level();

        $updates_unread = (int) $wpdb->get_var( $wpdb->prepare( "
            SELECT COUNT(*) FROM {$p}bd_internal_threads t
            LEFT JOIN {$p}bd_internal_participants pp
                ON pp.thread_id = t.id AND pp.user_id = %d
            WHERE t.channel = 'updates'
              AND t.updated_at > COALESCE(pp.last_read_at, '1970-01-01')
        ", $uid ) );

        if ( $level === 'agent' ) {
            $tickets_unread = (int) $wpdb->get_var( $wpdb->prepare( "
                SELECT COUNT(*) FROM {$p}bd_internal_threads t
                LEFT JOIN {$p}bd_internal_participants pp
                    ON pp.thread_id = t.id AND pp.user_id = %d
                WHERE t.channel = 'tickets'
                  AND t.created_by = %d
                  AND t.updated_at > COALESCE(pp.last_read_at, '1970-01-01')
            ", $uid, $uid ) );
        } else {
            $tickets_unread = (int) $wpdb->get_var( $wpdb->prepare( "
                SELECT COUNT(*) FROM {$p}bd_internal_threads t
                LEFT JOIN {$p}bd_internal_participants pp
                    ON pp.thread_id = t.id AND pp.user_id = %d
                WHERE t.channel = 'tickets'
                  AND t.status = 'open'
                  AND t.updated_at > COALESCE(pp.last_read_at, '1970-01-01')
            ", $uid ) );
        }

        return rest_ensure_response( array(
            'updates' => $updates_unread,
            'tickets' => $tickets_unread,
            'total'   => $updates_unread + $tickets_unread,
        ) );
    }

    /* ============================================================
     *  NOTIFICATIONS
     * ============================================================ */

    private function notify_supervisors( $thread_id, $title, $creator_level ) {
        if ( ! class_exists( 'BD_Notifications' ) ) return;

        $supervisors = get_users( array( 'role__in' => array( 'bd_team_lead', 'administrator' ) ) );

        foreach ( $supervisors as $u ) {
            BD_Notifications::notify(
                $u->ID,
                'internal_ticket',
                'New support ticket: ' . $title,
                'A ' . $creator_level . ' has opened a new ticket.',
                $thread_id
            );
        }
    }

    private function notify_thread_participants( $thread, $sender_id, $sender_level ) {
        if ( ! class_exists( 'BD_Notifications' ) ) return;

        if ( (int) $thread->created_by !== (int) $sender_id
             && in_array( $sender_level, array( 'team_lead', 'admin' ), true ) ) {

            BD_Notifications::notify(
                $thread->created_by,
                'internal_reply',
                'Reply on "' . $thread->title . '"',
                'A ' . $sender_level . ' replied to your thread.',
                $thread->id
            );
        }
    }

    /* ============================================================
     *  MEDIA UPLOAD
     * ============================================================ */

    public function handle_media_upload() {
        // Log for diagnostics (remove after fixing).
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[BD Upload] handle_media_upload called' );
            error_log( '[BD Upload] $_POST keys: ' . implode( ',', array_keys( (array) $_POST ) ) );
            error_log( '[BD Upload] $_FILES keys: ' . implode( ',', array_keys( (array) $_FILES ) ) );
        }

        // 1. Auth check.
        if ( ! is_user_logged_in() || ! BD_Roles::is_agent() ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
        }

        // 2. Nonce check.
        if ( ! isset( $_POST['_wpnonce'] ) ) {
            wp_send_json_error( array( 'message' => 'Missing security token' ), 400 );
        }

        if ( ! wp_verify_nonce( $_POST['_wpnonce'], 'bd_upload_media' ) ) {
            wp_send_json_error( array( 'message' => 'Invalid security token. Please refresh and try again.' ), 403 );
        }

        // 3. File present?
        if ( empty( $_FILES['file'] ) ) {
            wp_send_json_error( array( 'message' => 'No file uploaded' ), 400 );
        }

        $file = $_FILES['file'];

        // 4. Upload error from PHP?
        if ( ! empty( $file['error'] ) ) {
            $err_map = array(
                UPLOAD_ERR_INI_SIZE   => 'File exceeds upload_max_filesize',
                UPLOAD_ERR_FORM_SIZE  => 'File exceeds MAX_FILE_SIZE',
                UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded',
                UPLOAD_ERR_NO_FILE    => 'No file was uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the file upload',
            );
            $msg = isset( $err_map[ $file['error'] ] )
                ? $err_map[ $file['error'] ]
                : 'Upload error code: ' . $file['error'];
            wp_send_json_error( array( 'message' => $msg ), 400 );
        }

        // 5. Whitelist.
        $allowed = array(
            'image/jpeg', 'image/png', 'image/gif', 'image/webp',
            'application/pdf',
            'video/mp4', 'video/webm', 'video/quicktime',
            'audio/mpeg', 'audio/wav', 'audio/ogg', 'audio/mp4',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/plain',
            'application/zip',
        );

        $file_type = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );

        // Some servers return false for `type` on non-standard files.
        if ( empty( $file_type['type'] ) ) {
            $fallback = wp_check_filetype( $file['name'] );
            $file_type['type'] = $fallback['type'] ?: '';
        }

        if ( empty( $file_type['type'] ) || ! in_array( $file_type['type'], $allowed, true ) ) {
            wp_send_json_error( array( 'message' => 'File type not allowed (' . esc_html( $file_type['type'] ) . ')' ), 400 );
        }

        // 6. Size check (25 MB max).
        if ( $file['size'] > 25 * 1024 * 1024 ) {
            wp_send_json_error( array( 'message' => 'File too large (max 25 MB)' ), 400 );
        }

        // 7. Handle upload.
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $upload = wp_handle_upload( $file, array( 'test_form' => false ) );

        if ( ! empty( $upload['error'] ) ) {
            wp_send_json_error( array( 'message' => $upload['error'] ), 400 );
        }

        // 8. Attach to media library.
        $attachment = array(
            'post_mime_type' => $upload['type'],
            'post_title'     => sanitize_file_name( basename( $upload['file'] ) ),
            'post_content'   => '',
            'post_status'    => 'inherit',
        );

        $attach_id = wp_insert_attachment( $attachment, $upload['file'] );
        if ( is_wp_error( $attach_id ) ) {
            wp_send_json_error( array( 'message' => 'Could not save attachment' ), 500 );
        }

        wp_update_attachment_metadata(
            $attach_id,
            wp_generate_attachment_metadata( $attach_id, $upload['file'] )
        );

        // 9. Success.
        wp_send_json_success( array(
            'attachment_id'   => $attach_id,
            'attachment_url'  => $upload['url'],
            'attachment_name' => basename( $upload['file'] ),
            'attachment_type' => $upload['type'],
            'attachment_size' => $file['size'],
        ) );
    }

    /* ============================================================
     *  CLEANUP (cron)
     * ============================================================ */

    public static function cleanup_old_threads() {
        if ( ! self::tables_exist() ) return;

        global $wpdb;
        $p = $wpdb->prefix;

        $ids = $wpdb->get_col(
            "SELECT id FROM {$p}bd_internal_threads
             WHERE status = 'closed'
             AND updated_at < DATE_SUB(NOW(), INTERVAL 365 DAY)"
        );

        if ( empty( $ids ) ) return;

        $ids_sql = implode( ',', array_map( 'intval', $ids ) );
        $wpdb->query( "DELETE FROM {$p}bd_internal_messages_v2 WHERE thread_id IN ({$ids_sql})" );
        $wpdb->query( "DELETE FROM {$p}bd_internal_participants WHERE thread_id IN ({$ids_sql})" );
        $wpdb->query( "DELETE FROM {$p}bd_internal_threads WHERE id IN ({$ids_sql})" );
    }
}