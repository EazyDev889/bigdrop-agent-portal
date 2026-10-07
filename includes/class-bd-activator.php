<?php
/**
 * Activation, deactivation, and database schema setup.
 *
 * @package BigDrop
 */

defined( 'ABSPATH' ) || exit;

class BD_Activator {

    public static function activate() {
        self::create_tables();
        self::create_roles();
        self::create_portal_page();
        self::generate_vapid_keys();
        self::seed_default_canned_replies();
        self::schedule_cron();
        update_option( 'bd_version', BD_VERSION );
        flush_rewrite_rules();
    }

    public static function deactivate() {
        wp_clear_scheduled_hook( 'bd_escalate_chats' );
        wp_clear_scheduled_hook( 'bd_cleanup_old' );
        wp_clear_scheduled_hook( 'bd_archive_chats' );
        flush_rewrite_rules();
    }

    private static function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $p       = $wpdb->prefix;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // --- Chats (conversations) ---
        dbDelta( "CREATE TABLE {$p}bd_chats (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            visitor_id VARCHAR(64) NOT NULL,
            visitor_name VARCHAR(100) DEFAULT '',
            visitor_email VARCHAR(150) DEFAULT '',
            visitor_phone VARCHAR(30) DEFAULT '',
            assigned_agent_id BIGINT UNSIGNED DEFAULT 0,
            status VARCHAR(20) DEFAULT 'new',
            priority TINYINT DEFAULT 1,
            first_response_at DATETIME NULL,
            resolved_at DATETIME NULL,
            resolution_time_seconds INT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_status (status),
            INDEX idx_agent (assigned_agent_id),
            INDEX idx_priority (priority, created_at)
        ) {$charset};" );

        // --- Messages ---
        dbDelta( "CREATE TABLE {$p}bd_messages (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            chat_id BIGINT UNSIGNED NOT NULL,
            sender_type VARCHAR(20) NOT NULL,
            sender_id BIGINT UNSIGNED DEFAULT 0,
            message LONGTEXT NOT NULL,
            is_read TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_chat (chat_id, created_at),
            INDEX idx_unread (chat_id, is_read, sender_type)
        ) {$charset};" );

        // --- Archive Tables (For Performance) ---
        dbDelta( "CREATE TABLE {$p}bd_chats_archive (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            visitor_id VARCHAR(64) NOT NULL,
            visitor_name VARCHAR(100) DEFAULT '',
            visitor_email VARCHAR(150) DEFAULT '',
            visitor_phone VARCHAR(30) DEFAULT '',
            assigned_agent_id BIGINT UNSIGNED DEFAULT 0,
            status VARCHAR(20) DEFAULT 'resolved',
            resolution_time_seconds INT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            resolved_at DATETIME NULL,
            INDEX idx_date (created_at)
        ) {$charset};" );

        dbDelta( "CREATE TABLE {$p}bd_messages_archive (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            chat_id BIGINT UNSIGNED NOT NULL,
            sender_type VARCHAR(20) NOT NULL,
            sender_id BIGINT UNSIGNED DEFAULT 0,
            message LONGTEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_chat (chat_id)
        ) {$charset};" );

        // --- Internal team messages ---
        dbDelta( "CREATE TABLE {$p}bd_internal_messages (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            sender_id BIGINT UNSIGNED NOT NULL,
            message TEXT NOT NULL,
            mention_user_id BIGINT UNSIGNED DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_created (created_at)
        ) {$charset};" );

        // --- Canned replies ---
        dbDelta( "CREATE TABLE {$p}bd_canned_replies (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            header VARCHAR(200) NOT NULL,
            message LONGTEXT NOT NULL,
            attachment_url VARCHAR(500) DEFAULT '',
            created_by BIGINT UNSIGNED DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) {$charset};" );

        // --- Notifications ---
        dbDelta( "CREATE TABLE {$p}bd_notifications (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            type VARCHAR(50) NOT NULL,
            reference_id BIGINT UNSIGNED DEFAULT 0,
            title VARCHAR(200) DEFAULT '',
            body TEXT,
            is_read TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_unread (user_id, is_read)
        ) {$charset};" );

        // --- Push subscriptions ---
        dbDelta( "CREATE TABLE {$p}bd_push_subscriptions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            endpoint TEXT NOT NULL,
            p256dh VARCHAR(255) NOT NULL,
            auth VARCHAR(255) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user (user_id)
        ) {$charset};" );

        // --- Daily stats ---
        dbDelta( "CREATE TABLE {$p}bd_daily_stats (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            agent_id BIGINT UNSIGNED NOT NULL,
            stat_date DATE NOT NULL,
            chats_taken INT DEFAULT 0,
            chats_resolved INT DEFAULT 0,
            chats_unresolved INT DEFAULT 0,
            resolved_under_3min INT DEFAULT 0,
            avg_response_seconds INT DEFAULT 0,
            UNIQUE KEY uniq_agent_date (agent_id, stat_date)
        ) {$charset};" );

