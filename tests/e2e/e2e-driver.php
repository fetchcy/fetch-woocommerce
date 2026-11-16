<?php
/**
 * Runs inside WordPress Playground: GET /e2e-driver.php?step=<name>.
 * Each step prints JSON; the node runner asserts on it.
 */
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

	case 'render_box':
		$order = Fetch_WC_Order_Sync::find( 'ord-1' );
		ob_start();
		Fetch_WC_Order_Actions::render( $order );
		$out['html'] = ob_get_clean();
		break;
}
echo wp_json_encode( $out, JSON_PRETTY_PRINT );
