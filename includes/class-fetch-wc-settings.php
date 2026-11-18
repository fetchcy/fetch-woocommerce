<?php
/**
 * Plugin settings, stored as one option array.
 *
 * Credentials, server, brand attribute and the sync and import switches are
 * global. Each connected Fetch store can set its own price markup, catalogue
 * mode, category filter, auto-accept and preparation time; a store that sets
 * none uses the global value.
 *
 * `store_id` is the "primary" store: the one connected first, and the only one
 * before 1.4.0. It keeps the 1.3.0 option names, meta keys and job arguments;
 * every other store gets its own, suffixed with its id (see suffix()). So an
 * upgrade needs no migration, jobs queued by 1.3.0 still run, and a
 * single-store shop's data stays exactly as it was.
 */

defined( 'ABSPATH' ) || exit;

class Fetch_WC_Settings {

	const OPTION = 'fetch_wc_settings';

	/**
	 * Sandbox keys are issued on production too (the key, not the server,
	 * decides test data), so staging is only for keys Fetch moved there.
	 */
	const ENVIRONMENTS = array(
		'production' => 'https://api.fetch.com.cy',
		'staging'    => 'https://api-stg.fetch.com.cy',
	);

	public static function defaults() {
		return array(
			'environment'        => 'production',
			'custom_base_url'    => '',
			'client_id'          => '',
			'client_secret'      => '',
			'store_id'           => '',
			'store_name'         => '',
			'sync_products'      => 'yes',
			'price_markup'       => 0,
			'catalogue_mode'     => 'add_update',
			'brand_attribute'    => '',
			// Absent from a 1.4.0 config, so an upgrade reads it as on.
			'exclude_backorders' => 'yes',
			'import_orders'      => 'yes',
			'auto_accept'        => 'no',
			'prep_minutes'       => 15,
			'webhook_secret'     => '',
			'webhook_registered' => '',
			// Connected stores, id => store config; null until 1.4.0 saves them,
			// which means "the 1.3.0 store_id, with the global settings".
			'stores'             => null,
		);
	}

	const STATUS_OPTION = 'fetch_wc_store_status';

	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public static function update( array $values ) {
		update_option( self::OPTION, array_merge( self::all(), $values ), false );
	}

	/** "production", "staging" or "custom"; anything else is production. */
	public static function sanitize_environment( $raw ) {
		$env = (string) $raw;
		return 'custom' === $env || isset( self::ENVIRONMENTS[ $env ] ) ? $env : 'production';
	}

	public static function base_url() {
		$env = self::sanitize_environment( self::get( 'environment' ) );
		if ( 'custom' === $env ) {
			return untrailingslashit( (string) self::get( 'custom_base_url' ) );
		}
		return self::ENVIRONMENTS[ $env ];
	}

	public static function is_connected() {
		return '' !== self::get( 'client_id' ) && '' !== self::get( 'client_secret' ) && (bool) self::stores();
	}

	/** A store's own settings; blank values (and no categories) mean "use the global setting". */
	public static function store_defaults() {
		return array(
			'id'             => '',
			'name'           => '',
			'price_markup'   => '',
			'catalogue_mode' => '',
			'categories'     => array(),
			'auto_accept'    => '',
			'prep_minutes'   => '',
		);
	}

	/**
	 * Every connected Fetch store, id => config, in the order they were connected.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function stores() {
		$all = self::all();
		if ( is_array( $all['stores'] ) ) {
			$rows = $all['stores'];
		} elseif ( '' !== (string) $all['store_id'] ) {
			// 1.3.0: one store, with the global settings.
			$rows = array(
				array(
					'id'   => (string) $all['store_id'],
					'name' => (string) $all['store_name'],
				),
			);
		} else {
			$rows = array();
		}
		$out = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['id'] ) || '' === (string) $row['id'] ) {
				continue;
			}
			$store               = array_merge( self::store_defaults(), array_intersect_key( $row, self::store_defaults() ) );
			$store['id']         = (string) $store['id'];
			$store['categories'] = array_values( array_unique( array_filter( array_map( 'intval', (array) $store['categories'] ) ) ) );
			$out[ $store['id'] ] = $store;
		}
		return $out;
	}

	/** @return array<string,mixed>|null A connected store's config. */
	public static function store( $store_id ) {
		$stores = self::stores();
		return isset( $stores[ (string) $store_id ] ) ? $stores[ (string) $store_id ] : null;
	}

	/** null means the primary store (what jobs and calls from before 1.4.0 meant). */
	public static function resolve_store( $store_id ) {
		return null === $store_id || '' === (string) $store_id ? (string) self::get( 'store_id' ) : (string) $store_id;
	}

	public static function is_primary( $store_id ) {
		return self::resolve_store( $store_id ) === (string) self::get( 'store_id' );
	}

	/**
	 * "" for the primary store, "_<id>" for any other: appended to the 1.3.0
	 * option names and meta keys to give each store its own queue and results.
	 */
	public static function suffix( $store_id ) {
		if ( self::is_primary( $store_id ) ) {
			return '';
		}
		$id  = (string) $store_id;
		$key = strtolower( preg_replace( '/[^A-Za-z0-9_-]/', '', $id ) );
		return '_' . ( '' !== $key && strlen( $key ) <= 64 && strtolower( $id ) === $key ? $key : md5( $id ) );
	}

