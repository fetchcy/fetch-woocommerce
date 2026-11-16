<?php
/**
 * Standalone unit tests (no WordPress): `php tests/unit.php`.
 * Covers webhook signature verification, the order mapping rules, the
 * price markup maths, the server setting, request batching, the full
 * deactivation row, masked customer details, the per-order import lock and
 * the "Mirror this shop 1:1" scan, plan, guard and switch-off batches.
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
class WP_Error {
	private $message;
	public function __construct( $code = '', $message = '' ) {
		$this->message = $message;
	}
	public function get_error_message() {
		return $this->message;
	}
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
function wp_generate_uuid4() {
	return bin2hex( random_bytes( 16 ) );
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
		$this->data = $data + array( 'id' => 1, 'status' => 'publish', 'sku' => '', 'meta' => array(), 'sale' => '', 'visibility' => 'visible' );
	}
	public function is_type( $type ) { return 'simple' === $type; }
	public function get_id() { return $this->data['id']; }
	public function get_name() { return $this->data['name']; }
	public function get_sku( $context = 'view' ) { return $this->data['sku']; }
	public function get_meta( $key ) { return isset( $this->data['meta'][ $key ] ) ? $this->data['meta'][ $key ] : ''; }
	public function get_status() { return $this->data['status']; }
	public function get_catalog_visibility() { return $this->data['visibility']; }
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
require __DIR__ . '/../includes/class-fetch-wc-api.php';
require __DIR__ . '/../includes/class-fetch-wc-mirror.php';

/** Partner API stand-in for the mirror: pages GET /listings, records listings/batch. */
class Fake_Listings_Api {
	public $listings;
	public $per_page;
	public $fail_page;
	public $batch_code = 200;
	public $reject     = array();
	public $queries    = array();
	public $batches    = array();
	public function __construct( array $listings, $per_page = 2, $fail_page = 0 ) {
		$this->listings  = $listings;
		$this->per_page  = $per_page;
		$this->fail_page = $fail_page;
	}
	public function list_listings( $store_id, array $query ) {
		$this->queries[] = $query;
		$page            = isset( $query['cursor'] ) ? (int) substr( $query['cursor'], 1 ) : 1;
		if ( $page === $this->fail_page ) {
			return array( 'code' => 500, 'body' => array( 'message' => 'Internal server error' ) );
		}
		$data = array_slice( $this->listings, ( $page - 1 ) * $this->per_page, $this->per_page );
		$more = $page * $this->per_page < count( $this->listings );
		return array( 'code' => 200, 'body' => array( 'data' => $data, 'nextCursor' => $more ? 'p' . ( $page + 1 ) : null ) );
	}
	public function batch_listings( $store_id, array $items, $key = null ) {
		$this->batches[] = array( 'items' => $items, 'key' => $key );
		if ( $this->batch_code >= 500 ) {
			return array( 'code' => $this->batch_code, 'body' => array( 'message' => 'Service unavailable' ) );
		}
		$results = array();
		foreach ( $items as $item ) {
			$results[] = array( 'listingId' => $item['listingId'], 'status' => in_array( $item['listingId'], $this->reject, true ) ? 'error' : 'updated' );
		}
		$rejected = count( array_filter( $results, function ( $r ) { return 'error' === $r['status']; } ) );
		$code     = $rejected === count( $items ) ? 422 : ( $rejected ? 207 : 200 );
		return array( 'code' => $code, 'body' => array( 'accepted' => count( $items ) - $rejected, 'rejected' => $rejected, 'results' => $results ) );
	}
	public function switched_off() {
		$ids = array();
		foreach ( $this->batches as $batch ) {
			foreach ( $batch['items'] as $item ) {
				$ids[] = $item['listingId'];
			}
		}
		return $ids;
	}
}
function listing( $id, $ref, $active = true, $price = '5.00' ) {
	return array( 'listingId' => $id, 'ref' => $ref, 'name' => 'Listing ' . $id, 'price' => $price, 'isActive' => $active );
}

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

