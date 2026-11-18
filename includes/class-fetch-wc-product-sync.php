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
 *
 * With several Fetch stores connected, each store has its own queue, flush
 * job, full sync and results, so one store's API error never holds up the
 * others. Prices and stock come from the same WooCommerce product for every
 * store, plus that store's markup.
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
		add_action( self::FLUSH_HOOK, array( __CLASS__, 'flush' ), 10, 1 );
		add_action( self::BULK_HOOK, array( __CLASS__, 'bulk_page' ), 10, 2 );

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

	/** A store's option name or meta key: the 1.3.0 one for the primary store. */
	public static function key( $base, $store_id = null ) {
		return $base . Fetch_WC_Settings::suffix( $store_id );
	}

	/**
	 * Queue a product (a variation queues its parent) for every connected store
	 * whose category filter takes it, or that already has it (to switch it off
	 * there). Translations are skipped.
	 */
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
		$product = null;
		foreach ( Fetch_WC_Settings::stores() as $store_id => $store ) {
			if ( $store['categories'] ) {
				$product = $product ? $product : wc_get_product( $id );
				if ( ! $product instanceof WC_Product || ( ! Fetch_WC_Product_Mapper::in_categories( $product, $store['categories'] ) && ! Fetch_WC_Product_Mapper::was_synced( $product, $store_id ) ) ) {
					continue;
				}
			}
			$option         = self::key( self::PENDING_OPTION, $store_id );
			$pending        = self::pending( $option );
			$pending[ $id ] = true;
			update_option( $option, $pending, false );
			self::schedule_flush( $store_id );
		}
	}

	/**
	 * A permanent delete: build the full switched-off rows now, the product is
	 * gone by flush time, for each store that has it, at that store's prices.
	 * A variation deleted on its own is switched off too.
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
		foreach ( array_keys( Fetch_WC_Settings::stores() ) as $store_id ) {
			if ( $product instanceof WC_Product_Variation ) {
				if ( isset( self::$deleting[ $product->get_parent_id() ] ) || ! $product->get_meta( self::key( self::META_PRODUCT_ID, $store_id ) ) ) {
					continue;
				}
			} elseif ( ! Fetch_WC_Product_Mapper::supports( $product ) || ! Fetch_WC_Product_Mapper::was_synced( $product, $store_id ) ) {
				continue;
			}
			self::queue_deactivations( Fetch_WC_Product_Mapper::deactivations( $product, Fetch_WC_Settings::store_markup( $store_id ) ), $store_id );

			if ( 'product' === $post_type ) {
				self::$deleting[ (int) $id ] = true;
				$option                      = self::key( self::PENDING_OPTION, $store_id );
				$queued                      = self::pending( $option );
				unset( $queued[ $id ] );
				update_option( $option, $queued, false );
			}
			self::schedule_flush( $store_id );
		}
	}

	/** Keyed by ref, so a variation deleted with its parent is sent once. */
	private static function queue_deactivations( array $items, $store_id ) {
		$option  = self::key( self::DEACTIVATE_OPTION, $store_id );
		$pending = self::pending( $option );
		foreach ( $items as $item ) {
			$pending[ $item['ref'] ] = $item;
		}
		update_option( $option, $pending, false );
	}

	private static function schedule_flush( $store_id ) {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}
		$args = Fetch_WC_Settings::job_args( $store_id );
		if ( as_has_scheduled_action( self::FLUSH_HOOK, $args, self::GROUP ) ) {
			return;
		}
		as_schedule_single_action( time() + self::FLUSH_DELAY, self::FLUSH_HOOK, $args, self::GROUP );
	}

	/** Send one store's queue (null: the primary store's). Each store has its own job. */
	public static function flush( $store_id = null ) {
		$store_id = Fetch_WC_Settings::resolve_store( $store_id );
		if ( ! Fetch_WC_Settings::is_connected() || ! Fetch_WC_Settings::store( $store_id ) ) {
			return;
		}

		$option        = self::key( self::DEACTIVATE_OPTION, $store_id );
		$deactivations = self::pending( $option );
		delete_option( $option );
		if ( $deactivations ) {
			$items = array();
			foreach ( $deactivations as $entry ) {
				// Each entry is one row; 1.1.0 queues held a list of rows per product.
				$items = array_merge( $items, isset( $entry['ref'] ) ? array( $entry ) : $entry );
			}
			if ( ! self::send_all( $items, array(), $store_id ) ) {
				return;
			}
		}

		$option  = self::key( self::PENDING_OPTION, $store_id );
		$pending = self::pending( $option );
		delete_option( $option );
		$chunks = array_chunk( array_keys( $pending ), self::CHUNK );
		foreach ( $chunks as $i => $ids ) {
			if ( ! self::sync_ids( $ids, $store_id ) ) {
				self::requeue_ids( array_merge( array(), ...array_slice( $chunks, $i + 1 ) ), $store_id );
				return;
			}
		}
	}

	/**
	 * Start a full catalogue sync in the background, one job per store (null:
	 * every connected store). A full sync already running for one of them
	 * starts again from the first page; the others carry on.
	 *
	 * @param string[]|null $store_ids
	 */
	public static function start_bulk_sync( $store_ids = null ) {
		$stores = null === $store_ids ? array_keys( Fetch_WC_Settings::stores() ) : array_map( 'strval', (array) $store_ids );
		if ( function_exists( 'as_get_scheduled_actions' ) && class_exists( 'ActionScheduler_Store' ) ) {
			$running = as_get_scheduled_actions(
				array(
					'hook'     => self::BULK_HOOK,
					'group'    => self::GROUP,
					'status'   => ActionScheduler_Store::STATUS_PENDING,
					'per_page' => 200,
				)
			);
			foreach ( $running as $action ) {
				$args = $action->get_args();
				if ( in_array( Fetch_WC_Settings::resolve_store( isset( $args['store'] ) ? (string) $args['store'] : null ), $stores, true ) ) {
					as_unschedule_action( self::BULK_HOOK, $args, self::GROUP );
				}
			}
		}
		foreach ( array_unique( $stores ) as $store_id ) {
			if ( ! Fetch_WC_Settings::store( $store_id ) ) {
				continue;
			}
			delete_option( self::key( self::BULK_FAILED_OPTION, $store_id ) );
			as_enqueue_async_action( self::BULK_HOOK, Fetch_WC_Settings::job_args( $store_id, array( 'page' => 1 ) ), self::GROUP );
			Fetch_WC_Logger::info( Fetch_WC_Settings::label( $store_id ) . 'Full product sync started.' );
		}
	}

	/** One page of a store's full sync (null: the primary store's, as queued by 1.3.0). */
	public static function bulk_page( $page, $store_id = null ) {
		$page     = max( 1, (int) $page );
		$store_id = Fetch_WC_Settings::resolve_store( $store_id );
		if ( ! Fetch_WC_Settings::store( $store_id ) ) {
			return;
		}
		$ids = Fetch_WC_Language::product_ids(
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
			Fetch_WC_Logger::info( Fetch_WC_Settings::label( $store_id ) . 'Full product sync finished.' );
			$option = self::key( self::BULK_FAILED_OPTION, $store_id );
			$failed = 'yes' === get_option( $option );
			delete_option( $option );
			Fetch_WC_Settings::set_store_status(
				$store_id,
				array(
					'full_sync'        => time(),
					'full_sync_failed' => $failed,
				)
			);
			Fetch_WC_Mirror::after_full_sync( $failed, $store_id );
			return;
		}
		if ( ! self::sync_ids( $ids, $store_id ) ) {
			update_option( self::key( self::BULK_FAILED_OPTION, $store_id ), 'yes', false );
		}
		as_enqueue_async_action( self::BULK_HOOK, Fetch_WC_Settings::job_args( $store_id, array( 'page' => $page + 1 ) ), self::GROUP );
	}

	/**
	 * Send products to one store, at its markup. A product outside the store's
	 * category filter that the store already has is sent switched off.
	 *
	 * @param int[] $ids
	 * @return bool false when Fetch could not be reached (the products are queued again).
	 */
	public static function sync_ids( array $ids, $store_id = null ) {
		$store_id   = Fetch_WC_Settings::resolve_store( $store_id );
		$markup     = Fetch_WC_Settings::store_markup( $store_id );
		$categories = Fetch_WC_Settings::store_categories( $store_id );
		$items      = array();
		$products   = array();
		foreach ( $ids as $id ) {
			if ( ! Fetch_WC_Language::is_default( $id ) ) {
				continue; // A translation: the default-language product is the one on Fetch.
			}
			$product = wc_get_product( $id );
			if ( ! Fetch_WC_Product_Mapper::supports( $product ) ) {
				continue;
			}
			$wanted = Fetch_WC_Product_Mapper::in_categories( $product, $categories );
			if ( ( 'trash' === $product->get_status() || ! $wanted ) && ! Fetch_WC_Product_Mapper::was_synced( $product, $store_id ) ) {
				continue; // Never synced to this store, nothing to switch off.
			}
			foreach ( Fetch_WC_Product_Mapper::to_fetch_items( $product, ! $wanted, $markup, $store_id ) as $pair ) {
				list( $item, $owner )     = $pair;
				$items[]                  = $item;
				$products[ $item['ref'] ] = $owner;
			}
		}
		return self::send_all( $items, $products, $store_id );
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
	 * everything after it again, for this store only.
	 *
	 * @param array<int,array<string,mixed>> $items
	 * @param array<string,WC_Product>       $products ref → product (empty for deactivations).
	 */
	private static function send_all( array $items, array $products, $store_id ) {
		$batches = self::batches( $items );
		foreach ( $batches as $i => $batch ) {
			if ( self::send( $batch, $products, $store_id ) ) {
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
				self::requeue_ids( $ids, $store_id );
			} else {
				self::queue_deactivations( $left, $store_id );
			}
			return false;
		}
		return true;
	}

	/** @param int[] $ids */
	private static function requeue_ids( array $ids, $store_id ) {
		if ( ! $ids ) {
			return;
		}
		$option  = self::key( self::PENDING_OPTION, $store_id );
		$pending = self::pending( $option );
		foreach ( $ids as $id ) {
			$pending[ $id ] = true;
		}
		update_option( $option, $pending, false );
	}

	/**
	 * @param array<int,array<string,mixed>> $items
	 * @param array<string,WC_Product>       $products ref → product, to record results.
	 * @return bool false when the request failed as a whole.
	 */
	private static function send( array $items, array $products, $store_id ) {
		$api   = new Fetch_WC_Api();
		$label = Fetch_WC_Settings::label( $store_id );
		// Unique per request: the sandbox replays a repeated key without applying
		// it, so a product switched off and then back on would stay off there.
		$result = $api->upsert_products( $store_id, $items, 'wc-' . wp_generate_uuid4() );

		if ( ! Fetch_WC_Api::ok( $result ) ) {
			$message = Fetch_WC_Api::error_message( $result );
			Fetch_WC_Logger::error( $label . sprintf( 'Product sync failed for %d product(s): %s', count( $items ), $message ) );
			Fetch_WC_Settings::set_store_status( $store_id, array( 'last_error' => $message, 'last_error_at' => time() ) );
			foreach ( $items as $item ) {
				if ( isset( $products[ $item['ref'] ] ) ) {
					self::record( $products[ $item['ref'] ], 'error', $message, $store_id );
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
				self::record( $product, 'error', $error, $store_id );
				Fetch_WC_Logger::error( $label . sprintf( 'Fetch rejected "%s" (%s): %s', $product->get_name(), $ref, $error ) );
				Fetch_WC_Settings::set_store_status( $store_id, array( 'last_error' => sprintf( '%s: %s', $ref, $error ), 'last_error_at' => time() ) );
				continue;
			}
			Fetch_WC_Product_Mapper::remember_ref( $product, $ref );
			if ( ! empty( $row['productId'] ) ) {
				$product->update_meta_data( self::key( self::META_PRODUCT_ID, $store_id ), (string) $row['productId'] );
			}
			$warnings = ! empty( $row['warnings'] ) && is_array( $row['warnings'] ) ? implode( ' ', $row['warnings'] ) : '';
			self::record( $product, 'synced', $warnings, $store_id );
		}

		Fetch_WC_Logger::info(
			$label . sprintf(
				'Synced %d product(s) to Fetch: %d created, %d updated, %d rejected.',
				count( $items ),
				isset( $body['created'] ) ? (int) $body['created'] : 0,
				isset( $body['updated'] ) ? (int) $body['updated'] : 0,
				isset( $body['rejected'] ) ? (int) $body['rejected'] : $rejected
			)
		);
		return true;
	}

	private static function record( WC_Product $product, $status, $error, $store_id ) {
		$product->update_meta_data( self::key( self::META_STATUS, $store_id ), $status );
		$product->update_meta_data( self::key( self::META_ERROR, $store_id ), $error );
		$product->update_meta_data( self::key( self::META_SYNCED_AT, $store_id ), time() );
		$product->save_meta_data();
	}

	/** Retry anything left pending after a failed send, store by store. */
	public static function retry_pending() {
		foreach ( array_keys( Fetch_WC_Settings::stores() ) as $store_id ) {
			if ( self::pending( self::key( self::PENDING_OPTION, $store_id ) ) || self::pending( self::key( self::DEACTIVATE_OPTION, $store_id ) ) ) {
				self::schedule_flush( $store_id );
			}
		}
	}

	/** A store was disconnected: drop its queues and jobs. Its product meta stays, for a reconnect. */
	public static function forget_store( $store_id ) {
		foreach ( array( self::PENDING_OPTION, self::DEACTIVATE_OPTION, self::BULK_FAILED_OPTION, Fetch_WC_Mirror::PENDING_OPTION ) as $base ) {
			delete_option( self::key( $base, $store_id ) );
		}
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::FLUSH_HOOK, Fetch_WC_Settings::job_args( $store_id ), self::GROUP );
		}
	}

	private static function pending( $option ) {
		$value = get_option( $option, array() );
		return is_array( $value ) ? $value : array();
	}
}
