<?php
/**
 * Role and capability helpers.
 *
 * @package BigDrop
 */

defined( 'ABSPATH' ) || exit;

class BD_Roles {

    /**
     * Constructor — adds capability filters and user status tracking.
     */
    public function __construct() {
        add_action( 'wp_login',  array( $this, 'mark_online' ), 10, 2 );
        add_action( 'wp_logout', array( $this, 'mark_offline' ) );
        add_action( 'admin_init', array( $this, 'mark_online_on_admin' ) );

        // Ensure roles exist
        add_action( 'init', array( $this, 'ensure_roles_exist' ) );
        
        // Handle standard login redirect (for Admins and Team Leads)
        add_filter( 'login_redirect', array( $this, 'redirect_to_portal_dashboard' ), 999, 3 );
        
        // ============================================================
        // CRITICAL FIX: Stop WooCommerce from blocking Team Lead access
        // ============================================================
        // This tells WooCommerce: "For Team Leads, don't prevent admin access."
        // This stops WooCommerce from redirecting them to /my-account/.
        add_filter( 'woocommerce_prevent_admin_access', array( $this, 'prevent_woocommerce_redirect' ), 10, 1 );
    }

    /**
     * Ensure the Team Lead role exists and has the correct capabilities.
     */
    public function ensure_roles_exist() {
        if ( ! get_role( 'bd_team_lead' ) ) {
            add_role(
                'bd_team_lead',
                __( 'Team Lead', 'bigdrop' ),
                array(
                    'read'              => true,
                    'bd_access_portal'  => true,
                    'bd_manage_canned'  => true,
                    'bd_view_clients'   => true,
                    'bd_view_all_agents'=> true,
                    'bd_manage_agents'  => true,
                )
            );
        }

        if ( ! get_role( 'bd_agent' ) ) {
            add_role(
                'bd_agent',
                __( 'Agent', 'bigdrop' ),
                array(
                    'read'              => true,
                    'bd_access_portal'  => true,
                )
            );
        }

        $admin = get_role( 'administrator' );
        if ( $admin ) {
            $admin->add_cap( 'bd_access_portal' );
            $admin->add_cap( 'bd_manage_canned' );
            $admin->add_cap( 'bd_view_clients' );
            $admin->add_cap( 'bd_view_all_agents' );
            $admin->add_cap( 'bd_manage_agents' );
        }
    }

    /**
     * Redirect Admins and Team Leads to the Big Drop Portal dashboard after standard login.
     */
    public function redirect_to_portal_dashboard( $redirect_to, $request, $user ) {
        if ( $user instanceof WP_User && isset( $user->roles ) && is_array( $user->roles ) ) {
            if ( in_array( 'administrator', $user->roles ) || in_array( 'bd_team_lead', $user->roles ) ) {
                return admin_url( 'admin.php?page=bd-portal' );
            }
        }
        return $redirect_to;
    }

    /**
     * Prevent WooCommerce from redirecting Team Leads to /my-account/.
     * 
     * WooCommerce uses this filter to decide if a user should be blocked from the admin area.
     * By returning false for Team Leads, we tell WooCommerce "Don't block them, and don't redirect them."
     */
    public function prevent_woocommerce_redirect( $prevent_access ) {
        // If the user is not logged in, don't change anything.
        if ( ! is_user_logged_in() ) {
            return $prevent_access;
        }
        
        $user = wp_get_current_user();
        
        // If the user is a Team Lead, tell WooCommerce not to redirect them.
        if ( in_array( 'bd_team_lead', (array) $user->roles ) ) {
            return false;
        }
        
        // For all other users, keep WooCommerce's default behavior.
        return $prevent_access;
    }

    /**
     * Is the current user an agent (or admin)?
     */
    public static function is_agent() {
        if ( ! is_user_logged_in() ) {
            return false;
        }
        return current_user_can( 'bd_access_portal' ) || current_user_can( 'manage_options' );
    }

    /**
     * Is the current user a full admin / team lead?
     */
    public static function is_admin() {
        return current_user_can( 'manage_options' ) || current_user_can( 'bd_manage_canned' );
    }

