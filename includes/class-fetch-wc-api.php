<?php
/**
 * Fetch Partner API client: OAuth client-credentials token (cached), JSON
 * requests, one transparent retry on an expired token.
 */

defined( 'ABSPATH' ) || exit;

class Fetch_WC_Api {

	const TOKEN_TRANSIENT = 'fetch_wc_access_token';

	/** @var string */
	private $base_url;
	/** @var string */
	private $client_id;
	/** @var string */
	private $client_secret;

	public function __construct( $base_url = null, $client_id = null, $client_secret = null ) {
		$this->base_url      = untrailingslashit( null !== $base_url ? $base_url : Fetch_WC_Settings::base_url() );
		$this->client_id     = null !== $client_id ? $client_id : (string) Fetch_WC_Settings::get( 'client_id' );
		$this->client_secret = null !== $client_secret ? $client_secret : (string) Fetch_WC_Settings::get( 'client_secret' );
	}

	public static function forget_token() {
		delete_transient( self::TOKEN_TRANSIENT );
	}

	/** Partner path, e.g. "stores" → /api/v1/partner/stores. */
	public function partner( $method, $path, $body = null, array $query = array(), $idempotency_key = null ) {
		return $this->request( $method, '/api/v1/partner/' . ltrim( $path, '/' ), $body, $query, $idempotency_key );
	}

	public function list_stores() {
		return $this->partner( 'GET', 'stores' );
	}

	public function upsert_products( $store_id, array $products, $idempotency_key = null ) {
		return $this->partner( 'PUT', 'stores/' . rawurlencode( $store_id ) . '/products', array( 'products' => $products ), array(), $idempotency_key );
	}

	public function list_orders( $store_id, array $query = array() ) {
		return $this->partner( 'GET', 'stores/' . rawurlencode( $store_id ) . '/orders', null, $query );
	}

	public function get_order( $store_id, $order_id ) {
		return $this->partner( 'GET', 'stores/' . rawurlencode( $store_id ) . '/orders/' . rawurlencode( $order_id ) );
	}

	public function accept_order( $store_id, $order_id, $prep_minutes ) {
		return $this->partner( 'POST', 'stores/' . rawurlencode( $store_id ) . '/orders/' . rawurlencode( $order_id ) . '/accept', array( 'prepMinutes' => (int) $prep_minutes ) );
	}

	public function ready_order( $store_id, $order_id ) {
		return $this->partner( 'POST', 'stores/' . rawurlencode( $store_id ) . '/orders/' . rawurlencode( $order_id ) . '/ready', new stdClass() );
	}

	public function reject_order( $store_id, $order_id, $reason, $note = '' ) {
		$body = array( 'reason' => $reason );
		if ( '' !== $note ) {
			$body['note'] = $note;
		}
		return $this->partner( 'POST', 'stores/' . rawurlencode( $store_id ) . '/orders/' . rawurlencode( $order_id ) . '/reject', $body );
	}

	public function register_webhook( $url ) {
		return $this->partner( 'PUT', 'webhook', array( 'url' => $url, 'rotateSecret' => '1' ) );
	}

	/**
	 * @return array{code:int, body:mixed}|WP_Error
	 */
	public function request( $method, $path, $body = null, array $query = array(), $idempotency_key = null, $retried = false ) {
		$token = $this->token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$url = $this->base_url . $path;
		if ( $query ) {
			$url = add_query_arg( array_map( 'rawurlencode', $query ), $url );
		}

		$headers = array(
			'Authorization' => 'Bearer ' . $token,
			'Accept'        => 'application/json',
			'User-Agent'    => 'FetchForWooCommerce/' . FETCH_WC_VERSION . ' (+' . home_url() . ')',
		);
		$args    = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => $headers,
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}
		if ( $idempotency_key ) {
			$args['headers']['Idempotency-Key'] = $idempotency_key;
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 401 === $code && ! $retried ) {
			self::forget_token();
			return $this->request( $method, $path, $body, $query, $idempotency_key, true );
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		return array(
			'code' => $code,
			'body' => $decoded,
		);
	}

	/** Human-readable error from a non-2xx response (Nest error shape). */
	public static function error_message( $result ) {
		if ( is_wp_error( $result ) ) {
			return $result->get_error_message();
		}
		$body    = isset( $result['body'] ) ? $result['body'] : null;
		$message = is_array( $body ) && isset( $body['message'] ) ? $body['message'] : null;
		if ( is_array( $message ) ) {
			$message = implode( '; ', $message );
		}
		return sprintf( 'HTTP %d%s', isset( $result['code'] ) ? $result['code'] : 0, $message ? ': ' . $message : '' );
	}

	public static function ok( $result ) {
		return ! is_wp_error( $result ) && $result['code'] >= 200 && $result['code'] < 300;
	}

	/** @return string|WP_Error */
	private function token() {
		$cached = get_transient( self::TOKEN_TRANSIENT );
		if ( is_array( $cached ) && isset( $cached['token'], $cached['key'] ) && $cached['key'] === $this->cache_key() ) {
			return $cached['token'];
		}
		if ( '' === $this->client_id || '' === $this->client_secret ) {
			return new WP_Error( 'fetch_not_configured', __( 'Enter your Fetch client ID and secret first.', 'fetch-for-woocommerce' ) );
		}

		$response = wp_remote_post(
			$this->base_url . '/api/v1/oauth/token',
			array(
				'timeout' => 20,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'grant_type'    => 'client_credentials',
						'client_id'     => $this->client_id,
						'client_secret' => $this->client_secret,
					)
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || empty( $body['access_token'] ) ) {
			return new WP_Error( 'fetch_auth_failed', self::error_message( array( 'code' => $code, 'body' => $body ) ) );
		}

		$ttl = isset( $body['expires_in'] ) ? max( 60, (int) $body['expires_in'] - 60 ) : 3000;
		set_transient(
			self::TOKEN_TRANSIENT,
			array(
				'token' => $body['access_token'],
				'key'   => $this->cache_key(),
			),
			$ttl
		);
		return $body['access_token'];
	}

	/** A token belongs to one credential on one server. */
	private function cache_key() {
		return md5( $this->base_url . '|' . $this->client_id );
	}
}
