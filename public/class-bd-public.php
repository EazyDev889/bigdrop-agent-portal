<?php
/**
 * Public-facing functionality: shortcodes, widget, asset loading.
 *
 * @package BigDrop
 */

defined( 'ABSPATH' ) || exit;

class BD_Public {

    public function __construct() {
        // Shortcodes.
        add_shortcode( 'bigdrop_portal', array( $this, 'shortcode_portal' ) );
        add_shortcode( 'bigdrop_widget', array( $this, 'shortcode_widget' ) );

        // Frontend widget injection (auto-injects floating widget).
        add_action( 'wp_footer', array( $this, 'maybe_render_widget' ) );

        // Enqueue widget assets on the frontend when needed.
        add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_frontend_assets' ) );
    }

    /**
     * Conditionally enqueue assets on the frontend
     * (widget CSS/JS if the widget is enabled).
     */
    public function maybe_enqueue_frontend_assets() {
        if ( is_admin() ) {
            return;
        }

        $settings = BD_Settings::all();
        if ( empty( $settings['widget_enabled'] ) ) {
            return;
        }

        // Don't show the widget to logged-in agents on the portal page.
        if ( BD_Roles::is_agent() && is_page( get_option( 'bd_portal_page_id' ) ) ) {
            return;
        }

        $this->enqueue_widget_assets();
    }

    /**
     * Shortcode: [bigdrop_portal]
     * Renders the full agent portal (requires login + agent role).
     */
    public function shortcode_portal() {
        if ( ! is_user_logged_in() ) {
            return $this->login_prompt();
        }

        if ( ! BD_Roles::is_agent() ) {
            return '<div class="bd-notice bd-notice-error">' .
                esc_html__( 'You do not have permission to access the Agent Portal. Please contact your administrator.', 'bigdrop' ) .
                '</div>';
        }

        $this->enqueue_portal_assets();

        ob_start();
        include BD_PATH . 'public/views/portal.php';
        return ob_get_clean();
    }

    /**
     * Shortcode: [bigdrop_widget]
     * Renders the visitor chat widget inline.
     */
    public function shortcode_widget() {
        $this->enqueue_widget_assets();
        ob_start();
        include BD_PATH . 'public/views/widget.php';
        return ob_get_clean();
    }

    /**
     * Auto-inject the floating widget on the frontend.
     */
    public function maybe_render_widget() {
        if ( is_admin() ) {
            return;
        }

        if ( BD_Roles::is_agent() && is_page( get_option( 'bd_portal_page_id' ) ) ) {
            return;
        }

        $settings = BD_Settings::all();
        if ( empty( $settings['widget_enabled'] ) ) {
            return;
        }

        // Assets already enqueued in maybe_enqueue_frontend_assets().
        $widget_view = BD_PATH . 'public/views/widget.php';
        if ( file_exists( $widget_view ) ) {
            include $widget_view;
        }
    }

    /**
     * Enqueue assets for the agent portal SPA.
     */
    private function enqueue_portal_assets() {
        wp_enqueue_style(
            'bd-portal-css',
            BD_URL . 'assets/css/portal.css',
            array(),
            BD_VERSION
        );
        
        wp_enqueue_style(
            'bd-internal-chat-css',
            BD_URL . 'assets/css/internal-chat.css',
            array( 'bd-portal-css' ),
            BD_VERSION
        );

        wp_enqueue_script(
            'bd-portal-js',
            BD_URL . 'assets/js/portal.js',
            array( 'jquery' ),
            BD_VERSION,
            true
        );
        
        wp_enqueue_script(
            'bd-internal-chat-js',
            BD_URL . 'assets/js/internal-chat.js',
            array( 'bd-portal-js' ),
            BD_VERSION,
            true
        );

        wp_localize_script( 'bd-portal-js', 'BD', array(
            'restUrl'      => rest_url( 'bigdrop/v1/' ),
            'nonce'        => wp_create_nonce( 'wp_rest' ),
            'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
            'portalUrl'    => $this->portal_url(),
            'homeUrl'      => home_url( '/' ),
            'assetsUrl'    => BD_URL . 'assets/',
            'currentUser'  => array(
                'id'       => get_current_user_id(),
                'name'     => BD_Roles::current_agent_name(),
                'initials' => BD_Roles::current_agent_initials(),
                'role'     => self::current_role_label(),
            ),
            'settings'     => array(
                'pollChat'        => (int) BD_Settings::get( 'poll_interval_chat', 3 ),
                'pollNotify'      => (int) BD_Settings::get( 'poll_interval_notify', 8 ),
                'soundEnabled'    => (int) BD_Settings::get( 'sound_enabled', 1 ),
                'pushEnabled'     => (int) BD_Settings::get( 'push_enabled', 1 ),
                'desktopNotify'   => (int) BD_Settings::get( 'desktop_notifications', 1 ),
                'escalateMinutes' => (int) BD_Settings::get( 'escalate_after_minutes', 3 ),
            ),
            'isAdmin'      => BD_Roles::is_admin() ? 1 : 0,
            'canViewClients' => BD_Roles::can_view_clients() ? 1 : 0,
            'internalLevel' => current_user_can( 'manage_options' ) ? 'admin' : ( current_user_can( 'bd_manage_canned' ) ? 'team_lead' : 'agent' ),
            'internalUploadNonce' => wp_create_nonce( 'bd_upload_media' ),
            'i18n'         => array(
                'online'      => __( 'Online', 'bigdrop' ),
                'offline'     => __( 'Offline', 'bigdrop' ),
                'sending'     => __( 'Sending…', 'bigdrop' ),
                'noChats'     => __( 'No chats yet.', 'bigdrop' ),
                'typeReply'   => __( 'Type a reply…', 'bigdrop' ),
                'send'        => __( 'Send', 'bigdrop' ),
                'resolve'     => __( 'Resolve', 'bigdrop' ),
                'unresolve'   => __( 'Unresolve', 'bigdrop' ),
                'search'      => __( 'Search chats, clients…', 'bigdrop' ),
                'notifications' => __( 'Notifications', 'bigdrop' ),
                'markRead'    => __( 'Mark all read', 'bigdrop' ),
            ),
        ) );

        // PWA registration on portal pages.
        wp_enqueue_script(
            'bd-pwa-register',
            BD_URL . 'assets/js/pwa.js',
            array( 'bd-portal-js' ),
            BD_VERSION,
            true
        );

        wp_localize_script( 'bd-pwa-register', 'BD_PWA', array(
            'swUrl'    => home_url( '/sw.js' ),
            'scope'    => '/',
            'restUrl'  => rest_url( 'bigdrop/v1/' ),
            'nonce'    => wp_create_nonce( 'wp_rest' ),
            'vapidKey' => get_option( 'bd_vapid_public_key' ),
            'homeUrl'  => home_url( '/' ),
            'portalUrl'=> $this->portal_url(),
        ) );
    }

