<?php
/**
 * Standalone unit tests (no WordPress): `php tests/unit.php`.
 * Covers webhook signature verification, the order mapping rules, the
 * price markup maths, the server setting, request batching, the full
 * deactivation row, masked customer details and the per-order import lock.
 */

define( 'ABSPATH', __DIR__ . '/' );

// Just enough of WordPress for Fetch_WC_Settings to read a saved option.
$GLOBALS['fake_options'] = array();
function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['fake_options'] ) ? $GLOBALS['fake_options'][ $name ] : $default;
}
function untrailingslashit( $value ) {
	return rtrim( $value, '/\\' );
}
function wp_json_encode( $data ) {
	return json_encode( $data );
}
function wp_strip_all_tags( $text ) {
	return trim( strip_tags( (string) $text ) );
}
function taxonomy_exists( $taxonomy ) {
	return false;
}
function wp_get_post_terms( $id, $taxonomy, $args = array() ) {
	return array();
}
function is_wp_error( $thing ) {
	return false;
}
function wc_get_price_including_tax( $product, $args ) {
	return $args['price'] * 1.19; // 19% VAT, prices entered without tax.
}
function wp_get_attachment_image_url( $id, $size ) {
	return false;
}
define( 'DAY_IN_SECONDS', 86400 );

/** A simple product, just the getters the mapper reads. */
class WC_Product {
	public $data;
	public function __construct( array $data ) {
		$this->data = $data + array( 'id' => 1, 'status' => 'publish', 'sku' => '', 'meta' => array(), 'sale' => '' );
	}
	public function is_type( $type ) { return 'simple' === $type; }
	public function get_id() { return $this->data['id']; }
	public function get_name() { return $this->data['name']; }
	public function get_sku( $context = 'view' ) { return $this->data['sku']; }
	public function get_meta( $key ) { return isset( $this->data['meta'][ $key ] ) ? $this->data['meta'][ $key ] : ''; }
	public function get_status() { return $this->data['status']; }
	public function get_catalog_visibility() { return 'visible'; }
	public function get_description() { return 'Braided cable'; }
	public function get_short_description() { return ''; }
	public function get_attribute( $name ) { return ''; }
	public function get_category_ids() { return array(); }
	public function get_regular_price( $context = 'view' ) { return $this->data['price']; }
	public function get_sale_price( $context = 'view' ) { return $this->data['sale']; }
	public function is_on_sale( $context = 'view' ) { return '' !== $this->data['sale']; }
	public function managing_stock() { return true; }
	public function get_stock_quantity() { return $this->data['stock']; }
	public function get_stock_status() { return 'instock'; }
	public function get_image_id() { return 0; }
	public function get_gallery_image_ids() { return array(); }
}

/** $wpdb on an in-memory SQLite options table with option_name UNIQUE, like WordPress. */
class Fake_WPDB {
	public $options       = 'wp_options';
	public $rows_affected = 0;
	public $last_error    = '';
	public $pdo;
	public function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' );
		$this->pdo->exec( 'CREATE TABLE wp_options (option_id INTEGER PRIMARY KEY, option_name TEXT NOT NULL UNIQUE, option_value TEXT, autoload TEXT)' );
	}
	public function prepare( $query, ...$args ) {
		$i = 0;
		return preg_replace_callback(
			'/%[sd]/',
			function ( $m ) use ( &$i, $args ) {
				$value = $args[ $i++ ];
				return '%d' === $m[0] ? (string) (int) $value : $this->pdo->quote( (string) $value );
			},
			$query
		);
	}
	public function query( $query ) {
		$n = $this->pdo->exec( str_replace( 'INSERT IGNORE', 'INSERT OR IGNORE', $query ) );
		if ( false === $n ) {
			return false;
		}
		$this->rows_affected = $n;
		return $n;
	}
	public function get_var( $query ) {
		$value = $this->pdo->query( $query )->fetchColumn();
		return false === $value ? null : $value;
	}
}
$GLOBALS['wpdb'] = new Fake_WPDB();

require __DIR__ . '/../includes/class-fetch-wc-order-mapper.php';
require __DIR__ . '/../includes/class-fetch-wc-webhook.php';
require __DIR__ . '/../includes/class-fetch-wc-settings.php';
require __DIR__ . '/../includes/class-fetch-wc-product-mapper.php';
require __DIR__ . '/../includes/class-fetch-wc-product-sync.php';
require __DIR__ . '/../includes/class-fetch-wc-order-sync.php';

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

