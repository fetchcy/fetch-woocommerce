<?php
/**
 * Pushes WooCommerce product changes to Fetch.
 *
 * Every change (save, stock change, trash, delete) adds the product to a
 * pending queue; one Action Scheduler job flushes it 20 s later in chunks of
 * 50, so a bulk edit or a busy checkout becomes a handful of API calls rather
 * than one per product. "Sync all products" pages through the catalogue the
 * same way. Each request stays within the live limits of 200 products and
 * 500 KB (a variable product sends one row per variation).
 */

defined( 'ABSPATH' ) || exit;

class Fetch_WC_Product_Sync {

	const PENDING_OPTION    = 'fetch_wc_pending_products';
	const DEACTIVATE_OPTION = 'fetch_wc_pending_deactivations';
	const FLUSH_HOOK        = 'fetch_wc_flush_products';
	const BULK_HOOK         = 'fetch_wc_bulk_sync';
	/** Set when a page of the running full sync could not reach Fetch. */
	const BULK_FAILED_OPTION = 'fetch_wc_bulk_failed';
	const GROUP             = 'fetch-for-woocommerce';
	const CHUNK             = 50;
	const FLUSH_DELAY       = 20;
	/** Live PUT /products takes at most 200 products per call. */
	const MAX_ITEMS = 200;
	/** Stay under the API's 500 KB request limit. */
	const MAX_BYTES = 450000;

	const META_PRODUCT_ID = '_fetch_product_id';
	const META_STATUS     = '_fetch_sync_status';
	const META_ERROR      = '_fetch_sync_error';
	const META_SYNCED_AT  = '_fetch_synced_at';

	/** @var array<int,true> Variable products being deleted in this request. */
	private static $deleting = array();

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

	/** Queue a product (a variation queues its parent). Translations are skipped. */
	public static function queue( $id ) {
		$post_type = get_post_type( $id );
		if ( 'product_variation' === $post_type ) {
			$id = wp_get_post_parent_id( $id );
		} elseif ( 'product' !== $post_type ) {
			return;
		}
		if ( ! $id || ! Fetch_WC_Language::is_default( $id ) ) {
			return;
		}
		$pending        = self::pending( self::PENDING_OPTION );
		$pending[ $id ] = true;
		update_option( self::PENDING_OPTION, $pending, false );
		self::schedule_flush();
	}

	/**
	 * A permanent delete: build the full switched-off rows now, the product is
	 * gone by flush time. A variation deleted on its own is switched off too.
	 * The variations of a product being deleted are skipped: the parent already
	 * queued their rows, and WooCommerce deletes them after the parent's meta is
	 * gone, when their names can no longer be built.
	 * A translation was never synced: deleting it must not switch off the
	 * original, which may share its SKU (or carry its copied meta).
	 */
	public static function queue_deletion( $id ) {
		$post_type = get_post_type( $id );
		if ( 'product' !== $post_type && 'product_variation' !== $post_type ) {
			return;
		}
		if ( ! Fetch_WC_Language::is_default( $id ) ) {
			return;
		}
		$product = wc_get_product( $id );
		if ( $product instanceof WC_Product_Variation ) {
			if ( isset( self::$deleting[ $product->get_parent_id() ] ) || ! $product->get_meta( self::META_PRODUCT_ID ) ) {
				return;
			}
		} elseif ( ! Fetch_WC_Product_Mapper::supports( $product ) || ! Fetch_WC_Product_Mapper::was_synced( $product ) ) {
			return;
		}
		self::queue_deactivations( Fetch_WC_Product_Mapper::deactivations( $product ) );

		if ( 'product' === $post_type ) {
			self::$deleting[ (int) $id ] = true;
			$queued = self::pending( self::PENDING_OPTION );
			unset( $queued[ $id ] );
			update_option( self::PENDING_OPTION, $queued, false );
		}
		self::schedule_flush();
	}

