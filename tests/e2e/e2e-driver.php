<?php
/**
 * Runs inside WordPress Playground: GET /e2e-driver.php?step=<name>.
 * Each step prints JSON; the node runner asserts on it.
 */
if ( ! empty( $_GET['admin'] ) ) {
	define( 'WP_ADMIN', true ); // Load plugins as on a wp-admin screen (Polylang filters admin lists by language).
}
require dirname( __DIR__ ) . '/wp-load.php';
header( 'Content-Type: application/json' );
wp_set_current_user( 1 );

$step = isset( $_GET['step'] ) ? $_GET['step'] : '';
$out  = array( 'step' => $step );

function product_stock( $id ) {
	$p = wc_get_product( $id );
	return $p ? $p->get_stock_quantity() : null;
}

/**
 * Submit the settings form through the real admin-post handler. Its redirect
 * is turned into an exception so the request carries on.
 */
function save_settings( array $fields ) {
	$s     = Fetch_WC_Settings::all();
	$_POST = array_merge(
		array(
			'action'          => Fetch_WC_Admin::ACTION,
			'do'              => 'save',
			'environment'     => $s['environment'],
			'custom_base_url' => $s['custom_base_url'],
			'client_id'       => $s['client_id'],
			'store_id'        => $s['store_id'],
			'sync_products'   => $s['sync_products'],
			'import_orders'   => $s['import_orders'],
			'auto_accept'     => $s['auto_accept'],
			'prep_minutes'    => (string) $s['prep_minutes'],
			'price_markup'    => (string) $s['price_markup'],
			'catalogue_mode'  => $s['catalogue_mode'],
			'brand_attribute' => $s['brand_attribute'],
			'_wpnonce'        => wp_create_nonce( Fetch_WC_Admin::ACTION ),
		),
		$fields
	);
	$_REQUEST = $_POST;
	$redirect = static function ( $location ) {
		throw new RuntimeException( $location );
	};
	add_filter( 'wp_redirect', $redirect );
	try {
		Fetch_WC_Admin::handle();
	} catch ( RuntimeException $e ) {
		// Redirected back to the settings page, as in the browser.
	}
	remove_filter( 'wp_redirect', $redirect );
	$notice = get_transient( 'fetch_wc_notice_' . get_current_user_id() );
	delete_transient( 'fetch_wc_notice_' . get_current_user_id() );
	return is_array( $notice ) ? $notice[1] : null;
}

/** Post one of the standalone forms (Preview, Apply anyway): action, nonce, "do" and, per store, "store". */
function post_action( $do, array $extra = array() ) {
	$_POST    = array_merge(
		array(
			'action'   => Fetch_WC_Admin::ACTION,
			'do'       => $do,
			'_wpnonce' => wp_create_nonce( Fetch_WC_Admin::ACTION ),
		),
		$extra
	);
	$_REQUEST = $_POST;
	$redirect = static function ( $location ) {
		throw new RuntimeException( $location );
	};
	add_filter( 'wp_redirect', $redirect );
	try {
		Fetch_WC_Admin::handle();
	} catch ( RuntimeException $e ) {
		// Redirected back to the settings page.
	}
	remove_filter( 'wp_redirect', $redirect );
	$notice = get_transient( 'fetch_wc_notice_' . get_current_user_id() );
	delete_transient( 'fetch_wc_notice_' . get_current_user_id() );
	return is_array( $notice ) ? $notice[1] : null;
}

/** "Sync all products now", with its Action Scheduler pages run here, to the end. */
function run_full_sync() {
	save_settings( array( 'do' => 'bulk_sync' ) );
	as_unschedule_all_actions( Fetch_WC_Product_Sync::BULK_HOOK, null, Fetch_WC_Product_Sync::GROUP );
	// Each page queues the next one; the empty last page ends the sync and queues nothing.
	for ( $page = 1; $page <= 100; $page++ ) {
		Fetch_WC_Product_Sync::bulk_page( $page );
		$more = as_has_scheduled_action( Fetch_WC_Product_Sync::BULK_HOOK, null, Fetch_WC_Product_Sync::GROUP );
		as_unschedule_all_actions( Fetch_WC_Product_Sync::BULK_HOOK, null, Fetch_WC_Product_Sync::GROUP );
		if ( ! $more ) {
			break;
		}
	}
}

/** Save the 1.4.0 settings form: the store list with its "Connect" ticks and per-store fields. */
function save_stores( array $rows, array $fields = array() ) {
	return save_settings( array_merge( array( 'stores_form' => '1', 'stores' => $rows ), $fields ) );
}

/** One store's full sync, its Action Scheduler pages run here, to the end. */
function run_bulk( $store_id ) {
	as_unschedule_all_actions( Fetch_WC_Product_Sync::BULK_HOOK, null, Fetch_WC_Product_Sync::GROUP );
	for ( $page = 1; $page <= 100; $page++ ) {
		Fetch_WC_Product_Sync::bulk_page( $page, $store_id );
		$more = as_has_scheduled_action( Fetch_WC_Product_Sync::BULK_HOOK, Fetch_WC_Settings::job_args( $store_id, array( 'page' => $page + 1 ) ), Fetch_WC_Product_Sync::GROUP );
		as_unschedule_all_actions( Fetch_WC_Product_Sync::BULK_HOOK, null, Fetch_WC_Product_Sync::GROUP );
		if ( ! $more ) {
			break;
		}
	}
}

function scheduled_args( $hook ) {
	$actions = as_get_scheduled_actions( array( 'hook' => $hook, 'status' => ActionScheduler_Store::STATUS_PENDING, 'group' => Fetch_WC_Product_Sync::GROUP, 'per_page' => 50 ) );
	return array_values( array_map( function ( $a ) { return $a->get_args(); }, $actions ) );
}

function make_in_category( $name, $sku, $price, $stock, $term_id ) {
	$p = new WC_Product_Simple();
	$p->set_name( $name );
	$p->set_sku( $sku );
	$p->set_regular_price( $price );
	$p->set_manage_stock( true );
	$p->set_stock_quantity( $stock );
	$p->set_category_ids( array( $term_id ) );
	$p->set_status( 'publish' );
	return $p->save();
}

function store_status_cells( $html ) {
	preg_match_all( '#<div class="fetch-wc-store" data-store="([^"]+)">.*?<td class="fetch-wc-store-status">(.*?)</td>#s', $html, $m, PREG_SET_ORDER );
	$out = array();
	foreach ( $m as $row ) {
		$out[ $row[1] ] = html_entity_decode( wp_strip_all_tags( str_replace( '<br>', ' | ', $row[2] ) ) );
	}
	return $out;
}

/** A Polylang language, through whichever API this Polylang version has. */
function pll_e2e_add_language( array $args ) {
	$model = PLL()->model;
	if ( isset( $model->languages ) && is_object( $model->languages ) && method_exists( $model->languages, 'add' ) ) {
		return $model->languages->add( $args );
	}
	return $model->add_language( $args );
}

function make_simple( $name, $sku, $price, $stock ) {
	$p = new WC_Product_Simple();
	$p->set_name( $name );
	$p->set_sku( $sku );
	$p->set_regular_price( $price );
	$p->set_manage_stock( true );
	$p->set_stock_quantity( $stock );
	$p->set_status( 'publish' );
	return $p->save();
}