// --- Server setting -------------------------------------------------------------
$server = function ( $saved ) {
	$GLOBALS['fake_options'] = null === $saved ? array() : array( Fetch_WC_Settings::OPTION => $saved );
	return Fetch_WC_Settings::base_url();
};
check( 'server: nothing saved → production', $server( null ), 'https://api.fetch.com.cy' );
check( 'server: saved before the setting existed → production', $server( array( 'client_id' => 'x' ) ), 'https://api.fetch.com.cy' );
check( 'server: existing "production" unchanged', $server( array( 'environment' => 'production', 'custom_base_url' => 'https://ignored.example' ) ), 'https://api.fetch.com.cy' );
check( 'server: staging', $server( array( 'environment' => 'staging' ) ), 'https://api-stg.fetch.com.cy' );
check( 'server: existing "custom" unchanged, trailing slash dropped', $server( array( 'environment' => 'custom', 'custom_base_url' => 'https://partner.example/' ) ), 'https://partner.example' );
check( 'server: unknown value → production', $server( array( 'environment' => 'sandbox' ) ), 'https://api.fetch.com.cy' );
check( 'sanitize environment production', Fetch_WC_Settings::sanitize_environment( 'production' ), 'production' );
check( 'sanitize environment staging', Fetch_WC_Settings::sanitize_environment( 'staging' ), 'staging' );
check( 'sanitize environment custom', Fetch_WC_Settings::sanitize_environment( 'custom' ), 'custom' );
check( 'sanitize environment garbage', Fetch_WC_Settings::sanitize_environment( 'sandbox' ), 'production' );
check( 'sanitize environment blank', Fetch_WC_Settings::sanitize_environment( '' ), 'production' );
$GLOBALS['fake_options'] = array();

// --- Request batching (live: 200 products, 500 KB per PUT) ------------------------
$items = array();
for ( $i = 0; $i < 450; $i++ ) {
	$items[] = array( 'ref' => 'r' . $i, 'name' => 'n', 'price' => '1.00' );
}
check( '450 rows → batches of 200, 200, 50', array_map( 'count', Fetch_WC_Product_Sync::batches( $items ) ), array( 200, 200, 50 ) );
check( 'exactly 200 rows → one batch', array_map( 'count', Fetch_WC_Product_Sync::batches( array_slice( $items, 0, 200 ) ) ), array( 200 ) );
check( '201 rows → 200 + 1', array_map( 'count', Fetch_WC_Product_Sync::batches( array_slice( $items, 0, 201 ) ) ), array( 200, 1 ) );
check( 'batching keeps every row in order', array_merge( ...Fetch_WC_Product_Sync::batches( $items ) ), $items );
$big = array();
for ( $i = 0; $i < 150; $i++ ) {
	$big[] = array( 'ref' => 'b' . $i, 'name' => 'n', 'price' => '1.00', 'description' => str_repeat( 'x', 5000 ) );
}
$sizes = array_map(
	function ( $batch ) {
		return strlen( json_encode( array( 'products' => $batch ) ) );
	},
	Fetch_WC_Product_Sync::batches( $big )
);
check( '150 rows of 5 KB → several requests, each under 500 KB', count( $sizes ) > 1 && max( $sizes ) < 500 * 1024, true );
check( 'one oversized row still goes, alone', array_map( 'count', Fetch_WC_Product_Sync::batches( array( array( 'ref' => 'huge', 'description' => str_repeat( 'x', 600000 ) ), array( 'ref' => 'small' ) ) ) ), array( 1, 1 ) );
check( 'no rows → no batches', Fetch_WC_Product_Sync::batches( array() ), array() );

// --- Deleting a product sends the full row, switched off -----------------------------
$cable = new WC_Product( array( 'id' => 7, 'name' => 'USB-C Cable', 'sku' => 'CBL-001', 'price' => '10', 'sale' => '8', 'stock' => 5, 'meta' => array( '_fetch_ref' => 'CBL-OLD' ) ) );
$rows  = Fetch_WC_Product_Mapper::deactivations( $cable );
$row   = $rows[0];
check( 'deactivation: one row for a simple product', count( $rows ), 1 );
check( 'deactivation: isActive false', $row['isActive'], false );
check( 'deactivation: frozen ref', $row['ref'], 'CBL-OLD' );
check( 'deactivation: price included (live requires it)', $row['price'], '11.90' );
check( 'deactivation: sale price and stock kept', array( $row['salePrice'], $row['stockCount'], $row['name'], $row['productType'] ), array( '9.52', 5, 'USB-C Cable', 'SIMPLE' ) );
$live = Fetch_WC_Product_Mapper::to_fetch_items( $cable );
unset( $live[0][0]['isActive'], $row['isActive'] );
check( 'deactivation row equals the normal row apart from isActive', $row, $live[0][0] );
check( 'normal sync of a published product stays active', Fetch_WC_Product_Mapper::to_fetch_items( $cable )[0][0]['isActive'], true );

