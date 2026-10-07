<?php
/**
 * Loads a minimal template for the Big Drop portal —
 * bypasses the theme's header, footer, and sidebar.
 *
 * @package BigDrop
 */

defined( 'ABSPATH' ) || exit;

class BD_Templates {

    public function __construct() {
        // Intercept the template when our portal page loads.
        add_filter( 'template_include', array( $this, 'maybe_use_blank_template' ), 99 );
    }

    /**
     * If the current page contains our portal shortcode (or is the portal page),
     * swap the theme's template for our minimal one.
     */
    public function maybe_use_blank_template( $template ) {
        // Only on the frontend.
        if ( is_admin() ) {
            return $template;
        }

        // Only if we're on a singular page.
        if ( ! is_singular() ) {
            return $template;
        }

        // Only if the current user is an agent (portal is only for agents anyway).
        if ( ! is_user_logged_in() ) {
            return $template;
        }

        $post = get_post();
        if ( ! $post ) {
            return $template;
        }

        // Match if:
        // 1. This is the portal page saved in options, OR
        // 2. The post content contains [bigdrop_portal]
        $is_portal_page = false;

        $portal_page_id = (int) get_option( 'bd_portal_page_id' );
        if ( $portal_page_id && $post->ID === $portal_page_id ) {
            $is_portal_page = true;
        }

        if ( ! $is_portal_page && has_shortcode( $post->post_content, 'bigdrop_portal' ) ) {
            $is_portal_page = true;
        }

        if ( ! $is_portal_page ) {
            return $template;
        }

        // Point to our minimal template.
        $blank = BD_PATH . 'public/views/blank-template.php';
        if ( file_exists( $blank ) ) {
            return $blank;
        }

        return $template;
    }
}