	/** Action Scheduler arguments for a store's job: the primary store's are 1.3.0's. */
	public static function job_args( $store_id, array $args = array() ) {
		return self::is_primary( $store_id ) ? $args : $args + array( 'store' => (string) $store_id );
	}

	public static function store_name( $store_id ) {
		$store = self::store( self::resolve_store( $store_id ) );
		if ( $store && '' !== $store['name'] ) {
			return $store['name'];
		}
		return self::is_primary( $store_id ) && '' !== (string) self::get( 'store_name' ) ? (string) self::get( 'store_name' ) : self::resolve_store( $store_id );
	}

	/** "[Branch name] " in front of log lines when more than one store is connected. */
	public static function label( $store_id ) {
		return count( self::stores() ) > 1 ? '[' . self::store_name( $store_id ) . '] ' : '';
	}

	/** The markup this store's prices get: its own, else the global one. */
	public static function store_markup( $store_id ) {
		$store = self::store( self::resolve_store( $store_id ) );
		return $store && '' !== (string) $store['price_markup'] ? self::sanitize_markup( $store['price_markup'] ) : self::markup();
	}

	public static function store_mirror( $store_id ) {
		$store = self::store( self::resolve_store( $store_id ) );
		return $store && '' !== (string) $store['catalogue_mode'] ? 'mirror' === self::sanitize_catalogue_mode( $store['catalogue_mode'] ) : self::mirror();
	}

	public static function store_auto_accept( $store_id ) {
		$store = self::store( self::resolve_store( $store_id ) );
		return $store && '' !== (string) $store['auto_accept'] ? 'yes' === $store['auto_accept'] : self::enabled( 'auto_accept' );
	}

	public static function store_prep_minutes( $store_id ) {
		$store = self::store( self::resolve_store( $store_id ) );
		$value = $store && '' !== (string) $store['prep_minutes'] ? $store['prep_minutes'] : self::get( 'prep_minutes' );
		return max( 0, min( 180, (int) $value ) );
	}

	/** @return int[] The store's category filter (default-language term ids); empty means every product. */
	public static function store_categories( $store_id ) {
		$store = self::store( self::resolve_store( $store_id ) );
		return $store ? $store['categories'] : array();
	}

	/**
	 * Last full sync, last order and last error per store, for the settings page.
	 *
	 * @return array<string,mixed>
	 */
	public static function store_status( $store_id ) {
		$all = get_option( self::STATUS_OPTION, array() );
		$id  = self::resolve_store( $store_id );
		return is_array( $all ) && isset( $all[ $id ] ) && is_array( $all[ $id ] ) ? $all[ $id ] : array();
	}

	public static function set_store_status( $store_id, array $values ) {
		$all = get_option( self::STATUS_OPTION, array() );
		$all = is_array( $all ) ? $all : array();
		$id  = self::resolve_store( $store_id );
		if ( '' === $id ) {
			return;
		}
		$all[ $id ] = array_merge( isset( $all[ $id ] ) && is_array( $all[ $id ] ) ? $all[ $id ] : array(), $values );
		update_option( self::STATUS_OPTION, $all, false );
	}

	/**
	 * "Price markup (%)" input → a percentage in 0–100 with at most 2 decimals.
	 * Accepts "12,5" and a trailing "%"; blank means 0; anything else non-numeric is 0.
	 */
	public static function sanitize_markup( $raw ) {
		$value = str_replace( array( ',', '%', ' ' ), array( '.', '', '' ), trim( (string) $raw ) );
		if ( '' === $value || ! is_numeric( $value ) ) {
			return 0.0;
		}
		return round( max( 0.0, min( 100.0, (float) $value ) ), 2 );
	}

	public static function markup() {
		return self::sanitize_markup( self::get( 'price_markup' ) );
	}

	/** "add_update" (default) or "mirror"; anything else is add_update. */
	public static function sanitize_catalogue_mode( $raw ) {
		return 'mirror' === (string) $raw ? 'mirror' : 'add_update';
	}

	public static function mirror() {
		return 'mirror' === self::sanitize_catalogue_mode( self::get( 'catalogue_mode' ) );
	}

	/**
	 * "Brand attribute" setting → a global attribute taxonomy ("pa_brands"), or
	 * "" for Automatic. A name that is not a valid attribute taxonomy is "".
	 * $known lists the shop's attribute taxonomies when they can be checked.
	 */
	public static function sanitize_brand_attribute( $raw, $known = null ) {
		$value = strtolower( trim( (string) $raw ) );
		if ( ! preg_match( '/^pa_[a-z0-9_%.-]{1,60}$/', $value ) ) {
			return '';
		}
		return null === $known || in_array( $value, (array) $known, true ) ? $value : '';
	}

	public static function brand_attribute() {
		return self::sanitize_brand_attribute( self::get( 'brand_attribute' ) );
	}

	/** "Exclude backorder items": on unless a shop turned it off. */
	public static function exclude_backorders() {
		return 'no' !== self::get( 'exclude_backorders' );
	}

	public static function enabled( $key ) {
		return 'yes' === self::get( $key );
	}
}