    /**
     * Enqueue assets for the visitor widget.
     */
    private function enqueue_widget_assets() {
        wp_enqueue_style(
            'bd-widget-css',
            BD_URL . 'assets/css/widget.css',
            array(),
            BD_VERSION
        );

        wp_enqueue_script(
            'bd-widget-js',
            BD_URL . 'assets/js/widget.js',
            array(),
            BD_VERSION,
            true
        );

        $settings = BD_Settings::all();

        wp_localize_script( 'bd-widget-js', 'BD_WIDGET', array(
            'restUrl'     => rest_url( 'bigdrop/v1/' ),
            'nonce'       => wp_create_nonce( 'wp_rest' ),
            'title'       => $settings['widget_title'],
            'greeting'    => $settings['widget_greeting'],
            'offlineMsg'  => $settings['widget_offline_message'],
            'position'    => $settings['widget_position'],
            'color'       => $settings['widget_color'],
            'pollSeconds' => max( 3, (int) $settings['poll_interval_chat'] ),
            'i18n'        => array(
                'placeholder' => __( 'Type your message…', 'bigdrop' ),
                'send'        => __( 'Send', 'bigdrop' ),
                'close'       => __( 'Close', 'bigdrop' ),
                'loading'     => __( 'Connecting…', 'bigdrop' ),
                'waiting'     => __( 'An agent will be with you shortly.', 'bigdrop' ),
            ),
        ) );
    }

    /**
     * Login prompt for anonymous users.
     */
    private function login_prompt() {
        $login_url = wp_login_url( $this->portal_url() );

        return sprintf(
            '<div class="bd-notice bd-notice-info" style="padding:24px;text-align:center;background:#fff;border-radius:20px;box-shadow:0 6px 24px rgba(31,13,94,.08);">
                <h3 style="color:#1F0D5E;margin:0 0 8px;">%1$s</h3>
                <p style="color:#6C6F8C;margin:0 0 16px;">%2$s</p>
                <a href="%3$s" style="display:inline-block;background:#7FD344;color:#1F0D5E;font-weight:700;padding:12px 28px;border-radius:14px;text-decoration:none;box-shadow:0 8px 20px rgba(127,211,68,.3);">%4$s</a>
            </div>',
            esc_html__( 'Agent Portal', 'bigdrop' ),
            esc_html__( 'Please sign in with your agent account to continue.', 'bigdrop' ),
            esc_url( $login_url ),
            esc_html__( 'Sign In', 'bigdrop' )
        );
    }

    /**
     * Get the portal page URL.
     */
    private function portal_url() {
        $page_id = get_option( 'bd_portal_page_id' );
        if ( $page_id && get_post( $page_id ) ) {
            return get_permalink( $page_id );
        }
        return admin_url( 'admin.php?page=bd-portal' );
    }

    /**
     * Human-readable role label for current user.
     */
    private static function current_role_label() {
        if ( current_user_can( 'manage_options' ) ) {
            return __( 'Administrator', 'bigdrop' );
        }
        if ( current_user_can( 'bd_manage_canned' ) ) {
            return __( 'Team Lead', 'bigdrop' );
        }
        return __( 'Agent', 'bigdrop' );
    }
}