function make_variable( $name, $sku, array $variation_skus ) {
	$attr = new WC_Product_Attribute();
	$attr->set_name( 'Colour' );
	$attr->set_options( array_keys( $variation_skus ) );
	$attr->set_visible( true );
	$attr->set_variation( true );
	$p = new WC_Product_Variable();
	$p->set_name( $name );
	$p->set_sku( $sku );
	$p->set_attributes( array( $attr ) );
	$p->set_status( 'publish' );
	$id = $p->save();
	foreach ( $variation_skus as $colour => $vsku ) {
		$v = new WC_Product_Variation();
		$v->set_parent_id( $id );
		$v->set_attributes( array( 'colour' => $colour ) );
		$v->set_sku( $vsku );
		$v->set_regular_price( '8' );
		$v->set_manage_stock( true );
		$v->set_stock_quantity( 5 );
		$v->set_status( 'publish' );
		$v->save();
	}
	return $id;
}

function recent_log( $n = 8 ) {
	return array_column( array_slice( Fetch_WC_Logger::activity(), 0, $n ), 'message' );
}

function render_page() {
	require_once ABSPATH . 'wp-admin/includes/template.php';
	ob_start();
	Fetch_WC_Admin::notices();
	Fetch_WC_Admin::render();
	return ob_get_clean();
}

