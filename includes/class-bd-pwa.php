<?php
/**
 * PWA (Progressive Web App) support.
 * Serves /sw.js and /manifest.webmanifest.
 *
 * @package BigDrop
 */

defined( 'ABSPATH' ) || exit;

class BD_PWA {

    public function __construct() {
        add_action( 'init',               array( $this, 'add_rewrite_rules' ) );
        add_filter( 'query_vars',         array( $this, 'add_query_vars' ) );
        add_action( 'template_redirect',  array( $this, 'serve_files' ) );
        add_action( 'wp_head',            array( $this, 'inject_meta' ), 1 );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_register' ) );

        // Fallback: intercept very early, before WordPress can 404.
        add_action( 'init', array( $this, 'early_serve_files' ), 1 );
    }

    /* ============================================================
     *  REWRITE RULES
     * ============================================================ */

    public function add_rewrite_rules() {
        add_rewrite_rule( '^sw\.js$', 'index.php?bd_sw=1', 'top' );
        add_rewrite_rule( '^manifest\.webmanifest$', 'index.php?bd_manifest=1', 'top' );
    }

    public function add_query_vars( $vars ) {
        $vars[] = 'bd_sw';
        $vars[] = 'bd_manifest';
        return $vars;
    }

    /* ============================================================
     *  EARLY INTERCEPT (bypasses rewrite rules entirely)
     * ============================================================ */

    public function early_serve_files() {
        if ( is_admin() ) return;

        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
        $path = parse_url( $request_uri, PHP_URL_PATH );
        $path = trim( (string) $path, '/' );

        // Note: path will be 'sw.js' or 'manifest.webmanifest' at domain root.
        if ( 'sw.js' === $path ) {
            $this->serve_service_worker();
            exit;
        }

        if ( 'manifest.webmanifest' === $path ) {
            $this->serve_manifest();
            exit;
        }
    }

    /* ============================================================
     *  TEMPLATE REDIRECT (fallback if early intercept misses)
     * ============================================================ */

    public function serve_files() {
        if ( get_query_var( 'bd_sw' ) ) {
            $this->serve_service_worker();
        }

        if ( get_query_var( 'bd_manifest' ) ) {
            $this->serve_manifest();
        }
    }

    /* ============================================================
     *  SERVE SERVICE WORKER
     * ============================================================ */

    private function serve_service_worker() {
        $file = BD_PATH . 'assets/js/sw.js';

        header( 'Content-Type: application/javascript; charset=utf-8' );
        header( 'Service-Worker-Allowed: /' );
        header( 'Cache-Control: public, max-age=3600' );
        header( 'X-Content-Type-Options: nosniff' );

        if ( file_exists( $file ) ) {
            $content = file_get_contents( $file );
            $content = str_replace(
                array( '{{BD_VERSION}}', '{{BD_URL}}', '{{HOME_URL}}' ),
                array( BD_VERSION, BD_URL, home_url( '/' ) ),
                $content
            );
            echo $content; // phpcs:ignore
        } else {
            // Minimal fallback so service worker registration doesn't fail.
            echo "/* Big Drop fallback SW */\n";
            echo "self.addEventListener('install', function(e){ self.skipWaiting(); });\n";
            echo "self.addEventListener('activate', function(e){ self.clients.claim(); });\n";
            echo "self.addEventListener('fetch', function(e){ /* passthrough */ });\n";
        }

        exit;
    }

    /* ============================================================
     *  SERVE MANIFEST
     * ============================================================ */

    private function serve_manifest() {
        $file = BD_PATH . 'manifest.json';

        header( 'Content-Type: application/manifest+json; charset=utf-8' );
        header( 'Cache-Control: public, max-age=3600' );

        if ( file_exists( $file ) ) {
            $content = file_get_contents( $file );
            $content = str_replace(
                array( '{{BD_URL}}', '{{HOME_URL}}', '{{PORTAL_URL}}' ),
                array( BD_URL, home_url( '/' ), $this->portal_url() ),
                $content
            );
            echo $content; // phpcs:ignore
        } else {
            // Minimal manifest fallback.
            $manifest = array(
                'name'             => 'Big Drop Agent Portal',
                'short_name'       => 'BigDrop',
                'start_url'        => $this->portal_url(),
                'scope'            => '/',
                'display'          => 'standalone',
                'background_color' => '#1F0D5E',
                'theme_color'      => '#7FD344',
                'icons'            => array(),
            );
            echo wp_json_encode( $manifest );
        }

        exit;
    }

    /* ============================================================
     *  HELPERS
     * ============================================================ */

    private function portal_url() {
        $page_id = get_option( 'bd_portal_page_id' );
        if ( $page_id && get_post( $page_id ) ) {
            return get_permalink( $page_id );
        }
        return admin_url( 'admin.php?page=bd-portal' );
    }

    private function is_portal_context() {
        if ( isset( $_GET['page'] ) && strpos( sanitize_key( $_GET['page'] ), 'bd-' ) === 0 ) {
            return true;
        }

        $page_id = get_option( 'bd_portal_page_id' );
        if ( $page_id && is_page( $page_id ) ) {
            return true;
        }

        if ( is_singular() ) {
            $post = get_post();
            if ( $post && has_shortcode( $post->post_content, 'bigdrop_portal' ) ) {
                return true;
            }
        }

        return false;
    }

    /* ============================================================
     *  HEAD META + SCRIPT ENQUEUE
     * ============================================================ */

    public function inject_meta() {
        if ( ! is_user_logged_in() ) return;
        if ( ! $this->is_portal_context() ) return;

        $icon_192 = BD_URL . 'assets/icons/icon-192.png';
        $icon_512 = BD_URL . 'assets/icons/icon-512.png';

        echo '<link rel="manifest" href="' . esc_url( home_url( '/manifest.webmanifest' ) ) . '">' . "\n";
        echo '<meta name="theme-color" content="#7FD344">' . "\n";
        echo '<meta name="mobile-web-app-capable" content="yes">' . "\n";
        echo '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
        echo '<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">' . "\n";
        echo '<meta name="apple-mobile-web-app-title" content="Big Drop">' . "\n";
        echo '<link rel="apple-touch-icon" href="' . esc_url( $icon_192 ) . '">' . "\n";
        echo '<link rel="icon" type="image/png" sizes="192x192" href="' . esc_url( $icon_192 ) . '">' . "\n";
        echo '<link rel="icon" type="image/png" sizes="512x512" href="' . esc_url( $icon_512 ) . '">' . "\n";
    }

    public function enqueue_register() {
        if ( ! is_user_logged_in() ) return;
        if ( ! $this->is_portal_context() ) return;

        wp_enqueue_script(
            'bd-pwa-register',
            BD_URL . 'assets/js/pwa.js',
            array(),
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
}