<?php
/**
 * Pushes WooCommerce product changes to Fetch.
 *
 * Every change (save, stock change, trash, delete) adds the product to a
 * pending queue; one Action Scheduler job flushes it 20 s later in chunks of
 * 50, so a bulk edit or a busy checkout becomes a handful of API calls rather
 * than one per product. "Sync all products" pages through the catalogue the
 * same way.
 */

defined( 'ABSPATH' ) || exit;

class Fetch_WC_Product_Sync {

	const PENDING_OPTION    = 'fetch_wc_pending_products';
	const DEACTIVATE_OPTION = 'fetch_wc_pending_deactivations';
	const FLUSH_HOOK        = 'fetch_wc_flush_products';
	const BULK_HOOK         = 'fetch_wc_bulk_sync';
	const GROUP             = 'fetch-for-woocommerce';
	const CHUNK             = 50;
	const FLUSH_DELAY       = 20;

	const META_PRODUCT_ID = '_fetch_product_id';
	const META_STATUS     = '_fetch_sync_status';
	const META_ERROR      = '_fetch_sync_error';
	const META_SYNCED_AT  = '_fetch_synced_at';

	public static function init() {
		add_action( self::FLUSH_HOOK, array( __CLASS__, 'flush' ) );
		add_action( self::BULK_HOOK, array( __CLASS__, 'bulk_page' ), 10, 1 );

		if ( ! Fetch_WC_Settings::enabled( 'sync_products' ) ) {
			return;
		}
		add_action( 'woocommerce_new_product', array( __CLASS__, 'queue' ) );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'queue' ) );
		add_action( 'woocommerce_update_product_variation', array( __CLASS__, 'queue' ) );
		add_action( 'woocommerce_product_set_stock', array( __CLASS__, 'queue_product' ) );
		add_action( 'woocommerce_variation_set_stock', array( __CLASS__, 'queue_product' ) );
		add_action( 'woocommerce_product_set_stock_status', array( __CLASS__, 'queue' ) );
		add_action( 'woocommerce_variation_set_stock_status', array( __CLASS__, 'queue' ) );
		add_action( 'wp_trash_post', array( __CLASS__, 'queue' ) );
		add_action( 'untrashed_post', array( __CLASS__, 'queue' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'queue_deletion' ) );
	}

	public static function queue_product( $product ) {
		if ( $product instanceof WC_Product ) {
			self::queue( $product->get_id() );
		}
	}

	/** Queue a product (a variation queues its parent). */
	public static function queue( $id ) {
		$post_type = get_post_type( $id );
		if ( 'product_variation' === $post_type ) {
			$id = wp_get_post_parent_id( $id );
		} elseif ( 'product' !== $post_type ) {
			return;
		}
		if ( ! $id ) {
			return;
		}
		$pending        = self::pending( self::PENDING_OPTION );
		$pending[ $id ] = true;
		update_option( self::PENDING_OPTION, $pending, false );
		self::schedule_flush();
	}

	/** A permanent delete: remember ref + name now, the product is gone by flush time. */
	public static function queue_deletion( $id ) {
		if ( 'product' !== get_post_type( $id ) ) {
			return;
		}
		$product = wc_get_product( $id );
		if ( ! Fetch_WC_Product_Mapper::supports( $product ) || ! Fetch_WC_Product_Mapper::was_synced( $product ) ) {
			return;
		}
		$pending        = self::pending( self::DEACTIVATE_OPTION );
		$pending[ $id ] = Fetch_WC_Product_Mapper::deactivations( $product );
		update_option( self::DEACTIVATE_OPTION, $pending, false );

		$queued = self::pending( self::PENDING_OPTION );
		unset( $queued[ $id ] );
		update_option( self::PENDING_OPTION, $queued, false );
		self::schedule_flush();
	}

	private static function schedule_flush() {
		if ( ! function_exists( 'as_has_scheduled_action' ) || as_has_scheduled_action( self::FLUSH_HOOK, array(), self::GROUP ) ) {
			return;
		}
		as_schedule_single_action( time() + self::FLUSH_DELAY, self::FLUSH_HOOK, array(), self::GROUP );
	}

	public static function flush() {
		if ( ! Fetch_WC_Settings::is_connected() ) {
			return;
		}

		$deactivations = self::pending( self::DEACTIVATE_OPTION );
		delete_option( self::DEACTIVATE_OPTION );
		if ( $deactivations ) {
			$items = array();
			foreach ( $deactivations as $entry ) {
				// Each entry is a list of payloads (one per variation); older queues held one payload.
				$items = array_merge( $items, isset( $entry['ref'] ) ? array( $entry ) : $entry );
			}
			self::send( $items, array() );
		}

		$pending = self::pending( self::PENDING_OPTION );
		delete_option( self::PENDING_OPTION );
		foreach ( array_chunk( array_keys( $pending ), self::CHUNK ) as $ids ) {
			self::sync_ids( $ids );
		}
	}

	/** Start a full catalogue sync in the background. */
	public static function start_bulk_sync() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::BULK_HOOK, null, self::GROUP );
		}
		as_enqueue_async_action( self::BULK_HOOK, array( 'page' => 1 ), self::GROUP );
		Fetch_WC_Logger::info( 'Full product sync started.' );
	}

	public static function bulk_page( $page ) {
		$page = max( 1, (int) $page );
		$ids  = wc_get_products(
			array(
				'type'    => array( 'simple', 'variable' ),
				'status'  => array( 'publish', 'private', 'draft' ),
				'limit'   => self::CHUNK,
				'page'    => $page,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'return'  => 'ids',
			)
		);
		if ( ! $ids ) {
			Fetch_WC_Logger::info( 'Full product sync finished.' );
			return;
		}
		self::sync_ids( $ids );
		as_enqueue_async_action( self::BULK_HOOK, array( 'page' => $page + 1 ), self::GROUP );
	}

	/** @param int[] $ids */
	public static function sync_ids( array $ids ) {
		$items    = array();
		$products = array();
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! Fetch_WC_Product_Mapper::supports( $product ) ) {
				continue;
			}
			if ( 'trash' === $product->get_status() && ! Fetch_WC_Product_Mapper::was_synced( $product ) ) {
				continue; // Never synced, nothing to switch off.
			}
			foreach ( Fetch_WC_Product_Mapper::to_fetch_items( $product ) as $pair ) {
				list( $item, $owner )     = $pair;
				$items[]                  = $item;
				$products[ $item['ref'] ] = $owner;
			}
		}
		if ( $items ) {
			self::send( $items, $products );
		}
	}

	/**
	 * @param array<int,array<string,mixed>> $items
	 * @param array<string,WC_Product>       $products ref → product, to record results.
	 */
	private static function send( array $items, array $products ) {
		$store_id = (string) Fetch_WC_Settings::get( 'store_id' );
		$api      = new Fetch_WC_Api();
		$result   = $api->upsert_products( $store_id, $items, 'wc-' . md5( wp_json_encode( $items ) ) );

		if ( ! Fetch_WC_Api::ok( $result ) ) {
			$message = Fetch_WC_Api::error_message( $result );
			Fetch_WC_Logger::error( sprintf( 'Product sync failed for %d product(s): %s', count( $items ), $message ) );
			foreach ( $products as $product ) {
				self::record( $product, 'error', $message );
			}
			// Put them back so the next change (or the hourly retry) tries again.
			$pending = self::pending( self::PENDING_OPTION );
			foreach ( $products as $product ) {
				$pending[ $product->get_id() ] = true;
			}
			update_option( self::PENDING_OPTION, $pending, false );
			return;
		}

		$body     = is_array( $result['body'] ) ? $result['body'] : array();
		$results  = isset( $body['results'] ) && is_array( $body['results'] ) ? $body['results'] : array();
		$rejected = 0;
		foreach ( $results as $row ) {
			$ref = isset( $row['ref'] ) ? (string) $row['ref'] : '';
			if ( ! isset( $products[ $ref ] ) ) {
				continue;
			}
			$product = $products[ $ref ];
			$status  = isset( $row['status'] ) ? (string) $row['status'] : '';
			if ( 'error' === $status ) {
				++$rejected;
				$error = trim( ( isset( $row['code'] ) ? $row['code'] . ': ' : '' ) . ( isset( $row['message'] ) ? $row['message'] : 'rejected' ) );
				self::record( $product, 'error', $error );
				Fetch_WC_Logger::error( sprintf( 'Fetch rejected "%s" (%s): %s', $product->get_name(), $ref, $error ) );
				continue;
			}
			Fetch_WC_Product_Mapper::remember_ref( $product, $ref );
			if ( ! empty( $row['productId'] ) ) {
				$product->update_meta_data( self::META_PRODUCT_ID, (string) $row['productId'] );
			}
			$warnings = ! empty( $row['warnings'] ) && is_array( $row['warnings'] ) ? implode( ' ', $row['warnings'] ) : '';
			self::record( $product, 'synced', $warnings );
		}

		Fetch_WC_Logger::info(
			sprintf(
				'Synced %d product(s) to Fetch: %d created, %d updated, %d rejected.',
				count( $items ),
				isset( $body['created'] ) ? (int) $body['created'] : 0,
				isset( $body['updated'] ) ? (int) $body['updated'] : 0,
				isset( $body['rejected'] ) ? (int) $body['rejected'] : $rejected
			)
		);
	}

	private static function record( WC_Product $product, $status, $error ) {
		$product->update_meta_data( self::META_STATUS, $status );
		$product->update_meta_data( self::META_ERROR, $error );
		$product->update_meta_data( self::META_SYNCED_AT, time() );
		$product->save_meta_data();
	}

	/** Retry anything left pending after a failed send. */
	public static function retry_pending() {
		if ( self::pending( self::PENDING_OPTION ) || self::pending( self::DEACTIVATE_OPTION ) ) {
			self::schedule_flush();
		}
	}

	private static function pending( $option ) {
		$value = get_option( $option, array() );
		return is_array( $value ) ? $value : array();
	}
}
