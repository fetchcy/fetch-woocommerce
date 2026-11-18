<?php
/**
 * Standalone unit tests (no WordPress): `php tests/unit.php`.
 * Covers webhook signature verification, the order mapping rules, the
 * price markup maths, the server setting, request batching, the full
 * deactivation row, masked customer details, the per-order import lock,
 * the "Mirror this shop 1:1" scan, plan, guard and switch-off batches,
 * default-language-only sync with stubbed WPML filters and Polylang functions,
 * the product content sent to Fetch: brand detection, specs, plain-text
 * descriptions with paragraphs, and Yoast / Rank Math SEO meta, and several
 * Fetch stores from one site: the 1.3.0 upgrade, per-store settings with
 * fallbacks, keys and queues, markups, category filters and switch-offs.
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
// Taxonomies, terms and post meta for the brand, specs and SEO tests.
$GLOBALS['fake_taxonomies'] = array(); // taxonomy => [product id => term names]
$GLOBALS['fake_post_meta']  = array(); // product id => [key => value]
function taxonomy_exists( $taxonomy ) {
	return isset( $GLOBALS['fake_taxonomies'][ $taxonomy ] );
}
function wp_get_post_terms( $id, $taxonomy, $args = array() ) {
	return isset( $GLOBALS['fake_taxonomies'][ $taxonomy ][ $id ] ) ? $GLOBALS['fake_taxonomies'][ $taxonomy ][ $id ] : array();
}
function wc_get_product_terms( $id, $taxonomy, $args = array() ) {
	return wp_get_post_terms( $id, $taxonomy, $args );
}
/** Global attributes have labels; a local attribute's name is its label. */
function wc_attribute_label( $name, $product = null ) {
	$labels = array( 'pa_brands' => 'Brands', 'pa_brand' => 'Brand', 'pa_installation' => 'Installation', 'pa_height' => 'Height', 'pa_dimensions' => 'Width', 'pa_depth' => 'Depth', 'pa_colour' => 'Colour' );
	return isset( $labels[ $name ] ) ? $labels[ $name ] : $name;
}
function get_post_meta( $id, $key, $single = false ) {
	return isset( $GLOBALS['fake_post_meta'][ $id ][ $key ] ) ? $GLOBALS['fake_post_meta'][ $id ][ $key ] : '';
}
function get_post( $id ) {
	return isset( $GLOBALS['fake_products'][ $id ] ) ? (object) array( 'ID' => $id, 'post_title' => $GLOBALS['fake_products'][ $id ]->get_name() ) : null;
}
/** Yoast's renderer, for the variables these tests use. */
function wpseo_replace_vars( $value, $post ) {
	if ( false !== strpos( $value, '%%explode%%' ) ) {
		throw new RuntimeException( 'Yoast failed' );
	}
	return str_replace( array( '%%title%%', '%%sep%%', '%%sitename%%' ), array( $post->post_title, '-', 'Shop' ), $value );
}
eval( 'namespace RankMath; class Helper { public static function replace_vars( $value, $post ) { return str_replace( "%title%", $post->post_title, $value ); } }' );
/** WooCommerce's product attribute: global (taxonomy) or local (options). */
class WC_Product_Attribute {
	private $d;
	public function __construct( $name, array $options = array(), $visible = true, $variation = false ) {
		$this->d = array( $name, $options, $visible, $variation );
	}
	public function get_name() { return $this->d[0]; }
	public function get_options() { return $this->d[1]; }
	public function get_visible() { return $this->d[2]; }
	public function get_variation() { return $this->d[3]; }
	public function is_taxonomy() { return 0 === strpos( $this->d[0], 'pa_' ); }
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
define( 'FETCH_WC_VERSION', 'test' );

// Hooks, posts, products and HTTP: just enough for the multilingual tests.
$GLOBALS['fake_filters']  = array();
$GLOBALS['fake_posts']    = array(); // id => [type, parent]
$GLOBALS['fake_products'] = array(); // id => WC_Product
$GLOBALS['fake_requests'] = array();
function add_filter( $hook, $callback ) {
	$GLOBALS['fake_filters'][ $hook ][] = $callback;
	return true;
}
function remove_filter( $hook, $callback ) {
	foreach ( isset( $GLOBALS['fake_filters'][ $hook ] ) ? $GLOBALS['fake_filters'][ $hook ] : array() as $i => $cb ) {
		if ( $cb === $callback ) {
			unset( $GLOBALS['fake_filters'][ $hook ][ $i ] );
		}
	}
	return true;
}
function has_filter( $hook, $callback = false ) {
	return ! empty( $GLOBALS['fake_filters'][ $hook ] );
}
function apply_filters( $hook, $value, ...$args ) {
	foreach ( isset( $GLOBALS['fake_filters'][ $hook ] ) ? $GLOBALS['fake_filters'][ $hook ] : array() as $cb ) {
		$value = $cb( $value, ...$args );
	}
	return $value;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['fake_options'][ $name ] = $value;
	return true;
}
function get_post_type( $id ) {
	return isset( $GLOBALS['fake_posts'][ $id ] ) ? $GLOBALS['fake_posts'][ $id ][0] : false;
}
function wp_get_post_parent_id( $id ) {
	return isset( $GLOBALS['fake_posts'][ $id ] ) ? $GLOBALS['fake_posts'][ $id ][1] : 0;
}
function wc_get_product( $id ) {
	return isset( $GLOBALS['fake_products'][ $id ] ) ? $GLOBALS['fake_products'][ $id ] : false;
}
/** Pages through every fake product, like WooCommerce, after the same query filter. */
function wc_get_products( $args ) {
	$GLOBALS['fake_wp_query'] = apply_filters( 'woocommerce_product_data_store_cpt_get_products_query', array( 'post_type' => 'product' ), $args, null );
	$ids = array();
	foreach ( $GLOBALS['fake_posts'] as $id => $post ) {
		if ( 'product' === $post[0] ) {
			$ids[] = $id;
		}
	}
	return array_slice( $ids, ( $args['page'] - 1 ) * $args['limit'], $args['limit'] );
}
function get_transient( $name ) {
	// A cached token, so the API client never asks a server for one.
	return array( 'token' => 'test-token', 'key' => md5( Fetch_WC_Settings::base_url() . '|' . Fetch_WC_Settings::get( 'client_id' ) ) );
}
function home_url() {
	return 'https://shop.example';
}
/** Records each request instead of sending it; answers like PUT /products. */
function wp_remote_request( $url, $args ) {
	$body                      = json_decode( $args['body'], true );
	$GLOBALS['fake_requests'][] = array( 'method' => $args['method'], 'url' => $url, 'body' => $body );
	if ( ! empty( $GLOBALS['fake_fail_store'] ) && false !== strpos( $url, '/stores/' . $GLOBALS['fake_fail_store'] . '/' ) ) {
		return array( 'code' => 503, 'body' => wp_json_encode( array( 'message' => 'Service unavailable' ) ) );
	}
	$results                   = array();
	foreach ( $body['products'] as $row ) {
		$results[] = array( 'ref' => $row['ref'], 'status' => 'created', 'productId' => 'prod-' . $row['ref'] );
	}
	return array( 'code' => 200, 'body' => wp_json_encode( array( 'created' => count( $results ), 'updated' => 0, 'rejected' => 0, 'results' => $results ) ) );
}
/** Parent categories, term id => ancestor ids (nearest first), for the category filter tests. */
$GLOBALS['fake_ancestors'] = array();
function get_ancestors( $id, $object_type = '', $resource_type = '' ) {
	return isset( $GLOBALS['fake_ancestors'][ $id ] ) ? $GLOBALS['fake_ancestors'][ $id ] : array();
}
function get_term( $id, $taxonomy = '' ) {
	return (object) array( 'term_id' => $id, 'name' => 'Category ' . $id );
}
function delete_option( $name ) {
	unset( $GLOBALS['fake_options'][ $name ] );
	return true;
}
function wp_remote_retrieve_response_code( $response ) {
	return $response['code'];
}
function wp_remote_retrieve_body( $response ) {
	return $response['body'];
}

/** A simple (or variable) product, just the getters the mapper reads. */
class WC_Product {
	public $data;
	public function __construct( array $data ) {
		$this->data = $data + array( 'id' => 1, 'type' => 'simple', 'status' => 'publish', 'sku' => '', 'meta' => array(), 'sale' => '', 'visibility' => 'visible', 'children' => array(), 'parent' => 0, 'stock' => 1, 'description' => 'Braided cable', 'short' => '', 'attributes' => array() );
	}
	public function is_type( $type ) { return $this->data['type'] === $type; }
	public function get_children() { return $this->data['children']; }
	public function get_parent_id() { return $this->data['parent']; }
	public function update_meta_data( $key, $value ) { $this->data['meta'][ $key ] = $value; }
	public function save_meta_data() {}
	public function get_id() { return $this->data['id']; }
	public function get_name() { return $this->data['name']; }
	public function get_sku( $context = 'view' ) { return $this->data['sku']; }
	public function get_meta( $key ) { return isset( $this->data['meta'][ $key ] ) ? $this->data['meta'][ $key ] : ''; }
	public function get_status() { return $this->data['status']; }
	public function get_catalog_visibility() { return $this->data['visibility']; }
	public function get_description() { return $this->data['description']; }
	public function get_short_description() { return $this->data['short']; }
	/** Keyed like WooCommerce: taxonomy name, or the sanitized local name. */
	public function get_attributes() {
		$out = array();
		foreach ( $this->data['attributes'] as $attribute ) {
			$out[ strtolower( str_replace( ' ', '-', $attribute->get_name() ) ) ] = $attribute;
		}
		return $out;
	}
	public function get_category_ids() { return isset( $this->data['cats'] ) ? $this->data['cats'] : array(); }
	public function get_regular_price( $context = 'view' ) { return $this->data['price']; }
	public function get_sale_price( $context = 'view' ) { return $this->data['sale']; }
	public function is_on_sale( $context = 'view' ) { return '' !== $this->data['sale']; }
	public function managing_stock() { return true; }
	public function get_stock_quantity() { return $this->data['stock']; }
	public function get_stock_status() { return 'instock'; }
	public function get_image_id() { return 0; }
	public function get_gallery_image_ids() { return array(); }
}
class WC_Product_Variation extends WC_Product {
	public function __construct( array $data ) {
		parent::__construct( $data + array( 'type' => 'variation' ) );
	}
	public function variation_is_visible() { return true; }
	public function get_variation_attributes( $with_prefix = true ) { return array(); }
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
require __DIR__ . '/../includes/class-fetch-wc-logger.php';
require __DIR__ . '/../includes/class-fetch-wc-language.php';
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

// --- Brand, specs, descriptions and SEO (1.3.0) ---------------------------------------
// A hob as a real appliance shop lists it: brand in the global pa_brands ("Brands"), specs a mix of
// local and global attributes, one of them hidden and one a variation axis.
$GLOBALS['fake_taxonomies']['pa_brands']       = array( 300 => array( 'HAIER' ) );
$GLOBALS['fake_taxonomies']['pa_installation'] = array( 300 => array( 'Built-in' ) );
$GLOBALS['fake_taxonomies']['pa_height']       = array( 300 => array( '5.1 cm' ) );
$GLOBALS['fake_taxonomies']['pa_dimensions']   = array( 300 => array( '65 cm' ) );
$GLOBALS['fake_taxonomies']['pa_depth']        = array( 300 => array( '51 cm' ) );
$hob_attributes = array(
	new WC_Product_Attribute( 'pa_brands' ),
	new WC_Product_Attribute( 'Type', array( 'Induction' ) ),
	new WC_Product_Attribute( 'Number of Cooking Zones', array( '4 (Varycook flexible zones)' ) ),
	new WC_Product_Attribute( 'Special Functions', array( '2200 W &amp; boost', 'Wi-Fi' ) ),
	new WC_Product_Attribute( 'Dimensions (H x W x D)', array( '5.1 x 65 x 51 cm' ) ),
	new WC_Product_Attribute( 'Cut-out Dimensions (W x D)', array( '56 x 48 cm' ) ),
	new WC_Product_Attribute( 'Internal code', array( 'X-99' ), false ),
	new WC_Product_Attribute( 'Empty', array( '  ' ) ),
	new WC_Product_Attribute( 'pa_installation' ),
	new WC_Product_Attribute( 'pa_height' ),
	new WC_Product_Attribute( 'pa_dimensions' ),
	new WC_Product_Attribute( 'pa_depth' ),
);
$hob = new WC_Product(
	array(
		'id'          => 300,
		'name'        => 'Induction Hob, 65cm, 4 Zones',
		'sku'         => 'HOB-65',
		'price'       => '500',
		'description' => "<p>A 65 cm induction hob.</p>\n<p>Touch controls &amp; timer;<br>cut-out 56 x 48 cm.</p><ul><li>Wi-Fi</li><li>Bluetooth</li></ul>",
		'short'       => '<p>Four zones,   Varycook.</p>',
		'attributes'  => $hob_attributes,
	)
);
$GLOBALS['fake_products'][300] = $hob;
$GLOBALS['fake_post_meta'][300] = array(
	'_yoast_wpseo_title'    => '%%title%% %%sep%% %%sitename%%',
	'_yoast_wpseo_metadesc' => '',
	'rank_math_description' => 'Buy the %title% online.',
);
$row = Fetch_WC_Product_Mapper::to_fetch_items( $hob )[0][0];
check( 'brand: global pa_brands ("Brands") is found automatically', $row['brand'], 'HAIER' );
check(
	'specs: visible non-variation attributes, labels, shop order, brand included, hidden and empty left out',
	$row['specs'],
	array(
		array( 'name' => 'Brands', 'value' => 'HAIER' ),
		array( 'name' => 'Type', 'value' => 'Induction' ),
		array( 'name' => 'Number of Cooking Zones', 'value' => '4 (Varycook flexible zones)' ),
		array( 'name' => 'Special Functions', 'value' => '2200 W & boost, Wi-Fi' ),
		array( 'name' => 'Dimensions (H x W x D)', 'value' => '5.1 x 65 x 51 cm' ),
		array( 'name' => 'Cut-out Dimensions (W x D)', 'value' => '56 x 48 cm' ),
		array( 'name' => 'Installation', 'value' => 'Built-in' ),
		array( 'name' => 'Height', 'value' => '5.1 cm' ),
		array( 'name' => 'Width', 'value' => '65 cm' ),
		array( 'name' => 'Depth', 'value' => '51 cm' ),
	)
);
check( 'description: paragraphs kept as blank lines, <br> and list items as line breaks, entities decoded', $row['description'], "A 65 cm induction hob.\n\nTouch controls & timer;\ncut-out 56 x 48 cm.\n\nWi-Fi\nBluetooth" );
check( 'shortDescription: plain text, whitespace collapsed', $row['shortDescription'], 'Four zones, Varycook.' );
check( 'seo: Yoast title rendered, Rank Math description used when Yoast has none', $row['seo'], array( 'title' => 'Induction Hob, 65cm, 4 Zones - Shop', 'description' => 'Buy the Induction Hob, 65cm, 4 Zones online.' ) );

$GLOBALS['fake_post_meta'][300] = array( '_yoast_wpseo_title' => '%%explode%% title' );
check( 'seo: a failing Yoast renderer sends the raw value instead of stopping the sync', Fetch_WC_Product_Mapper::to_fetch_items( $hob )[0][0]['seo'], array( 'title' => '%%explode%% title' ) );
$GLOBALS['fake_post_meta'][300] = array( '_yoast_wpseo_title' => '   ' );
check( 'seo: omitted when the shop has none', array_key_exists( 'seo', Fetch_WC_Product_Mapper::to_fetch_items( $hob )[0][0] ), false );

$GLOBALS['fake_options']['fetch_wc_settings'] = array( 'brand_attribute' => 'pa_installation' );
check( 'brand: the "Brand attribute" setting wins', Fetch_WC_Product_Mapper::to_fetch_items( $hob )[0][0]['brand'], 'Built-in' );
$GLOBALS['fake_options']['fetch_wc_settings'] = array( 'brand_attribute' => 'pa_colour' );
check( 'brand: a chosen attribute the product lacks falls back to automatic', Fetch_WC_Product_Mapper::to_fetch_items( $hob )[0][0]['brand'], 'HAIER' );
$GLOBALS['fake_options']['fetch_wc_settings'] = array();

$GLOBALS['fake_taxonomies']['product_brand'] = array( 300 => array( 'Haier Europe' ) );
check( 'brand: a brand taxonomy still comes before attributes', Fetch_WC_Product_Mapper::to_fetch_items( $hob )[0][0]['brand'], 'Haier Europe' );
unset( $GLOBALS['fake_taxonomies']['product_brand'] );

$local_brand = new WC_Product( array( 'id' => 301, 'name' => 'Kettle', 'price' => '20', 'description' => '', 'short' => 'Fast <b>kettle</b>', 'attributes' => array( new WC_Product_Attribute( 'Μάρκα', array( 'Bosch', 'Siemens' ), false ) ) ) );
$row         = Fetch_WC_Product_Mapper::to_fetch_items( $local_brand )[0][0];
check( 'brand: a local attribute labelled Μάρκα, first value, even when hidden', $row['brand'], 'Bosch' );
check( 'specs: none sent when every attribute is hidden', array_key_exists( 'specs', $row ), false );
check( 'description: falls back to the short description, as before', array( $row['description'], $row['shortDescription'] ), array( 'Fast kettle', 'Fast kettle' ) );
$GLOBALS['fake_taxonomies']['pa_brand'] = array( 302 => array( 'Philips', 'Saeco' ) );
check( 'brand: pa_brand, first term', Fetch_WC_Product_Mapper::to_fetch_items( new WC_Product( array( 'id' => 302, 'name' => 'Iron', 'price' => '30', 'attributes' => array( new WC_Product_Attribute( 'pa_brand' ) ) ) ) )[0][0]['brand'], 'Philips' );
check( 'no brand, specs, short description or seo on a bare product', array_intersect( array( 'brand', 'specs', 'shortDescription', 'seo' ), array_keys( Fetch_WC_Product_Mapper::to_fetch_items( new WC_Product( array( 'id' => 303, 'name' => 'Plain', 'price' => '1' ) ) )[0][0] ) ), array() );

$long = array();
for ( $i = 0; $i < 120; $i++ ) {
	$long[] = new WC_Product_Attribute( 'Spec ' . $i . str_repeat( 'n', 150 ), array( str_repeat( 'v', 1500 ) ) );
}
$specs = Fetch_WC_Product_Mapper::to_fetch_items( new WC_Product( array( 'id' => 304, 'name' => 'Big', 'price' => '1', 'attributes' => $long ) ) )[0][0]['specs'];
check( 'specs: capped at the API limits (100 rows, name 100, value 1000)', array( count( $specs ), mb_strlen( $specs[0]['name'] ), mb_strlen( $specs[0]['value'] ) ), array( 100, 100, 1000 ) );

check( 'plain_text: unwrapped text keeps its own blank lines', Fetch_WC_Product_Mapper::plain_text( "First line\r\nsecond line\r\n\r\n\r\nNext paragraph &nbsp; here" ), "First line\nsecond line\n\nNext paragraph here" );
check( 'plain_text: headings and divs end a paragraph', Fetch_WC_Product_Mapper::plain_text( '<h2>Specs</h2><div>Quiet</div>Intro<ol><li>One</li></ol>' ), "Specs\n\nQuiet\n\nIntro\nOne" );
check( 'plain_text: capped at 5000 characters', mb_strlen( Fetch_WC_Product_Mapper::plain_text( str_repeat( 'ab ', 3000 ) ) ), 5000 );

// Variations sent as simple products carry the parent's specs and SEO.
$GLOBALS['fake_taxonomies']['pa_colour'] = array( 310 => array( 'Black', 'White' ) );
$GLOBALS['fake_post_meta'][310]         = array( 'rank_math_title' => '%title% | Shop' );
$GLOBALS['fake_products'][311]          = new WC_Product_Variation( array( 'id' => 311, 'name' => 'Case Black', 'sku' => 'CS-B', 'price' => '9', 'parent' => 310 ) );
$GLOBALS['fake_products'][312]          = new WC_Product_Variation( array( 'id' => 312, 'name' => 'Case White', 'sku' => 'CS-W', 'price' => '9', 'parent' => 310 ) );
$case                                   = new WC_Product( array( 'id' => 310, 'type' => 'variable', 'name' => 'Phone Case', 'children' => array( 311, 312 ), 'attributes' => array( new WC_Product_Attribute( 'pa_colour', array(), true, true ), new WC_Product_Attribute( 'Material', array( 'Silicone' ) ) ) ) );
$GLOBALS['fake_products'][310]          = $case;
$rows                                   = array_map( function ( $pair ) { return $pair[0]; }, Fetch_WC_Product_Mapper::to_fetch_items( $case ) );
check( 'variations: each carries the parent specs without the variation axis', array_column( $rows, 'specs' ), array_fill( 0, 2, array( array( 'name' => 'Material', 'value' => 'Silicone' ) ) ) );
check( 'variations: each carries the parent SEO (Rank Math rendered)', array_column( $rows, 'seo' ), array_fill( 0, 2, array( 'title' => 'Phone Case | Shop' ) ) );
unset( $GLOBALS['fake_products'][300], $GLOBALS['fake_products'][310], $GLOBALS['fake_products'][311], $GLOBALS['fake_products'][312] );

// "Brand attribute" setting.
check( 'brand setting: Automatic by default', Fetch_WC_Settings::brand_attribute(), '' );
check( 'brand setting: a known attribute taxonomy is kept', Fetch_WC_Settings::sanitize_brand_attribute( 'pa_brands', array( 'pa_brands', 'pa_depth' ) ), 'pa_brands' );
check( 'brand setting: an attribute the shop does not have is Automatic', Fetch_WC_Settings::sanitize_brand_attribute( 'pa_brand', array( 'pa_brands' ) ), '' );
check( 'brand setting: not an attribute taxonomy is Automatic', array( Fetch_WC_Settings::sanitize_brand_attribute( 'product_brand' ), Fetch_WC_Settings::sanitize_brand_attribute( 'pa_x"><script>' ), Fetch_WC_Settings::sanitize_brand_attribute( '' ) ), array( '', '', '' ) );
check( 'brand setting: url-encoded (non-Latin) attribute slugs are allowed', Fetch_WC_Settings::sanitize_brand_attribute( 'pa_%ce%bc%ce%ac%cf%81%ce%ba%ce%b1' ), 'pa_%ce%bc%ce%ac%cf%81%ce%ba%ce%b1' );

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


// --- Several Fetch stores from one site (1.4.0) -------------------------------------------
// A 1.3.0 install: one store, the global settings. It must read as one connected store, unchanged.
$GLOBALS['fake_options'] = array( Fetch_WC_Settings::OPTION => array( 'client_id' => 'c', 'client_secret' => 's', 'store_id' => 'store-1', 'store_name' => 'Main', 'price_markup' => 10, 'catalogue_mode' => 'mirror', 'auto_accept' => 'yes', 'prep_minutes' => 20 ) );
check( 'upgrade: the 1.3.0 store is the one connected store', array_keys( Fetch_WC_Settings::stores() ), array( 'store-1' ) );
check( 'upgrade: still connected, same name', array( Fetch_WC_Settings::is_connected(), Fetch_WC_Settings::store_name( 'store-1' ) ), array( true, 'Main' ) );
check( 'upgrade: the store uses the 1.3.0 settings', array( Fetch_WC_Settings::store_markup( 'store-1' ), Fetch_WC_Settings::store_mirror( 'store-1' ), Fetch_WC_Settings::store_auto_accept( 'store-1' ), Fetch_WC_Settings::store_prep_minutes( 'store-1' ), Fetch_WC_Settings::store_categories( 'store-1' ) ), array( 10.0, true, true, 20, array() ) );
check( 'upgrade: the 1.3.0 option names and meta keys (no migration)', array( Fetch_WC_Settings::suffix( 'store-1' ), Fetch_WC_Settings::suffix( null ), Fetch_WC_Product_Sync::key( Fetch_WC_Product_Sync::PENDING_OPTION, 'store-1' ), Fetch_WC_Product_Sync::key( Fetch_WC_Product_Sync::META_PRODUCT_ID ) ), array( '', '', 'fetch_wc_pending_products', '_fetch_product_id' ) );
check( 'upgrade: the 1.3.0 job arguments, so jobs it queued still run', array( Fetch_WC_Settings::job_args( 'store-1', array( 'page' => 2 ) ), Fetch_WC_Settings::job_args( null ) ), array( array( 'page' => 2 ), array() ) );
check( 'one store: log lines are not labelled', Fetch_WC_Settings::label( 'store-1' ), '' );
$GLOBALS['fake_options'][ Fetch_WC_Settings::OPTION ]['stores'] = array();
check( 'every store unticked: not connected, even with the old store_id', array( Fetch_WC_Settings::stores(), Fetch_WC_Settings::is_connected() ), array( array(), false ) );
$GLOBALS['fake_options'] = array();
check( 'fresh install: no store, not connected', array( Fetch_WC_Settings::stores(), Fetch_WC_Settings::is_connected(), Fetch_WC_Settings::resolve_store( null ) ), array( array(), false, '' ) );

// The chain: one site, the primary store on the defaults, a second with its own settings.
$multi_settings = array(
	'client_id'      => 'c',
	'client_secret'  => 's',
	'environment'    => 'custom',
	'custom_base_url' => 'http://fetch.invalid',
	'store_id'       => 'store-1',
	'store_name'     => 'Main',
	'price_markup'   => 10,
	'catalogue_mode' => 'mirror',
	'prep_minutes'   => 20,
	'stores'         => array(
		'store-1' => array( 'id' => 'store-1', 'name' => 'Main' ),
		'B-2'     => array( 'id' => 'B-2', 'name' => 'Branch B', 'price_markup' => 15, 'catalogue_mode' => 'add_update', 'categories' => array( '5', 5, 0 ), 'auto_accept' => 'yes', 'prep_minutes' => 30 ),
	),
);
$GLOBALS['fake_options'] = array( Fetch_WC_Settings::OPTION => $multi_settings );
check( 'multi: both stores connected, in order', array_keys( Fetch_WC_Settings::stores() ), array( 'store-1', 'B-2' ) );
check( 'multi: a blank store setting falls back to the global one', array( Fetch_WC_Settings::store_markup( 'store-1' ), Fetch_WC_Settings::store_mirror( 'store-1' ), Fetch_WC_Settings::store_auto_accept( 'store-1' ), Fetch_WC_Settings::store_prep_minutes( 'store-1' ) ), array( 10.0, true, false, 20 ) );
check( 'multi: a store\'s own settings win', array( Fetch_WC_Settings::store_markup( 'B-2' ), Fetch_WC_Settings::store_mirror( 'B-2' ), Fetch_WC_Settings::store_auto_accept( 'B-2' ), Fetch_WC_Settings::store_prep_minutes( 'B-2' ), Fetch_WC_Settings::store_categories( 'B-2' ) ), array( 15.0, false, true, 30, array( 5 ) ) );
check( 'multi: a second store gets its own option names, meta keys and job arguments', array( Fetch_WC_Product_Sync::key( Fetch_WC_Product_Sync::PENDING_OPTION, 'B-2' ), Fetch_WC_Product_Sync::key( Fetch_WC_Product_Sync::META_PRODUCT_ID, 'B-2' ), Fetch_WC_Settings::job_args( 'B-2', array( 'page' => 1 ) ) ), array( 'fetch_wc_pending_products_b-2', '_fetch_product_id_b-2', array( 'page' => 1, 'store' => 'B-2' ) ) );
check( 'multi: an id that is not a safe key is hashed', Fetch_WC_Settings::suffix( 'a/b c' ), '_' . md5( 'a/b c' ) );
check( 'multi: log lines carry the store name', array( Fetch_WC_Settings::label( 'B-2' ), Fetch_WC_Settings::label( null ) ), array( '[Branch B] ', '[Main] ' ) );
check( 'multi: a store that is not connected has no config', Fetch_WC_Settings::store( 'store-3' ), null );

// Products: 70 in category 7 (a child of 5), 71 in category 9, 72 a variable product in 7.
$GLOBALS['fake_ancestors'] = array( 7 => array( 5 ) );
$GLOBALS['fake_posts']     = array( 70 => array( 'product', 0 ), 71 => array( 'product', 0 ), 72 => array( 'product', 0 ), 73 => array( 'product_variation', 72 ), 74 => array( 'product_variation', 72 ) );
$GLOBALS['fake_products']  = array(
	70 => new WC_Product( array( 'id' => 70, 'name' => 'Dog food', 'sku' => 'DOG-1', 'price' => '10', 'cats' => array( 7 ) ) ),
	71 => new WC_Product( array( 'id' => 71, 'name' => 'Cat toy', 'sku' => 'CAT-1', 'price' => '4', 'cats' => array( 9 ) ) ),
	72 => new WC_Product( array( 'id' => 72, 'name' => 'Lead', 'sku' => 'LEAD', 'price' => '0', 'type' => 'variable', 'children' => array( 73, 74 ), 'cats' => array( 7 ) ) ),
	73 => new WC_Product_Variation( array( 'id' => 73, 'name' => 'Lead', 'sku' => 'LEAD-S', 'price' => '6', 'parent' => 72 ) ),
	74 => new WC_Product_Variation( array( 'id' => 74, 'name' => 'Lead', 'sku' => 'LEAD-L', 'price' => '8', 'parent' => 72 ) ),
);
check( 'category filter: a product in a subcategory of a chosen one is in', Fetch_WC_Product_Mapper::in_categories( $GLOBALS['fake_products'][70], array( 5 ) ), true );
check( 'category filter: a product in another category is out', Fetch_WC_Product_Mapper::in_categories( $GLOBALS['fake_products'][71], array( 5 ) ), false );
check( 'category filter: none chosen is every product', Fetch_WC_Product_Mapper::in_categories( $GLOBALS['fake_products'][71], array() ), true );
$rows_by_ref = function ( $store ) {
	$out = array();
	foreach ( $GLOBALS['fake_requests'] as $r ) {
		if ( false !== strpos( $r['url'], '/stores/' . $store . '/products' ) ) {
			foreach ( $r['body']['products'] as $row ) {
				$out[ $row['ref'] ] = $row;
			}
		}
	}
	return $out;
};
$GLOBALS['fake_requests'] = array();
check( 'sync to the primary store reaches Fetch', Fetch_WC_Product_Sync::sync_ids( array( 70, 71, 72 ), 'store-1' ), true );
check( 'sync to store B reaches Fetch', Fetch_WC_Product_Sync::sync_ids( array( 70, 71, 72 ), 'B-2' ), true );
$main = $rows_by_ref( 'store-1' );
$b    = $rows_by_ref( 'B-2' );
check( 'primary store: every product, at the global markup (10 + 19% VAT + 10%)', array( array_keys( $main ), $main['DOG-1']['price'], $main['LEAD-L']['price'] ), array( array( 'DOG-1', 'CAT-1', 'LEAD-S', 'LEAD-L' ), '13.09', '10.47' ) );
check( 'store B: only its categories, at its own markup (10 + 19% VAT + 15%)', array( array_keys( $b ), $b['DOG-1']['price'], $b['LEAD-L']['price'] ), array( array( 'DOG-1', 'LEAD-S', 'LEAD-L' ), '13.69', '10.95' ) );
check( 'refs and stock are the same in every store', array( $b['DOG-1']['ref'] === $main['DOG-1']['ref'], $b['DOG-1']['stockCount'] === $main['DOG-1']['stockCount'] ), array( true, true ) );
$dog = $GLOBALS['fake_products'][70]->data['meta'];
check( 'each store\'s Fetch product id and result are kept apart; the ref is shared', array( $dog['_fetch_product_id'], $dog['_fetch_product_id_b-2'], $dog['_fetch_sync_status'], $dog['_fetch_sync_status_b-2'], $dog['_fetch_ref'] ), array( 'prod-DOG-1', 'prod-DOG-1', 'synced', 'synced', 'DOG-1' ) );
check( 'a store never sent a product has no id for it', isset( $GLOBALS['fake_products'][71]->data['meta']['_fetch_product_id_b-2'] ), false );
check( 'was_synced is per store', array( Fetch_WC_Product_Mapper::was_synced( $GLOBALS['fake_products'][71], 'store-1' ), Fetch_WC_Product_Mapper::was_synced( $GLOBALS['fake_products'][71], 'B-2' ), Fetch_WC_Product_Mapper::was_synced( $GLOBALS['fake_products'][72], 'B-2' ) ), array( true, false, true ) );

// The dog food moves to category 9: store B switches it off, the primary store keeps it.
$GLOBALS['fake_products'][70]->data['cats'] = array( 9 );
$GLOBALS['fake_requests']                   = array();
Fetch_WC_Product_Sync::sync_ids( array( 70, 71 ), 'B-2' );
$b = $rows_by_ref( 'B-2' );
check( 'a product leaving a store\'s categories is switched off there (full row, its price)', array( array_keys( $b ), $b['DOG-1']['isActive'], $b['DOG-1']['price'] ), array( array( 'DOG-1' ), false, '13.69' ) );
$GLOBALS['fake_products'][70]->data['cats'] = array( 7 );

// Save hooks queue a product once per store that takes it (or has it).
unset( $GLOBALS['fake_options']['fetch_wc_pending_products'], $GLOBALS['fake_options']['fetch_wc_pending_products_b-2'] );
foreach ( array( 70, 71, 73 ) as $id ) {
	Fetch_WC_Product_Sync::queue( $id );
}
check( 'queue: the primary store gets every product', array_keys( get_option( 'fetch_wc_pending_products', array() ) ), array( 70, 71, 72 ) );
check( 'queue: store B only gets products in its categories', array_keys( get_option( 'fetch_wc_pending_products_b-2', array() ) ), array( 70, 72 ) );

// Deleting: each store that has the product gets its switch-off row, at its own price.
Fetch_WC_Product_Sync::queue_deletion( 70 );
$off_main = get_option( 'fetch_wc_pending_deactivations', array() );
$off_b    = get_option( 'fetch_wc_pending_deactivations_b-2', array() );
check( 'delete: switched off in both stores, each at its own price', array( $off_main['DOG-1']['isActive'], $off_main['DOG-1']['price'], $off_b['DOG-1']['isActive'], $off_b['DOG-1']['price'] ), array( false, '13.09', false, '13.69' ) );
check( 'delete: no longer queued for an update in either store', array( isset( get_option( 'fetch_wc_pending_products' )[70] ), isset( get_option( 'fetch_wc_pending_products_b-2' )[70] ) ), array( false, false ) );
Fetch_WC_Product_Sync::queue_deletion( 71 );
check( 'delete: a store that never had the product is not sent anything', array( isset( get_option( 'fetch_wc_pending_deactivations', array() )['CAT-1'] ), isset( get_option( 'fetch_wc_pending_deactivations_b-2', array() )['CAT-1'] ) ), array( true, false ) );

// Mirror: each store keeps only what it sells.
$keep_main = array_keys( Fetch_WC_Mirror::shop_refs( 'store-1' ) );
$keep_b    = array_keys( Fetch_WC_Mirror::shop_refs( 'B-2' ) );
sort( $keep_main );
sort( $keep_b );
check( 'mirror: the primary store keeps every product', $keep_main, array( 'CAT-1', 'DOG-1', 'LEAD-L', 'LEAD-S' ) );
check( 'mirror: store B keeps only its categories, so a listing for the cat toy would be switched off there', $keep_b, array( 'DOG-1', 'LEAD-L', 'LEAD-S' ) );

// One store's API error does not hold up the other.
$GLOBALS['fake_fail_store'] = 'store-1';
$GLOBALS['fake_requests']   = array();
unset( $GLOBALS['fake_options']['fetch_wc_pending_products'], $GLOBALS['fake_options']['fetch_wc_pending_products_b-2'] );
check( 'a store that cannot be reached reports failure', Fetch_WC_Product_Sync::sync_ids( array( 70 ), 'store-1' ), false );
check( '…and requeues only its own product', array( array_keys( get_option( 'fetch_wc_pending_products', array() ) ), get_option( 'fetch_wc_pending_products_b-2', array() ) ), array( array( 70 ), array() ) );
check( 'the other store syncs as usual', array( Fetch_WC_Product_Sync::sync_ids( array( 70 ), 'B-2' ), array_keys( $rows_by_ref( 'B-2' ) ) ), array( true, array( 'DOG-1' ) ) );
$status = Fetch_WC_Settings::store_status( 'store-1' );
check( 'the failure is on the failing store\'s status, not the other\'s', array( false !== strpos( $status['last_error'], 'HTTP 503' ), Fetch_WC_Settings::store_status( 'B-2' ) ), array( true, array() ) );
check( 'the failing store\'s product shows the error; the other store\'s result is untouched', array( $GLOBALS['fake_products'][70]->data['meta']['_fetch_sync_status'], $GLOBALS['fake_products'][70]->data['meta']['_fetch_sync_status_b-2'] ), array( 'error', 'synced' ) );
unset( $GLOBALS['fake_fail_store'] );
$GLOBALS['fake_posts']     = array();
$GLOBALS['fake_products']  = array();
$GLOBALS['fake_ancestors'] = array();

// --- Multilingual shops: only the default language goes to Fetch --------------------------
// A WooCommerce shop with English originals and Greek / Russian translations as their own posts.
$GLOBALS['fake_options'] = array( Fetch_WC_Settings::OPTION => array( 'environment' => 'custom', 'custom_base_url' => 'http://fetch.invalid', 'client_id' => 'c', 'client_secret' => 's', 'store_id' => 'store-1' ) );
$catalogue = array(
	// id => [type, parent, language, sku, price, original id]
	10 => array( 'product', 0, 'en', 'CCAF 011', '10', 10 ),
	11 => array( 'product', 0, 'el', 'CCAF 011-el', '10', 10 ),
	12 => array( 'product', 0, 'ru', 'CCAF 011-ru', '10', 10 ),
	13 => array( 'product', 0, '', 'NOLANG-1', '3', 13 ),       // no language assigned: default
	20 => array( 'product', 0, 'en', 'TEA-1', '5', 20 ),
	21 => array( 'product', 0, 'el', 'TEA-1', '9', 20 ),        // translation sharing the original's SKU
	30 => array( 'product', 0, 'en', 'MUG', '0', 30 ),          // variable
	31 => array( 'product_variation', 30, 'en', 'MUG-BLK', '4', 31 ),
	32 => array( 'product_variation', 30, 'en', 'MUG-WHT', '4', 32 ),
	40 => array( 'product', 0, 'el', 'MUG-el', '0', 30 ),       // variable translation
	41 => array( 'product_variation', 40, 'el', 'MUG-BLK-el', '4', 31 ),
	42 => array( 'product_variation', 40, 'el', 'MUG-WHT', '4', 32 ), // shares the original variation's SKU
);
$lang_of = function ( $id ) use ( &$catalogue ) {
	return isset( $catalogue[ $id ] ) ? $catalogue[ $id ][2] : '';
};
$build_shop = function () use ( &$catalogue ) {
	$GLOBALS['fake_posts']    = array();
	$GLOBALS['fake_products'] = array();
	foreach ( $catalogue as $id => $c ) {
		$GLOBALS['fake_posts'][ $id ] = array( $c[0], $c[1] );
		$data = array( 'id' => $id, 'name' => 'P' . $id, 'sku' => $c[3], 'price' => $c[4], 'parent' => $c[1] );
		if ( 'product_variation' === $c[0] ) {
			$GLOBALS['fake_products'][ $id ] = new WC_Product_Variation( $data );
			continue;
		}
		if ( in_array( $id, array( 30, 40 ), true ) ) {
			$data['type']     = 'variable';
			$data['children'] = 30 === $id ? array( 31, 32 ) : array( 41, 42 );
		}
		$GLOBALS['fake_products'][ $id ] = new WC_Product( $data );
	}
	// WPML / WCML copy custom fields to translations: the translation looks synced too.
	$GLOBALS['fake_products'][21]->data['meta'] = array( '_fetch_product_id' => 'prod-TEA-1', '_fetch_ref' => 'TEA-1' );
	$GLOBALS['fake_products'][10]->data['meta'] = array( '_fetch_product_id' => 'prod-CCAF 011' );
};
$sent_refs = function () {
	$refs = array();
	foreach ( $GLOBALS['fake_requests'] as $r ) {
		foreach ( $r['body']['products'] as $row ) {
			$refs[] = $row['ref'];
		}
	}
	return $refs;
};
$sent_row = function ( $ref ) {
	foreach ( $GLOBALS['fake_requests'] as $r ) {
		foreach ( $r['body']['products'] as $row ) {
			if ( $row['ref'] === $ref ) {
				return $row;
			}
		}
	}
	return null;
};
$default_refs = array( 'CCAF 011', 'NOLANG-1', 'TEA-1', 'MUG-BLK', 'MUG-WHT' );
$all_ids      = array( 10, 11, 12, 13, 20, 21, 30, 40 );

// The same checks for each plugin (and none).
$multilingual = function ( $label ) use ( $build_shop, $sent_refs, $sent_row, $default_refs, $all_ids ) {
	$build_shop();
	$GLOBALS['fake_requests'] = array();
	check( "$label: default language", Fetch_WC_Language::default_language(), 'en' );
	check( "$label: original is default", Fetch_WC_Language::is_default( 10 ), true );
	check( "$label: Greek and Russian translations are not", array( Fetch_WC_Language::is_default( 11 ), Fetch_WC_Language::is_default( 12 ) ), array( false, false ) );
	check( "$label: no language assigned counts as default", Fetch_WC_Language::is_default( 13 ), true );
	check( "$label: variation of the original is default", Fetch_WC_Language::is_default( 31 ), true );
	check( "$label: variation of a translation is not", Fetch_WC_Language::is_default( 41 ), false );
	check( "$label: a translation resolves to its original (order import)", array( Fetch_WC_Language::original_id( 11 ), Fetch_WC_Language::original_id( 21 ) ), array( 10, 20 ) );
	check( "$label: an original and a product with no language resolve to themselves", array( Fetch_WC_Language::original_id( 10 ), Fetch_WC_Language::original_id( 13 ) ), array( 10, 13 ) );

	// Full sync / hooks flush: one request with the default-language rows only.
	check( "$label: sync reaches Fetch", Fetch_WC_Product_Sync::sync_ids( $all_ids ), true );
	$refs = $sent_refs();
	sort( $refs );
	$want = $default_refs;
	sort( $want );
	check( "$label: only default-language products and variations are sent", $refs, $want );
	check( "$label: a shared SKU is sent once, with the original's price (5 + 19% VAT)", $sent_row( 'TEA-1' )['price'], '5.95' );
	check( "$label: a variation sharing the original's SKU is sent once, from the original", array( count( array_keys( $refs, 'MUG-WHT', true ) ), $sent_row( 'MUG-WHT' )['name'] ), array( 1, 'P30' ) );
	$GLOBALS['fake_requests'] = array();
	check( "$label: a page of translations only sends nothing", array( Fetch_WC_Product_Sync::sync_ids( array( 11, 12, 21, 40 ) ), $GLOBALS['fake_requests'] ), array( true, array() ) );

	// Save / stock hooks.
	unset( $GLOBALS['fake_options'][ Fetch_WC_Product_Sync::PENDING_OPTION ] );
	foreach ( array( 11, 12, 21, 40, 41, 42 ) as $id ) {
		Fetch_WC_Product_Sync::queue( $id );
	}
	check( "$label: saving a translation (or its variation) queues nothing", get_option( Fetch_WC_Product_Sync::PENDING_OPTION, array() ), array() );
	Fetch_WC_Product_Sync::queue( 10 );
	Fetch_WC_Product_Sync::queue( 31 );
	check( "$label: saving the original (or its variation) queues it", array_keys( get_option( Fetch_WC_Product_Sync::PENDING_OPTION, array() ) ), array( 10, 30 ) );

	// Delete / trash hooks.
	unset( $GLOBALS['fake_options'][ Fetch_WC_Product_Sync::DEACTIVATE_OPTION ] );
	Fetch_WC_Product_Sync::queue_deletion( 21 ); // shares the SKU and carries the original's copied meta
	Fetch_WC_Product_Sync::queue_deletion( 11 );
	Fetch_WC_Product_Sync::queue_deletion( 42 );
	check( "$label: deleting a translation does not switch off the original", get_option( Fetch_WC_Product_Sync::DEACTIVATE_OPTION, array() ), array() );
	Fetch_WC_Product_Sync::queue_deletion( 10 );
	$off = get_option( Fetch_WC_Product_Sync::DEACTIVATE_OPTION, array() );
	check( "$label: deleting the original still switches it off", array( array_keys( $off ), $off['CCAF 011']['isActive'] ), array( array( 'CCAF 011' ), false ) );

	// Mirror: translations are not kept, so their old listings are switched off.
	$keep = Fetch_WC_Mirror::shop_refs();
	$kept = array_keys( $keep );
	sort( $kept );
	check( "$label: mirror keeps the default-language refs only", $kept, $want );
	check( "$label: the product query covers every language", array( $GLOBALS['fake_wp_query']['lang'], $GLOBALS['fake_wp_query']['suppress_filters'] ), array( '', true ) );
	check( "$label: the query filter is removed afterwards", has_filter( 'woocommerce_product_data_store_cpt_get_products_query' ), false );
	$plan = Fetch_WC_Mirror::plan( array( listing( 'l1', 'CCAF 011' ), listing( 'l2', 'CCAF 011-el' ), listing( 'l3', 'CCAF 011-ru' ), listing( 'l4', 'MUG-BLK-el' ), listing( 'l5', 'TEA-1' ) ), $keep );
	check( "$label: listings that came from translations are switched off", array_column( $plan['off'], 'listingId' ), array( 'l2', 'l3', 'l4' ) );
	$api = new Fake_Listings_Api( array( listing( 'l1', 'CCAF 011' ), listing( 'l2', 'CCAF 011-el' ), listing( 'l3', 'CCAF 011-ru' ), listing( 'l5', 'TEA-1' ), listing( 'l6', 'MUG-BLK' ), listing( 'l7', 'MUG-WHT' ) ), 500 );
	$r   = Fetch_WC_Mirror::execute( $api, 'store-1', $keep, true );
	check( "$label: preview counts the translations as switched off", array( $r['off'], $r['kept'], $api->batches ), array( 2, 4, array() ) );
	check( "$label: settings readout", Fetch_WC_Language::default_language_name(), 'English' );
};

// No multilingual plugin: every product is synced as before.
$build_shop();
$GLOBALS['fake_requests'] = array();
check( 'no plugin: none detected', array( Fetch_WC_Language::plugin(), Fetch_WC_Language::default_language(), Fetch_WC_Language::default_language_name() ), array( '', '', '' ) );
check( 'no plugin: every product is default', array( Fetch_WC_Language::is_default( 10 ), Fetch_WC_Language::is_default( 11 ), Fetch_WC_Language::is_default( 41 ) ), array( true, true, true ) );
check( 'no plugin: original_id is the product itself', Fetch_WC_Language::original_id( 11 ), 11 );
Fetch_WC_Product_Sync::sync_ids( array( 10, 11, 12 ) );
check( 'no plugin: translations-as-products are sent like any product', $sent_refs(), array( 'CCAF 011', 'CCAF 011-el', 'CCAF 011-ru' ) );
check( 'no plugin: mirror keeps every product\'s ref (shared SKUs once)', count( Fetch_WC_Mirror::shop_refs() ), 8 );
check( 'no plugin: the product query is not touched', isset( $GLOBALS['fake_wp_query']['lang'] ), false );

// WPML (+ WooCommerce Multilingual), through its public filters.
add_filter( 'wpml_default_language', function () { return 'en'; } );
add_filter(
	'wpml_element_language_details',
	function ( $value, $args ) use ( $lang_of ) {
		$expected = 'post_' . get_post_type( $args['element_id'] );
		$lang     = $args['element_type'] === $expected ? $lang_of( $args['element_id'] ) : '';
		return '' === $lang ? null : (object) array( 'element_id' => $args['element_id'], 'language_code' => $lang );
	},
	10,
	2
);
add_filter(
	'wpml_object_id',
	function ( $id, $type, $return_original, $lang ) use ( &$catalogue ) {
		return 'en' === $lang && isset( $catalogue[ $id ] ) ? $catalogue[ $id ][5] : null;
	}
);
add_filter( 'wpml_active_languages', function () { return array( 'en' => array( 'code' => 'en', 'translated_name' => 'English' ), 'el' => array( 'code' => 'el', 'translated_name' => 'Greek' ) ); } );
check( 'WPML: detected', Fetch_WC_Language::plugin(), 'wpml' );
$multilingual( 'WPML' );
// WPML set up without a default language yet: nothing is filtered.
$wpml_default                                   = $GLOBALS['fake_filters']['wpml_default_language'];
$GLOBALS['fake_filters']['wpml_default_language'] = array( function () { return false; } );
check( 'WPML without a default language: every product is default', Fetch_WC_Language::is_default( 11 ), true );
$GLOBALS['fake_filters']['wpml_default_language'] = $wpml_default;

// Polylang (Polylang for WooCommerce, or products made translatable), through its functions.
// Its WPML compatibility layer answers wpml_* filters too; Polylang must win.
$GLOBALS['fake_filters'] = array( 'wpml_default_language' => array( function () { return 'xx'; } ), 'wpml_element_language_details' => array( function () { return null; } ) );
if ( ! function_exists( 'pll_default_language' ) ) {
	function pll_default_language( $field = 'slug' ) {
		return 'name' === $field ? 'English' : 'en';
	}
	function pll_get_post_language( $id, $field = 'slug' ) {
		// Polylang does not give variations a language of their own.
		$lang = 'product' === get_post_type( $id ) ? $GLOBALS['fake_lang_of']( $id ) : '';
		return '' === $lang ? false : $lang;
	}
	function pll_get_post( $id, $lang = '' ) {
		return 'product' === get_post_type( $id ) && 'en' === $lang ? $GLOBALS['fake_original_of'][ $id ] : false;
	}
}
$GLOBALS['fake_lang_of']     = $lang_of;
$GLOBALS['fake_original_of'] = array_map( function ( $c ) { return $c[5]; }, $catalogue );
check( 'Polylang: detected (before its WPML compatibility filters)', Fetch_WC_Language::plugin(), 'polylang' );
$multilingual( 'Polylang' );

echo "$passes passed, $failures failed\n";
exit( $failures ? 1 : 0 );
