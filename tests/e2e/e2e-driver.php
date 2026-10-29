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

	case 'render_box':
		$order = Fetch_WC_Order_Sync::find( 'ord-1' );
		ob_start();
		Fetch_WC_Order_Actions::render( $order );
		$out['html'] = ob_get_clean();
		break;
}
echo wp_json_encode( $out, JSON_PRETTY_PRINT );
