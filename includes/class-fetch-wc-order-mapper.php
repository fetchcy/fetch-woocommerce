<?php
/**
 * Pure mapping rules between a Fetch order (Partner API shape) and WooCommerce.
 * Kept free of database access so it can be unit-tested on its own.
 */

defined( 'ABSPATH' ) || exit;

class Fetch_WC_Order_Mapper {

	/**
	 * Fetch slice status → WooCommerce order status.
	 * DRAFT is a new order waiting for the shop to accept it.
	 */
	const STATUS_MAP = array(
		'DRAFT'            => 'on-hold',
		'ACCEPTED'         => 'processing',
		'PREPARING'        => 'processing',
		'READY_FOR_PICKUP' => 'processing',
		'DISPATCHED'       => 'processing',
		'DELIVERED'        => 'completed',
		'CANCELLED'        => 'cancelled',
		'REJECTED'         => 'cancelled',
		'REFUNDED'         => 'refunded',
	);

	const STATUS_LABELS = array(
		'DRAFT'            => 'Waiting for you to accept',
		'ACCEPTED'         => 'Accepted',
		'PREPARING'        => 'Preparing',
		'READY_FOR_PICKUP' => 'Ready for pickup',
		'DISPATCHED'       => 'Out for delivery',
		'DELIVERED'        => 'Delivered',
		'CANCELLED'        => 'Cancelled',
		'REJECTED'         => 'Rejected',
		'REFUNDED'         => 'Refunded',
	);

	/** Statuses after which Fetch has released the stock again. */
	const RESTOCKED = array( 'CANCELLED', 'REJECTED', 'REFUNDED' );

	public static function wc_status( $fetch_status ) {
		return isset( self::STATUS_MAP[ $fetch_status ] ) ? self::STATUS_MAP[ $fetch_status ] : 'on-hold';
	}

	public static function label( $fetch_status ) {
		return isset( self::STATUS_LABELS[ $fetch_status ] ) ? self::STATUS_LABELS[ $fetch_status ] : (string) $fetch_status;
	}

	/** Which shop actions are allowed in a status (mirrors the API's own rules). */
	public static function allowed_actions( $fetch_status ) {
		$actions = array();
		if ( 'DRAFT' === $fetch_status ) {
			$actions[] = 'accept';
		}
		if ( in_array( $fetch_status, array( 'ACCEPTED', 'PREPARING' ), true ) ) {
			$actions[] = 'ready';
		}
		if ( in_array( $fetch_status, array( 'DRAFT', 'ACCEPTED' ), true ) ) {
			$actions[] = 'reject';
		}
		return $actions;
	}

	/** "Maria Georgiou" → ["Maria", "Georgiou"]. */
	public static function split_name( $name ) {
		$name = trim( preg_replace( '/\s+/u', ' ', (string) $name ) );
		if ( '' === $name ) {
			return array( 'Fetch', 'customer' );
		}
		$parts = explode( ' ', $name, 2 );
		return array( $parts[0], isset( $parts[1] ) ? $parts[1] : '' );
	}

	/** Address fields shared by billing and shipping. */
	public static function address( array $order ) {
		$customer = isset( $order['customer'] ) && is_array( $order['customer'] ) ? $order['customer'] : array();
		$address  = isset( $customer['address'] ) && is_array( $customer['address'] ) ? $customer['address'] : array();
		list( $first, $last ) = self::split_name( isset( $customer['name'] ) ? $customer['name'] : '' );

		return array(
			'first_name' => $first,
			'last_name'  => $last,
			'address_1'  => isset( $address['line1'] ) ? (string) $address['line1'] : '',
			'city'       => isset( $address['city'] ) ? (string) $address['city'] : '',
			'postcode'   => isset( $address['postalCode'] ) ? (string) $address['postalCode'] : '',
			'country'    => 'CY',
			'phone'      => isset( $customer['phone'] ) ? (string) $customer['phone'] : '',
		);
	}

	public static function customer_note( array $order ) {
		$note = isset( $order['customer']['address']['notes'] ) ? trim( (string) $order['customer']['address']['notes'] ) : '';
		return $note;
	}

	public static function is_pickup( array $order ) {
		return isset( $order['deliveryType'] ) && 'pickup' === $order['deliveryType'];
	}
}
