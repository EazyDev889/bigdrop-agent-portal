<?php
/**
 * Admin menu, screens, and asset loading.
 *
 * @package BigDrop
 */

defined( 'ABSPATH' ) || exit;

class BD_Admin {

    public function __construct() {
        add_action( 'admin_menu',            array( $this, 'register_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        
        // Restrict Team Lead admin access
        add_action( 'admin_menu', array( $this, 'restrict_team_lead_menus' ), 999 );
        add_action( 'admin_init', array( $this, 'block_team_lead_admin_access' ) );
        
        // AJAX handlers for agent management
        add_action( 'wp_ajax_bd_delete_agent', array( $this, 'ajax_delete_agent' ) );
        add_action( 'wp_ajax_bd_promote_agent', array( $this, 'ajax_promote_agent' ) );
        add_action( 'wp_ajax_bd_demote_agent', array( $this, 'ajax_demote_agent' ) );
    }

    /**
     * Register the admin menu.
     */
    public function register_menu() {
        add_menu_page(
            __( 'Big Drop', 'bigdrop' ),
            __( 'Big Drop', 'bigdrop' ),
            'bd_access_portal',
            'bd-portal',
            array( $this, 'render_portal' ),
            'dashicons-format-chat',
            26
        );

        add_submenu_page(
            'bd-portal',
            __( 'Agent Portal', 'bigdrop' ),
            __( 'Agent Portal', 'bigdrop' ),
            'bd_access_portal',
            'bd-portal',
            array( $this, 'render_portal' )
        );

        add_submenu_page(
            'bd-portal',
            __( 'Settings', 'bigdrop' ),
            __( 'Settings', 'bigdrop' ),
            'manage_options',
            'bd-settings',
            array( $this, 'render_settings' )
        );

        add_submenu_page(
            'bd-portal',
            __( 'Pre-Reply Messages', 'bigdrop' ),
            __( 'Pre-Reply Messages', 'bigdrop' ),
            'bd_manage_canned',
            'bd-canned',
            array( $this, 'render_canned' )
        );

        add_submenu_page(
            'bd-portal',
            __( 'Manage Agents', 'bigdrop' ),
            __( 'Manage Agents', 'bigdrop' ),
            'bd_manage_agents',
            'bd-agents',
            array( $this, 'render_agents' )
        );

        add_submenu_page(
            'bd-portal',
            __( 'Client Accounts', 'bigdrop' ),
            __( 'Client Accounts', 'bigdrop' ),
            'bd_view_clients',
            'bd-clients',
            array( $this, 'render_clients' )
        );
    }

    /**
     * Hide all WordPress admin menus for Team Leads except the Big Drop Portal.
     */
    public function restrict_team_lead_menus() {
        $user = wp_get_current_user();
        
        if ( in_array( 'bd_team_lead', (array) $user->roles ) && ! in_array( 'administrator', (array) $user->roles ) ) {
            
            $menus_to_remove = array(
                'index.php',
                'edit.php',
                'upload.php',
                'edit.php?post_type=page',
                'edit-comments.php',
                'themes.php',
                'plugins.php',
                'users.php',
                'tools.php',
                'options-general.php',
                'woocommerce',
                'edit.php?post_type=product',
                'edit.php?post_type=shop_order',
            );
            
            foreach ( $menus_to_remove as $menu_slug ) {
                remove_menu_page( $menu_slug );
            }
            
            remove_submenu_page( 'options-general.php', 'options-writing.php' );
            remove_submenu_page( 'options-general.php', 'options-reading.php' );
            remove_submenu_page( 'options-general.php', 'options-discussion.php' );
            remove_submenu_page( 'options-general.php', 'options-media.php' );
            remove_submenu_page( 'options-general.php', 'options-permalink.php' );
        }
    }

    /**
     * Block Team Leads from accessing any WP Admin page except the Big Drop Portal.
     */
    public function block_team_lead_admin_access() {
        $user = wp_get_current_user();
        
        if ( in_array( 'bd_team_lead', (array) $user->roles ) && ! in_array( 'administrator', (array) $user->roles ) ) {
            
            global $pagenow;
            $current_page = isset( $_GET['page'] ) ? $_GET['page'] : '';
            
            $allowed_pages = array( 'bd-portal', 'bd-canned', 'bd-clients', 'bd-agents' );
            $allowed_files = array( 'admin-ajax.php', 'admin-post.php' );
            
            if ( ! in_array( $current_page, $allowed_pages ) && ! in_array( $pagenow, $allowed_files ) ) {
                wp_safe_redirect( admin_url( 'admin.php?page=bd-portal' ) );
                exit;
            }
        }
    }

    /**
     * Render the SPA portal inside wp-admin.
     */
    public function render_portal() {
        if ( ! BD_Roles::is_agent() ) {
            wp_die( esc_html__( 'You do not have permission to access the Agent Portal.', 'bigdrop' ) );
        }

        $current_user     = wp_get_current_user();
        $agent_name       = BD_Roles::current_agent_name();
        $agent_initials   = BD_Roles::current_agent_initials();
        $role_label       = self::current_role_label();
        $is_admin         = BD_Roles::is_admin();
        $can_view_clients = BD_Roles::can_view_clients();

        include BD_PATH . 'public/views/portal.php';
    }

    /**
     * Render settings page.
     */
    public function render_settings() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'bigdrop' ) );
        }

        $settings      = BD_Settings::all();
        $import_status = isset( $_GET['bd_import'] ) ? sanitize_key( $_GET['bd_import'] ) : '';
        ?>
        <div class="wrap bd-admin-wrap">
            <h1><?php esc_html_e( 'Big Drop — Settings', 'bigdrop' ); ?></h1>

            <?php if ( 'done' === $import_status ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p>
                        <?php
                        printf(
                            esc_html__( 'Import finished. %1$d clients imported, %2$d skipped.', 'bigdrop' ),
                            (int) ( $_GET['count'] ?? 0 ),
                            (int) ( $_GET['errors'] ?? 0 )
                        );
                        ?>
                    </p>
                </div>
            <?php elseif ( in_array( $import_status, array( 'no_file', 'read_fail' ), true ) ) : ?>
                <div class="notice notice-error is-dismissible">
                    <p><?php esc_html_e( 'Import failed. Please check the file and try again.', 'bigdrop' ); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" action="options.php" class="bd-settings-form">
                <?php settings_fields( 'bd_settings_group' ); ?>

                <h2 class="title"><?php esc_html_e( 'Widget (Visitor Chat)', 'bigdrop' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Enable widget', 'bigdrop' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( BD_Settings::OPTION_KEY ); ?>[widget_enabled]" value="1" <?php checked( $settings['widget_enabled'], 1 ); ?>>
                                <?php esc_html_e( 'Show the chat widget on the frontend of the site.', 'bigdrop' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Widget title', 'bigdrop' ); ?></th>
                        <td>
                            <input type="text" class="regular-text" name="<?php echo esc_attr( BD_Settings::OPTION_KEY ); ?>[widget_title]" value="<?php echo esc_attr( $settings['widget_title'] ); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Greeting message', 'bigdrop' ); ?></th>
                        <td>
                            <textarea class="large-text" rows="2" name="<?php echo esc_attr( BD_Settings::OPTION_KEY ); ?>[widget_greeting]"><?php echo esc_textarea( $settings['widget_greeting'] ); ?></textarea>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Position', 'bigdrop' ); ?></th>
                        <td>
                            <select name="<?php echo esc_attr( BD_Settings::OPTION_KEY ); ?>[widget_position]">
                                <option value="bottom-right" <?php selected( $settings['widget_position'], 'bottom-right' ); ?>><?php esc_html_e( 'Bottom right', 'bigdrop' ); ?></option>
                                <option value="bottom-left"  <?php selected( $settings['widget_position'], 'bottom-left' ); ?>><?php esc_html_e( 'Bottom left', 'bigdrop' ); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Widget color', 'bigdrop' ); ?></th>
                        <td>
                            <input type="color" name="<?php echo esc_attr( BD_Settings::OPTION_KEY ); ?>[widget_color]" value="<?php echo esc_attr( $settings['widget_color'] ); ?>">
                        </td>
                    </tr>
                </table>

                <h2 class="title"><?php esc_html_e( 'Chat Behavior', 'bigdrop' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Chat poll interval (seconds)', 'bigdrop' ); ?></th>
                        <td>
                            <input type="number" min="2" max="60" name="<?php echo esc_attr( BD_Settings::OPTION_KEY ); ?>[poll_interval_chat]" value="<?php echo esc_attr( $settings['poll_interval_chat'] ); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Notification poll interval (seconds)', 'bigdrop' ); ?></th>
                        <td>
                            <input type="number" min="3" max="120" name="<?php echo esc_attr( BD_Settings::OPTION_KEY ); ?>[poll_interval_notify]" value="<?php echo esc_attr( $settings['poll_interval_notify'] ); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Escalate chats after (minutes)', 'bigdrop' ); ?></th>
                        <td>
                            <input type="number" min="1" max="60" name="<?php echo esc_attr( BD_Settings::OPTION_KEY ); ?>[escalate_after_minutes]" value="<?php echo esc_attr( $settings['escalate_after_minutes'] ); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Auto-assign chats', 'bigdrop' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( BD_Settings::OPTION_KEY ); ?>[auto_assign]" value="1" <?php checked( $settings['auto_assign'], 1 ); ?>>
                                <?php esc_html_e( 'Assign chat to the agent on first reply.', 'bigdrop' ); ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <h2 class="title"><?php esc_html_e( 'Notifications', 'bigdrop' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Enable sound alerts', 'bigdrop' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( BD_Settings::OPTION_KEY ); ?>[sound_enabled]" value="1" <?php checked( $settings['sound_enabled'], 1 ); ?>>
                                <?php esc_html_e( 'Play a soft ping on new chats/messages.', 'bigdrop' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Enable Web Push', 'bigdrop' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( BD_Settings::OPTION_KEY ); ?>[push_enabled]" value="1" <?php checked( $settings['push_enabled'], 1 ); ?>>
                                <?php esc_html_e( 'Send browser push notifications (requires HTTPS).', 'bigdrop' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Desktop notifications', 'bigdrop' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( BD_Settings::OPTION_KEY ); ?>[desktop_notifications]" value="1" <?php checked( $settings['desktop_notifications'], 1 ); ?>>
                                <?php esc_html_e( 'Use the browser Notification API when the tab is open.', 'bigdrop' ); ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <?php submit_button( __( 'Save Settings', 'bigdrop' ) ); ?>
            </form>

            <hr>

            <h2><?php esc_html_e( 'Test Web Push', 'bigdrop' ); ?></h2>
            <p><?php esc_html_e( 'Send a test push notification to your current browser.', 'bigdrop' ); ?></p>
            <button type="button" class="button button-secondary" id="bd-test-push">
                <?php esc_html_e( 'Send Test Push', 'bigdrop' ); ?>
            </button>
            <span id="bd-test-push-status" style="margin-left:12px;color:#6C6F8C;"></span>

            <hr>

            <h2><?php esc_html_e( 'Import Clients (CSV)', 'bigdrop' ); ?></h2>
            <p><?php esc_html_e( 'Upload a CSV with columns: account_number, full_name, phone, id_number, kyc_status, account_status, plan_selected, payment_status.', 'bigdrop' ); ?></p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
                <input type="hidden" name="action" value="bd_import_clients">
                <?php wp_nonce_field( 'bd_import_clients' ); ?>
                <input type="file" name="bd_csv" accept=".csv" required>
                <?php submit_button( __( 'Import CSV', 'bigdrop' ), 'secondary', 'submit', false ); ?>
            </form>

            <hr>

            <h2><?php esc_html_e( 'Diagnostics', 'bigdrop' ); ?></h2>
            <table class="widefat striped" style="max-width:760px;">
                <tbody>
                    <tr><td><strong>VAPID public key</strong></td><td><?php echo esc_html( substr( (string) get_option( 'bd_vapid_public_key' ), 0, 40 ) . '…' ); ?></td></tr>
                    <tr><td><strong>VAPID private key</strong></td><td><?php echo get_option( 'bd_vapid_private_key' ) ? esc_html__( 'Set', 'bigdrop' ) : esc_html__( 'MISSING', 'bigdrop' ); ?></td></tr>
                    <tr><td><strong>OpenSSL</strong></td><td><?php echo function_exists( 'openssl_pkey_new' ) ? '✅ Available' : '❌ Not available'; ?></td></tr>
                    <tr><td><strong>PHP version</strong></td><td><?php echo esc_html( PHP_VERSION ); ?></td></tr>
                    <tr><td><strong>WP version</strong></td><td><?php echo esc_html( get_bloginfo( 'version' ) ); ?></td></tr>
                    <tr><td><strong>Push subscriptions</strong></td><td>
                        <?php
                        global $wpdb;
                        echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bd_push_subscriptions" );
                        ?>
                    </td></tr>
                    <tr><td><strong>Total chats</strong></td><td>
                        <?php
                        global $wpdb;
                        echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bd_chats" );
                        ?>
                    </td></tr>
                    <tr><td><strong>Client records</strong></td><td><?php echo (int) BD_Clients::count(); ?></td></tr>
                    <tr><td><strong>Cron: escalate</strong></td><td><?php echo wp_next_scheduled( 'bd_escalate_chats' ) ? '✅ Scheduled' : '❌ Not scheduled'; ?></td></tr>
                    <tr><td><strong>Cron: cleanup</strong></td><td><?php echo wp_next_scheduled( 'bd_cleanup_old' ) ? '✅ Scheduled' : '❌ Not scheduled'; ?></td></tr>
                </tbody>
            </table>
        </div>

        <script>
        jQuery(function($){
            $('#bd-test-push').on('click', function(){
                var $btn = $(this);
                var $status = $('#bd-test-push-status');
                $btn.prop('disabled', true);
                $status.text('Sending…');

                $.ajax({
                    url: '<?php echo esc_url_raw( rest_url( 'bigdrop/v1/push/test' ) ); ?>',
                    method: 'POST',
                    beforeSend: function(xhr){ xhr.setRequestHeader('X-WP-Nonce', '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>'); }
                }).done(function(res){
                    $status.text(res && res.sent > 0 ? '✅ Sent to ' + res.sent + ' subscription(s).' : '⚠️ No active subscriptions. Enable notifications first.');
                }).fail(function(){
                    $status.text('❌ Failed to send.');
                }).always(function(){
                    $btn.prop('disabled', false);
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Render canned replies admin page.
     */
    public function render_canned() {
        if ( ! current_user_can( 'bd_manage_canned' ) && ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'bigdrop' ) );
        }
        global $wpdb;
        $rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}bd_canned_replies ORDER BY id DESC" );
        ?>
        <div class="wrap bd-admin-wrap">
            <h1><?php esc_html_e( 'Pre-Written Replies', 'bigdrop' ); ?></h1>

            <div class="bd-card">
                <h2><?php esc_html_e( 'Add a Pre-Written Reply', 'bigdrop' ); ?></h2>
                <form id="bd-canned-form">
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="bd-canned-header"><?php esc_html_e( 'Header (internal only)', 'bigdrop' ); ?></label></th>
                            <td><input type="text" id="bd-canned-header" class="regular-text" required></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bd-canned-msg"><?php esc_html_e( 'Message', 'bigdrop' ); ?></label></th>
                            <td><textarea id="bd-canned-msg" rows="4" class="large-text" required></textarea></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bd-canned-att"><?php esc_html_e( 'Attachment URL (optional)', 'bigdrop' ); ?></label></th>
                            <td><input type="url" id="bd-canned-att" class="regular-text" placeholder="https://…"></td>
                        </tr>
                    </table>
                    <button class="button button-primary" type="submit"><?php esc_html_e( 'Save Reply', 'bigdrop' ); ?></button>
                    <span id="bd-canned-status" style="margin-left:12px;color:#2E6B34;font-weight:600;"></span>
                </form>
            </div>

            <div class="bd-card" style="margin-top:24px;">
                <h2><?php esc_html_e( 'Current Pre-Written Replies', 'bigdrop' ); ?></h2>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Header', 'bigdrop' ); ?></th>
                            <th><?php esc_html_e( 'Message', 'bigdrop' ); ?></th>
                            <th><?php esc_html_e( 'Created', 'bigdrop' ); ?></th>
                            <th><?php esc_html_e( 'Actions', 'bigdrop' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $rows ) ) : ?>
                            <tr><td colspan="4"><?php esc_html_e( 'No canned replies yet.', 'bigdrop' ); ?></td></tr>
                        <?php else : ?>
                            <?php foreach ( $rows as $row ) : ?>
                                <tr data-id="<?php echo (int) $row->id; ?>">
                                    <td><strong><?php echo esc_html( $row->header ); ?></strong></td>
                                    <td><?php echo esc_html( wp_trim_words( $row->message, 18 ) ); ?></td>
                                    <td><?php echo esc_html( mysql2date( 'M j, Y', $row->created_at ) ); ?></td>
                                    <td>
                                        <button class="button button-link-delete bd-delete-canned" data-id="<?php echo (int) $row->id; ?>" style="color:#C11501;">
                                            <?php esc_html_e( 'Delete', 'bigdrop' ); ?>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <script>
        jQuery(function($){
            var nonce = '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>';
            var api   = '<?php echo esc_url_raw( rest_url( 'bigdrop/v1/' ) ); ?>';

            $('#bd-canned-form').on('submit', function(e){
                e.preventDefault();
                var $status = $('#bd-canned-status').text('Saving…');
                $.ajax({
                    url: api + 'canned',
                    method: 'POST',
                    contentType: 'application/json',
                    beforeSend: function(xhr){ xhr.setRequestHeader('X-WP-Nonce', nonce); },
                    data: JSON.stringify({
                        header: $('#bd-canned-header').val(),
                        message: $('#bd-canned-msg').val(),
                        attachment_url: $('#bd-canned-att').val()
                    })
                }).done(function(){
                    $status.text('Saved!').css('color','#2E6B34');
                    setTimeout(function(){ location.reload(); }, 500);
                }).fail(function(){
                    $status.text('Failed to save.').css('color','#C11501');
                });
            });

            $('.bd-delete-canned').on('click', function(){
                if ( ! confirm('Delete this reply?') ) return;
                var id = $(this).data('id');
                $.ajax({
                    url: api + 'canned/' + id,
                    method: 'DELETE',
                    beforeSend: function(xhr){ xhr.setRequestHeader('X-WP-Nonce', nonce); }
                }).done(function(){ $('tr[data-id="'+id+'"]').fadeOut(200, function(){ $(this).remove(); }); });
            });
        });
        </script>
        <?php
    }

    /**
     * Render Manage Agents admin page.
     */
    public function render_agents() {
        if ( ! BD_Roles::can_manage_agents() ) {
            wp_die( esc_html__( 'Unauthorized', 'bigdrop' ) );
        }
        
        $is_admin        = current_user_can( 'manage_options' );
        $current_user_id = get_current_user_id();
        
        $agents = get_users( array(
            'role__in' => array( 'bd_agent', 'bd_team_lead', 'administrator' ),
            'orderby'  => 'display_name',
            'order'    => 'ASC',
            'number'   => 200,
        ) );
        
        ?>
        <div class="wrap bd-admin-wrap">
            <h1><?php esc_html_e( 'Manage Agents', 'bigdrop' ); ?></h1>

            <div class="bd-card">
                <h2><?php esc_html_e( 'Add a New Agent', 'bigdrop' ); ?></h2>
                <form id="bd-agent-form">
                    <table class="form-table">
                        <tr>
                            <th><label for="bd-name"><?php esc_html_e( 'Full Name', 'bigdrop' ); ?></label></th>
                            <td><input type="text" id="bd-name" class="regular-text" required></td>
                        </tr>
                        <tr>
                            <th><label for="bd-surname"><?php esc_html_e( 'Surname', 'bigdrop' ); ?></label></th>
                            <td><input type="text" id="bd-surname" class="regular-text" required></td>
                        </tr>
                        <tr>
                            <th><label for="bd-position"><?php esc_html_e( 'Position', 'bigdrop' ); ?></label></th>
                            <td>
                                <select id="bd-position">
                                    <option value="bd_agent"><?php esc_html_e( 'Agent', 'bigdrop' ); ?></option>
                                    <?php if ( $is_admin ) : ?>
                                        <option value="bd_team_lead"><?php esc_html_e( 'Team Lead', 'bigdrop' ); ?></option>
                                    <?php endif; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="bd-username"><?php esc_html_e( 'Login Username', 'bigdrop' ); ?></label></th>
                            <td><input type="text" id="bd-username" class="regular-text" required></td>
                        </tr>
                        <tr>
                            <th><label for="bd-email"><?php esc_html_e( 'Email', 'bigdrop' ); ?></label></th>
                            <td><input type="email" id="bd-email" class="regular-text" required></td>
                        </tr>
                        <tr>
                            <th><label for="bd-password"><?php esc_html_e( 'Passcode', 'bigdrop' ); ?></label></th>
                            <td><input type="password" id="bd-password" class="regular-text" required minlength="6"></td>
                        </tr>
                    </table>
                    <button class="button button-primary" type="submit"><?php esc_html_e( 'Save Agent', 'bigdrop' ); ?></button>
                    <span id="bd-agent-status" style="margin-left:12px;font-weight:600;"></span>
                </form>
            </div>

            <div class="bd-card" style="margin-top:24px;">
                <h2><?php esc_html_e( 'Current System Users', 'bigdrop' ); ?></h2>
                
                <?php if ( empty( $agents ) ) : ?>
                    <p><?php esc_html_e( 'No agents yet.', 'bigdrop' ); ?></p>
                <?php else : ?>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Name', 'bigdrop' ); ?></th>
                                <th><?php esc_html_e( 'Username', 'bigdrop' ); ?></th>
                                <th><?php esc_html_e( 'Email', 'bigdrop' ); ?></th>
                                <th><?php esc_html_e( 'Role', 'bigdrop' ); ?></th>
                                <th><?php esc_html_e( 'Actions', 'bigdrop' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $agents as $agent ) : ?>
                                <?php
                                $roles      = (array) $agent->roles;
                                $is_admin_r = in_array( 'administrator', $roles );
                                $is_tl      = in_array( 'bd_team_lead', $roles );
                                $is_agent_r = in_array( 'bd_agent', $roles );
                                $is_me      = ( (int) $agent->ID === (int) $current_user_id );
                                
                                if ( $is_admin_r ) {
                                    $role_label = __( 'Administrator', 'bigdrop' );
                                } elseif ( $is_tl ) {
                                    $role_label = __( 'Team Lead', 'bigdrop' );
                                } else {
                                    $role_label = __( 'Agent', 'bigdrop' );
                                }
                                ?>
                                <tr>
                                    <td><strong><?php echo esc_html( $agent->display_name ); ?></strong></td>
                                    <td><?php echo esc_html( $agent->user_login ); ?></td>
                                    <td><?php echo esc_html( $agent->user_email ); ?></td>
                                    <td><?php echo esc_html( $role_label ); ?></td>
                                    <td>
                                        <?php if ( $is_me ) : ?>
                                            <span style="color:#999;font-style:italic;"><?php esc_html_e( '(You)', 'bigdrop' ); ?></span>
                                        <?php elseif ( $is_admin_r ) : ?>
                                            <span style="color:#999;">—</span>
                                        <?php else : ?>
                                            <?php if ( $is_admin ) : ?>
                                                <?php if ( $is_tl ) : ?>
                                                    <button type="button" class="button button-secondary bd-demote-agent" data-id="<?php echo (int) $agent->ID; ?>" style="margin-right:4px;color:#8A5A00;border-color:#8A5A00;">
                                                        <?php esc_html_e( 'Demote to Agent', 'bigdrop' ); ?>
                                                    </button>
                                                <?php elseif ( $is_agent_r ) : ?>
                                                    <button type="button" class="button button-secondary bd-promote-agent" data-id="<?php echo (int) $agent->ID; ?>" style="margin-right:4px;color:#2E6B34;border-color:#2E6B34;">
                                                        <?php esc_html_e( 'Promote to Team Lead', 'bigdrop' ); ?>
                                                    </button>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            
                                            <button type="button" class="button button-link-delete bd-delete-agent" data-id="<?php echo (int) $agent->ID; ?>" style="color:#C11501;border-color:#C11501;">
                                                <?php esc_html_e( 'Delete', 'bigdrop' ); ?>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <script>
        jQuery(function($){
            var nonce = '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>';
            var api   = '<?php echo esc_url_raw( rest_url( 'bigdrop/v1/' ) ); ?>';
            var ajaxNonce = '<?php echo esc_js( wp_create_nonce( 'bd_manage_agents' ) ); ?>';
            var ajaxUrl = '<?php echo esc_url_raw( admin_url( 'admin-ajax.php' ) ); ?>';

            $('#bd-agent-form').on('submit', function(e){
                e.preventDefault();
                var $status = $('#bd-agent-status').text('Saving…').css('color','#6C6F8C');
                $.ajax({
                    url: api + 'agents',
                    method: 'POST',
                    contentType: 'application/json',
                    beforeSend: function(xhr){ xhr.setRequestHeader('X-WP-Nonce', nonce); },
                    data: JSON.stringify({
                        name:      $('#bd-name').val(),
                        surname:   $('#bd-surname').val(),
                        position:  $('#bd-position').val(),
                        username:  $('#bd-username').val(),
                        email:     $('#bd-email').val(),
                        password:  $('#bd-password').val()
                    })
                }).done(function(){
                    $status.text('✅ Agent saved!').css('color','#2E6B34');
                    $('#bd-agent-form')[0].reset();
                    setTimeout(function(){ location.reload(); }, 800);
                }).fail(function(xhr){
                    var msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Failed.';
                    $status.text('❌ '+msg).css('color','#C11501');
                });
            });

            $('.bd-delete-agent').on('click', function(){
                if ( ! confirm('Delete this agent permanently?') ) return;
                var id = $(this).data('id');
                var $btn = $(this);
                $btn.prop('disabled', true).text('Deleting…');
                
                $.post(ajaxUrl, {
                    action: 'bd_delete_agent',
                    id: id,
                    nonce: ajaxNonce
                }, function(res){
                    if ( res.success ) {
                        $btn.closest('tr').fadeOut(200, function(){ $(this).remove(); });
                    } else {
                        alert(res.data.message || 'Failed to delete.');
                        $btn.prop('disabled', false).text('Delete');
                    }
                });
            });

            $('.bd-promote-agent').on('click', function(){
                if ( ! confirm('Promote this agent to Team Lead?') ) return;
                var id = $(this).data('id');
                var $btn = $(this);
                $btn.prop('disabled', true).text('Promoting…');
                
                $.post(ajaxUrl, {
                    action: 'bd_promote_agent',
                    id: id,
                    nonce: ajaxNonce
                }, function(res){
                    if ( res.success ) {
                        location.reload();
                    } else {
                        alert(res.data.message || 'Failed to promote.');
                        $btn.prop('disabled', false).text('Promote to Team Lead');
                    }
                });
            });

            $('.bd-demote-agent').on('click', function(){
                if ( ! confirm('Demote this Team Lead to a regular Agent?') ) return;
                var id = $(this).data('id');
                var $btn = $(this);
                $btn.prop('disabled', true).text('Demoting…');
                
                $.post(ajaxUrl, {
                    action: 'bd_demote_agent',
                    id: id,
                    nonce: ajaxNonce
                }, function(res){
                    if ( res.success ) {
                        location.reload();
                    } else {
                        alert(res.data.message || 'Failed to demote.');
                        $btn.prop('disabled', false).text('Demote to Agent');
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * AJAX: Delete an agent.
     */
    public function ajax_delete_agent() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'bd_manage_agents' ) ) {
            wp_send_json_error( array( 'message' => 'Invalid security token.' ) );
        }
        
        if ( ! BD_Roles::can_manage_agents() ) {
            wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
        }
        
        $agent_id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
        if ( ! $agent_id ) {
            wp_send_json_error( array( 'message' => 'Invalid agent ID.' ) );
        }
        
        $user = get_userdata( $agent_id );
        if ( ! $user ) {
            wp_send_json_error( array( 'message' => 'User not found.' ) );
        }
        
        $roles = (array) $user->roles;
        
        if ( in_array( 'administrator', $roles ) ) {
            wp_send_json_error( array( 'message' => 'Cannot delete Administrators.' ) );
        }
        
        if ( $agent_id === get_current_user_id() ) {
            wp_send_json_error( array( 'message' => 'You cannot delete your own account.' ) );
        }
        
        if ( in_array( 'bd_team_lead', $roles ) && ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Only Administrators can delete Team Leads.' ) );
        }
        
        require_once ABSPATH . 'wp-admin/includes/user.php';
        $deleted = wp_delete_user( $agent_id );
        
        if ( $deleted ) {
            wp_send_json_success( array( 'message' => 'Agent deleted.' ) );
        } else {
            wp_send_json_error( array( 'message' => 'Failed to delete agent.' ) );
        }
    }

    /**
     * AJAX: Promote an Agent to Team Lead.
     */
    public function ajax_promote_agent() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'bd_manage_agents' ) ) {
            wp_send_json_error( array( 'message' => 'Invalid security token.' ) );
        }
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Only Administrators can promote agents to Team Lead.' ) );
        }
        
        $agent_id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
        if ( ! $agent_id ) {
            wp_send_json_error( array( 'message' => 'Invalid agent ID.' ) );
        }
        
        $user = new WP_User( $agent_id );
        if ( ! $user->exists() ) {
            wp_send_json_error( array( 'message' => 'User not found.' ) );
        }
        
        $user->remove_role( 'bd_agent' );
        $user->add_role( 'bd_team_lead' );
        
        $user->add_cap( 'bd_access_portal' );
        $user->add_cap( 'bd_manage_canned' );
        $user->add_cap( 'bd_view_clients' );
        $user->add_cap( 'bd_view_all_agents' );
        $user->add_cap( 'bd_manage_agents' );
        
        wp_send_json_success( array( 'message' => 'Agent promoted to Team Lead.' ) );
    }

    /**
     * AJAX: Demote a Team Lead to Agent.
     */
    public function ajax_demote_agent() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'bd_manage_agents' ) ) {
            wp_send_json_error( array( 'message' => 'Invalid security token.' ) );
        }
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Only Administrators can demote Team Leads.' ) );
        }
        
        $agent_id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
        if ( ! $agent_id ) {
            wp_send_json_error( array( 'message' => 'Invalid agent ID.' ) );
        }
        
        $user = new WP_User( $agent_id );
        if ( ! $user->exists() ) {
            wp_send_json_error( array( 'message' => 'User not found.' ) );
        }
        
        $user->remove_role( 'bd_team_lead' );
        $user->add_role( 'bd_agent' );
        
        $user->remove_cap( 'bd_manage_canned' );
        $user->remove_cap( 'bd_view_clients' );
        $user->remove_cap( 'bd_view_all_agents' );
        $user->remove_cap( 'bd_manage_agents' );
        
        wp_send_json_success( array( 'message' => 'Team Lead demoted to Agent.' ) );
    }

    /**
     * Render Clients admin page.
     */
    public function render_clients() {
        if ( ! BD_Roles::can_view_clients() ) {
            wp_die( esc_html__( 'Unauthorized', 'bigdrop' ) );
        }
        $page  = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
        $rows  = BD_Clients::paginate( $page, 50 );
        $total = BD_Clients::count();
        ?>
        <div class="wrap bd-admin-wrap">
            <h1><?php esc_html_e( 'Client Accounts', 'bigdrop' ); ?></h1>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" style="margin:16px 0 24px;">
                <input type="hidden" name="action" value="bd_import_clients">
                <?php wp_nonce_field( 'bd_import_clients' ); ?>
                <input type="file" name="bd_csv" accept=".csv" required>
                <?php submit_button( __( 'Import CSV', 'bigdrop' ), 'secondary', 'submit', false ); ?>
            </form>

            <p><strong><?php echo (int) $total; ?></strong> <?php esc_html_e( 'client records', 'bigdrop' ); ?></p>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Account</th>
                        <th>Name</th>
                        <th>Phone</th>
                        <th>Plan</th>
                        <th>Account Status</th>
                        <th>Payment</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $rows ) ) : ?>
                        <tr><td colspan="6"><?php esc_html_e( 'No clients yet. Import a CSV to get started.', 'bigdrop' ); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ( $rows as $c ) :
                            $pay = BD_Clients::payment_status_label( $c->payment_status );
                        ?>
                            <tr>
                                <td><strong><?php echo esc_html( $c->account_number ); ?></strong></td>
                                <td><?php echo esc_html( $c->full_name ); ?></td>
                                <td><?php echo esc_html( $c->phone ); ?></td>
                                <td><?php echo esc_html( $c->plan_selected ); ?></td>
                                <td><?php echo esc_html( $c->account_status ); ?></td>
                                <td>
                                    <span style="background:<?php echo esc_attr( $pay['bg'] ); ?>;color:<?php echo esc_attr( $pay['color'] ); ?>;padding:4px 10px;border-radius:40px;font-size:11px;font-weight:700;">
                                        <?php echo esc_html( $pay['label'] ); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php
            $pages = (int) ceil( $total / 50 );
            if ( $pages > 1 ) {
                echo '<div class="tablenav"><div class="tablenav-pages">';
                echo paginate_links( array(
                    'base'    => add_query_arg( 'paged', '%#%' ),
                    'format'  => '',
                    'current' => $page,
                    'total'   => $pages,
                ) );
                echo '</div></div>';
            }
            ?>
        </div>
        <?php
    }

    /**
     * Enqueue assets on Big Drop admin pages.
     */
    public function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'bd-' ) === false ) {
            return;
        }

        wp_enqueue_style(
            'bd-portal-css',
            BD_URL . 'assets/css/portal.css',
            array(),
            BD_VERSION
        );

        if ( file_exists( BD_PATH . 'assets/css/admin.css' ) ) {
            wp_enqueue_style(
                'bd-admin-extra',
                BD_URL . 'assets/css/admin.css',
                array( 'bd-portal-css' ),
                BD_VERSION
            );
        }

        if ( strpos( $hook, 'bd-portal' ) === false ) {
            return;
        }

        wp_enqueue_script(
            'bd-portal-js',
            BD_URL . 'assets/js/portal.js',
            array(),
            BD_VERSION,
            true
        );
        
        wp_enqueue_style(
            'bd-internal-chat-css',
            BD_URL . 'assets/css/internal-chat.css',
            array( 'bd-portal-css' ),
            BD_VERSION
        );

        wp_enqueue_script(
            'bd-internal-chat-js',
            BD_URL . 'assets/js/internal-chat.js',
            array( 'bd-portal-js' ),
            BD_VERSION,
            true
        );

        wp_localize_script( 'bd-portal-js', 'BD', array(
            'restUrl'        => rest_url( 'bigdrop/v1/' ),
            'nonce'          => wp_create_nonce( 'wp_rest' ),
            'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
            'portalUrl'      => admin_url( 'admin.php?page=bd-portal' ),
            'homeUrl'        => home_url( '/' ),
            'assetsUrl'      => BD_URL . 'assets/',
            'currentUser'    => array(
                'id'       => get_current_user_id(),
                'name'     => BD_Roles::current_agent_name(),
                'initials' => BD_Roles::current_agent_initials(),
                'role'     => self::current_role_label(),
            ),
            'settings'       => array(
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
            'i18n'           => array(
                'online'        => __( 'Online', 'bigdrop' ),
                'offline'       => __( 'Offline', 'bigdrop' ),
                'sending'       => __( 'Sending…', 'bigdrop' ),
                'noChats'       => __( 'No chats yet.', 'bigdrop' ),
                'typeReply'     => __( 'Type a reply…', 'bigdrop' ),
                'send'          => __( 'Send', 'bigdrop' ),
                'resolve'       => __( 'Resolve', 'bigdrop' ),
                'unresolve'     => __( 'Unresolve', 'bigdrop' ),
                'search'        => __( 'Search chats, clients…', 'bigdrop' ),
                'notifications' => __( 'Notifications', 'bigdrop' ),
                'markRead'      => __( 'Mark all read', 'bigdrop' ),
            ),
        ) );
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