// --- Masked customer details -----------------------------------------------------------
$real   = array( 'customer' => array( 'name' => 'Maria Georgiou', 'phone' => '+35799123456', 'address' => array( 'line1' => 'Makariou 12', 'city' => 'Nicosia', 'postalCode' => '1065', 'notes' => 'Ring twice' ) ) );
$masked = array( 'customer' => array( 'name' => '******', 'phone' => '******', 'address' => array( 'line1' => '******', 'city' => '******', 'postalCode' => '******', 'notes' => '******' ) ) );
check( 'real details are not masked', Fetch_WC_Order_Mapper::is_masked( $real ), false );
check( 'masked details detected', Fetch_WC_Order_Mapper::is_masked( $masked ), true );
check( 'only the phone masked counts as masked', Fetch_WC_Order_Mapper::is_masked( array( 'customer' => array( 'name' => 'A B', 'phone' => '******' ) ) ), true );
check( 'masked initials are not the mask', Fetch_WC_Order_Mapper::is_masked( array( 'customer' => array( 'name' => 'M. G.', 'phone' => '+357' ) ) ), false );
check(
	'masked address never stores "******"',
	Fetch_WC_Order_Mapper::address( $masked ),
	array( 'first_name' => 'Fetch', 'last_name' => 'customer', 'address_1' => '', 'city' => '', 'postcode' => '', 'country' => 'CY', 'phone' => '' )
);
check( 'masked note → empty', Fetch_WC_Order_Mapper::customer_note( $masked ), '' );
check( 'masked order + real copy → fill in', Fetch_WC_Order_Mapper::customer_update( true, $real ), Fetch_WC_Order_Mapper::address( $real ) );
check( 'masked order + masked copy → leave alone', Fetch_WC_Order_Mapper::customer_update( true, $masked ), null );
check( 'good order + masked copy → never overwritten', Fetch_WC_Order_Mapper::customer_update( false, $masked ), null );
check( 'good order + real copy → untouched', Fetch_WC_Order_Mapper::customer_update( false, $real ), null );

// --- Per-order import lock (webhook + poll race) ---------------------------------------
$now   = 1793260000;
$first = Fetch_WC_Order_Sync::lock( 'ord-1', $now );
check( 'lock: first claim wins', is_string( $first ), true );
check( 'lock: a second claim on the same order fails', Fetch_WC_Order_Sync::lock( 'ord-1', $now + 1 ), null );
check( 'lock: another order is independent', is_string( Fetch_WC_Order_Sync::lock( 'ord-2', $now ) ), true );
Fetch_WC_Order_Sync::unlock( 'ord-1', 'someone-else' );
check( 'lock: unlock with a foreign token keeps it', Fetch_WC_Order_Sync::lock( 'ord-1', $now + 2 ), null );
Fetch_WC_Order_Sync::unlock( 'ord-1', $first );
$again = Fetch_WC_Order_Sync::lock( 'ord-1', $now + 3 );
check( 'lock: released → can be claimed again', is_string( $again ), true );
check( 'lock: still held 299 s later', Fetch_WC_Order_Sync::lock( 'ord-1', $now + 3 + 299 ), null );
$taken = Fetch_WC_Order_Sync::lock( 'ord-1', $now + 3 + 300 );
check( 'lock: a stale lock (300 s) is taken over', is_string( $taken ) && $taken !== $again, true );
Fetch_WC_Order_Sync::unlock( 'ord-1', $again );
check( 'lock: the dead request\'s late unlock does not free the new holder', Fetch_WC_Order_Sync::lock( 'ord-1', $now + 3 + 301 ), null );
check( 'lock: one row per order in the options table', (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM wp_options WHERE option_name LIKE 'fetch_wc_lock_%'" ), 2 );

echo "$passes passed, $failures failed\n";
exit( $failures ? 1 : 0 );