    /**
     * Can the current user view client accounts?
     */
    public static function can_view_clients() {
        return current_user_can( 'bd_view_clients' ) || current_user_can( 'manage_options' );
    }

    /**
     * Can the current user see all agents' performance?
     */
    public static function can_view_all_agents() {
        return current_user_can( 'bd_view_all_agents' ) || current_user_can( 'manage_options' );
    }

    /**
     * Can the current user manage agents?
     */
    public static function can_manage_agents() {
        return current_user_can( 'bd_manage_agents' ) || current_user_can( 'manage_options' );
    }

    /**
     * Permission callback: any logged-in Big Drop user.
     */
    public static function permission_any_agent() {
        return self::is_agent();
    }

    /**
     * Permission callback: admin-level only.
     */
    public static function permission_admin() {
        return self::is_admin();
    }

    /**
     * Permission callback: client-viewer.
     */
    public static function permission_client_viewer() {
        return self::can_view_clients();
    }

    /**
     * Mark user as online on login.
     */
    public function mark_online( $user_login, $user ) {
        if ( $user instanceof WP_User ) {
            update_user_meta( $user->ID, 'bd_status', 'online' );
            update_user_meta( $user->ID, 'bd_last_seen', time() );
        }
    }

    /**
     * Mark user as offline on logout.
     */
    public function mark_offline() {
        $user_id = get_current_user_id();
        if ( $user_id ) {
            update_user_meta( $user_id, 'bd_status', 'offline' );
            update_user_meta( $user_id, 'bd_last_seen', time() );
        }
    }

    /**
     * Refresh last_seen on admin activity (crude presence indicator).
     */
    public function mark_online_on_admin() {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return;
        }
        if ( ! current_user_can( 'bd_access_portal' ) ) {
            return;
        }
        $last = (int) get_user_meta( $user_id, 'bd_last_seen', true );
        if ( time() - $last > 120 ) {
            update_user_meta( $user_id, 'bd_last_seen', time() );
            update_user_meta( $user_id, 'bd_status', 'online' );
        }
    }

    /**
     * Return current user's display name for chat bubbles.
     */
    public static function current_agent_name() {
        $user = wp_get_current_user();
        if ( ! $user || ! $user->ID ) {
            return 'Agent';
        }
        return $user->display_name ?: $user->user_login;
    }

    /**
     * Return current user's initials (for avatar).
     */
    public static function current_agent_initials() {
        $name = self::current_agent_name();
        $parts = preg_split( '/\s+/', trim( $name ) );
        if ( count( $parts ) === 1 ) {
            return strtoupper( substr( $parts[0], 0, 2 ) );
        }
        return strtoupper( substr( $parts[0], 0, 1 ) . substr( end( $parts ), 0, 1 ) );
    }

    /**
     * Get all Big Drop agents (for roster, mentions, etc.).
     */
    public static function get_all_agents() {
        return get_users( array(
            'role__in' => array( 'bd_agent', 'bd_team_lead', 'administrator' ),
            'orderby'  => 'display_name',
            'order'    => 'ASC',
            'number'   => 200,
        ) );
    }

    /**
     * Get an agent's public status — online / taking / offline.
     */
    public static function get_agent_status( $user_id ) {
        $status    = get_user_meta( $user_id, 'bd_status', true ) ?: 'offline';
        $last_seen = (int) get_user_meta( $user_id, 'bd_last_seen', true );

        // If marked online but hasn't been active for 5 minutes, show offline.
        if ( 'online' === $status && $last_seen && ( time() - $last_seen > 300 ) ) {
            $status = 'offline';
        }

        // Check for active chats to upgrade to "taking".
        if ( 'online' === $status ) {
            global $wpdb;
            $p       = $wpdb->prefix;
            $active  = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}bd_chats WHERE assigned_agent_id = %d AND status = 'active'",
                $user_id
            ) );
            if ( $active > 0 ) {
                $status = 'taking';
            }
        }

        return $status;
    }
}