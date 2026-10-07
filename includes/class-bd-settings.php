<?php
/**
 * Plugin settings — stored in wp_options under 'bd_settings'.
 *
 * @package BigDrop
 */

defined( 'ABSPATH' ) || exit;

class BD_Settings {

    const OPTION_KEY = 'bd_settings';

    /**
     * Default settings.
     */
    public static function defaults() {
        return array(
            // Widget (visitor-facing chat)
            'widget_enabled'         => 1,
            'widget_position'        => 'bottom-right',   // bottom-right | bottom-left
            'widget_greeting'        => 'Hi! 👋 How can we help you today?',
            'widget_title'           => 'Big Drop Support',
            'widget_color'           => '#7FD344',
            'widget_offline_message' => 'We are offline right now. Leave your number and we will get back to you.',

            // Chat behavior
            'poll_interval_chat'     => 3,    // seconds (agent chat poll)
            'poll_interval_notify'   => 8,    // seconds (notification poll)
            'escalate_after_minutes' => 3,    // priority escalation
            'auto_assign'            => 1,    // auto-assign chats on first reply

            // Notifications
            'sound_enabled'          => 1,
            'push_enabled'           => 1,
            'desktop_notifications'  => 1,

            // Branding
            'brand_name'             => 'Big Drop',
            'brand_color'            => '#7FD344',
            'brand_navy'             => '#1F0D5E',

            // Retention
            'retention_days'         => 90,
            'notification_retention' => 30,
        );
    }

    public function __construct() {
        add_action( 'admin_init', array( $this, 'register_settings' ) );
    }

    /**
     * Register the settings in WordPress.
     */
    public function register_settings() {
        register_setting( 'bd_settings_group', self::OPTION_KEY, array(
            'type'              => 'array',
            'sanitize_callback' => array( $this, 'sanitize' ),
            'default'           => self::defaults(),
        ) );
    }

    /**
     * Get a specific setting.
     */
    public static function get( $key, $default = null ) {
        $settings = self::all();
        if ( array_key_exists( $key, $settings ) ) {
            return $settings[ $key ];
        }
        return null !== $default ? $default : null;
    }

    /**
     * Get all settings merged with defaults.
     */
    public static function all() {
        $saved    = get_option( self::OPTION_KEY, array() );
        if ( ! is_array( $saved ) ) {
            $saved = array();
        }
        return wp_parse_args( $saved, self::defaults() );
    }

    /**
     * Update one key.
     */
    public static function set( $key, $value ) {
        $all         = self::all();
        $all[ $key ] = $value;
        update_option( self::OPTION_KEY, $all );
    }

    /**
     * Update many keys at once.
     */
    public static function update( $new ) {
        $all = wp_parse_args( $new, self::all() );
        update_option( self::OPTION_KEY, $all );
        return $all;
    }

    /**
     * Sanitize the entire settings array before saving.
     */
    public function sanitize( $input ) {
        $input = is_array( $input ) ? $input : array();
        $clean = self::defaults();

        // Booleans.
        foreach ( array( 'widget_enabled', 'auto_assign', 'sound_enabled', 'push_enabled', 'desktop_notifications' ) as $k ) {
            $clean[ $k ] = ! empty( $input[ $k ] ) ? 1 : 0;
        }

        // Text fields.
        foreach ( array( 'widget_greeting', 'widget_title', 'widget_offline_message', 'brand_name' ) as $k ) {
            if ( isset( $input[ $k ] ) ) {
                $clean[ $k ] = sanitize_textarea_field( $input[ $k ] );
            }
        }

        // Select fields.
        if ( isset( $input['widget_position'] ) && in_array( $input['widget_position'], array( 'bottom-right', 'bottom-left' ), true ) ) {
            $clean['widget_position'] = $input['widget_position'];
        }

        // Colors.
        foreach ( array( 'widget_color', 'brand_color', 'brand_navy' ) as $k ) {
            if ( isset( $input[ $k ] ) ) {
                $color = sanitize_hex_color( $input[ $k ] );
                if ( $color ) {
                    $clean[ $k ] = $color;
                }
            }
        }

        // Integers.
        $int_fields = array(
            'poll_interval_chat'     => array( 2, 60 ),
            'poll_interval_notify'   => array( 3, 120 ),
            'escalate_after_minutes' => array( 1, 60 ),
            'retention_days'         => array( 7, 730 ),
            'notification_retention' => array( 3, 180 ),
        );

        foreach ( $int_fields as $k => $range ) {
            if ( isset( $input[ $k ] ) ) {
                $v = (int) $input[ $k ];
                $v = max( $range[0], min( $range[1], $v ) );
                $clean[ $k ] = $v;
            }
        }

        return $clean;
    }

    /**
     * Export settings as an array (for API / debug).
     */
    public static function export() {
        return self::all();
    }
}