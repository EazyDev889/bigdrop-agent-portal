<?php
/**
 * Client accounts — lookup, import, sync helpers.
 *
 * @package BigDrop
 */

defined( 'ABSPATH' ) || exit;

class BD_Clients {

    public function __construct() {
        // Add "Import CSV" handling on admin_init.
        add_action( 'admin_post_bd_import_clients', array( $this, 'handle_csv_import' ) );
    }

    /**
     * Lookup by account number.
     */
    public static function get_by_account( $account_number ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bd_clients WHERE account_number = %s",
            sanitize_text_field( $account_number )
        ) );
    }

    /**
     * Lookup by phone.
     */
    public static function get_by_phone( $phone ) {
        global $wpdb;
        $phone = preg_replace( '/[^0-9]/', '', $phone );
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bd_clients WHERE phone LIKE %s",
            '%' . $wpdb->esc_like( $phone ) . '%'
        ) );
    }

    /**
     * Search across name, account number, phone.
     */
    public static function search( $q, $limit = 20 ) {
        global $wpdb;
        $q = trim( $q );
        if ( strlen( $q ) < 2 ) {
            return array();
        }
        $like = '%' . $wpdb->esc_like( $q ) . '%';

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bd_clients
             WHERE account_number LIKE %s
                OR full_name LIKE %s
                OR phone LIKE %s
                OR id_number LIKE %s
             ORDER BY full_name ASC
             LIMIT %d",
            $like, $like, $like, $like, $limit
        ) );
    }

    /**
     * Insert or update a client record.
     *
     * @param array $data Keys: account_number, full_name, phone, id_number,
     *                    kyc_status, account_status, plan_selected, payment_status, meta.
     * @return int|false  Inserted / updated ID or false.
     */
    public static function upsert( $data ) {
        global $wpdb;

        if ( empty( $data['account_number'] ) ) {
            return false;
        }

        $table = $wpdb->prefix . 'bd_clients';
        $account = sanitize_text_field( $data['account_number'] );

        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE account_number = %s",
            $account
        ) );

        $row = array(
            'account_number' => $account,
            'full_name'      => isset( $data['full_name'] )      ? sanitize_text_field( $data['full_name'] )      : '',
            'phone'          => isset( $data['phone'] )          ? sanitize_text_field( $data['phone'] )          : '',
            'id_number'      => isset( $data['id_number'] )      ? sanitize_text_field( $data['id_number'] )      : '',
            'kyc_status'     => isset( $data['kyc_status'] )     ? sanitize_text_field( $data['kyc_status'] )     : '',
            'account_status' => isset( $data['account_status'] ) ? sanitize_text_field( $data['account_status'] ) : '',
            'plan_selected'  => isset( $data['plan_selected'] )  ? sanitize_text_field( $data['plan_selected'] )  : '',
            'payment_status' => isset( $data['payment_status'] ) ? sanitize_text_field( $data['payment_status'] ) : '',
            'meta'           => isset( $data['meta'] )           ? wp_json_encode( $data['meta'] )                : '',
            'last_synced'    => current_time( 'mysql' ),
        );

        if ( $existing ) {
            $wpdb->update( $table, $row, array( 'id' => $existing ) );
            return (int) $existing;
        }

        $wpdb->insert( $table, $row );
        return $wpdb->insert_id;
    }

    /**
     * Delete a client by ID (admin only).
     */
    public static function delete( $id ) {
        global $wpdb;
        return $wpdb->delete(
            "{$wpdb->prefix}bd_clients",
            array( 'id' => (int) $id ),
            array( '%d' )
        );
    }

    /**
     * Total count of client records.
     */
    public static function count() {
        global $wpdb;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bd_clients" );
    }

    /**
     * Get clients paginated (admin table).
     */
    public static function paginate( $page = 1, $per_page = 50 ) {
        global $wpdb;
        $offset = max( 0, ( (int) $page - 1 ) * (int) $per_page );
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bd_clients ORDER BY id DESC LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ) );
    }

    /**
     * Handle a CSV import posted to admin-post.php.
     * CSV format: account_number, full_name, phone, id_number, kyc_status, account_status, plan_selected, payment_status
     */
    public function handle_csv_import() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized', 403 );
        }
        check_admin_referer( 'bd_import_clients' );

        if ( empty( $_FILES['bd_csv']['tmp_name'] ) ) {
            wp_safe_redirect( add_query_arg( 'bd_import', 'no_file', wp_get_referer() ) );
            exit;
        }

        $file = $_FILES['bd_csv']['tmp_name'];
        $fh   = fopen( $file, 'r' );
        if ( ! $fh ) {
            wp_safe_redirect( add_query_arg( 'bd_import', 'read_fail', wp_get_referer() ) );
            exit;
        }

        $header = fgetcsv( $fh );
        $count  = 0;
        $errors = 0;

        // Expected header positions.
        $map = array_flip( array_map( 'strtolower', array_map( 'trim', (array) $header ) ) );

        while ( ( $row = fgetcsv( $fh ) ) !== false ) {
            if ( empty( array_filter( $row ) ) ) {
                continue;
            }

            $data = array(
                'account_number' => $this->csv_get( $row, $map, 'account_number' ),
                'full_name'      => $this->csv_get( $row, $map, 'full_name' ),
                'phone'          => $this->csv_get( $row, $map, 'phone' ),
                'id_number'      => $this->csv_get( $row, $map, 'id_number' ),
                'kyc_status'     => $this->csv_get( $row, $map, 'kyc_status' ),
                'account_status' => $this->csv_get( $row, $map, 'account_status' ),
                'plan_selected'  => $this->csv_get( $row, $map, 'plan_selected' ),
                'payment_status' => $this->csv_get( $row, $map, 'payment_status' ),
            );

            if ( empty( $data['account_number'] ) ) {
                $errors++;
                continue;
            }

            if ( self::upsert( $data ) ) {
                $count++;
            } else {
                $errors++;
            }
        }

        fclose( $fh );

        wp_safe_redirect( add_query_arg( array(
            'bd_import' => 'done',
            'count'     => $count,
            'errors'    => $errors,
        ), wp_get_referer() ) );
        exit;
    }

    /**
     * Helper to pull a value from a CSV row using a header map.
     */
    private function csv_get( $row, $map, $key ) {
        if ( ! isset( $map[ $key ] ) ) {
            return '';
        }
        $i = $map[ $key ];
        return isset( $row[ $i ] ) ? trim( $row[ $i ] ) : '';
    }

    /**
     * Get current subscription payment status label with color.
     */
    public static function payment_status_label( $status ) {
        $status = strtolower( (string) $status );
        if ( in_array( $status, array( 'up to date', 'paid', 'active', 'current' ), true ) ) {
            return array( 'label' => 'Up to date', 'color' => '#2E6B34', 'bg' => '#EAFBE3' );
        }
        if ( in_array( $status, array( 'overdue', 'pending' ), true ) ) {
            return array( 'label' => ucfirst( $status ), 'color' => '#8A5A00', 'bg' => '#FFF7E0' );
        }
        if ( in_array( $status, array( 'suspended', 'cancelled', 'defaulted' ), true ) ) {
            return array( 'label' => ucfirst( $status ), 'color' => '#C11501', 'bg' => '#FEE6E2' );
        }
        return array( 'label' => ucfirst( $status ), 'color' => '#6C6F8C', 'bg' => '#E9EBF2' );
    }
}