// --- Mirror this shop 1:1 ------------------------------------------------------------
$GLOBALS['fake_options'] = array();
check( 'catalogue mode defaults to add and update only', array( Fetch_WC_Settings::get( 'catalogue_mode' ), Fetch_WC_Settings::mirror() ), array( 'add_update', false ) );
check( 'sanitize catalogue mode mirror', Fetch_WC_Settings::sanitize_catalogue_mode( 'mirror' ), 'mirror' );
check( 'sanitize catalogue mode garbage → add_update', Fetch_WC_Settings::sanitize_catalogue_mode( 'delete-all' ), 'add_update' );
$GLOBALS['fake_options'] = array( Fetch_WC_Settings::OPTION => array( 'catalogue_mode' => 'mirror' ) );
check( 'mirror mode on when saved', Fetch_WC_Settings::mirror(), true );
$GLOBALS['fake_options'] = array();
// Mirror off: returns before touching WooCommerce or Fetch (either would fatal here).
check( 'mirror off: the end of a full sync does nothing', Fetch_WC_Mirror::after_full_sync( false ), null );

check( 'active refs: published product is kept', Fetch_WC_Product_Mapper::active_refs( new WC_Product( array( 'name' => 'A', 'sku' => 'A-1', 'price' => '1' ) ) ), array( 'A-1' ) );
check( 'active refs: frozen ref wins over a later SKU', Fetch_WC_Product_Mapper::active_refs( new WC_Product( array( 'name' => 'A', 'sku' => 'A-2', 'price' => '1', 'meta' => array( '_fetch_ref' => 'A-1' ) ) ) ), array( 'A-1' ) );
check( 'active refs: draft product is not kept', Fetch_WC_Product_Mapper::active_refs( new WC_Product( array( 'name' => 'A', 'sku' => 'A-1', 'price' => '1', 'status' => 'draft' ) ) ), array() );
check( 'active refs: hidden product is not kept', Fetch_WC_Product_Mapper::active_refs( new WC_Product( array( 'name' => 'A', 'sku' => 'A-1', 'price' => '1', 'visibility' => 'hidden' ) ) ), array() );
check( 'active refs: not a product → nothing', Fetch_WC_Product_Mapper::active_refs( null ), array() );

$keep  = array( 'KEEP-1' => true, 'KEEP-2' => true );
$store = array(
	listing( 'l1', 'KEEP-1' ),
	listing( 'l2', 'KEEP-2', false ),       // kept, already off
	listing( 'l3', 'OTHER-SHOP' ),          // ref not in this shop
	listing( 'l4', null ),                  // made in the portal, no ref
	listing( 'l5', '' ),                    // empty ref
	listing( 'l6', 'DRAFT-1' ),             // excluded by the shop (draft, hidden, unsupported type)
	listing( 'l7', null, false ),           // already off, not ours: left alone
);
$plan = Fetch_WC_Mirror::plan( $store, $keep );
check( 'plan: counts scanned, active and kept', array( $plan['scanned'], $plan['active'], $plan['kept'] ), array( 7, 5, 2 ) );
check( 'plan: switches off foreign refs, no ref, empty ref and excluded products', array_column( $plan['off'], 'listingId' ), array( 'l3', 'l4', 'l5', 'l6' ) );
check( 'plan: nothing to keep → every active listing goes', count( Fetch_WC_Mirror::plan( $store, array() )['off'] ), 5 );
check( 'plan: every listing kept → nothing goes', Fetch_WC_Mirror::plan( array( listing( 'a', 'KEEP-1' ) ), $keep )['off'], array() );

list( $rows, $unusable ) = Fetch_WC_Mirror::rows( array( listing( 'l4', null, true, '12.50' ), listing( 'l9', 'X', true, '' ) ) );
check( 'rows: addressed by listingId, current price, isActive false', $rows, array( array( 'listingId' => 'l4', 'price' => '12.50', 'isActive' => false ) ) );
check( 'rows: a listing without a price cannot be sent on live', $unusable, 1 );

check( 'guard: exactly half is allowed', Fetch_WC_Mirror::needs_confirmation( 5, 10 ), false );
check( 'guard: more than half needs confirming', Fetch_WC_Mirror::needs_confirmation( 6, 10 ), true );
check( 'guard: nothing to switch off', Fetch_WC_Mirror::needs_confirmation( 0, 0 ), false );
check( 'guard: 1 of 1 needs confirming', Fetch_WC_Mirror::needs_confirmation( 1, 1 ), true );

