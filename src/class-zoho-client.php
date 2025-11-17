<?php

if ( ! defined( 'ABSPATH' ) ) exit;



class PBSR_Zoho_Client {



	private $settings;



	public function __construct() {

		$this->settings = PBSR_Settings::get();

	}



	/** --------------------------------------------------

	 *  TOKEN MANAGEMENT

	 *  -------------------------------------------------- */

	private function tokenEndpoint() {

    $dc = isset( $this->settings['zoho_dc'] ) ? $this->settings['zoho_dc'] : 'eu';

    return "https://accounts.zoho.{$dc}/oauth/v2/token";

}





	private function ensureAccessToken() {

		$token = PBSR_Token_Store::get();

		//$token = null;

		if ( $token && ! PBSR_Token_Store::expired() ) {

			return $token;

		}



		$args = [

			'body' => [

				'grant_type'    => 'refresh_token',

				'refresh_token' => $this->settings['refresh_token'],

				'client_id'     => $this->settings['client_id'],

				'client_secret' => $this->settings['client_secret'],

			],

			'timeout' => 20,

		];



		$response = wp_remote_post( $this->tokenEndpoint(), $args );



		if ( is_wp_error( $response ) ) {

			throw new Exception( 'Token refresh failed: ' . $response->get_error_message() );

		}



		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['access_token'] ) ) {

			throw new Exception( 'Invalid token refresh response: ' . wp_remote_retrieve_body( $response ) );

		}



		PBSR_Token_Store::set( $body['access_token'], time() + (int) $body['expires_in'] );

		return $body['access_token'];

	}



	/** --------------------------------------------------

	 *  GENERIC REQUEST HANDLERS

	 *  -------------------------------------------------- */

	public function get( $url ) {

		$token = $this->ensureAccessToken();

		$args  = [

			'headers' => [

				'Authorization' => 'Zoho-oauthtoken ' . $token,

				'Content-Type'  => 'application/json',

			],

			'timeout' => 30,

		];

		$res = wp_remote_get( $url, $args );

		return $this->handleResponse( $res );

	}



	public function post($url, $body) {
    $token = $this->ensureAccessToken();
    $args = [
        'headers' => [
            'Authorization' => 'Zoho-oauthtoken ' . $token,
            'Content-Type'  => 'application/json',
        ],
        'body'    => wp_json_encode($body),
        'timeout' => 30,
        'method'  => 'POST',
    ];

    $res = wp_remote_post($url, $args);

    if (is_wp_error($res)) {
        error_log('Zoho POST ERROR: ' . $res->get_error_message());
        $more = $res->get_error_data();
        if ($more) error_log('Zoho POST ERROR DATA: ' . print_r($more, true));
        return ['code' => 0, 'body' => null];
    }

    $code = wp_remote_retrieve_response_code($res);
    $body = wp_remote_retrieve_body($res);
    error_log("Zoho POST RESPONSE code={$code} body=" . substr($body, 0, 500)); // limit for readability

    return ['code' => $code, 'body' => json_decode($body, true)];
}




	private function handleResponse( $res ) {

		if ( is_wp_error( $res ) ) {

			throw new Exception( 'HTTP request failed: ' . $res->get_error_message() );

		}

		$code = wp_remote_retrieve_response_code( $res );

		$body = json_decode( wp_remote_retrieve_body( $res ), true );

		return [ 'code' => $code, 'body' => $body ];

	}



	/** --------------------------------------------------

	 *  CRM + BOOKS HELPERS

	 *  -------------------------------------------------- */

	private function crmBase() {

		$dc = isset( $this->settings['zoho_dc'] ) ? $this->settings['zoho_dc'] : 'eu';

		return "https://www.zohoapis.{$dc}/crm/v2";

	}



	private function booksBase() {
    $dc = $this->settings['zoho_dc'] ?? 'eu';
    return "https://www.zohoapis.{$dc}/books/v3";
}




	public function crm_get( $endpoint ) {

		return $this->get( $this->crmBase() . $endpoint );

	}



	public function books_get( $endpoint ) {

		return $this->get( $this->booksBase() . $endpoint );

	}



	public function crm_post( $endpoint, $body ) {

		return $this->post( $this->crmBase() . $endpoint, $body );

	}



public function books_post($endpoint, $body) {
    $url = rtrim($this->booksBase(), '/') . '/' . ltrim($endpoint, '/');
    error_log('BOOKS POST URL: ' . $url); // 👈 add this line
    return $this->post($url, $body);
}



}