	/** Keyed by ref, so a variation deleted with its parent is sent once. */
	private static function queue_deactivations( array $items ) {
		$pending = self::pending( self::DEACTIVATE_OPTION );
		foreach ( $items as $item ) {
			$pending[ $item['ref'] ] = $item;
		}
		update_option( self::DEACTIVATE_OPTION, $pending, false );
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
				// Each entry is one row; 1.1.0 queues held a list of rows per product.
				$items = array_merge( $items, isset( $entry['ref'] ) ? array( $entry ) : $entry );
			}
			if ( ! self::send_all( $items, array() ) ) {
				return;
			}
		}

		$pending = self::pending( self::PENDING_OPTION );
		delete_option( self::PENDING_OPTION );
		$chunks = array_chunk( array_keys( $pending ), self::CHUNK );
		foreach ( $chunks as $i => $ids ) {
			if ( ! self::sync_ids( $ids ) ) {
				self::requeue_ids( array_merge( array(), ...array_slice( $chunks, $i + 1 ) ) );
				return;
			}
		}
	}

	/** Start a full catalogue sync in the background. */
	public static function start_bulk_sync() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::BULK_HOOK, null, self::GROUP );
		}
		delete_option( self::BULK_FAILED_OPTION );
		as_enqueue_async_action( self::BULK_HOOK, array( 'page' => 1 ), self::GROUP );
		Fetch_WC_Logger::info( 'Full product sync started.' );
	}

	public static function bulk_page( $page ) {
		$page = max( 1, (int) $page );
		$ids  = Fetch_WC_Language::product_ids(
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
			$failed = 'yes' === get_option( self::BULK_FAILED_OPTION );
			delete_option( self::BULK_FAILED_OPTION );
			Fetch_WC_Mirror::after_full_sync( $failed );
			return;
		}
		if ( ! self::sync_ids( $ids ) ) {
			update_option( self::BULK_FAILED_OPTION, 'yes', false );
		}
		as_enqueue_async_action( self::BULK_HOOK, array( 'page' => $page + 1 ), self::GROUP );
	}

	/**
	 * @param int[] $ids
	 * @return bool false when Fetch could not be reached (the products are queued again).
	 */
	public static function sync_ids( array $ids ) {
		$items    = array();
		$products = array();
		foreach ( $ids as $id ) {
			if ( ! Fetch_WC_Language::is_default( $id ) ) {
				continue; // A translation: the default-language product is the one on Fetch.
			}
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
		return self::send_all( $items, $products );
	}

	/**
	 * Split into requests of at most MAX_ITEMS products and MAX_BYTES of JSON.
	 * A single row larger than the limit still goes, on its own.
	 *
	 * @param array<int,array<string,mixed>> $items
	 * @return array<int,array<int,array<string,mixed>>>
	 */
	public static function batches( array $items ) {
		$out   = array();
		$batch = array();
		$bytes = 0;
		foreach ( $items as $item ) {
			$size = strlen( wp_json_encode( $item ) ) + 1;
			if ( $batch && ( count( $batch ) >= self::MAX_ITEMS || $bytes + $size > self::MAX_BYTES ) ) {
				$out[] = $batch;
				$batch = array();
				$bytes = 0;
			}
			$batch[] = $item;
			$bytes  += $size;
		}
		if ( $batch ) {
			$out[] = $batch;
		}
		return $out;
	}

	/**
	 * Send in batches; stop at the first failed request and queue it and
	 * everything after it again.
	 *
	 * @param array<int,array<string,mixed>> $items
	 * @param array<string,WC_Product>       $products ref → product (empty for deactivations).
	 */
	private static function send_all( array $items, array $products ) {
		$batches = self::batches( $items );
		foreach ( $batches as $i => $batch ) {
			if ( self::send( $batch, $products ) ) {
				continue;
			}
			$left = array_merge( array(), ...array_slice( $batches, $i ) );
			if ( $products ) {
				$ids = array();
				foreach ( $left as $item ) {
					if ( isset( $products[ $item['ref'] ] ) ) {
						$owner = $products[ $item['ref'] ];
						// The queue holds products; a variation is synced through its parent.
						$ids[] = $owner instanceof WC_Product_Variation ? $owner->get_parent_id() : $owner->get_id();
					}
				}
				self::requeue_ids( $ids );
			} else {
				self::queue_deactivations( $left );
			}
			return false;
		}
		return true;
	}

	/** @param int[] $ids */
	private static function requeue_ids( array $ids ) {
		if ( ! $ids ) {
			return;
		}
		$pending = self::pending( self::PENDING_OPTION );
		foreach ( $ids as $id ) {
			$pending[ $id ] = true;
		}
		update_option( self::PENDING_OPTION, $pending, false );
	}

	/**
	 * @param array<int,array<string,mixed>> $items
	 * @param array<string,WC_Product>       $products ref → product, to record results.
	 * @return bool false when the request failed as a whole.
	 */
	private static function send( array $items, array $products ) {
		$store_id = (string) Fetch_WC_Settings::get( 'store_id' );
		$api      = new Fetch_WC_Api();
		// Unique per request: the sandbox replays a repeated key without applying
		// it, so a product switched off and then back on would stay off there.
		$result   = $api->upsert_products( $store_id, $items, 'wc-' . wp_generate_uuid4() );

		if ( ! Fetch_WC_Api::ok( $result ) ) {
			$message = Fetch_WC_Api::error_message( $result );
			Fetch_WC_Logger::error( sprintf( 'Product sync failed for %d product(s): %s', count( $items ), $message ) );
			foreach ( $items as $item ) {
				if ( isset( $products[ $item['ref'] ] ) ) {
					self::record( $products[ $item['ref'] ], 'error', $message );
				}
			}
			return false;
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
		return true;
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
