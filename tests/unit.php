<?php
/**
 * Standalone unit tests (no WordPress): `php tests/unit.php`.
 * Covers webhook signature verification, the order mapping rules and the
 * price markup maths.
 */

define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../includes/class-fetch-wc-order-mapper.php';
require __DIR__ . '/../includes/class-fetch-wc-webhook.php';
require __DIR__ . '/../includes/class-fetch-wc-settings.php';
require __DIR__ . '/../includes/class-fetch-wc-product-mapper.php';

$failures = 0;
$passes   = 0;
function check( $label, $actual, $expected ) {
	global $failures, $passes;
	if ( $actual === $expected ) {
		++$passes;
		return;
	}
	++$failures;
	echo "FAIL: $label\n  expected: " . var_export( $expected, true ) . "\n  actual:   " . var_export( $actual, true ) . "\n";
}

// --- Webhook signature -------------------------------------------------------
$secret = 'whsec_test';
$body   = '{"event":"order.created","eventId":"evt_1","data":{"orderId":"o1"}}';
$now    = 1793260000;
$sign   = function ( $t, $b, $s ) {
	return 't=' . $t . ',v1=' . hash_hmac( 'sha256', $t . '.' . $b, $s );
};
check( 'valid signature', Fetch_WC_Webhook::verify( $sign( $now, $body, $secret ), $body, $secret, $now ), true );
check( 'valid within tolerance', Fetch_WC_Webhook::verify( $sign( $now - 299, $body, $secret ), $body, $secret, $now ), true );
check( 'tampered body', Fetch_WC_Webhook::verify( $sign( $now, $body, $secret ), $body . ' ', $secret, $now ), false );
check( 'wrong secret', Fetch_WC_Webhook::verify( $sign( $now, $body, 'other' ), $body, $secret, $now ), false );
check( 'stale timestamp', Fetch_WC_Webhook::verify( $sign( $now - 301, $body, $secret ), $body, $secret, $now ), false );
check( 'future timestamp beyond tolerance', Fetch_WC_Webhook::verify( $sign( $now + 301, $body, $secret ), $body, $secret, $now ), false );
check( 'missing header', Fetch_WC_Webhook::verify( '', $body, $secret, $now ), false );
check( 'garbage header', Fetch_WC_Webhook::verify( 'nonsense', $body, $secret, $now ), false );
check( 'second v1 matches', Fetch_WC_Webhook::verify( 't=' . $now . ',v1=deadbeef,v1=' . hash_hmac( 'sha256', $now . '.' . $body, $secret ), $body, $secret, $now ), true );
check( 'timestamp swapped after signing', Fetch_WC_Webhook::verify( 't=' . ( $now + 1 ) . ',v1=' . hash_hmac( 'sha256', $now . '.' . $body, $secret ), $body, $secret, $now ), false );

