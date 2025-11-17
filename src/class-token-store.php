<?php
if (!defined('ABSPATH')) exit;

class PBSR_Token_Store {

	public static function get() {
		return get_option( 'pbsr_access_token', '' );
	}

	public static function expired() {
		$expiry = (int) get_option( 'pbsr_token_expiry', 0 );
		return ( time() >= $expiry );
	}

	public static function set( $token, $expiry ) {
		// 🧩 Safety check – prevent saving invalid/empty tokens
		if ( empty( $token ) || strlen( $token ) < 20 ) {
			error_log( '[PBSR] Token rejected (too short or empty)' );
			return;
		}
		update_option( 'pbsr_access_token', $token );
		update_option( 'pbsr_token_expiry', $expiry );
	}
}
