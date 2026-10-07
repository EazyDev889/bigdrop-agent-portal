<?php
/**
 * Core plugin loader — initializes all modules.
 *
 * @package BigDrop
 */

defined( 'ABSPATH' ) || exit;

class BD_Core {

    /**
     * Singleton instance.
     *
     * @var BD_Core|null
     */
    private static $instance = null;

    /**
     * Get the singleton instance.
     *
     * @return BD_Core
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Initialize the plugin — load files and hook modules.
     */
    public function init() {
        $this->load_dependencies();
        $this->register_cron_schedules();
        $this->register_cron_handlers();
        $this->boot_modules();

        /**
         * Fires after Big Drop is fully loaded.
         */
        do_action( 'bigdrop_loaded' );
    }

    /**
     * Include all class files.
     */
    private function load_dependencies() {
        require_once BD_PATH . 'includes/class-bd-roles.php';
        require_once BD_PATH . 'includes/class-bd-rest.php';
        require_once BD_PATH . 'includes/class-bd-chat.php';
        require_once BD_PATH . 'includes/class-bd-notifications.php';
        require_once BD_PATH . 'includes/class-bd-webpush.php';
        require_once BD_PATH . 'includes/class-bd-pwa.php';
        require_once BD_PATH . 'includes/class-bd-clients.php';
        require_once BD_PATH . 'includes/class-bd-settings.php';
        require_once BD_PATH . 'includes/class-bd-internal-chat.php';
        require_once BD_PATH . 'admin/class-bd-admin.php';
        require_once BD_PATH . 'public/class-bd-public.php';
        require_once BD_PATH . 'public/class-bd-templates.php';
    }

    /**
     * Boot each module by instantiating it.
     */
    private function boot_modules() {
        new BD_Roles();
        new BD_REST();
        new BD_Chat();
        new BD_Notifications();
        new BD_WebPush();
        new BD_PWA();
        new BD_Clients();
        new BD_Settings();
        new BD_Internal_Chat();
        new BD_Public();
        new BD_Templates();

        if ( is_admin() ) {
            new BD_Admin();
        }
    }

    /**
     * Add custom cron intervals.
     */
    private function register_cron_schedules() {
        add_filter( 'cron_schedules', function ( $schedules ) {
            if ( ! isset( $schedules['bd_every_minute'] ) ) {
                $schedules['bd_every_minute'] = array(
                    'interval' => 60,
                    'display'  => __( 'Every Minute (Big Drop)', 'bigdrop' ),
                );
            }
            if ( ! isset( $schedules['bd_every_five_minutes'] ) ) {
                $schedules['bd_every_five_minutes'] = array(
                    'interval' => 300,
                    'display'  => __( 'Every 5 Minutes (Big Drop)', 'bigdrop' ),
                );
            }
            return $schedules;
        } );
    }

    /**
     * Hook cron job handlers.
     */
    private function register_cron_handlers() {
        add_action( 'bd_escalate_chats', array( 'BD_Chat', 'escalate_stale_chats' ) );
        add_action( 'bd_cleanup_old',    array( 'BD_Chat', 'cleanup_old_chats' ) );
        add_action( 'bd_internal_cleanup', array( 'BD_Internal_Chat', 'cleanup_old_threads' ) );
        add_action( 'bd_prune_notifications', array( 'BD_Notifications', 'prune_old' ) );
        add_action( 'bd_update_stats',   array( 'BD_Chat', 'update_daily_stats' ) );
    }
}