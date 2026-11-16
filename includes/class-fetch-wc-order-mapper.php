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

	/** Fetch masks the customer's name, phone and address 2 hours after the order. */
	const MASK = '******';

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

	/** True when Fetch sent any of the customer's name, phone or street masked. */
	public static function is_masked( array $order ) {
		$customer = isset( $order['customer'] ) && is_array( $order['customer'] ) ? $order['customer'] : array();
		$address  = isset( $customer['address'] ) && is_array( $customer['address'] ) ? $customer['address'] : array();
		foreach ( array( 'name' => $customer, 'phone' => $customer, 'line1' => $address ) as $key => $source ) {
			if ( isset( $source[ $key ] ) && self::MASK === trim( (string) $source[ $key ] ) ) {
				return true;
			}
		}
		return false;
	}

	/** Address fields shared by billing and shipping; masked values become empty. */
	public static function address( array $order ) {
		$customer = isset( $order['customer'] ) && is_array( $order['customer'] ) ? $order['customer'] : array();
		$address  = isset( $customer['address'] ) && is_array( $customer['address'] ) ? $customer['address'] : array();
		list( $first, $last ) = self::split_name( self::unmask( isset( $customer['name'] ) ? $customer['name'] : '' ) );

		return array(
			'first_name' => $first,
			'last_name'  => $last,
			'address_1'  => self::unmask( isset( $address['line1'] ) ? $address['line1'] : '' ),
			'city'       => self::unmask( isset( $address['city'] ) ? $address['city'] : '' ),
			'postcode'   => self::unmask( isset( $address['postalCode'] ) ? $address['postalCode'] : '' ),
			'country'    => 'CY',
			'phone'      => self::unmask( isset( $customer['phone'] ) ? $customer['phone'] : '' ),
		);
	}

	/**
	 * The address to write over an imported order's, or null to leave it alone:
	 * only an order first imported masked is completed, and only from an
	 * unmasked copy, so good details are never replaced by masked ones.
	 */
	public static function customer_update( $stored_masked, array $order ) {
		if ( ! $stored_masked || self::is_masked( $order ) ) {
			return null;
		}
		return self::address( $order );
	}

	public static function customer_note( array $order ) {
		$note = isset( $order['customer']['address']['notes'] ) ? (string) $order['customer']['address']['notes'] : '';
		return self::unmask( $note );
	}

	public static function is_pickup( array $order ) {
		return isset( $order['deliveryType'] ) && 'pickup' === $order['deliveryType'];
	}

	private static function unmask( $value ) {
		$value = trim( (string) $value );
		return self::MASK === $value ? '' : $value;
	}
}
