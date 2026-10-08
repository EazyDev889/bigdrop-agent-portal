<?php
/**
 * Plugin Name:       Big Drop Agent Portal
 * Plugin URI:        https://bigdrop.co.zw
 * Description:       Complete agent portal with live chat, notifications, PWA support and admin management. Hostinger-shared-hosting ready.
 * Version:           1.0.1
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            EazyLabz
 * Author URI:        https://eazylabz.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       eazylabz
 * Domain Path:       /languages
 */

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

// Plugin constants.
define( 'BD_VERSION',  '1.0.1' );
define( 'BD_FILE',     __FILE__ );
define( 'BD_PATH',     plugin_dir_path( __FILE__ ) );
define( 'BD_URL',      plugin_dir_url( __FILE__ ) );
define( 'BD_BASENAME', plugin_basename( __FILE__ ) );

// Load required base classes.
require_once BD_PATH . 'includes/class-bd-activator.php';
require_once BD_PATH . 'includes/class-bd-core.php';

// Activation / Deactivation hooks.
register_activation_hook( __FILE__,   array( 'BD_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'BD_Activator', 'deactivate' ) );

/**
 * Bootstrap the plugin once WordPress is ready.
 */
function bd_bootstrap() {
    BD_Core::instance()->init();
}
add_action( 'plugins_loaded', 'bd_bootstrap', 5 );

/**
 * Add "Settings" link on the Plugins page.
 */
add_filter( 'plugin_action_links_' . BD_BASENAME, function( $links ) {
    $settings_url = admin_url( 'admin.php?page=bd-settings' );
    $links[] = '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'bigdrop' ) . '</a>';
    return $links;
} );