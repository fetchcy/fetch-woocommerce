<?php
/**
 * Plugin settings, stored as one option array.
 */

defined( 'ABSPATH' ) || exit;

class Fetch_WC_Settings {

	const OPTION = 'fetch_wc_settings';

	const ENVIRONMENTS = array(
		'production' => 'https://api.fetch.com.cy',
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

	public static function base_url() {
		$env = self::get( 'environment' );
		if ( 'custom' === $env ) {
			return untrailingslashit( (string) self::get( 'custom_base_url' ) );
		}
		return isset( self::ENVIRONMENTS[ $env ] ) ? self::ENVIRONMENTS[ $env ] : self::ENVIRONMENTS['production'];
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

	public static function enabled( $key ) {
		return 'yes' === self::get( $key );
	}
}