switch ( $step ) {
	case 'ping':
		$out['ready'] = class_exists( 'WooCommerce' ) && class_exists( 'Fetch_WC_Settings' );
		break;

	case 'configure':
		Fetch_WC_Settings::update(
			array(
				'environment'     => 'custom',
				'custom_base_url' => 'http://127.0.0.1:9099',
				'client_id'       => 'client-1',
				'client_secret'   => 'secret-1',
				'sync_products'   => 'yes',
				'import_orders'   => 'yes',
				'prep_minutes'    => 20,
			)
		);
		Fetch_WC_Api::forget_token();
		$stores = ( new Fetch_WC_Api() )->list_stores();
		$out['stores'] = $stores;
		Fetch_WC_Settings::update( array( 'store_id' => 'store-1', 'store_name' => 'Mock Shop' ) );
		$hook = ( new Fetch_WC_Api() )->register_webhook( Fetch_WC_Webhook::url() );
		$out['webhook_register'] = $hook;
		Fetch_WC_Settings::update( array( 'webhook_secret' => $hook['body']['signingSecret'], 'webhook_registered' => time() ) );
		$out['webhook_url'] = Fetch_WC_Webhook::url();
		$out['active'] = is_plugin_active( 'fetch-woocommerce/fetch-for-woocommerce.php' );
		$out['wc'] = class_exists( 'WooCommerce' ) ? WC()->version : null;
		break;

	case 'create_products':
		// Hooks were registered at boot with sync on? Settings were saved after boot of the previous request, so this request has them.
		$simple = new WC_Product_Simple();
		$simple->set_name( 'USB-C Cable 1m' );
		$simple->set_sku( 'CBL-001' );
		$simple->set_regular_price( '9.5' );
		$simple->set_sale_price( '7' );
		$simple->set_manage_stock( true );
		$simple->set_stock_quantity( 10 );
		$simple->set_status( 'publish' );
		$simple_id = $simple->save();

		$attr = new WC_Product_Attribute();
		$attr->set_name( 'Colour' );
		$attr->set_options( array( 'Black', 'White' ) );
		$attr->set_visible( true );
		$attr->set_variation( true );
		$variable = new WC_Product_Variable();
		$variable->set_name( 'Phone Case' );
		$variable->set_sku( 'CASE' );
		$variable->set_attributes( array( $attr ) );
		$variable->set_status( 'publish' );
		$variable_id = $variable->save();
		foreach ( array( 'Black' => 4, 'White' => 0 ) as $colour => $qty ) {
			$v = new WC_Product_Variation();
			$v->set_parent_id( $variable_id );
			$v->set_attributes( array( 'colour' => $colour ) );
			$v->set_sku( 'CASE-' . strtoupper( $colour ) );
			$v->set_regular_price( '12' );
			$v->set_manage_stock( true );
			$v->set_stock_quantity( $qty );
			$v->set_status( 'publish' );
			$v->save();
		}
		$out['pending']     = array_keys( get_option( Fetch_WC_Product_Sync::PENDING_OPTION, array() ) );
		$out['scheduled']   = as_has_scheduled_action( Fetch_WC_Product_Sync::FLUSH_HOOK, array(), Fetch_WC_Product_Sync::GROUP );
		Fetch_WC_Product_Sync::flush();
		$out['simple_id']   = $simple_id;
		$out['variable_id'] = $variable_id;
		$out['simple_meta'] = array(
			'ref'    => get_post_meta( $simple_id, '_fetch_ref', true ),
			'pid'    => get_post_meta( $simple_id, '_fetch_product_id', true ),
			'status' => get_post_meta( $simple_id, '_fetch_sync_status', true ),
		);
		break;

	case 'change_stock':
		$id = wc_get_product_id_by_sku( 'CBL-001' );
		wc_update_product_stock( $id, 6 );
		$out['pending'] = array_keys( get_option( Fetch_WC_Product_Sync::PENDING_OPTION, array() ) );
		Fetch_WC_Product_Sync::flush();
		$out['stock'] = product_stock( $id );
		break;

	case 'markup':
		as_unschedule_all_actions( Fetch_WC_Product_Sync::BULK_HOOK, null, Fetch_WC_Product_Sync::GROUP );
		$out['notice']         = save_settings( array( 'price_markup' => '10' ) );
		$out['saved']          = Fetch_WC_Settings::get( 'price_markup' );
		$out['bulk_scheduled'] = as_has_scheduled_action( Fetch_WC_Product_Sync::BULK_HOOK, null, Fetch_WC_Product_Sync::GROUP );
		// Run the queued full sync now instead of waiting for Action Scheduler.
		as_unschedule_all_actions( Fetch_WC_Product_Sync::BULK_HOOK, null, Fetch_WC_Product_Sync::GROUP );
		Fetch_WC_Product_Sync::bulk_page( 1 );
		Fetch_WC_Product_Sync::bulk_page( 2 );
		as_unschedule_all_actions( Fetch_WC_Product_Sync::BULK_HOOK, null, Fetch_WC_Product_Sync::GROUP );
		// Saving the same markup again must not resend the catalogue.
		$out['same_notice']         = save_settings( array( 'price_markup' => '10.00' ) );
		$out['same_bulk_scheduled'] = as_has_scheduled_action( Fetch_WC_Product_Sync::BULK_HOOK, null, Fetch_WC_Product_Sync::GROUP );
		$out['wc_price']            = wc_get_product( wc_get_product_id_by_sku( 'CBL-001' ) )->get_regular_price( 'edit' );
		break;

	case 'server':
		// Runs last: switching server clears the store, as for a real merchant.
		require_once ABSPATH . 'wp-admin/includes/template.php';
		$render = static function () {
			ob_start();
			Fetch_WC_Admin::render();
			return ob_get_clean();
		};
		$html = $render();
		preg_match_all( '/<option value="(production|staging|custom)"/', $html, $m );
		$out['options']            = $m[1];
		$out['help']               = false !== strpos( $html, 'Your key decides whether you see test data.' );
		$out['custom_shown']       = (bool) preg_match( '/id="fetch_wc_custom_base_url_row"\s*>/', $html );
		$out['custom_url_kept']    = Fetch_WC_Settings::base_url();
		$out['token_before']       = (bool) get_transient( Fetch_WC_Api::TOKEN_TRANSIENT );
		$out['notice']             = save_settings( array( 'environment' => 'staging' ) );
		$out['staging_url']        = Fetch_WC_Settings::base_url();
		$out['token_after']        = (bool) get_transient( Fetch_WC_Api::TOKEN_TRANSIENT );
		$html                      = $render();
		$out['staging_selected']   = (bool) preg_match( '/<option value="staging"\s+selected/', $html );
		$out['custom_hidden']      = (bool) preg_match( '/id="fetch_wc_custom_base_url_row"\s*hidden/', $html );
		save_settings( array( 'environment' => 'nonsense' ) );
		$out['garbage_saved_as']   = Fetch_WC_Settings::get( 'environment' );
		$out['production_url']     = Fetch_WC_Settings::base_url();
		break;

	case 'poll':
		Fetch_WC_Order_Sync::poll();
		$order = Fetch_WC_Order_Sync::find( 'ord-1' );
		$out['found'] = (bool) $order;
		if ( $order ) {
			$items = array();
			foreach ( $order->get_items() as $item ) {
				$items[] = array( 'name' => $item->get_name(), 'product_id' => $item->get_product_id(), 'qty' => $item->get_quantity(), 'total' => $item->get_total() );
			}
			$ship = array();
			foreach ( $order->get_items( 'shipping' ) as $s ) {
				$ship[] = array( 'title' => $s->get_method_title(), 'total' => $s->get_total() );
			}
			$out['order'] = array(
				'id'       => $order->get_id(),
				'status'   => $order->get_status(),
				'total'    => $order->get_total(),
				'items'    => $items,
				'shipping' => $ship,
				'fetch_status' => $order->get_meta( '_fetch_status' ),
				'billing'  => $order->get_formatted_billing_full_name() . ' / ' . $order->get_billing_phone() . ' / ' . $order->get_billing_address_1(),
				'note'     => $order->get_customer_note(),
				'payment'  => $order->get_payment_method_title(),
				'stock_reduced' => (bool) $order->get_data_store()->get_stock_reduced( $order->get_id() ),
			);
		}
		$out['stock'] = product_stock( wc_get_product_id_by_sku( 'CBL-001' ) );
		// Second poll must not duplicate.
		Fetch_WC_Order_Sync::poll();
		$out['count_after_second_poll'] = count( wc_get_orders( array( 'limit' => -1, 'meta_key' => '_fetch_order_id', 'meta_value' => 'ord-1' ) ) );
		break;

	case 'poll2':
		Fetch_WC_Order_Sync::poll();
		$a = Fetch_WC_Order_Sync::find( 'ord-1' );
		$b = Fetch_WC_Order_Sync::find( 'ord-2' );
		$out['ids'] = array( $a ? $a->get_id() : null, $b ? $b->get_id() : null );
		$out['ord2_fetch_id'] = $b ? $b->get_meta( '_fetch_order_id' ) : null;
		$out['missing'] = Fetch_WC_Order_Sync::find( 'does-not-exist' ) ? 'found' : null;
		break;

	case 'accept':
		$order = Fetch_WC_Order_Sync::find( 'ord-1' );
		$out['error']  = Fetch_WC_Order_Actions::run( $order, 'accept', array( 'prepMinutes' => 25 ) );
		$order = wc_get_order( $order->get_id() );
		$out['status'] = $order->get_status();
		$out['fetch_status'] = $order->get_meta( '_fetch_status' );
		$out['second_accept_error'] = Fetch_WC_Order_Actions::run( $order, 'accept', array() );
		break;

	case 'state':
		$order = Fetch_WC_Order_Sync::find( 'ord-1' );
		$out['status'] = $order ? wc_get_order( $order->get_id() )->get_status() : null;
		$out['fetch_status'] = $order ? $order->get_meta( '_fetch_status' ) : null;
		$out['stock'] = product_stock( wc_get_product_id_by_sku( 'CBL-001' ) );
		$out['notes'] = $order ? array_map( function ( $n ) { return $n->content; }, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) ) : array();
		$out['pending'] = array_keys( get_option( Fetch_WC_Product_Sync::PENDING_OPTION, array() ) );
		Fetch_WC_Product_Sync::flush();
		break;

	case 'many_variations':
		// One variable product with 210 variations = 210 Fetch rows: more than one PUT may carry.
		$attr = new WC_Product_Attribute();
		$attr->set_name( 'Size' );
		$attr->set_options( array_map( 'strval', range( 1, 210 ) ) );
		$attr->set_visible( true );
		$attr->set_variation( true );
		$big = new WC_Product_Variable();
		$big->set_name( 'Big Pack' );
		$big->set_sku( 'BIG' );
		$big->set_attributes( array( $attr ) );
		$big->set_status( 'publish' );
		$big_id = $big->save();
		foreach ( range( 1, 210 ) as $size ) {
			$v = new WC_Product_Variation();
			$v->set_parent_id( $big_id );
			$v->set_attributes( array( 'size' => (string) $size ) );
			$v->set_regular_price( '3' );
			$v->set_status( 'publish' );
			$v->save();
		}
		$out['pending'] = array_keys( get_option( Fetch_WC_Product_Sync::PENDING_OPTION, array() ) );
		Fetch_WC_Product_Sync::flush();
		$out['big_id']        = $big_id;
		$out['pending_after'] = array_keys( get_option( Fetch_WC_Product_Sync::PENDING_OPTION, array() ) );
		$synced               = 0;
		foreach ( wc_get_product( $big_id )->get_children() as $child ) {
			$synced += get_post_meta( $child, '_fetch_product_id', true ) ? 1 : 0;
		}
		$out['variations_synced'] = $synced;
		break;

	case 'delete_products':
		$make = static function ( $name, $sku, $price ) {
			$p = new WC_Product_Simple();
			$p->set_name( $name );
			$p->set_sku( $sku );
			$p->set_regular_price( $price );
			$p->set_manage_stock( true );
			$p->set_stock_quantity( 3 );
			$p->set_status( 'publish' );
			return $p->save();
		};
		$lamp = $make( 'Desk Lamp', 'LAMP-1', '22' );
		$mug  = $make( 'Mug', 'MUG-1', '6' );
		Fetch_WC_Product_Sync::flush();
		$out['synced'] = (bool) get_post_meta( $lamp, '_fetch_product_id', true ) && (bool) get_post_meta( $mug, '_fetch_product_id', true );
		wp_delete_post( $lamp, true ); // wp-admin "Delete permanently"
		$big_children        = wc_get_product( wc_get_product_id_by_sku( 'BIG' ) )->get_children();
		$out['variation_ref'] = get_post_meta( $big_children[0], '_fetch_ref', true );
		wp_delete_post( $big_children[0], true ); // one variation on its own
		wc_get_product( wc_get_product_id_by_sku( 'CASE' ) )->delete( true ); // WooCommerce API, variable product
		wp_trash_post( $mug );
		$out['deactivations_queued'] = array_keys( get_option( Fetch_WC_Product_Sync::DEACTIVATE_OPTION, array() ) );
		Fetch_WC_Product_Sync::flush();
		$out['deactivations_after'] = count( get_option( Fetch_WC_Product_Sync::DEACTIVATE_OPTION, array() ) );
		break;

	case 'poll_only':
		Fetch_WC_Order_Sync::poll();
		break;

	case 'order_info':
		$fetch_id = isset( $_GET['order'] ) ? (string) $_GET['order'] : '';
		$order    = Fetch_WC_Order_Sync::find( $fetch_id );
		$out['count'] = count( wc_get_orders( array( 'limit' => -1, 'meta_key' => '_fetch_order_id', 'meta_value' => $fetch_id ) ) );
		if ( $order ) {
			$order = wc_get_order( $order->get_id() );
			$out['order'] = array(
				'status'       => $order->get_status(),
				'fetch_status' => $order->get_meta( '_fetch_status' ),
				'masked'       => $order->get_meta( '_fetch_customer_masked' ),
				'name'         => $order->get_formatted_billing_full_name(),
				'phone'        => $order->get_billing_phone(),
				'address'      => $order->get_billing_address_1() . ' / ' . $order->get_billing_city() . ' / ' . $order->get_billing_postcode(),
				'shipping'     => $order->get_shipping_address_1(),
				'note'         => $order->get_customer_note(),
				'has_mask'     => false !== strpos( wp_json_encode( array( $order->get_address( 'billing' ), $order->get_address( 'shipping' ), $order->get_customer_note() ) ), '******' ),
				'notes'        => array_map( function ( $n ) { return $n->content; }, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) ),
			);
		}
		break;

	case 'hold_lock':
		// Stand in for a request that is importing this order right now.
		$token = Fetch_WC_Order_Sync::lock( (string) $_GET['order'] );
		update_option( 'e2e_lock_token', $token, false );
		$out['held'] = is_string( $token );
		break;

	case 'release_lock':
		Fetch_WC_Order_Sync::unlock( (string) $_GET['order'], get_option( 'e2e_lock_token' ) );
		delete_option( 'e2e_lock_token' );
		$out['released'] = true;
		break;

	case 'mirror_off':
		// A product type the plugin does not sync: its old Fetch listing is "excluded by the filter".
		$ext = new WC_Product_External();
		$ext->set_name( 'Gift card' );
		$ext->set_sku( 'EXT-1' );
		$ext->set_regular_price( '20' );
		$ext->set_product_url( 'https://example.com/gift' );
		$ext->set_status( 'publish' );
		$ext->save();
		Fetch_WC_Product_Sync::flush();
		$out['mode'] = Fetch_WC_Settings::get( 'catalogue_mode' );
		run_full_sync();
		$out['log'] = recent_log();
		break;

	case 'mirror_preview':
		$out['notice']  = post_action( 'mirror_preview' );
		$out['preview'] = get_transient( 'fetch_wc_mirror_preview_' . get_current_user_id() );
		$out['mode']    = Fetch_WC_Settings::get( 'catalogue_mode' );
		$html           = render_page();
		$out['html_has_count']  = 1 === preg_match( '/(\d+) of (\d+) Fetch products would be switched off\./', $html, $m );
		$out['html_count']      = $m ? array( (int) $m[1], (int) $m[2] ) : null;
		$out['html_samples']    = preg_match_all( '#<li>([^<]+)</li>#', substr( $html, (int) strpos( $html, 'id="fetch_wc_mirror_preview"' ) ), $li ) ? $li[1] : array();
		$out['html_help']       = false !== strpos( $html, 'Products in your Fetch store that aren&#039;t in this shop will be switched off on Fetch (not deleted). Prices and stock always follow this shop.' );
		$out['html_modes']      = preg_match_all( '/<option value="(add_update|mirror)"/', $html, $mm ) ? $mm[1] : array();
		$out['html_preview_form'] = false !== strpos( $html, 'form="fetch_wc_mirror_preview_form"' ) && false !== strpos( $html, 'id="fetch_wc_mirror_preview_form"' );
		break;

	case 'mirror_on':
		$out['notice'] = save_settings( array( 'catalogue_mode' => 'mirror' ) );
		$out['mode']   = Fetch_WC_Settings::get( 'catalogue_mode' );
		run_full_sync();
		$out['log']     = recent_log();
		$out['pending'] = Fetch_WC_Mirror::pending();
		break;

	case 'mirror_comeback':
		// The shop starts selling a product whose listing the mirror switched off.
		$back = new WC_Product_Simple();
		$back->set_name( 'Returning Lamp' );
		$back->set_sku( 'BACK-1' );
		$back->set_regular_price( '15' );
		$back->set_status( 'publish' );
		$back->save();
		Fetch_WC_Product_Sync::flush();
		$out['synced'] = get_post_meta( $back->get_id(), '_fetch_sync_status', true );
		run_full_sync();
		$out['log'] = recent_log();
		break;

	case 'mirror_sync':
		run_full_sync();
		$out['log']     = recent_log();
		$out['pending'] = Fetch_WC_Mirror::pending();
		$html           = render_page();
		$out['notice_shown'] = false !== strpos( $html, 'id="fetch_wc_mirror_notice"' );
		$out['apply_button'] = false !== strpos( $html, 'value="mirror_apply"' );
		$out['notice_text']  = preg_match( '/It would switch off (\d+) of your (\d+) active Fetch products/', $html, $m ) ? array( (int) $m[1], (int) $m[2] ) : null;
		break;

	case 'mirror_apply':
		$out['notice']    = post_action( 'mirror_apply' );
		$actions          = as_get_scheduled_actions( array( 'hook' => Fetch_WC_Mirror::HOOK, 'status' => ActionScheduler_Store::STATUS_PENDING, 'group' => Fetch_WC_Product_Sync::GROUP ) );
		$out['scheduled'] = array_values( array_map( function ( $a ) { return $a->get_args(); }, $actions ) );
		$out['pending_after_confirm'] = Fetch_WC_Mirror::pending();
		as_unschedule_all_actions( Fetch_WC_Mirror::HOOK, null, Fetch_WC_Product_Sync::GROUP );
		// Run the confirmed action now instead of waiting for Action Scheduler.
		foreach ( $out['scheduled'] as $args ) {
			do_action_ref_array( Fetch_WC_Mirror::HOOK, array_values( $args ) );
		}
		$out['log']          = recent_log();
		$out['pending']      = Fetch_WC_Mirror::pending();
		$out['notice_shown'] = false !== strpos( render_page(), 'id="fetch_wc_mirror_notice"' );
		save_settings( array( 'catalogue_mode' => 'add_update' ) );
		$out['mode_after'] = Fetch_WC_Settings::get( 'catalogue_mode' );
		break;

	case 'lang_activate':
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$result        = activate_plugin( 'polylang/polylang.php' );
		$out['error']  = is_wp_error( $result ) ? $result->get_error_message() : null;
		$out['active'] = is_plugin_active( 'polylang/polylang.php' );
		break;

	case 'lang_setup':
		// English first: Polylang makes the first language the default.
		foreach ( array( array( 'English', 'en', 'en_US', 0 ), array( 'Ελληνικά', 'el', 'el', 1 ), array( 'Русский', 'ru', 'ru_RU', 2 ) ) as $l ) {
			$r = pll_e2e_add_language( array( 'name' => $l[0], 'slug' => $l[1], 'locale' => $l[2], 'rtl' => false, 'term_group' => $l[3] ) );
			$out['add'][ $l[1] ] = is_wp_error( $r ) ? $r->get_error_message() : $r;
		}
		// Products translatable, the way Polylang for WooCommerce makes them.
		wp_mkdir_p( WPMU_PLUGIN_DIR );
		$out['mu_plugin'] = (bool) file_put_contents(
			WPMU_PLUGIN_DIR . '/e2e-polylang-products.php',
			"<?php\nadd_filter( 'pll_get_post_types', function ( \$types ) { return \$types + array( 'product' => 'product' ); } );\n"
		);
		break;

	case 'lang_products':
		$out['default']    = pll_default_language();
		$out['languages']  = pll_languages_list();
		$out['translated'] = pll_is_translated_post_type( 'product' );
		$out['no_language'] = pll_get_post_language( wc_get_product_id_by_sku( 'CBL-001' ) );
		// Translations made the WPML way: separate posts, "-el"/"-ru" SKUs or the original's SKU.
		add_filter( 'wc_product_has_unique_sku', '__return_false' );
		$cup    = make_simple( 'Coffee Cup', 'CCAF 011', '10', 7 );
		$cup_el = make_simple( 'Κούπα καφέ', 'CCAF 011-el', '10', 7 );
		$cup_ru = make_simple( 'Кофейная чашка', 'CCAF 011-ru', '10', 7 );
		$tea    = make_simple( 'Green Tea', 'TEA-1', '5', 8 );
		$tea_el = make_simple( 'Πράσινο τσάι', 'TEA-1', '9', 8 );
		$mug    = make_variable( 'Travel Mug', 'TMUG', array( 'Black' => 'TMUG-BLK', 'White' => 'TMUG-WHT' ) );
		$mug_el = make_variable( 'Κούπα ταξιδιού', 'TMUG-el', array( 'Black' => 'TMUG-BLK-el', 'White' => 'TMUG-WHT' ) );
		remove_filter( 'wc_product_has_unique_sku', '__return_false' );
		foreach ( array( $cup => 'en', $cup_el => 'el', $cup_ru => 'ru', $tea => 'en', $tea_el => 'el', $mug => 'en', $mug_el => 'el' ) as $id => $lang ) {
			pll_set_post_language( $id, $lang );
		}
		pll_save_post_translations( array( 'en' => $cup, 'el' => $cup_el, 'ru' => $cup_ru ) );
		pll_save_post_translations( array( 'en' => $tea, 'el' => $tea_el ) );
		pll_save_post_translations( array( 'en' => $mug, 'el' => $mug_el ) );
		$out['queued'] = array_keys( get_option( Fetch_WC_Product_Sync::PENDING_OPTION, array() ) );
		// The translations were queued on save, before they had a language: the flush skips them.
		Fetch_WC_Product_Sync::flush();
		$ids = compact( 'cup', 'cup_el', 'cup_ru', 'tea', 'tea_el', 'mug', 'mug_el' );
		update_option( 'e2e_lang_ids', $ids, false );
		$out['ids'] = $ids;
		$out['translation_status'] = array( get_post_meta( $cup_el, '_fetch_sync_status', true ), get_post_meta( $tea_el, '_fetch_sync_status', true ) );
		// Editing a translation (price, stock) sends nothing.
		$el = wc_get_product( $cup_el );
		$el->set_regular_price( '12' );
		$el->save();
		wc_update_product_stock( $tea_el, 3 );
		$out['pending_after_translation_edit'] = array_keys( get_option( Fetch_WC_Product_Sync::PENDING_OPTION, array() ) );
		wc_update_product_stock( $tea_el, 8 );
		// WPML / WCML copy custom fields to translations: the copy must not confuse orders or deletes.
		foreach ( array( '_fetch_ref', '_fetch_product_id' ) as $key ) {
			update_post_meta( $tea_el, $key, get_post_meta( $tea, $key, true ) );
		}
		delete_option( Fetch_WC_Product_Sync::PENDING_OPTION );
		break;

	case 'lang_preview':
		// The merchant browses wp-admin with Polylang's language filter on Greek.
		update_user_meta( 1, 'pll_filter_content', 'el' );
		$out['is_admin'] = is_admin();
		$out['notice']   = post_action( 'mirror_preview' );
		$out['preview']  = get_transient( 'fetch_wc_mirror_preview_' . get_current_user_id() );
		delete_user_meta( 1, 'pll_filter_content' );
		break;

	case 'lang_mirror':
		$out['notice'] = save_settings( array( 'catalogue_mode' => 'mirror' ) );
		run_full_sync();
		$out['log']     = recent_log();
		$out['pending'] = Fetch_WC_Mirror::pending();
		save_settings( array( 'catalogue_mode' => 'add_update' ) );
		break;

	case 'lang_order_info':
		$ids   = get_option( 'e2e_lang_ids' );
		$order = Fetch_WC_Order_Sync::find( (string) $_GET['order'] );
		$out['items'] = array();
		foreach ( $order ? $order->get_items() : array() as $item ) {
			$out['items'][] = array( 'name' => $item->get_name(), 'product_id' => $item->get_product_id(), 'qty' => $item->get_quantity() );
		}
		$out['ids']   = $ids;
		$out['stock'] = array(
			'tea'    => product_stock( $ids['tea'] ),
			'tea_el' => product_stock( $ids['tea_el'] ),
			'cup'    => product_stock( $ids['cup'] ),
			'cup_el' => product_stock( $ids['cup_el'] ),
		);
		Fetch_WC_Product_Sync::flush();
		break;

	case 'lang_delete':
		$ids = get_option( 'e2e_lang_ids' );
		wp_trash_post( $ids['cup_el'] );
		wp_delete_post( $ids['cup_ru'], true );
		wp_delete_post( $ids['tea_el'], true ); // shares the SKU and carries the original's copied meta
		$el_children = wc_get_product( $ids['mug_el'] )->get_children();
		wp_delete_post( $el_children[1], true ); // the variation sharing "TMUG-WHT"
		wc_get_product( $ids['mug_el'] )->delete( true );
		$out['pending']       = array_keys( get_option( Fetch_WC_Product_Sync::PENDING_OPTION, array() ) );
		$out['deactivations'] = array_keys( get_option( Fetch_WC_Product_Sync::DEACTIVATE_OPTION, array() ) );
		Fetch_WC_Product_Sync::flush();
		break;

	case 'lang_readout':
		$html            = render_page();
		$out['help']     = false !== strpos( $html, 'Multilingual shops (WPML, Polylang) sync products in the default language only; translations are not sent to Fetch.' );
		$out['readout']  = preg_match( '#<strong>(Synced language: [^<]+)</strong>#', $html, $m ) ? $m[1] : null;
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		deactivate_plugins( 'polylang/polylang.php' );
		wp_delete_file( WPMU_PLUGIN_DIR . '/e2e-polylang-products.php' );
		$out['deactivated'] = ! is_plugin_active( 'polylang/polylang.php' );
		break;

	case 'lang_after':
		$html           = render_page();
		$out['help']    = false !== strpos( $html, 'sync products in the default language only' );
		$out['readout'] = false !== strpos( $html, 'Synced language:' );
		break;

	case 'content_setup':
		// Global attributes exist before the product, as in a real shop: WooCommerce
		// registers attribute taxonomies (and their labels) on init, so the next
		// request sees them.
		foreach ( array( 'brands' => array( 'Brands', 'HAIER' ), 'dimensions' => array( 'Width', '65 cm' ) ) as $slug => $def ) {
			$id = wc_attribute_taxonomy_id_by_name( $slug );
			if ( ! $id ) {
				$id = wc_create_attribute( array( 'name' => $def[0], 'slug' => $slug ) );
			}
			$taxonomy = wc_attribute_taxonomy_name( $slug );
			register_taxonomy( $taxonomy, array( 'product' ) );
			if ( ! term_exists( $def[1], $taxonomy ) ) {
				wp_insert_term( $def[1], $taxonomy );
			}
			$out['attributes'][ $taxonomy ] = $id;
		}
		break;

	case 'content':
		// A hob shaped like a real shop's: brand in the global "Brands" (pa_brands),
		// specs from global and local attributes, HTML descriptions, SEO meta.
		$global = static function ( $slug, $term ) {
			$taxonomy = wc_attribute_taxonomy_name( $slug );
			$t        = term_exists( $term, $taxonomy );
			$attr     = new WC_Product_Attribute();
			$attr->set_id( wc_attribute_taxonomy_id_by_name( $slug ) );
			$attr->set_name( $taxonomy );
			$attr->set_options( array( (int) $t['term_id'] ) );
			$attr->set_visible( true );
			$attr->set_variation( false );
			return $attr;
		};
		$local = static function ( $name, $value, $visible = true ) {
			$attr = new WC_Product_Attribute();
			$attr->set_name( $name );
			$attr->set_options( array( $value ) );
			$attr->set_visible( $visible );
			$attr->set_variation( false );
			return $attr;
		};
		$hob = new WC_Product_Simple();
		$hob->set_name( 'Induction Hob 65cm' );
		$hob->set_sku( 'HOB-65' );
		$hob->set_regular_price( '500' );
		$hob->set_status( 'publish' );
		$hob->set_description( "<p>A 65 cm induction hob.</p>\n<p>Touch controls &amp; timer;<br>cut-out 56 x 48 cm.</p>" );
		$hob->set_short_description( '<p>Four zones, Varycook.</p>' );
		$hob->set_attributes(
			array(
				$global( 'brands', 'HAIER' ),
				$local( 'Type', 'Induction' ),
				$local( 'Number of Cooking Zones', '4' ),
				$local( 'Internal code', 'X-99', false ),
				$global( 'dimensions', '65 cm' ),
			)
		);
		$hob->update_meta_data( '_yoast_wpseo_title', 'Haier induction hob | Mock Shop' );
		$hob->update_meta_data( 'rank_math_description', 'A 65 cm Haier induction hob with four zones.' );
		$hob_id = $hob->save();
		Fetch_WC_Product_Sync::flush();
		$out['hob_status'] = get_post_meta( $hob_id, '_fetch_sync_status', true );

		$html                  = render_page();
		$out['brand_select']   = preg_match( '#<select id="fetch_wc_brand_attribute" name="brand_attribute">(.*?)</select>#s', $html, $m ) && preg_match_all( '#<option value="([^"]*)"#', $m[1], $o ) ? $o[1] : null;
		as_unschedule_all_actions( Fetch_WC_Product_Sync::BULK_HOOK, null, Fetch_WC_Product_Sync::GROUP );
		$out['brand_notice']   = save_settings( array( 'brand_attribute' => 'pa_dimensions' ) );
		$out['brand_saved']    = Fetch_WC_Settings::get( 'brand_attribute' );
		$out['bulk_scheduled'] = as_has_scheduled_action( Fetch_WC_Product_Sync::BULK_HOOK, null, Fetch_WC_Product_Sync::GROUP );
		as_unschedule_all_actions( Fetch_WC_Product_Sync::BULK_HOOK, null, Fetch_WC_Product_Sync::GROUP );
		Fetch_WC_Product_Sync::queue( $hob_id );
		Fetch_WC_Product_Sync::flush();
		save_settings( array( 'brand_attribute' => 'pa_nope' ) );
		$out['unknown_saved_as'] = Fetch_WC_Settings::get( 'brand_attribute' );
		as_unschedule_all_actions( Fetch_WC_Product_Sync::BULK_HOOK, null, Fetch_WC_Product_Sync::GROUP );
		break;

	// --- 1.4.0: several Fetch stores from one site ------------------------------------------
	case 'multi_setup':
		// Categories with a parent: Dogs > Dog food, Dogs > Bowls, and Cats.
		$dogs  = wp_insert_term( 'Dogs', 'product_cat' );
		$food  = wp_insert_term( 'Dog food', 'product_cat', array( 'parent' => $dogs['term_id'] ) );
		$bowls = wp_insert_term( 'Bowls', 'product_cat', array( 'parent' => $dogs['term_id'] ) );
		$cats  = wp_insert_term( 'Cats', 'product_cat' );
		$ids   = array(
			'dogs'   => (int) $dogs['term_id'],
			'food'   => (int) $food['term_id'],
			'bowls'  => (int) $bowls['term_id'],
			'cats'   => (int) $cats['term_id'],
			'kibble' => make_in_category( 'Dog Kibble', 'DOG-KIB', '20', 10, $food['term_id'] ),
			'toy'    => make_in_category( 'Cat Toy', 'CAT-TOY', '5', 4, $cats['term_id'] ),
		);
		update_option( 'e2e_multi', $ids, false );
		Fetch_WC_Product_Sync::flush(); // Only the first store is connected so far.
		$out['ids'] = $ids;

		// "Connect & load stores": the key sees three stores.
		$out['connect_notice'] = save_settings( array( 'do' => 'connect' ) );
		$out['listed']         = wp_list_pluck( (array) get_transient( 'fetch_wc_stores_' . get_current_user_id() ), 'id' );
		$html                  = render_page();
		preg_match_all( '#name="stores\[(\d+)\]\[id\]" value="([^"]+)">\s*<input type="checkbox" name="stores\[\d+\]\[connect\]" value="yes"\s*(checked=\'checked\')?#', $html, $m, PREG_SET_ORDER );
		$out['choices']        = array_map( function ( $r ) { return array( $r[2], ! empty( $r[3] ) ); }, $m );

		// Tick the first two; the second sells only Dogs, at its own 20% markup.
		as_unschedule_all_actions( Fetch_WC_Product_Sync::BULK_HOOK, null, Fetch_WC_Product_Sync::GROUP );
		$out['save_notice'] = save_stores(
			array(
				array( 'id' => 'store-1', 'connect' => 'yes' ),
				array( 'id' => 'store-2', 'connect' => 'yes', 'price_markup' => '20', 'categories' => array( (string) $ids['dogs'] ) ),
				array( 'id' => 'store-3' ),
			)
		);
		$out['stores']  = array_map( function ( $s ) { return array( 'markup' => Fetch_WC_Settings::store_markup( $s['id'] ), 'categories' => $s['categories'], 'name' => $s['name'] ); }, Fetch_WC_Settings::stores() );
		$out['primary'] = Fetch_WC_Settings::get( 'store_id' );
		$out['bulk']    = scheduled_args( Fetch_WC_Product_Sync::BULK_HOOK );
		run_bulk( 'store-2' );
		$out['log'] = recent_log( 6 );
		break;

	case 'multi_products':
		$ids         = get_option( 'e2e_multi' );
		$ids['bowl'] = make_in_category( 'Dog Bowl', 'DOG-BOWL', '8', 10, $ids['bowls'] );
		$ids['bed']  = make_in_category( 'Cat Bed', 'CAT-BED', '30', 2, $ids['cats'] );
		update_option( 'e2e_multi', $ids, false );
		$out['pending'] = array(
			'store-1' => array_keys( get_option( 'fetch_wc_pending_products', array() ) ),
			'store-2' => array_keys( get_option( 'fetch_wc_pending_products_store-2', array() ) ),
		);
		$out['flush_jobs'] = scheduled_args( Fetch_WC_Product_Sync::FLUSH_HOOK );
		$out['ids']        = $ids;
		Fetch_WC_Product_Sync::flush( 'store-1' );
		Fetch_WC_Product_Sync::flush( 'store-2' );
		$out['meta'] = array(
			'bowl' => array( get_post_meta( $ids['bowl'], '_fetch_product_id', true ), get_post_meta( $ids['bowl'], '_fetch_product_id_store-2', true ), get_post_meta( $ids['bowl'], '_fetch_ref', true ) ),
			'bed'  => array( get_post_meta( $ids['bed'], '_fetch_product_id', true ), get_post_meta( $ids['bed'], '_fetch_product_id_store-2', true ) ),
		);
		$html                 = render_page();
		$out['status']        = store_status_cells( $html );
		$out['store_markups'] = preg_match_all( '#name="stores\[(\d+)\]\[price_markup\]"[^>]*value="([^"]*)"#', $html, $mm ) ? $mm[2] : array();
		$out['sections']      = preg_match_all( '#<h3>([^<]+)</h3>#', $html, $hh ) ? $hh[1] : array();
		break;

	case 'multi_order':
		$ids = get_option( 'e2e_multi' );
		Fetch_WC_Order_Sync::poll();
		$order               = Fetch_WC_Order_Sync::find( 'ord-b1' );
		$out['found']        = (bool) $order;
		$out['store_meta']   = $order ? array( $order->get_meta( '_fetch_store_id' ), $order->get_meta( '_fetch_store_name' ) ) : null;
		$out['stock']        = product_stock( $ids['bowl'] );
		$out['pending']      = array(
			'store-1' => array_keys( get_option( 'fetch_wc_pending_products', array() ) ),
			'store-2' => array_keys( get_option( 'fetch_wc_pending_products_store-2', array() ) ),
		);
		Fetch_WC_Product_Sync::flush( 'store-1' );
		Fetch_WC_Product_Sync::flush( 'store-2' );
		ob_start();
		Fetch_WC_Order_Actions::render( $order );
		$out['box'] = ob_get_clean();
		$out['columns'] = array_keys( apply_filters( 'manage_woocommerce_page_wc-orders_columns', array( 'order_number' => 'Order', 'order_status' => 'Status', 'order_total' => 'Total' ) ) );
		ob_start();
		do_action( 'manage_woocommerce_page_wc-orders_custom_column', 'fetch_store', $order );
		$out['column'] = ob_get_clean();
		$out['accept_error'] = Fetch_WC_Order_Actions::run( $order, 'accept', array( 'prepMinutes' => 12 ) );
		$order               = wc_get_order( $order->get_id() );
		$out['status']       = array( $order->get_status(), $order->get_meta( '_fetch_status' ) );
		$out['notes']        = array_map( function ( $n ) { return $n->content; }, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
		$out['store3_order'] = (bool) Fetch_WC_Order_Sync::find( 'ord-c2' );
		break;

	case 'multi_isolation':
		$ids = get_option( 'e2e_multi' );
		$p   = wc_get_product( $ids['kibble'] );
		$p->set_regular_price( '25' );
		$p->save();
		Fetch_WC_Product_Sync::flush( 'store-1' ); // Answers 503.
		Fetch_WC_Product_Sync::flush( 'store-2' );
		$out['pending'] = array(
			'store-1' => array_keys( get_option( 'fetch_wc_pending_products', array() ) ),
			'store-2' => array_keys( get_option( 'fetch_wc_pending_products_store-2', array() ) ),
		);
		$out['status'] = array(
			'store-1' => Fetch_WC_Settings::store_status( 'store-1' ),
			'store-2' => Fetch_WC_Settings::store_status( 'store-2' ),
		);
		$out['meta']   = array( get_post_meta( $ids['kibble'], '_fetch_sync_status', true ), get_post_meta( $ids['kibble'], '_fetch_sync_status_store-2', true ) );
		$out['log']    = recent_log( 4 );
		$out['ignored_logged'] = count( array_filter( array_column( Fetch_WC_Logger::activity(), 'message' ), function ( $m ) { return 0 === strpos( $m, 'Ignored Fetch orders from store store-3' ); } ) );
		break;

	case 'multi_flush':
		Fetch_WC_Product_Sync::flush( isset( $_GET['store'] ) ? (string) $_GET['store'] : null );
		$out['pending'] = array_keys( get_option( 'fetch_wc_pending_products', array() ) );
		$out['pending_2'] = array_keys( get_option( 'fetch_wc_pending_products_store-2', array() ) );
		break;

	case 'multi_mirror':
		// Store A (store-1) mirrors this shop; store B keeps the default, add and update only.
		$rows = array(
			array( 'id' => 'store-1', 'connect' => 'yes', 'catalogue_mode' => 'mirror' ),
			array( 'id' => 'store-2', 'connect' => 'yes', 'price_markup' => '20', 'categories' => array( (string) get_option( 'e2e_multi' )['dogs'] ) ),
		);
		$out['notice'] = save_stores( $rows );
		$out['modes']  = array( Fetch_WC_Settings::store_mirror( 'store-1' ), Fetch_WC_Settings::store_mirror( 'store-2' ), Fetch_WC_Settings::mirror() );
		$html          = render_page();
		$out['preview_buttons'] = preg_match_all( '#form="fetch_wc_mirror_preview_form" class="button" name="store" value="([^"]+)"#', $html, $pb ) ? $pb[1] : array();
		$out['preview_notice'] = post_action( 'mirror_preview', array( 'store' => 'store-1' ) );
		$out['preview_store']  = get_transient( 'fetch_wc_mirror_preview_' . get_current_user_id() )['store'];
		run_bulk( 'store-1' );
		run_bulk( 'store-2' );
		$out['log']     = recent_log( 8 );
		$out['pending'] = array( Fetch_WC_Mirror::pending( 'store-1' ), Fetch_WC_Mirror::pending( 'store-2' ) );
		$rows[0]['catalogue_mode'] = '';
		save_stores( $rows );
		$out['mode_after'] = Fetch_WC_Settings::store_mirror( 'store-1' );
		break;

	case 'multi_disconnect':
		$out['pending_before'] = get_option( 'fetch_wc_pending_products_store-2', 'none' );
		update_option( 'fetch_wc_pending_products_store-2', array( 1 => true ), false );
		$out['notice'] = save_stores( array( array( 'id' => 'store-1', 'connect' => 'yes' ), array( 'id' => 'store-2' ), array( 'id' => 'store-3' ) ) );
		$out['stores']        = array_keys( Fetch_WC_Settings::stores() );
		$out['pending_after'] = get_option( 'fetch_wc_pending_products_store-2', 'gone' );
		$out['columns']       = array_keys( apply_filters( 'manage_woocommerce_page_wc-orders_columns', array( 'order_number' => 'Order', 'order_status' => 'Status' ) ) );
		$html                 = render_page();
		$out['per_store_fields'] = false !== strpos( $html, '[price_markup]' );
		$out['status']           = store_status_cells( $html );
		$out['order_b_store']    = Fetch_WC_Order_Actions::store_label( Fetch_WC_Order_Sync::find( 'ord-b1' ) );
		break;

	case 'fresh_connect':
		// After the server step cleared the store: a fresh connection through the form.
		$out['before']  = array( Fetch_WC_Settings::is_connected(), Fetch_WC_Settings::get( 'store_id' ) );
		$out['notice']  = save_settings( array( 'environment' => 'custom', 'custom_base_url' => 'http://127.0.0.1:9099', 'do' => 'connect' ) );
		$out['after']   = array( Fetch_WC_Settings::is_connected(), Fetch_WC_Settings::get( 'store_id' ), array_keys( Fetch_WC_Settings::stores() ) );
		$out['saved']   = save_stores( array( array( 'id' => 'store-1', 'connect' => 'yes' ), array( 'id' => 'store-2' ), array( 'id' => 'store-3' ) ) );
		$out['final']   = array( Fetch_WC_Settings::is_connected(), Fetch_WC_Settings::get( 'store_id' ), array_keys( Fetch_WC_Settings::stores() ) );
		break;

	// --- Upgrade from 1.3.0 (tests/e2e/upgrade.mjs) ----------------------------------------
	case 'version':
		$out['version'] = FETCH_WC_VERSION;
		$out['settings'] = Fetch_WC_Settings::all();
		$out['pending']  = array_keys( get_option( Fetch_WC_Product_Sync::PENDING_OPTION, array() ) );
		break;

	case 'upgrade_queue_bulk':
		// Runs on 1.3.0: a full sync waiting in Action Scheduler when the update lands.
		Fetch_WC_Product_Sync::start_bulk_sync();
		$out['bulk'] = scheduled_args( Fetch_WC_Product_Sync::BULK_HOOK );
		break;

	case 'upgrade_check':
		$id = wc_get_product_id_by_sku( 'CBL-001' );
		// The job 1.3.0 queued, run the way Action Scheduler runs it.
		$legacy = scheduled_args( Fetch_WC_Product_Sync::BULK_HOOK );
		as_unschedule_all_actions( Fetch_WC_Product_Sync::BULK_HOOK, null, Fetch_WC_Product_Sync::GROUP );
		if ( $legacy ) {
			do_action_ref_array( Fetch_WC_Product_Sync::BULK_HOOK, array_values( $legacy[0] ) );
		}
		$out['legacy_bulk_next'] = 1 === count( $legacy ) && as_has_scheduled_action( Fetch_WC_Product_Sync::BULK_HOOK, array( 'page' => 2 ), Fetch_WC_Product_Sync::GROUP );
		$out['pending_before_change'] = array_keys( get_option( Fetch_WC_Product_Sync::PENDING_OPTION, array() ) );
		as_unschedule_all_actions( Fetch_WC_Product_Sync::BULK_HOOK, null, Fetch_WC_Product_Sync::GROUP );
		$out['version']   = FETCH_WC_VERSION;
		$out['connected'] = Fetch_WC_Settings::is_connected();
		$out['stores']    = array_map( function ( $s ) { return array( $s['name'], Fetch_WC_Settings::store_markup( $s['id'] ) ); }, Fetch_WC_Settings::stores() );
		$out['meta']      = array( get_post_meta( $id, '_fetch_product_id', true ), get_post_meta( $id, '_fetch_ref', true ), get_post_meta( $id, '_fetch_sync_status', true ) );
		wc_update_product_stock( $id, 3 );
		$out['pending'] = array_keys( get_option( Fetch_WC_Product_Sync::PENDING_OPTION, array() ) );
		$out['flush_scheduled'] = as_has_scheduled_action( Fetch_WC_Product_Sync::FLUSH_HOOK, array(), Fetch_WC_Product_Sync::GROUP );
		Fetch_WC_Product_Sync::flush();
		$out['pending_after_flush'] = array_keys( get_option( Fetch_WC_Product_Sync::PENDING_OPTION, array() ) );
		$out['status_after']        = get_post_meta( $id, '_fetch_sync_status', true );
		$html                = render_page();
		$out['ticked']       = (bool) preg_match( '#value="store-1">\s*<input type="checkbox" name="stores\[0\]\[connect\]" value="yes"\s*checked#', $html );
		$out['status_cell']  = store_status_cells( $html );
		// Saving the 1.4.0 form unchanged keeps the store and resends nothing.
		as_unschedule_all_actions( Fetch_WC_Product_Sync::BULK_HOOK, null, Fetch_WC_Product_Sync::GROUP );
		$out['save_notice'] = save_stores( array( array( 'id' => 'store-1', 'connect' => 'yes' ) ) );
		$out['after_save']  = array( Fetch_WC_Settings::is_connected(), Fetch_WC_Settings::get( 'store_id' ), array_keys( Fetch_WC_Settings::stores() ), Fetch_WC_Settings::store_markup( 'store-1' ), as_has_scheduled_action( Fetch_WC_Product_Sync::BULK_HOOK, null, Fetch_WC_Product_Sync::GROUP ) );
		$order               = Fetch_WC_Order_Sync::find( 'ord-1' );
		$out['accept_error'] = Fetch_WC_Order_Actions::run( $order, 'accept', array( 'prepMinutes' => 10 ) );
		Fetch_WC_Order_Sync::poll();
		$out['orders'] = count( wc_get_orders( array( 'limit' => -1, 'meta_key' => '_fetch_order_id', 'meta_value' => 'ord-1' ) ) );
		run_full_sync();
		$out['log'] = recent_log( 5 );
		break;

	case 'render_box':
		$order = Fetch_WC_Order_Sync::find( 'ord-1' );
		ob_start();
		Fetch_WC_Order_Actions::render( $order );
		$out['html'] = ob_get_clean();
		break;
}
echo wp_json_encode( $out, JSON_PRETTY_PRINT );
