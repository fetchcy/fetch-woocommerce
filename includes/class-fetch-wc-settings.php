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

	public static function enabled( $key ) {
		return 'yes' === self::get( $key );
	}
}
