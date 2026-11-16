<?php
/**
 * Brings Fetch orders into WooCommerce and keeps their status in step.
 *
 * Orders arrive by webhook (instant) and by a 2-minute poll (safety net for a
 * missed or unreachable webhook). Both paths go through import(), which holds
 * a per-order lock (an INSERT IGNORE on the unique option name) while it looks
 * the order up and creates or updates it, so a webhook and a poll seeing the
 * same order at the same moment never create two WooCommerce orders.
 *
 * Fetch masks the customer's details 2 hours after the order. An order first
 * imported masked is completed when an unmasked copy arrives.
 *
 * Fetch reserves the stock when the customer orders, so WooCommerce stock is
 * reduced on import and restored if Fetch cancels or the shop rejects. Those
 * stock changes flow back to Fetch through the normal product sync, which
 * keeps both sides on the same number.
 */

defined( 'ABSPATH' ) || exit;

class Fetch_WC_Order_Sync {

	const POLL_HOOK        = 'fetch_wc_poll_orders';
	const POLL_INTERVAL    = 120;
	const POLL_LOOKBACK    = 2 * DAY_IN_SECONDS;
	const PAYMENT_METHOD   = 'fetch';
	const LOCK_PREFIX      = 'fetch_wc_lock_';
	/** A lock older than this was left by a request that died; it is taken over. */
	const LOCK_TIMEOUT     = 300;

	const META_ORDER_ID     = '_fetch_order_id';
	const META_ORDER_NUMBER = '_fetch_order_number';
	const META_STATUS       = '_fetch_status';
	const META_STORE_ID     = '_fetch_store_id';
	const META_MASKED       = '_fetch_customer_masked';

	public static function init() {
		add_action( self::POLL_HOOK, array( __CLASS__, 'poll' ) );
	}

