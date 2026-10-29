<?php
/**
 * Receives Fetch order webhooks at /wp-json/fetch/v1/webhook.
 *
 * Fetch signs every delivery: `X-Fetch-Signature: t=<unix seconds>,v1=<hex>`
 * where v1 = HMAC-SHA256(secret, "<t>.<raw body>"). Deliveries older than five
 * minutes are refused so a captured request cannot be replayed.
 */

defined( 'ABSPATH' ) || exit;

class Fetch_WC_Webhook {

	const NAMESPACE_ROUTE = 'fetch/v1';
	const ROUTE           = '/webhook';
	const TOLERANCE       = 300;
	const SEEN_OPTION     = 'fetch_wc_seen_events';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
	}

	public static function url() {
		return rest_url( self::NAMESPACE_ROUTE . self::ROUTE );
	}

	public static function register_route() {
		register_rest_route(
			self::NAMESPACE_ROUTE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				// Authenticated by the HMAC signature, not by a WordPress login.
				'permission_callback' => '__return_true',
			)
		);
	}

	public static function handle( WP_REST_Request $request ) {
		$secret = (string) Fetch_WC_Settings::get( 'webhook_secret' );
		$raw    = $request->get_body();
		if ( '' === $secret || ! self::verify( (string) $request->get_header( 'x_fetch_signature' ), $raw, $secret, time() ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid signature' ), 401 );
		}

		$payload = json_decode( $raw, true );
		if ( ! is_array( $payload ) || empty( $payload['event'] ) || empty( $payload['data'] ) || ! is_array( $payload['data'] ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid payload' ), 400 );
		}

		$event_id = isset( $payload['eventId'] ) ? (string) $payload['eventId'] : '';
		if ( '' !== $event_id && self::seen( $event_id ) ) {
			return new WP_REST_Response( array( 'ok' => true, 'duplicate' => true ), 200 );
		}

		if ( 0 === strpos( (string) $payload['event'], 'order.' ) && Fetch_WC_Settings::enabled( 'import_orders' ) ) {
			Fetch_WC_Order_Sync::import( $payload['data'] );
		}
		if ( '' !== $event_id ) {
			self::remember( $event_id );
		}
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/** Constant-time check of a `t=…,v1=…` header against the raw body. */
	public static function verify( $header, $raw_body, $secret, $now ) {
		$timestamp  = null;
		$signatures = array();
		foreach ( explode( ',', $header ) as $part ) {
			$pair = explode( '=', trim( $part ), 2 );
			if ( 2 !== count( $pair ) ) {
				continue;
			}
			if ( 't' === $pair[0] ) {
				$timestamp = (int) $pair[1];
			} elseif ( 'v1' === $pair[0] ) {
				$signatures[] = $pair[1];
			}
		}
		if ( ! $timestamp || ! $signatures || abs( $now - $timestamp ) > self::TOLERANCE ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $timestamp . '.' . $raw_body, $secret );
		foreach ( $signatures as $signature ) {
			if ( hash_equals( $expected, $signature ) ) {
				return true;
			}
		}
		return false;
	}

	private static function seen( $event_id ) {
		$seen = get_option( self::SEEN_OPTION, array() );
		return is_array( $seen ) && isset( $seen[ $event_id ] );
	}

	/** Keep the last 500 event ids; Fetch retries reuse the same id. */
	private static function remember( $event_id ) {
		$seen = get_option( self::SEEN_OPTION, array() );
		if ( ! is_array( $seen ) ) {
			$seen = array();
		}
		$seen[ $event_id ] = time();
		if ( count( $seen ) > 500 ) {
			asort( $seen );
			$seen = array_slice( $seen, -500, null, true );
		}
		update_option( self::SEEN_OPTION, $seen, false );
	}
}
