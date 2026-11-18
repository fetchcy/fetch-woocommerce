<?php
/**
 * "Mirror this shop 1:1": at the end of a full sync, switch off every listing
 * in the Fetch store that is not a product this shop sells.
 *
 * It only ever switches listings off (isActive=false), never deletes them, so
 * it is reversible: a product that comes back is switched on again by the
 * normal sync. Names, photos and descriptions live in Fetch's shared catalogue
 * and are not touched; 1:1 means which products are on sale, at what price and
 * stock.
 *
 * Nothing is switched off unless the full sync reached Fetch for every product
 * and the listing scan read every page. A run that would switch off more than
 * half of the store's active listings waits for the merchant to confirm.
 *
 * Each Fetch store mirrors on its own: its own mode, its own full sync, and
 * only the products its category filter takes. Mirroring one store never
 * touches another.
 */

defined( 'ABSPATH' ) || exit;

class Fetch_WC_Mirror {

	const HOOK           = 'fetch_wc_mirror_apply';
	const PENDING_OPTION = 'fetch_wc_mirror_pending';
	/** GET /listings page size; the API's maximum. */
	const PAGE_LIMIT = 500;
	/** A cursor that never ends is a bug, not a 1M-listing store. */
	const MAX_PAGES = 2000;
	const SAMPLES   = 20;
	/** Above this share of the active listings, the merchant confirms first. */
	const GUARD_RATIO = 0.5;

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'apply_confirmed' ), 10, 2 );
	}

	/** End of a store's full sync ("Sync all products now", or the resync after a markup change). */
	public static function after_full_sync( $push_failed, $store_id = null ) {
		if ( ! Fetch_WC_Settings::store_mirror( $store_id ) ) {
			return;
		}
		if ( $push_failed ) {
			Fetch_WC_Logger::error( Fetch_WC_Settings::label( $store_id ) . 'Mirror skipped: the full sync could not reach Fetch for every product, so nothing was switched off.' );
			return;
		}
		self::run( null, $store_id );
	}

	/** "Apply anyway": switch off up to the number the merchant confirmed (null store: the primary one). */
	public static function apply_confirmed( $up_to, $store_id = null ) {
		self::run( max( 0, (int) $up_to ), $store_id );
	}

	/** Dry run for the settings page. */
	public static function preview( $store_id = null ) {
		$store_id = Fetch_WC_Settings::resolve_store( $store_id );
		return self::execute( new Fetch_WC_Api(), $store_id, self::shop_refs( $store_id ), true );
	}

	private static function run( $confirmed_up_to, $store_id ) {
		$store_id = Fetch_WC_Settings::resolve_store( $store_id );
		if ( ! Fetch_WC_Settings::store_mirror( $store_id ) || ! Fetch_WC_Settings::is_connected() || ! Fetch_WC_Settings::store( $store_id ) ) {
			return;
		}
		$result = self::execute( new Fetch_WC_Api(), $store_id, self::shop_refs( $store_id ), false, $confirmed_up_to );
		$option = self::PENDING_OPTION . Fetch_WC_Settings::suffix( $store_id );

		if ( 'needs_confirmation' === $result['status'] ) {
			update_option(
				$option,
				array(
					'time'    => time(),
					'off'     => $result['off'],
					'active'  => $result['active'],
					'scanned' => $result['scanned'],
					'samples' => $result['samples'],
				),
				false
			);
		} elseif ( 'scan_failed' !== $result['status'] ) {
			delete_option( $option );
		}
		self::log( $result, Fetch_WC_Settings::label( $store_id ) );
	}

	private static function log( array $r, $label ) {
		$counts = sprintf( 'scanned %d Fetch products, kept %d, switched off %d, %d errors', $r['scanned'], $r['kept'], $r['switched_off'], $r['errors'] );
		switch ( $r['status'] ) {
			case 'scan_failed':
				Fetch_WC_Logger::error( $label . 'Mirror aborted, nothing was switched off: could not read every listing from Fetch (' . $r['error'] . ').' );
				break;
			case 'needs_confirmation':
				Fetch_WC_Logger::error( $label . sprintf( 'Mirror paused: it would switch off %d of %d active Fetch products, more than half. Confirm with "Apply anyway" under WooCommerce → Fetch.', $r['off'], $r['active'] ) . ' (' . $counts . ')' );
				break;
			case 'apply_failed':
				Fetch_WC_Logger::error( $label . 'Mirror stopped early (' . $r['error'] . '): ' . $counts . '.' );
				break;
			default:
				$message = $label . 'Mirror finished: ' . $counts . '.';
				$r['errors'] ? Fetch_WC_Logger::error( $message ) : Fetch_WC_Logger::info( $message );
		}
	}

	/**
	 * Refs this shop sells in a store: every row a full sync sends to it as
	 * active. Translations are not synced, and products outside the store's
	 * category filter are not sent, so their listings are not kept either.
	 *
	 * @return array<string,true>
	 */
	public static function shop_refs( $store_id = null ) {
		$categories = Fetch_WC_Settings::store_categories( $store_id );
		$keep       = array();
		$page       = 1;
		do {
			$ids = Fetch_WC_Language::product_ids(
				array(
					'type'    => array( 'simple', 'variable' ),
					'status'  => array( 'publish' ),
					'limit'   => 200,
					'page'    => $page,
					'orderby' => 'ID',
					'order'   => 'ASC',
					'return'  => 'ids',
				)
			);
			foreach ( $ids as $id ) {
				if ( ! Fetch_WC_Language::is_default( $id ) ) {
					continue;
				}
				$product = wc_get_product( $id );
				if ( $categories && ( ! $product instanceof WC_Product || ! Fetch_WC_Product_Mapper::in_categories( $product, $categories ) ) ) {
					continue;
				}
				foreach ( Fetch_WC_Product_Mapper::active_refs( $product ) as $ref ) {
					$keep[ $ref ] = true;
				}
			}
			++$page;
		} while ( 200 === count( $ids ) );
		return $keep;
	}

	/**
	 * Scan, plan and, unless $dry, switch off. Takes the API client as an
	 * argument so the unit tests can drive it with a fake.
	 *
	 * @param object             $api             list_listings() and batch_listings(), like Fetch_WC_Api.
	 * @param array<string,true> $keep            shop_refs().
	 * @param int|null           $confirmed_up_to The count the merchant confirmed; null applies the 50% guard.
	 * @return array{status:string, error:string, scanned:int, active:int, kept:int, off:int, switched_off:int, errors:int, samples:string[], needs_confirmation:bool}
	 */
	public static function execute( $api, $store_id, array $keep, $dry = false, $confirmed_up_to = null ) {
		$out = array(
			'status'             => 'done',
			'error'              => '',
			'scanned'            => 0,
			'active'             => 0,
			'kept'               => 0,
			'off'                => 0,
			'switched_off'       => 0,
			'errors'             => 0,
			'samples'            => array(),
			'needs_confirmation' => false,
		);

		$listings = self::scan( $api, $store_id );
		if ( is_wp_error( $listings ) ) {
			$out['status'] = 'scan_failed';
			$out['error']  = $listings->get_error_message();
			return $out;
		}

		$plan                      = self::plan( $listings, $keep );
		$out['scanned']            = $plan['scanned'];
		$out['active']             = $plan['active'];
		$out['kept']               = $plan['kept'];
		$out['off']                = count( $plan['off'] );
		$out['needs_confirmation'] = self::needs_confirmation( $out['off'], $out['active'] );
		foreach ( array_slice( $plan['off'], 0, self::SAMPLES ) as $listing ) {
			$out['samples'][] = self::label( $listing );
		}

		if ( $dry ) {
			$out['status'] = 'preview';
			return $out;
		}
		if ( ! $out['off'] ) {
			return $out;
		}
		if ( $out['needs_confirmation'] && ( null === $confirmed_up_to || $out['off'] > $confirmed_up_to ) ) {
			$out['status'] = 'needs_confirmation';
			return $out;
		}

		list( $rows, $unusable ) = self::rows( $plan['off'] );
		$out['errors']           = $unusable;
		$run                     = wp_generate_uuid4();
		$batches                 = Fetch_WC_Product_Sync::batches( $rows );
		foreach ( $batches as $i => $batch ) {
			// Unique per request: the sandbox replays a repeated key without applying it.
			$result = $api->batch_listings( $store_id, $batch, 'wc-mirror-' . $run . '-' . $i );
			if ( is_wp_error( $result ) || ! in_array( (int) $result['code'], array( 200, 207, 422 ), true ) ) {
				$out['status'] = 'apply_failed';
				$out['error']  = Fetch_WC_Api::error_message( $result );
				foreach ( array_slice( $batches, $i ) as $left ) {
					$out['errors'] += count( $left );
				}
				return $out;
			}
			$body     = is_array( $result['body'] ) ? $result['body'] : array();
			$rejected = 0;
			if ( isset( $body['rejected'] ) ) {
				$rejected = (int) $body['rejected'];
			} elseif ( isset( $body['results'] ) && is_array( $body['results'] ) ) {
				foreach ( $body['results'] as $row ) {
					$rejected += isset( $row['status'] ) && 'error' === $row['status'] ? 1 : 0;
				}
			}
			$rejected = 422 === (int) $result['code'] ? count( $batch ) : min( $rejected, count( $batch ) );
			$out['switched_off'] += count( $batch ) - $rejected;
			$out['errors']       += $rejected;
		}
		return $out;
	}

	/**
	 * Every listing in the store, following nextCursor until null. Any failed
	 * page fails the whole scan: a partial scan would switch off listings it
	 * never saw as kept.
	 *
	 * @param object $api
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	public static function scan( $api, $store_id ) {
		$listings = array();
		$query    = array( 'limit' => (string) self::PAGE_LIMIT );
		$seen     = array();
		for ( $page = 1; $page <= self::MAX_PAGES; $page++ ) {
			$result = $api->list_listings( $store_id, $query );
			if ( ! Fetch_WC_Api::ok( $result ) ) {
				return new WP_Error( 'fetch_mirror_scan', sprintf( 'listing page %d: %s', $page, Fetch_WC_Api::error_message( $result ) ) );
			}
			$body = $result['body'];
			if ( ! is_array( $body ) || ! isset( $body['data'] ) || ! is_array( $body['data'] ) ) {
				return new WP_Error( 'fetch_mirror_scan', sprintf( 'listing page %d: unexpected response', $page ) );
			}
			foreach ( $body['data'] as $listing ) {
				if ( is_array( $listing ) && isset( $listing['listingId'] ) ) {
					// Keyed: a listing updated mid-scan moves to a later page and is seen twice.
					$listings[ (string) $listing['listingId'] ] = $listing;
				}
			}
			$next = isset( $body['nextCursor'] ) ? (string) $body['nextCursor'] : '';
			if ( '' === $next ) {
				return array_values( $listings );
			}
			if ( isset( $seen[ $next ] ) ) {
				return new WP_Error( 'fetch_mirror_scan', 'the listing cursor repeated' );
			}
			$seen[ $next ]   = true;
			$query['cursor'] = $next;
		}
		return new WP_Error( 'fetch_mirror_scan', sprintf( 'more than %d listing pages', self::MAX_PAGES ) );
	}

	/**
	 * Kept: listings whose ref is one of the shop's. Switched off: every other
	 * listing still active, including those with no ref at all.
	 *
	 * @param array<int,array<string,mixed>> $listings
	 * @param array<string,true>             $keep
	 * @return array{scanned:int, active:int, kept:int, off:array<int,array<string,mixed>>}
	 */
	public static function plan( array $listings, array $keep ) {
		$out = array(
			'scanned' => count( $listings ),
			'active'  => 0,
			'kept'    => 0,
			'off'     => array(),
		);
		foreach ( $listings as $listing ) {
			$ref    = isset( $listing['ref'] ) ? (string) $listing['ref'] : '';
			$active = ! empty( $listing['isActive'] );
			if ( $active ) {
				++$out['active'];
			}
			if ( '' !== $ref && isset( $keep[ $ref ] ) ) {
				++$out['kept'];
			} elseif ( $active ) {
				$out['off'][] = $listing;
			}
		}
		return $out;
	}

	/**
	 * listings/batch rows, addressed by listingId (exact and store-scoped, and
	 * the only handle a listing without a ref has), with the listing's current
	 * price because a live store rejects a row without one.
	 *
	 * @return array{0:array<int,array<string,mixed>>, 1:int} rows, and listings that cannot be addressed.
	 */
	public static function rows( array $off ) {
		$rows     = array();
		$unusable = 0;
		foreach ( $off as $listing ) {
			$id    = isset( $listing['listingId'] ) ? (string) $listing['listingId'] : '';
			$price = isset( $listing['price'] ) ? (string) $listing['price'] : '';
			if ( '' === $id || '' === $price ) {
				++$unusable;
				continue;
			}
			$rows[] = array(
				'listingId' => $id,
				'price'     => $price,
				'isActive'  => false,
			);
		}
		return array( $rows, $unusable );
	}

	public static function needs_confirmation( $off, $active ) {
		return $off > 0 && $off > $active * self::GUARD_RATIO;
	}

	private static function label( array $listing ) {
		foreach ( array( 'name', 'ref', 'listingId' ) as $key ) {
			if ( isset( $listing[ $key ] ) && '' !== (string) $listing[ $key ] ) {
				return (string) $listing[ $key ];
			}
		}
		return '?';
	}

	/** @return array<string,mixed>|null A store's run waiting for "Apply anyway" (null: the primary store's). */
	public static function pending( $store_id = null ) {
		$pending = get_option( self::PENDING_OPTION . Fetch_WC_Settings::suffix( $store_id ), null );
		return is_array( $pending ) && isset( $pending['off'] ) ? $pending : null;
	}
}