// --- Status mapping ------------------------------------------------------------
$map = array(
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
foreach ( $map as $fetch => $wc ) {
	check( "wc_status($fetch)", Fetch_WC_Order_Mapper::wc_status( $fetch ), $wc );
}
check( 'unknown status falls back to on-hold', Fetch_WC_Order_Mapper::wc_status( 'WHATEVER' ), 'on-hold' );

// --- Allowed actions mirror the API's transition rules ------------------------
// accept: DRAFT · ready: ACCEPTED, PREPARING · reject: DRAFT, ACCEPTED
check( 'actions DRAFT', Fetch_WC_Order_Mapper::allowed_actions( 'DRAFT' ), array( 'accept', 'reject' ) );
check( 'actions ACCEPTED', Fetch_WC_Order_Mapper::allowed_actions( 'ACCEPTED' ), array( 'ready', 'reject' ) );
check( 'actions PREPARING', Fetch_WC_Order_Mapper::allowed_actions( 'PREPARING' ), array( 'ready' ) );
foreach ( array( 'READY_FOR_PICKUP', 'DISPATCHED', 'DELIVERED', 'CANCELLED', 'REJECTED', 'REFUNDED' ) as $st ) {
	check( "actions $st", Fetch_WC_Order_Mapper::allowed_actions( $st ), array() );
}

// --- Names and addresses -------------------------------------------------------
check( 'split two names', Fetch_WC_Order_Mapper::split_name( 'Maria Georgiou' ), array( 'Maria', 'Georgiou' ) );
check( 'split three names', Fetch_WC_Order_Mapper::split_name( '  Anna  Maria   Ioannou ' ), array( 'Anna', 'Maria Ioannou' ) );
check( 'split one name', Fetch_WC_Order_Mapper::split_name( 'Andreas' ), array( 'Andreas', '' ) );
check( 'split empty', Fetch_WC_Order_Mapper::split_name( '' ), array( 'Fetch', 'customer' ) );
check( 'split masked', Fetch_WC_Order_Mapper::split_name( 'M. G.' ), array( 'M.', 'G.' ) );

$order = array(
	'deliveryType' => 'delivery',
	'customer'     => array(
		'name'    => 'Maria Georgiou',
		'phone'   => '+35799123456',
		'address' => array(
			'line1'      => 'Makariou 12 Flat 3',
			'city'       => 'Nicosia',
			'postalCode' => '1065',
			'notes'      => 'Ring twice',
		),
	),
);
check(
	'address',
	Fetch_WC_Order_Mapper::address( $order ),
	array(
		'first_name' => 'Maria',
		'last_name'  => 'Georgiou',
		'address_1'  => 'Makariou 12 Flat 3',
		'city'       => 'Nicosia',
		'postcode'   => '1065',
		'country'    => 'CY',
		'phone'      => '+35799123456',
	)
);
check( 'customer note', Fetch_WC_Order_Mapper::customer_note( $order ), 'Ring twice' );
check( 'not pickup', Fetch_WC_Order_Mapper::is_pickup( $order ), false );
$pickup = array(
	'deliveryType' => 'pickup',
	'customer'     => array( 'name' => 'A B', 'phone' => '', 'address' => array( 'line1' => 'Pickup at store', 'city' => 'Larnaca', 'postalCode' => null, 'notes' => null ) ),
);
check( 'pickup', Fetch_WC_Order_Mapper::is_pickup( $pickup ), true );
check( 'pickup null postcode → empty string', Fetch_WC_Order_Mapper::address( $pickup )['postcode'], '' );
check( 'missing customer block', Fetch_WC_Order_Mapper::address( array() )['first_name'], 'Fetch' );

// --- Price markup --------------------------------------------------------------
check( 'markup 0% leaves the price alone', Fetch_WC_Product_Mapper::apply_markup( '10.00', 0 ), '10.00' );
check( 'markup 10% on 10.00', Fetch_WC_Product_Mapper::apply_markup( '10.00', 10 ), '11.00' );
check( 'markup 10% on 9.50', Fetch_WC_Product_Mapper::apply_markup( '9.50', 10 ), '10.45' );
check( 'markup 12.5% on 10.00', Fetch_WC_Product_Mapper::apply_markup( '10.00', 12.5 ), '11.25' );
check( 'markup 12.5% on 7.99 rounds half-up', Fetch_WC_Product_Mapper::apply_markup( '7.99', 12.5 ), '8.99' );
check( 'markup 15% on 9.99 (11.4885) rounds half-up', Fetch_WC_Product_Mapper::apply_markup( '9.99', 15 ), '11.49' );
check( 'markup 10% on 0.05 (0.055) rounds half-up', Fetch_WC_Product_Mapper::apply_markup( '0.05', 10 ), '0.06' );
check( 'markup 100% doubles', Fetch_WC_Product_Mapper::apply_markup( '19.99', 100 ), '39.98' );
check( 'markup on 0.00 stays 0.00', Fetch_WC_Product_Mapper::apply_markup( '0.00', 10 ), '0.00' );
check( 'markup returns a decimal string', is_string( Fetch_WC_Product_Mapper::apply_markup( '10.00', 10 ) ), true );

check( 'sanitize markup "10"', Fetch_WC_Settings::sanitize_markup( '10' ), 10.0 );
check( 'sanitize markup "12,5"', Fetch_WC_Settings::sanitize_markup( '12,5' ), 12.5 );
check( 'sanitize markup "15 %"', Fetch_WC_Settings::sanitize_markup( '15 %' ), 15.0 );
check( 'sanitize markup blank → 0', Fetch_WC_Settings::sanitize_markup( '' ), 0.0 );
check( 'sanitize markup garbage → 0', Fetch_WC_Settings::sanitize_markup( 'abc' ), 0.0 );
check( 'sanitize markup negative → 0', Fetch_WC_Settings::sanitize_markup( '-5' ), 0.0 );
check( 'sanitize markup above 100 → 100', Fetch_WC_Settings::sanitize_markup( '150' ), 100.0 );
check( 'sanitize markup keeps 2 decimals', Fetch_WC_Settings::sanitize_markup( '7.456' ), 7.46 );

echo "$passes passed, $failures failed\n";
exit( $failures ? 1 : 0 );
