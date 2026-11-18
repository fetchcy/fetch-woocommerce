<?php
/**
 * Receives Fetch order webhooks at /wp-json/fetch/v1/webhook.
 *
 * Fetch signs every delivery: `X-Fetch-Signature: t=<unix seconds>,v1=<hex>`
 * where v1 = HMAC-SHA256(secret, "<t>.<raw body>"). Deliveries older than five
 * minutes are refused so a captured request cannot be replayed.
 *
 * Fetch registers one webhook URL and secret per API key, and sends it the
 * orders of every store the key can access. Each delivery is routed by the
 * order's storeId; orders of a store not connected to this site are
 * acknowledged and ignored.
 */

defined( 'ABSPATH' ) || exit;

class Fetch_WC_Webhook {

	const NAMESPACE_ROUTE = 'fetch/v1';
	const ROUTE           = '/webhook';
	const TOLERANCE       = 300;
	const SEEN_OPTION     = 'fetch_wc_seen_events';
	/** Seconds a delivery waits for a poll that is importing the same order. */
	const LOCK_WAIT       = 5;

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

		$store_id = isset( $payload['data']['storeId'] ) ? (string) $payload['data']['storeId'] : '';
		$order_id = isset( $payload['data']['orderId'] ) ? (string) $payload['data']['orderId'] : '';
		if ( '' !== $store_id && ! Fetch_WC_Settings::store( $store_id ) && ( '' === $order_id || ! Fetch_WC_Order_Sync::find( $order_id ) ) ) {
			self::log_ignored( $store_id );
			if ( '' !== $event_id ) {
				self::remember( $event_id );
			}
			return new WP_REST_Response( array( 'ok' => true, 'ignored' => 'store not connected' ), 200 );
		}

		if ( 0 === strpos( (string) $payload['event'], 'order.' ) && Fetch_WC_Settings::enabled( 'import_orders' ) ) {
			// order.created, order.status_changed and order.cancelled all carry the
			// full order; import() creates it or brings status and customer up to date.
			if ( false === Fetch_WC_Order_Sync::import( $payload['data'], self::LOCK_WAIT ) ) {
				// The poll or another delivery is on this order: not remembered, so Fetch's retry is processed.
				return new WP_REST_Response( array( 'error' => 'busy, retry' ), 503 );
			}
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

	/** Once a day per store, so a busy store this site does not serve cannot flood the activity log. */
	private static function log_ignored( $store_id ) {
		$key = 'fetch_wc_ignored_' . md5( $store_id );
		if ( get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, DAY_IN_SECONDS );
		Fetch_WC_Logger::info( sprintf( 'Ignored Fetch orders from store %s: it is not connected to this site. Connect it under WooCommerce → Fetch to import them.', $store_id ) );
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