// Mirror on: 4 kept; a foreign ref, no ref and an excluded product are switched off (3 of 7).
$store2 = array( listing( 'k1', 'KEEP-1' ), listing( 'k2', 'KEEP-2' ), listing( 'k3', 'KEEP-3' ), listing( 'x1', 'OTHER-SHOP' ), listing( 'x2', null ), listing( 'x3', 'DRAFT-1' ), listing( 'k4', 'KEEP-4' ) );
$keep2  = array( 'KEEP-1' => true, 'KEEP-2' => true, 'KEEP-3' => true, 'KEEP-4' => true );
$api    = new Fake_Listings_Api( $store2, 2 );
$r      = Fetch_WC_Mirror::execute( $api, 'store-1', $keep2 );
check( 'mirror on: scan follows nextCursor over every page', count( $api->queries ), 4 );
check( 'mirror on: asks for the largest page (500)', $api->queries[0], array( 'limit' => '500' ) );
check( 'mirror on: cursor passed on', $api->queries[1]['cursor'], 'p2' );
check( 'mirror on: foreign, no-ref and excluded listings switched off', $api->switched_off(), array( 'x1', 'x2', 'x3' ) );
check( 'mirror on: result counts', array( $r['status'], $r['scanned'], $r['kept'], $r['off'], $r['switched_off'], $r['errors'] ), array( 'done', 7, 4, 3, 3, 0 ) );
check( 'mirror on: every row carries the price and isActive false', $api->batches[0]['items'][1], array( 'listingId' => 'x2', 'price' => '5.00', 'isActive' => false ) );
check( 'mirror on: idempotency key sent', 0 === strpos( (string) $api->batches[0]['key'], 'wc-mirror-' ), true );

$api = new Fake_Listings_Api( $store2, 2, 3 );
$r   = Fetch_WC_Mirror::execute( $api, 'store-1', $keep2 );
check( 'scan page 3 fails → aborted', array( $r['status'], $r['switched_off'] ), array( 'scan_failed', 0 ) );
check( 'scan page 3 fails → nothing switched off', $api->batches, array() );
check( 'scan failure says which page', false !== strpos( $r['error'], 'page 3' ) && false !== strpos( $r['error'], 'HTTP 500' ), true );
$api = new Fake_Listings_Api( $store2, 2, 1 );
check( 'first scan page fails → aborted, nothing sent', array( Fetch_WC_Mirror::execute( $api, 'store-1', $keep2 )['status'], $api->batches ), array( 'scan_failed', array() ) );
$looping = new class( array() ) extends Fake_Listings_Api {
	public function list_listings( $store_id, array $query ) {
		$this->queries[] = $query;
		return array( 'code' => 200, 'body' => array( 'data' => array( listing( 'a', 'X' ) ), 'nextCursor' => 'same' ) );
	}
};
check( 'a cursor that repeats fails the scan', Fetch_WC_Mirror::execute( $looping, 'store-1', array() )['status'], 'scan_failed' );
$bad = new class( array() ) extends Fake_Listings_Api {
	public function list_listings( $store_id, array $query ) {
		return array( 'code' => 200, 'body' => array( 'items' => array() ) );
	}
};
check( 'an unexpected response fails the scan', Fetch_WC_Mirror::execute( $bad, 'store-1', array() )['status'], 'scan_failed' );
$dupe = new class( array() ) extends Fake_Listings_Api {
	public function list_listings( $store_id, array $query ) {
		// A listing updated mid-scan shows up again on a later page.
		return isset( $query['cursor'] )
			? array( 'code' => 200, 'body' => array( 'data' => array( listing( 'x1', null ), listing( 'k1', 'KEEP-1' ) ), 'nextCursor' => null ) )
			: array( 'code' => 200, 'body' => array( 'data' => array( listing( 'x1', null ), listing( 'k2', 'KEEP-2' ) ), 'nextCursor' => 'p2' ) );
	}
};
$r = Fetch_WC_Mirror::execute( $dupe, 'store-1', $keep2, true );
check( 'a listing seen on two pages counts once', array( $r['scanned'], $r['off'] ), array( 3, 1 ) );

