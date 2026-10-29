<?php
/**
 * Standalone unit tests (no WordPress): `php tests/unit.php`.
 * Covers webhook signature verification and the order mapping rules.
 */

define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../includes/class-fetch-wc-order-mapper.php';
require __DIR__ . '/../includes/class-fetch-wc-webhook.php';

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

echo "$passes passed, $failures failed\n";
exit( $failures ? 1 : 0 );
