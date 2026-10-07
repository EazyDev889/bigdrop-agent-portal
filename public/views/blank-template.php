<?php
/**
 * Blank template — full-screen wrapper for the Big Drop portal.
 * Loads only what's needed: wp_head, the content, wp_footer.
 * No theme header, no theme footer, no theme sidebar.
 *
 * @package BigDrop
 */

defined( 'ABSPATH' ) || exit;
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?> class="bd-portal-html">
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#7FD344">

    <title><?php echo esc_html( get_the_title() ); ?> — <?php echo esc_html( get_bloginfo( 'name' ) ); ?></title>

    <?php wp_head(); ?>

    <style>
        /* Reset any theme body styles that might leak in. */
        html, body {
            margin: 0 !important;
            padding: 0 !important;
            background: #F5F7FC !important;
            min-height: 100vh;
            overflow-x: hidden;
        }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        /* Hide any injected theme widgets (like WhatsApp buttons, chat bubbles, etc.). */
        body > .whatsapp-button,
        body > .wa-chat-button,
        body > #wpadminbar { /* keep admin bar if logged in, comment this out if you want to hide it */
            /* nothing */
        }
        /* Portal full-width wrapper. */
        #bd-blank-wrap {
            width: 100%;
            max-width: 100%;
            padding: 0;
            margin: 0;
        }
        /* Fix WP admin bar overlap. */
        .admin-bar #bd-blank-wrap {
            padding-top: 32px;
        }
        @media screen and (max-width: 782px) {
            .admin-bar #bd-blank-wrap {
                padding-top: 46px;
            }
        }
    </style>
</head>
<body <?php body_class( 'bd-portal-body' ); ?>>
<?php wp_body_open(); ?>

<div id="bd-blank-wrap">
    <?php
    // Render the page content (which contains [bigdrop_portal]).
    while ( have_posts() ) {
        the_post();
        the_content();
    }
    ?>
</div>

<?php wp_footer(); ?>
</body>
</html>