// Over 50%: paused until confirmed.
$mostly = array( listing( 'k1', 'KEEP-1' ), listing( 'x1', null ), listing( 'x2', 'OLD-1' ), listing( 'x3', 'OLD-2' ) );
$api    = new Fake_Listings_Api( $mostly, 500 );
$r      = Fetch_WC_Mirror::execute( $api, 'store-1', $keep2 );
check( 'over 50%: paused, nothing switched off', array( $r['status'], $r['off'], $r['active'], $api->batches ), array( 'needs_confirmation', 3, 4, array() ) );
$api = new Fake_Listings_Api( $mostly, 500 );
$r   = Fetch_WC_Mirror::execute( $api, 'store-1', $keep2, false, 3 );
check( '"Apply anyway" for 3 switches off the 3', array( $r['status'], $r['switched_off'], $api->switched_off() ), array( 'done', 3, array( 'x1', 'x2', 'x3' ) ) );
$api = new Fake_Listings_Api( $mostly, 500 );
$r   = Fetch_WC_Mirror::execute( $api, 'store-1', $keep2, false, 2 );
check( '"Apply anyway" for 2 when 3 would now go → asks again', array( $r['status'], $api->batches ), array( 'needs_confirmation', array() ) );
$api = new Fake_Listings_Api( array( listing( 'k1', 'KEEP-1' ), listing( 'x1', null ) ), 500 );
check( 'exactly half applies without asking', Fetch_WC_Mirror::execute( $api, 'store-1', $keep2 )['switched_off'], 1 );

// Preview: counts and up to 20 names, nothing written.
$many = array( listing( 'k1', 'KEEP-1' ) );
for ( $i = 1; $i <= 25; $i++ ) {
	$many[] = listing( 'x' . $i, 'GONE-' . $i );
}
$api = new Fake_Listings_Api( $many, 10 );
$r   = Fetch_WC_Mirror::execute( $api, 'store-1', $keep2, true );
check( 'preview: N of M counts', array( $r['status'], $r['off'], $r['active'], $r['scanned'], $r['kept'] ), array( 'preview', 25, 26, 26, 1 ) );
check( 'preview: 20 sample names', array( count( $r['samples'] ), $r['samples'][0], $r['samples'][19] ), array( 20, 'Listing x1', 'Listing x20' ) );
check( 'preview: flags the 50% guard', $r['needs_confirmation'], true );
check( 'preview: nothing switched off', $api->batches, array() );

// Batching above 200 rows: 450 to switch off, 550 kept, scanned over two pages of 500.
$big  = array();
$keep = array();
for ( $i = 0; $i < 1000; $i++ ) {
	$big[] = listing( 'b' . $i, 'R' . $i, true, '1.00' );
	if ( $i >= 450 ) {
		$keep[ 'R' . $i ] = true;
	}
}
$api   = new Fake_Listings_Api( $big, 500 );
$r     = Fetch_WC_Mirror::execute( $api, 'store-1', $keep );
$sizes = array_map( function ( $b ) { return count( $b['items'] ); }, $api->batches );
check( '450 switch-offs → batches of 200, 200, 50', $sizes, array( 200, 200, 50 ) );
check( 'batches: two scan pages of 500', count( $api->queries ), 2 );
check( 'batches: every batch under 450 KB', max( array_map( function ( $b ) { return strlen( json_encode( array( 'items' => $b['items'] ) ) ); }, $api->batches ) ) < 450000, true );
check( 'batches: a fresh idempotency key per request', count( array_unique( array_column( $api->batches, 'key' ) ) ), 3 );
check( 'batches: all 450 switched off', array( $r['switched_off'], $r['errors'] ), array( 450, 0 ) );

$api             = new Fake_Listings_Api( $big, 500 );
$api->batch_code = 503;
$r               = Fetch_WC_Mirror::execute( $api, 'store-1', $keep );
check( 'a failed batch stops the run and counts the rest as errors', array( $r['status'], count( $api->batches ), $r['switched_off'], $r['errors'] ), array( 'apply_failed', 1, 0, 450 ) );
$api         = new Fake_Listings_Api( $store2, 500 );
$api->reject = array( 'x2' );
$r           = Fetch_WC_Mirror::execute( $api, 'store-1', $keep2 );
check( 'a rejected row (207) counts as an error, the rest apply', array( $r['status'], $r['switched_off'], $r['errors'] ), array( 'done', 2, 1 ) );
$api         = new Fake_Listings_Api( array( listing( 'k1', 'KEEP-1' ), listing( 'x1', null ) ), 500 );
$api->reject = array( 'x1' );
check( 'every row rejected (422) → all errors, not a transport failure', array_values( array_intersect_key( Fetch_WC_Mirror::execute( $api, 'store-1', $keep2 ), array_flip( array( 'status', 'switched_off', 'errors' ) ) ) ), array( 'done', 0, 1 ) );

echo "$passes passed, $failures failed\n";
exit( $failures ? 1 : 0 );