        // --- Client accounts ---
        dbDelta( "CREATE TABLE {$p}bd_clients (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            account_number VARCHAR(50) UNIQUE NOT NULL,
            full_name VARCHAR(200) DEFAULT '',
            phone VARCHAR(30) DEFAULT '',
            id_number VARCHAR(50) DEFAULT '',
            kyc_status VARCHAR(30) DEFAULT '',
            account_status VARCHAR(30) DEFAULT '',
            plan_selected VARCHAR(100) DEFAULT '',
            payment_status VARCHAR(30) DEFAULT '',
            meta LONGTEXT,
            last_synced DATETIME DEFAULT CURRENT_TIMESTAMP
        ) {$charset};" );
        
        if ( ! class_exists( 'BD_Internal_Chat' ) ) {
            require_once BD_PATH . 'includes/class-bd-internal-chat.php';
        }
        BD_Internal_Chat::create_tables();
    }

    private static function create_roles() {
        add_role( 'bd_agent', 'Big Drop Agent', array(
            'read'             => true,
            'bd_access_portal' => true,
            'bd_take_chats'    => true,
        ) );

        add_role( 'bd_team_lead', 'Big Drop Team Lead', array(
            'read'               => true,
            'bd_access_portal'   => true,
            'bd_take_chats'      => true,
            'bd_view_all_agents' => true,
            'bd_manage_canned'   => true,
            'bd_view_clients'    => true,
        ) );

        $admin = get_role( 'administrator' );
        if ( $admin ) {
            $caps = array( 'bd_access_portal', 'bd_take_chats', 'bd_view_all_agents', 'bd_manage_canned', 'bd_view_clients' );
            foreach ( $caps as $cap ) {
                $admin->add_cap( $cap );
            }
        }
    }

    private static function create_portal_page() {
        $existing = get_option( 'bd_portal_page_id' );
        if ( $existing && get_post( $existing ) ) {
            return;
        }

        $page_id = wp_insert_post( array(
            'post_title'   => 'Agent Portal',
            'post_content' => '[bigdrop_portal]',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_author'  => get_current_user_id() ?: 1,
        ) );

        if ( $page_id && ! is_wp_error( $page_id ) ) {
            update_option( 'bd_portal_page_id', $page_id );
        }
    }

    private static function generate_vapid_keys() {
        if ( get_option( 'bd_vapid_public_key' ) && get_option( 'bd_vapid_private_key' ) ) {
            return;
        }

        if ( ! function_exists( 'openssl_pkey_new' ) ) {
            return;
        }

        $config = array(
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        );

        $key = openssl_pkey_new( $config );
        if ( ! $key ) return;

        $details = openssl_pkey_get_details( $key );
        if ( empty( $details['ec']['x'] ) || empty( $details['ec']['y'] ) ) return;

        $public_raw = "\x04" . $details['ec']['x'] . $details['ec']['y'];
        openssl_pkey_export( $key, $private_pem );

        update_option( 'bd_vapid_public_key',  self::base64url_encode( $public_raw ) );
        update_option( 'bd_vapid_private_key', self::base64url_encode( $private_pem ) );
    }

    private static function base64url_encode( $data ) {
        return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
    }

    private static function seed_default_canned_replies() {
        global $wpdb;
        $p     = $wpdb->prefix;
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}bd_canned_replies" );
        if ( $count > 0 ) return;

        $defaults = array(
            array( 'header' => 'Standard Greeting', 'message' => "Hello! Welcome to Big Drop. How can I help you today?" ),
            array( 'header' => 'How to register', 'message' => "To register, open the Big Drop app, tap 'Sign Up', enter your phone number, and follow the OTP verification." ),
            array( 'header' => 'Installation timing', 'message' => "Installation is typically scheduled within 24–48 hours after you complete registration." ),
            array( 'header' => 'Closing a chat', 'message' => "Thanks for chatting with Big Drop. Have a great day!" ),
        );

        foreach ( $defaults as $row ) {
            $wpdb->insert( "{$p}bd_canned_replies", array(
                'header'     => $row['header'],
                'message'    => $row['message'],
                'created_by' => get_current_user_id() ?: 0,
            ) );
        }
    }

    private static function schedule_cron() {
        if ( ! wp_next_scheduled( 'bd_escalate_chats' ) ) {
            wp_schedule_event( time(), 'bd_every_minute', 'bd_escalate_chats' );
        }
        if ( ! wp_next_scheduled( 'bd_cleanup_old' ) ) {
            wp_schedule_event( time(), 'daily', 'bd_cleanup_old' );
        }
        if ( ! wp_next_scheduled( 'bd_archive_chats' ) ) {
            wp_schedule_event( time(), 'monthly', 'bd_archive_chats' );
        }
    }
}