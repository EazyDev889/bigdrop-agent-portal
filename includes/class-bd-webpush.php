<?php
/**
 * Web Push notifications using pure PHP (no Composer, no external libs).
 * Works on Hostinger shared hosting with OpenSSL.
 *
 * @package BigDrop
 */

defined( 'ABSPATH' ) || exit;

class BD_WebPush {

    /**
     * Send a push notification to a specific user's subscriptions.
     *
     * @param int   $user_id
     * @param array $payload  [ 'title' => '', 'body' => '', 'url' => '', 'tag' => '' ]
     * @return int  Number of subscriptions successfully delivered to.
     */
    public static function send_to_user( $user_id, $payload = array() ) {
        global $wpdb;

        $user_id = (int) $user_id;
        if ( ! $user_id ) {
            return 0;
        }

        $subs = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bd_push_subscriptions WHERE user_id = %d",
            $user_id
        ) );

        if ( empty( $subs ) ) {
            return 0;
        }

        $sent = 0;
        foreach ( $subs as $sub ) {
            $result = self::send_one( $sub, $payload );
            if ( $result ) {
                $sent++;
            } else {
                // If subscription is gone (404/410), delete it.
                $code = self::last_status_code();
                if ( in_array( $code, array( 404, 410 ), true ) ) {
                    $wpdb->delete(
                        "{$wpdb->prefix}bd_push_subscriptions",
                        array( 'id' => $sub->id )
                    );
                }
            }
        }

        return $sent;
    }

    /**
     * Track last HTTP status for cleanup decisions.
     */
    private static $last_code = 0;
    private static function last_status_code() { return self::$last_code; }

    /**
     * Send one push notification via Web Push Protocol (RFC 8291 / 8188).
     *
     * @param object $sub      Row from bd_push_subscriptions.
     * @param array  $payload
     * @return bool
     */
    private static function send_one( $sub, $payload ) {
        $public_key  = get_option( 'bd_vapid_public_key' );
        $private_key = get_option( 'bd_vapid_private_key' );

        if ( ! $public_key || ! $private_key ) {
            return false;
        }

        // Build the JSON payload.
        $body = wp_json_encode( array(
            'title' => isset( $payload['title'] ) ? (string) $payload['title'] : 'Big Drop',
            'body'  => isset( $payload['body'] )  ? (string) $payload['body']  : '',
            'url'   => isset( $payload['url'] )   ? (string) $payload['url']   : home_url( '/' ),
            'tag'   => isset( $payload['tag'] )   ? (string) $payload['tag']   : 'bigdrop',
            'icon'  => BD_URL . 'assets/icons/icon-192.png',
        ) );

        // Encrypt the payload (aes128gcm).
        $encrypted = self::encrypt_payload( $body, $sub->p256dh, $sub->auth );
        if ( ! $encrypted ) {
            return false;
        }

        // VAPID Authorization header.
        $audience = self::get_audience( $sub->endpoint );
        $jwt      = self::build_vapid_jwt( $audience, $public_key, $private_key );

        // Headers.
        $headers = array(
            'TTL'            => '86400',
            'Content-Type'   => 'application/octet-stream',
            'Content-Encoding' => 'aes128gcm',
            'Authorization'  => 'vapid t=' . $jwt . ', k=' . $public_key,
            'Urgency'        => 'high',
        );

        $response = wp_remote_post( $sub->endpoint, array(
            'headers' => $headers,
            'body'    => $encrypted,
            'timeout' => 15,
        ) );

        if ( is_wp_error( $response ) ) {
            self::$last_code = 0;
            return false;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        self::$last_code = $code;

        return ( $code >= 200 && $code < 300 );
    }

    /**
     * Extract the origin from a push endpoint URL.
     */
    private static function get_audience( $endpoint ) {
        $parts = wp_parse_url( $endpoint );
        return $parts['scheme'] . '://' . $parts['host'];
    }

    /**
     * Build a VAPID JWT (ES256).
     */
    private static function build_vapid_jwt( $audience, $public_key_b64, $private_key_b64 ) {
        $header = array( 'typ' => 'JWT', 'alg' => 'ES256' );
        $payload = array(
            'aud' => $audience,
            'exp' => time() + 12 * 3600,
            'sub' => 'mailto:' . get_option( 'admin_email' ),
        );

        $header_enc  = self::base64url_encode( wp_json_encode( $header ) );
        $payload_enc = self::base64url_encode( wp_json_encode( $payload ) );
        $signing_input = $header_enc . '.' . $payload_enc;

        // Decode the private key. On activation we stored base64url(PEM).
        $private_pem = self::base64url_decode( $private_key_b64 );
        $private_key = openssl_pkey_get_private( $private_pem );

        if ( ! $private_key ) {
            return '';
        }

        $signature = '';
        openssl_sign( $signing_input, $signature, $private_key, OPENSSL_ALGO_SHA256 );

        // Convert DER signature to raw R||S (JWT ES256 format).
        $signature = self::der_to_jose( $signature );

        return $signing_input . '.' . self::base64url_encode( $signature );
    }

    /**
     * Encrypt the payload for Web Push (aes128gcm).
     *
     * @param string $payload      JSON string.
     * @param string $p256dh_b64   Base64url subscriber public key.
     * @param string $auth_b64     Base64url auth secret.
     * @return string|false        Binary encrypted body.
     */
    private static function encrypt_payload( $payload, $p256dh_b64, $auth_b64 ) {
        $ua_public = self::base64url_decode( $p256dh_b64 );
        $auth      = self::base64url_decode( $auth_b64 );

        if ( strlen( $ua_public ) !== 65 || strlen( $auth ) !== 16 ) {
            return false;
        }

        // Generate ephemeral key pair.
        $local_key = openssl_pkey_new( array(
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ) );

        if ( ! $local_key ) {
            return false;
        }

        $local_details = openssl_pkey_get_details( $local_key );
        $as_public     = "\x04" . $local_details['ec']['x'] . $local_details['ec']['y'];

        // ECDH shared secret.
        $ec_pub_key = openssl_pkey_get_public( self::pem_from_public( $ua_public ) );
        if ( ! $ec_pub_key ) {
            return false;
        }

        $shared_secret = openssl_pkey_derive( $ec_pub_key, $local_key, 32 );
        if ( ! $shared_secret ) {
            return false;
        }

        // auth_info = "WebPush: info\x00" || ua_public || as_public
        $auth_info = "WebPush: info\x00" . $ua_public . $as_public;

        // HKDF-Extract with auth secret.
        $prk_key = hash_hmac( 'sha256', $shared_secret, $auth, true );
        // HKDF-Expand → IKM
        $ikm = self::hkdf_expand( $prk_key, $auth_info, 32 );

        // PRK_key = HKDF-Extract(salt=random, IKM)
        $salt = random_bytes( 16 );
        $prk  = hash_hmac( 'sha256', $ikm, $salt, true );

        // CEK info: "Content-Encoding: aes128gcm\x00"
        $cek_info   = "Content-Encoding: aes128gcm\x00";
        $cek        = self::hkdf_expand( $prk, $cek_info, 16 );

        // Nonce info: "Content-Encoding: nonce\x00"
        $nonce_info = "Content-Encoding: nonce\x00";
        $nonce      = self::hkdf_expand( $prk, $nonce_info, 12 );

        // Add padding delimiter (0x02) to end of payload.
        $padded = $payload . "\x02";

        // AES-128-GCM encrypt.
        $tag        = '';
        $ciphertext = openssl_encrypt( $padded, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag );

        if ( false === $ciphertext ) {
            return false;
        }

        // aes128gcm content coding header:
        // salt (16) | rs (4, big-endian) | idlen (1) | keyid (as_public, 65) | ciphertext | tag (16)
        $rs        = pack( 'N', 4096 );
        $idlen     = chr( strlen( $as_public ) );

        return $salt . $rs . $idlen . $as_public . $ciphertext . $tag;
    }

    /**
     * HKDF-Expand (RFC 5869).
     */
    private static function hkdf_expand( $prk, $info, $length ) {
        $t       = '';
        $output  = '';
        $counter = 1;

        while ( strlen( $output ) < $length ) {
            $t       = hash_hmac( 'sha256', $t . $info . chr( $counter ), $prk, true );
            $output .= $t;
            $counter++;
        }

        return substr( $output, 0, $length );
    }

    /**
     * Convert an EC public key (raw uncompressed point) to PEM.
     */
    private static function pem_from_public( $raw ) {
        // DER-encode SubjectPublicKeyInfo for prime256v1.
        $prefix = hex2bin( '3059301306072a8648ce3d020106082a8648ce3d030107034200' );
        $der    = $prefix . $raw;
        return "-----BEGIN PUBLIC KEY-----\n" .
            chunk_split( base64_encode( $der ), 64, "\n" ) .
            "-----END PUBLIC KEY-----\n";
    }

    /**
     * Convert DER ECDSA signature to JOSE raw (R || S).
     */
    private static function der_to_jose( $der ) {
        $pos = 0;
        if ( ord( $der[ $pos++ ] ) !== 0x30 ) {
            return $der;
        }
        $pos++; // Skip length.
        if ( ord( $der[ $pos++ ] ) !== 0x02 ) {
            return $der;
        }
        $r_len = ord( $der[ $pos++ ] );
        $r     = substr( $der, $pos, $r_len );
        $pos  += $r_len;
        if ( ord( $der[ $pos++ ] ) !== 0x02 ) {
            return $der;
        }
        $s_len = ord( $der[ $pos++ ] );
        $s     = substr( $der, $pos, $s_len );

        $r = ltrim( $r, "\x00" );
        $s = ltrim( $s, "\x00" );
        $r = str_pad( $r, 32, "\x00", STR_PAD_LEFT );
        $s = str_pad( $s, 32, "\x00", STR_PAD_LEFT );

        return $r . $s;
    }

    private static function base64url_encode( $data ) {
        return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
    }

    private static function base64url_decode( $data ) {
        $pad = strlen( $data ) % 4;
        if ( $pad ) {
            $data .= str_repeat( '=', 4 - $pad );
        }
        return base64_decode( strtr( $data, '-_', '+/' ) );
    }

    /**
     * Send a test notification to the current user.
     */
    public static function test_current_user() {
        return self::send_to_user( get_current_user_id(), array(
            'title' => 'Big Drop Test',
            'body'  => 'Push notifications are working!',
            'url'   => admin_url( 'admin.php?page=bd-portal' ),
        ) );
    }
}