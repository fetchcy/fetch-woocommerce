<?php
/**
 * Plugin settings, stored as one option array.
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
			'import_orders'      => 'yes',
			'auto_accept'        => 'no',
			'prep_minutes'       => 15,
			'webhook_secret'     => '',
			'webhook_registered' => '',
		);
	}

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
		return '' !== self::get( 'client_id' ) && '' !== self::get( 'client_secret' ) && '' !== self::get( 'store_id' );
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

	public static function enabled( $key ) {
		return 'yes' === self::get( $key );
	}
}
