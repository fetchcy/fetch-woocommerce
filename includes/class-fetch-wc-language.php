<?php
/**
 * Multilingual shops: only products in the site's default language go to Fetch.
 *
 * WPML (with or without WooCommerce Multilingual) and Polylang store each
 * translation as its own product post, often with its own SKU ("CCAF 011-el")
 * or the original's. Synced as they are, every language would become another
 * Fetch product, or several rows with one ref would overwrite each other.
 * The default-language post holds the default-language content, so it is the
 * one sent. A product with no language assigned counts as default.
 *
 * TranslatePress and Weglot keep translations inside one post and need nothing.
 * Only the plugins' public APIs are used; without either, every product is
 * default and nothing changes.
 */

defined( 'ABSPATH' ) || exit;

class Fetch_WC_Language {

	/** "polylang", "wpml" or "" when no supported multilingual plugin is active. */
	public static function plugin() {
		// Polylang first: its WPML compatibility layer registers wpml_* filters too.
		if ( function_exists( 'pll_default_language' ) && function_exists( 'pll_get_post_language' ) ) {
			return 'polylang';
		}
		if ( function_exists( 'has_filter' ) && has_filter( 'wpml_default_language' ) && has_filter( 'wpml_element_language_details' ) ) {
			return 'wpml';
		}
		return '';
	}

	/** Language code of the site's default language, "" when not multilingual. */
	public static function default_language() {
		switch ( self::plugin() ) {
			case 'polylang':
				return (string) pll_default_language( 'slug' );
			case 'wpml':
				return (string) apply_filters( 'wpml_default_language', null );
		}
		return '';
	}

	/** "English" for the settings page; the code when the plugin has no name for it. */
	public static function default_language_name() {
		$code = self::default_language();
		if ( '' === $code ) {
			return '';
		}
		if ( 'polylang' === self::plugin() ) {
			$name = pll_default_language( 'name' );
		} else {
			$languages = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
			$name      = is_array( $languages ) && isset( $languages[ $code ]['translated_name'] ) ? $languages[ $code ]['translated_name'] : '';
		}
		return '' !== (string) $name ? (string) $name : $code;
	}

	/** Language code of one product or variation, "" when it has none. */
	public static function post_language( $id ) {
		$id = (int) $id;
		if ( ! $id ) {
			return '';
		}
		switch ( self::plugin() ) {
			case 'polylang':
				return (string) pll_get_post_language( $id, 'slug' );
			case 'wpml':
				$details = apply_filters(
					'wpml_element_language_details',
					null,
					array(
						'element_id'   => $id,
						'element_type' => 'post_' . get_post_type( $id ),
					)
				);
				if ( is_object( $details ) && ! empty( $details->language_code ) ) {
					return (string) $details->language_code;
				}
				$details = apply_filters( 'wpml_post_language_details', null, $id );
				return is_array( $details ) && ! empty( $details['language_code'] ) ? (string) $details['language_code'] : '';
		}
		return '';
	}

	/**
	 * True for a product (or variation) to sync. A variation without a
	 * language of its own (Polylang does not translate them) follows its parent.
	 */
	public static function is_default( $id ) {
		$default = self::default_language();
		if ( '' === $default ) {
			return true;
		}
		$language = self::post_language( $id );
		if ( '' === $language && 'product_variation' === get_post_type( $id ) ) {
			$language = self::post_language( wp_get_post_parent_id( $id ) );
		}
		return '' === $language || $language === $default;
	}

	/**
	 * The default-language original of a translated product, so a Fetch order
	 * reduces stock on the product that was synced. Itself when it is already
	 * default or has no original.
	 */
	public static function original_id( $id ) {
		$id = (int) $id;
		if ( ! $id || self::is_default( $id ) ) {
			return $id;
		}
		$default  = self::default_language();
		$original = 0;
		if ( 'polylang' === self::plugin() ) {
			$original = function_exists( 'pll_get_post' ) ? (int) pll_get_post( $id, $default ) : 0;
		} else {
			$original = (int) apply_filters( 'wpml_object_id', $id, get_post_type( $id ), false, $default );
		}
		return $original ? $original : $id;
	}

	/**
	 * wc_get_products() across every language, so a page of product IDs never
	 * depends on the language WPML or Polylang filters the current request by;
	 * callers keep the default-language ones with is_default().
	 *
	 * @return int[]
	 */
	public static function product_ids( array $args ) {
		if ( '' === self::plugin() ) {
			return wc_get_products( $args );
		}
		$all = static function ( $query ) {
			$query['suppress_filters'] = true; // WPML filters WP_Query by language in posts_where.
			$query['lang']             = ''; // Polylang: every language, and posts with none.
			return $query;
		};
		add_filter( 'woocommerce_product_data_store_cpt_get_products_query', $all );
		try {
			return wc_get_products( $args );
		} finally {
			remove_filter( 'woocommerce_product_data_store_cpt_get_products_query', $all );
		}
	}
}