	public static function schedule_poll() {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}
		$wanted = Fetch_WC_Settings::enabled( 'import_orders' ) && Fetch_WC_Settings::is_connected();
		$has    = as_has_scheduled_action( self::POLL_HOOK, array(), Fetch_WC_Product_Sync::GROUP );
		if ( $wanted && ! $has ) {
			as_schedule_recurring_action( time() + 30, self::POLL_INTERVAL, self::POLL_HOOK, array(), Fetch_WC_Product_Sync::GROUP );
		} elseif ( ! $wanted && $has ) {
			as_unschedule_all_actions( self::POLL_HOOK, array(), Fetch_WC_Product_Sync::GROUP );
		}
	}

	/** Pull recent orders and import or update each one. */
	public static function poll() {
		if ( ! Fetch_WC_Settings::enabled( 'import_orders' ) || ! Fetch_WC_Settings::is_connected() ) {
			return;
		}
		$store_id = (string) Fetch_WC_Settings::get( 'store_id' );
		$api      = new Fetch_WC_Api();
		$query    = array(
			'since' => gmdate( 'c', time() - self::POLL_LOOKBACK ),
			'limit' => '100',
		);

		for ( $page = 0; $page < 20; $page++ ) {
			$result = $api->list_orders( $store_id, $query );
			if ( ! Fetch_WC_Api::ok( $result ) ) {
				Fetch_WC_Logger::error( 'Could not read Fetch orders: ' . Fetch_WC_Api::error_message( $result ) );
				return;
			}
			$body = is_array( $result['body'] ) ? $result['body'] : array();
			foreach ( isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : array() as $order ) {
				self::import( $order );
			}
			if ( empty( $body['nextCursor'] ) ) {
				return;
			}
			$query['cursor'] = (string) $body['nextCursor'];
		}
	}

	/**
	 * Create the WooCommerce order for a Fetch order, or bring an existing one
	 * up to date. Returns the WooCommerce order, null when skipped, or false
	 * when another request is handling the same order right now.
	 *
	 * @param int $wait Seconds to wait for that other request to finish.
	 * @return WC_Order|null|false
	 */
	public static function import( array $data, $wait = 0 ) {
		if ( empty( $data['orderId'] ) || empty( $data['status'] ) ) {
			return null;
		}
		if ( ! empty( $data['storeId'] ) && (string) $data['storeId'] !== (string) Fetch_WC_Settings::get( 'store_id' ) ) {
			return null; // Another store on the same credential.
		}

		$fetch_id = (string) $data['orderId'];
		$deadline = microtime( true ) + max( 0, (int) $wait );
		$token    = self::lock( $fetch_id );
		while ( null === $token && microtime( true ) < $deadline ) {
			usleep( 250000 );
			$token = self::lock( $fetch_id );
		}
		if ( null === $token ) {
			return false;
		}

		try {
			$existing = self::find( $fetch_id );
			if ( $existing ) {
				self::fill_customer( $existing, $data );
				self::apply_status( $existing, (string) $data['status'] );
				return $existing;
			}

			// A cancelled order we never saw needs no WooCommerce order.
			if ( in_array( $data['status'], Fetch_WC_Order_Mapper::RESTOCKED, true ) ) {
				return null;
			}

			$order = self::create( $data );
			if ( $order && 'DRAFT' === $data['status'] && Fetch_WC_Settings::enabled( 'auto_accept' ) ) {
				Fetch_WC_Order_Actions::run( $order, 'accept', array( 'prepMinutes' => (int) Fetch_WC_Settings::get( 'prep_minutes' ) ) );
			}
			return $order;
		} finally {
			self::unlock( $fetch_id, $token );
		}
	}

	/**
	 * Take the lock for one Fetch order. Returns its token, or null while
	 * another request holds it.
	 */
	public static function lock( $fetch_order_id, $now = null ) {
		global $wpdb;
		$now   = null === $now ? time() : (int) $now;
		$name  = self::LOCK_PREFIX . md5( (string) $fetch_order_id );
		$token = $now . ':' . bin2hex( random_bytes( 8 ) );

		$taken = self::insert_lock( $name, $token );
		if ( null === $taken ) {
			return $token; // The database refused the statement; carry on unlocked as before 1.1.1.
		}
		if ( $taken ) {
			return $token;
		}
		$held = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		if ( null !== $held && $now - (int) $held < self::LOCK_TIMEOUT ) {
			return null;
		}
		if ( null !== $held ) {
			// Delete only the stale token we read, so two requests cannot both take over.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $held ) );
		}
		return self::insert_lock( $name, $token ) ? $token : null;
	}

	public static function unlock( $fetch_order_id, $token ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::LOCK_PREFIX . md5( (string) $fetch_order_id ), $token ) );
	}

	/** @return bool|null true when inserted, false when the row exists, null on a database error. */
	private static function insert_lock( $name, $token ) {
		global $wpdb;
		$result = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $token ) );
		if ( false === $result ) {
			Fetch_WC_Logger::error( 'Could not lock a Fetch order for import: ' . $wpdb->last_error );
			return null;
		}
		return 1 === (int) $wpdb->rows_affected;
	}

	/** Write the real customer details over ones that were imported masked. */
	private static function fill_customer( WC_Order $order, array $data ) {
		$address = Fetch_WC_Order_Mapper::customer_update( '1' === (string) $order->get_meta( self::META_MASKED ), $data );
		if ( ! $address ) {
			return;
		}
		$order->set_address( $address, 'billing' );
		$order->set_address( $address, 'shipping' );
		if ( '' === $order->get_customer_note() ) {
			$order->set_customer_note( Fetch_WC_Order_Mapper::customer_note( $data ) );
		}
		$order->delete_meta_data( self::META_MASKED );
		$order->save();
		$order->add_order_note( __( 'Customer details completed from Fetch.', 'fetch-for-woocommerce' ) );
	}

	public static function find( $fetch_order_id ) {
		// meta_key/meta_value, not meta_query: the posts-table order store
		// ignores meta_query and would return an unrelated order.
		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'meta_key'   => self::META_ORDER_ID, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => $fetch_order_id, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		if ( $orders && (string) $orders[0]->get_meta( self::META_ORDER_ID ) !== (string) $fetch_order_id ) {
			return null;
		}
		return $orders ? $orders[0] : null;
	}

	private static function create( array $data ) {
		$order = wc_create_order( array( 'created_via' => 'fetch' ) );
		if ( is_wp_error( $order ) ) {
			Fetch_WC_Logger::error( 'Could not create WooCommerce order for Fetch order ' . $data['orderId'] . ': ' . $order->get_error_message() );
			return null;
		}

		$unmatched = array();
		foreach ( isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array() as $line ) {
			if ( ! self::add_line( $order, $line ) ) {
				$unmatched[] = isset( $line['name'] ) ? $line['name'] : ( isset( $line['ref'] ) ? $line['ref'] : '?' );
			}
		}

		$shipping_fee = isset( $data['shippingFee'] ) ? (float) $data['shippingFee'] : 0.0;
		$shipping     = new WC_Order_Item_Shipping();
		$shipping->set_method_title( Fetch_WC_Order_Mapper::is_pickup( $data ) ? __( 'Pickup (Fetch)', 'fetch-for-woocommerce' ) : __( 'Delivery by Fetch', 'fetch-for-woocommerce' ) );
		$shipping->set_method_id( 'fetch' );
		$shipping->set_total( $shipping_fee );
		$order->add_item( $shipping );

		$address = Fetch_WC_Order_Mapper::address( $data );
		$order->set_address( $address, 'billing' );
		$order->set_address( $address, 'shipping' );
		$order->set_customer_note( Fetch_WC_Order_Mapper::customer_note( $data ) );
		$order->set_payment_method( self::PAYMENT_METHOD );
		$order->set_payment_method_title( __( 'Paid on Fetch', 'fetch-for-woocommerce' ) );
		if ( ! empty( $data['currency'] ) ) {
			$order->set_currency( $data['currency'] );
		}
		if ( ! empty( $data['placedAt'] ) ) {
			$order->set_date_created( strtotime( $data['placedAt'] ) );
		}

		$order->update_meta_data( self::META_ORDER_ID, (string) $data['orderId'] );
		$order->update_meta_data( self::META_ORDER_NUMBER, isset( $data['orderNumber'] ) ? (string) $data['orderNumber'] : '' );
		$order->update_meta_data( self::META_STORE_ID, isset( $data['storeId'] ) ? (string) $data['storeId'] : '' );
		$order->update_meta_data( self::META_STATUS, (string) $data['status'] );
		if ( Fetch_WC_Order_Mapper::is_masked( $data ) ) {
			$order->update_meta_data( self::META_MASKED, '1' );
		}

		// Fetch's prices already include VAT and its total is authoritative.
		$order->calculate_totals( false );
		if ( isset( $data['total'] ) ) {
			$order->set_total( (float) $data['total'] );
		}
		$order->save();

		$order->add_order_note(
			sprintf(
				/* translators: 1: Fetch order number, 2: status label */
				__( 'Fetch order %1$s imported (%2$s).', 'fetch-for-woocommerce' ),
				isset( $data['orderNumber'] ) ? $data['orderNumber'] : $data['orderId'],
				Fetch_WC_Order_Mapper::label( $data['status'] )
			)
		);
		if ( $unmatched ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: product names */
					__( 'These Fetch items match no WooCommerce product (no SKU match), so their stock was not reduced: %s', 'fetch-for-woocommerce' ),
					implode( ', ', $unmatched )
				)
			);
		}

		wc_reduce_stock_levels( $order->get_id() );
		$order->set_status( Fetch_WC_Order_Mapper::wc_status( $data['status'] ) );
		$order->save();

		Fetch_WC_Logger::info( sprintf( 'Imported Fetch order %s as WooCommerce order #%d.', isset( $data['orderNumber'] ) ? $data['orderNumber'] : $data['orderId'], $order->get_id() ) );
		return $order;
	}

	/** A Fetch line → a WooCommerce line, linked to the product when the ref matches a SKU. */
	private static function add_line( WC_Order $order, array $line ) {
		$quantity = max( 1, (int) ( isset( $line['quantity'] ) ? $line['quantity'] : 1 ) );
		$total    = isset( $line['totalPrice'] ) ? (float) $line['totalPrice'] : 0.0;
		$product  = self::product_for_ref( isset( $line['ref'] ) ? (string) $line['ref'] : '' );

		$item = new WC_Order_Item_Product();
		$item->set_quantity( $quantity );
		$item->set_subtotal( $total );
		$item->set_total( $total );
		if ( $product ) {
			$item->set_product( $product );
		} else {
			$name = isset( $line['name'] ) ? (string) $line['name'] : __( 'Fetch item', 'fetch-for-woocommerce' );
			if ( ! empty( $line['variantName'] ) ) {
				$name .= ' – ' . $line['variantName'];
			}
			$item->set_name( $name );
		}
		$item->add_meta_data( '_fetch_listing_id', isset( $line['listingId'] ) ? (string) $line['listingId'] : '', true );
		$order->add_item( $item );
		return (bool) $product;
	}

	/** Our frozen ref first, then the SKU (the ref defaults to the SKU). */
	private static function product_for_ref( $ref ) {
		if ( '' === $ref ) {
			return null;
		}
		$ids = get_posts(
			array(
				'post_type'      => array( 'product', 'product_variation' ),
				'post_status'    => 'any',
				'meta_key'       => Fetch_WC_Product_Mapper::META_REF,
				'meta_value'     => $ref,
				'fields'         => 'ids',
				'posts_per_page' => 1,
			)
		);
		$id = $ids ? (int) $ids[0] : (int) wc_get_product_id_by_sku( $ref );
		if ( ! $id && 0 === strpos( $ref, 'wc-' ) ) {
			$id = (int) substr( $ref, 3 );
		}
		$product = $id ? wc_get_product( $id ) : null;
		return $product instanceof WC_Product ? $product : null;
	}

	/** Mirror a Fetch status onto the WooCommerce order. */
	public static function apply_status( WC_Order $order, $fetch_status ) {
		$previous = (string) $order->get_meta( self::META_STATUS );
		if ( $previous === $fetch_status ) {
			return;
		}
		$order->update_meta_data( self::META_STATUS, $fetch_status );

		$wc_status = Fetch_WC_Order_Mapper::wc_status( $fetch_status );
		$note      = sprintf(
			/* translators: %s: status label */
			__( 'Fetch: %s.', 'fetch-for-woocommerce' ),
			Fetch_WC_Order_Mapper::label( $fetch_status )
		);

		if ( in_array( $fetch_status, Fetch_WC_Order_Mapper::RESTOCKED, true ) && $order->get_data_store()->get_stock_reduced( $order->get_id() ) ) {
			wc_increase_stock_levels( $order->get_id() );
		}
		if ( $order->get_status() !== $wc_status ) {
			$order->set_status( $wc_status, $note );
		} else {
			$order->add_order_note( $note );
		}
		$order->save();
	}
}
