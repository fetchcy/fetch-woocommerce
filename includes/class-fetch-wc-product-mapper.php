<?php
/**
 * WooCommerce product → Fetch `PUT /stores/{storeId}/products` item.
 *
 * Fetch prices are what the customer pays, VAT included, as decimal strings,
 * plus the merchant's optional price markup (the store's own, or the global one).
 * A product's ref is its SKU (or "wc-<id>" without one), frozen in post meta on
 * first sync so a later SKU edit updates the same Fetch product instead of
 * creating a second one.
 */

defined( 'ABSPATH' ) || exit;

class Fetch_WC_Product_Mapper {

	const META_REF = '_fetch_ref';

	/** Product types we can express on Fetch. */
	public static function supports( $product ) {
		return $product instanceof WC_Product && ( $product->is_type( 'simple' ) || $product->is_type( 'variable' ) );
	}

	public static function ref( WC_Product $product ) {
		$ref = (string) $product->get_meta( self::META_REF );
		if ( '' !== $ref ) {
			return $ref;
		}
		$sku = trim( (string) $product->get_sku( 'edit' ) );
		return '' !== $sku ? $sku : 'wc-' . $product->get_id();
	}

	/** Freeze the ref used for a successful sync. */
	public static function remember_ref( WC_Product $product, $ref ) {
		if ( (string) $product->get_meta( self::META_REF ) !== $ref ) {
			$product->update_meta_data( self::META_REF, $ref );
			$product->save_meta_data();
		}
	}

	/**
	 * Fetch items for one WooCommerce product, each paired with the WooCommerce
	 * product (or variation) its result is recorded on. $markup is the store's
 * price markup in percent; null is the global markup.
	 *
	 * Live Fetch stores accept SIMPLE products only through PUT /products, so a
	 * variable product is sent as one simple product per variation: its own ref
	 * (the variation SKU), "Parent – Black / M" as the name, its own price,
	 * stock and image, and the parent's descriptions, brand, category, tags,
	 * specs and SEO meta.
	 *
	 * $force_inactive builds the same full rows switched off, for a deletion.
	 * $new_for_store (a store id) leaves out items on backorder that store has
	 * not got yet, when "Exclude backorder items" is on: they are not created,
	 * but an item the store already has is still sent, at stock 0.
	 *
	 * @return array<int,array{0:array<string,mixed>,1:WC_Product}>
	 */
	public static function to_fetch_items( WC_Product $product, $force_inactive = false, $markup = null, $new_for_store = null ) {
		if ( ! self::supports( $product ) ) {
			return array();
		}

		$base = self::shared_fields( $product );
		if ( ! $product->is_type( 'variable' ) ) {
			if ( self::skip_new_backorder( $product, $new_for_store ) ) {
				return array();
			}
			$item = array_merge(
				array(
					'ref'         => self::ref( $product ),
					'name'        => wp_strip_all_tags( $product->get_name() ),
					'productType' => 'SIMPLE',
					'isActive'    => ! $force_inactive && self::is_active( $product ),
				),
				$base,
				self::price_and_stock( $product, $markup )
			);
			$barcode = self::barcode( $product );
			if ( '' !== $barcode ) {
				$item['barcode'] = $barcode;
			}
			$images = self::images( $product );
			if ( $images ) {
				$item['images'] = $images;
			}
			return array( array( $item, $product ) );
		}

		$parent_active = ! $force_inactive && self::is_active( $product );
		$parent_images = self::images( $product );
		$out           = array();
		foreach ( $product->get_children() as $child_id ) {
			$variation = wc_get_product( $child_id );
			if ( ! $variation instanceof WC_Product_Variation || self::skip_new_backorder( $variation, $new_for_store ) ) {
				continue;
			}
			$label = implode( ' / ', array_filter( array_values( self::variation_attributes( $variation ) ) ) );
			$item  = array_merge(
				array(
					'ref'         => self::ref( $variation ),
					'name'        => wp_strip_all_tags( $product->get_name() ) . ( '' !== $label ? ' – ' . $label : '' ),
					'productType' => 'SIMPLE',
					'isActive'    => $parent_active && self::variation_is_active( $variation ),
				),
				$base,
				self::price_and_stock( $variation, $markup )
			);
			$barcode = self::barcode( $variation );
			if ( '' !== $barcode ) {
				$item['barcode'] = $barcode;
			}
			$images = self::variation_images( $variation, $parent_images );
			if ( $images ) {
				$item['images'] = $images;
			}
			$out[] = array( $item, $variation );
		}
		return $out;
	}

	/**
	 * Rows that switch off a product (or one variation) about to be deleted.
	 * Live Fetch rejects a row without a price, so these are the full rows
	 * built from the product's current values with isActive=false.
	 */
	public static function deactivations( WC_Product $product, $markup = null ) {
		if ( $product instanceof WC_Product_Variation ) {
			$parent = wc_get_product( $product->get_parent_id() );
			foreach ( $parent instanceof WC_Product ? self::to_fetch_items( $parent, true, $markup ) : array() as $pair ) {
				if ( $pair[1]->get_id() === $product->get_id() ) {
					return array( $pair[0] );
				}
			}
			return array( self::deactivation( self::ref( $product ), $product->get_name() ) );
		}
		$out = array();
		foreach ( self::to_fetch_items( $product, true, $markup ) as $pair ) {
			$out[] = $pair[0];
		}
		return $out;
	}

	/**
	 * The refs a normal sync of this product sends as active: what "Mirror this
	 * shop" keeps on Fetch. Cheaper than to_fetch_items (no prices or images).
	 *
	 * @return string[]
	 */
	public static function active_refs( $product ) {
		if ( ! self::supports( $product ) || ! self::is_active( $product ) ) {
			return array();
		}
		if ( ! $product->is_type( 'variable' ) ) {
			return array( self::ref( $product ) );
		}
		$refs = array();
		foreach ( $product->get_children() as $child_id ) {
			$variation = wc_get_product( $child_id );
			if ( $variation instanceof WC_Product_Variation && self::variation_is_active( $variation ) ) {
				$refs[] = self::ref( $variation );
			}
		}
		return $refs;
	}

	/** True once a store (null: the primary one) has accepted this product, or any of its variations. */
	public static function was_synced( WC_Product $product, $store_id = null ) {
		$key = Fetch_WC_Product_Sync::META_PRODUCT_ID . Fetch_WC_Settings::suffix( $store_id );
		if ( $product->get_meta( $key ) ) {
			return true;
		}
		foreach ( $product->is_type( 'variable' ) ? $product->get_children() : array() as $child_id ) {
			$variation = wc_get_product( $child_id );
			if ( $variation && $variation->get_meta( $key ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A store's category filter: true when the product, or a parent category of
	 * one of its categories, is among $term_ids (default-language ids). No
	 * filter is every product.
	 *
	 * @param int[] $term_ids
	 */
	public static function in_categories( WC_Product $product, array $term_ids ) {
		if ( ! $term_ids ) {
			return true;
		}
		foreach ( $product->get_category_ids() as $term_id ) {
			foreach ( array_merge( array( (int) $term_id ), get_ancestors( (int) $term_id, 'product_cat', 'taxonomy' ) ) as $id ) {
				if ( in_array( Fetch_WC_Language::default_term_id( $id ), $term_ids, true ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/** Descriptions, brand, category, tags, specs and SEO, shared by a product's variations. */
	private static function shared_fields( WC_Product $product ) {
		$fields      = array();
		$description = self::plain_text( $product->get_description() ? $product->get_description() : $product->get_short_description() );
		if ( '' !== $description ) {
			$fields['description'] = $description;
		}
		$short = self::plain_text( $product->get_short_description() );
		if ( '' !== $short ) {
			$fields['shortDescription'] = $short;
		}
		$brand = self::brand( $product );
		if ( '' !== $brand ) {
			$fields['brand'] = $brand;
		}
		$category_path = self::category_path( $product );
		if ( '' !== $category_path ) {
			$fields['categoryPath'] = $category_path;
		}
		$tags = wp_get_post_terms( $product->get_id(), 'product_tag', array( 'fields' => 'names' ) );
		if ( ! is_wp_error( $tags ) && $tags ) {
			$fields['tags'] = array_values( $tags );
		}
		$specs = self::specs( $product );
		if ( $specs ) {
			$fields['specs'] = $specs;
		}
		$seo = self::seo( $product );
		if ( $seo ) {
			$fields['seo'] = $seo;
		}
		return $fields;
	}

	/** The variation's own image first, then the parent's gallery. */
	private static function variation_images( WC_Product_Variation $variation, array $parent_images ) {
		$images   = array();
		$image_id = $variation->get_image_id();
		$own      = $image_id ? wp_get_attachment_image_url( $image_id, 'full' ) : '';
		if ( $own ) {
			$images[] = array(
				'url'       => $own,
				'isPrimary' => true,
				'sortOrder' => 0,
				'alt'       => (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true ),
			);
		}
		foreach ( $parent_images as $image ) {
			if ( $image['url'] === $own ) {
				continue;
			}
			$image['isPrimary'] = 0 === count( $images );
			$image['sortOrder'] = count( $images );
			$images[]           = $image;
		}
		return $images;
	}

	/** Minimal row, only for a variation whose parent is already gone. */
	public static function deactivation( $ref, $name ) {
		return array(
			'ref'      => $ref,
			'name'     => $name ? $name : $ref,
			'isActive' => false,
		);
	}

	private static function is_active( WC_Product $product ) {
		return 'publish' === $product->get_status() && 'hidden' !== $product->get_catalog_visibility();
	}

	private static function variation_is_active( WC_Product_Variation $variation ) {
		return 'publish' === $variation->get_status() && $variation->variation_is_visible();
	}

	/**
	 * What WooCommerce would sell on backorder: status "onbackorder", or managed
	 * stock at or below 0 with backorders allowed ("notify" or "yes"). A
	 * variation that defers to its parent answers with the parent's settings.
	 */
	public static function on_backorder( WC_Product $product ) {
		if ( 'onbackorder' === $product->get_stock_status() ) {
			return true;
		}
		return $product->managing_stock() && (int) $product->get_stock_quantity() <= 0 && in_array( $product->get_backorders(), array( 'notify', 'yes' ), true );
	}

	/** A backorder item that $store_id has not got yet, with the setting on. */
	private static function skip_new_backorder( WC_Product $product, $store_id ) {
		return null !== $store_id
			&& Fetch_WC_Settings::exclude_backorders()
			&& self::on_backorder( $product )
			&& ! $product->get_meta( Fetch_WC_Product_Sync::META_PRODUCT_ID . Fetch_WC_Settings::suffix( $store_id ) );
	}

	/** price / salePrice / stockCount for a simple product or a variation. */
	private static function price_and_stock( WC_Product $product, $markup = null ) {
		$out     = array();
		$regular = $product->get_regular_price( 'edit' );
		$markup  = null === $markup ? Fetch_WC_Settings::markup() : $markup;
		if ( '' !== $regular && null !== $regular ) {
			$out['price'] = self::apply_markup( self::money( wc_get_price_including_tax( $product, array( 'price' => (float) $regular ) ) ), $markup );
		}
		if ( $product->is_on_sale( 'edit' ) && '' !== $product->get_sale_price( 'edit' ) ) {
			$out['salePrice'] = self::apply_markup( self::money( wc_get_price_including_tax( $product, array( 'price' => (float) $product->get_sale_price( 'edit' ) ) ) ), $markup );
		}

		$stock = self::stock( $product );
		if ( null !== $stock ) {
			$out['stockCount'] = $stock;
		}
		return $out;
	}

	/**
	 * Backorder items (when excluded) → 0. Managed stock → its quantity (never negative). Unmanaged → 0 when out of
	 * stock, otherwise omitted so Fetch sells it without stock tracking.
	 */
	private static function stock( WC_Product $product ) {
		if ( Fetch_WC_Settings::exclude_backorders() && self::on_backorder( $product ) ) {
			return 0;
		}
		if ( $product->managing_stock() ) {
			return max( 0, (int) $product->get_stock_quantity() );
		}
		return 'outofstock' === $product->get_stock_status() ? 0 : null;
	}

	/** {"Size": "M", "Colour": "Black"} with human labels and term names. */
	private static function variation_attributes( WC_Product_Variation $variation ) {
		$out = array();
		foreach ( $variation->get_variation_attributes( false ) as $taxonomy => $value ) {
			$label = wc_attribute_label( $taxonomy, $variation );
			if ( taxonomy_exists( $taxonomy ) ) {
				$term  = get_term_by( 'slug', $value, $taxonomy );
				$value = $term ? $term->name : $value;
			}
			if ( '' === (string) $value ) {
				$value = __( 'Any', 'fetch-for-woocommerce' );
			}
			$out[ $label ] = (string) $value;
		}
		return $out;
	}

	private static function images( WC_Product $product ) {
		$ids    = array_filter( array_merge( array( $product->get_image_id() ), $product->get_gallery_image_ids() ) );
		$images = array();
		foreach ( array_values( array_unique( $ids ) ) as $i => $id ) {
			$url = wp_get_attachment_image_url( $id, 'full' );
			if ( ! $url ) {
				continue;
			}
			$images[] = array(
				'url'       => $url,
				'isPrimary' => 0 === count( $images ),
				'sortOrder' => $i,
				'alt'       => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			);
		}
		return $images;
	}

	/** GTIN/EAN from WooCommerce 9.2+ "GTIN, UPC, EAN or ISBN", else common plugin meta. */
	private static function barcode( WC_Product $product ) {
		if ( method_exists( $product, 'get_global_unique_id' ) ) {
			$gtin = trim( (string) $product->get_global_unique_id( 'edit' ) );
			if ( '' !== $gtin ) {
				return $gtin;
			}
		}
		foreach ( array( '_ean', '_gtin', '_barcode', 'hwp_product_gtin', '_wpm_gtin_code' ) as $key ) {
			$value = trim( (string) $product->get_meta( $key ) );
			if ( '' !== $value ) {
				return $value;
			}
		}
		return '';
	}

	/**
	 * The "Brand attribute" setting first, then a brand taxonomy (WooCommerce
	 * Brands, Perfect Brands, YITH), then any attribute, global or local, called
	 * pa_brand/pa_brands or labelled Brand/Brands/Μάρκα. First value only.
	 */
	private static function brand( WC_Product $product ) {
		$attributes = $product->get_attributes();
		$chosen     = Fetch_WC_Settings::brand_attribute();
		if ( '' !== $chosen && isset( $attributes[ $chosen ] ) && $attributes[ $chosen ] instanceof WC_Product_Attribute ) {
			$values = self::attribute_values( $product, $attributes[ $chosen ] );
			if ( $values ) {
				return $values[0];
			}
		}
		foreach ( array( 'product_brand', 'pwb-brand', 'yith_product_brand' ) as $taxonomy ) {
			if ( taxonomy_exists( $taxonomy ) ) {
				$terms = wp_get_post_terms( $product->get_id(), $taxonomy, array( 'fields' => 'names' ) );
				if ( ! is_wp_error( $terms ) && $terms ) {
					return self::clean( $terms[0] );
				}
			}
		}
		foreach ( $attributes as $attribute ) {
			if ( ! $attribute instanceof WC_Product_Attribute ) {
				continue;
			}
			$name = strtolower( (string) $attribute->get_name() );
			if ( in_array( $name, array( 'pa_brand', 'pa_brands' ), true ) || preg_match( '/^(brand|brands|μάρκα)$/iu', self::clean( wc_attribute_label( $attribute->get_name(), $product ) ) ) ) {
				$values = self::attribute_values( $product, $attribute );
				if ( $values ) {
					return $values[0];
				}
			}
		}
		return '';
	}

	/**
	 * Visible, non-variation attributes in the shop's order: "Number of Cooking
	 * Zones" => "4". The brand attribute is included; Fetch drops it per store.
	 *
	 * @return array<int,array{name:string,value:string}>
	 */
	private static function specs( WC_Product $product ) {
		$specs = array();
		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof WC_Product_Attribute || ! $attribute->get_visible() || $attribute->get_variation() ) {
				continue;
			}
			$name  = self::cap( self::clean( wc_attribute_label( $attribute->get_name(), $product ) ), 100 );
			$value = self::cap( implode( ', ', self::attribute_values( $product, $attribute ) ), 1000 );
			if ( '' === $name || '' === $value ) {
				continue;
			}
			$specs[] = array(
				'name'  => $name,
				'value' => $value,
			);
			if ( count( $specs ) >= 100 ) {
				break;
			}
		}
		return $specs;
	}

	/** @return string[] Term names (global attribute) or options (local), cleaned, without blanks. */
	private static function attribute_values( WC_Product $product, WC_Product_Attribute $attribute ) {
		if ( $attribute->is_taxonomy() ) {
			$values = wc_get_product_terms( $product->get_id(), $attribute->get_name(), array( 'fields' => 'names' ) );
			$values = is_wp_error( $values ) ? array() : (array) $values;
		} else {
			$values = $attribute->get_options();
		}
		return array_values( array_filter( array_map( array( __CLASS__, 'clean' ), $values ), 'strlen' ) );
	}

	/**
	 * The shop's own SEO title and meta description, from Yoast SEO or Rank
	 * Math, with their variables rendered when the plugin is loaded.
	 *
	 * @return array{title?:string,description?:string}
	 */
	private static function seo( WC_Product $product ) {
		$id     = $product->get_id();
		$fields = array(
			'title'       => array( array( '_yoast_wpseo_title', 'yoast' ), array( 'rank_math_title', 'rank_math' ) ),
			'description' => array( array( '_yoast_wpseo_metadesc', 'yoast' ), array( 'rank_math_description', 'rank_math' ) ),
		);
		$caps   = array(
			'title'       => 255,
			'description' => 1000,
		);
		$seo    = array();
		foreach ( $fields as $field => $sources ) {
			foreach ( $sources as $source ) {
				$raw = (string) get_post_meta( $id, $source[0], true );
				if ( '' === trim( $raw ) ) {
					continue;
				}
				$value = self::cap( self::clean( self::replace_seo_vars( $raw, $id, $source[1] ) ), $caps[ $field ] );
				if ( '' !== $value ) {
					$seo[ $field ] = $value;
					break;
				}
			}
		}
		return $seo;
	}

	/** Renders %%title%% (Yoast) or %title% (Rank Math); the raw value when the plugin is absent or fails. */
	private static function replace_seo_vars( $value, $post_id, $plugin ) {
		try {
			if ( 'yoast' === $plugin && function_exists( 'wpseo_replace_vars' ) ) {
				$post = get_post( $post_id );
				return $post ? (string) wpseo_replace_vars( $value, $post ) : $value;
			}
			if ( 'rank_math' === $plugin && class_exists( '\RankMath\Helper' ) && method_exists( '\RankMath\Helper', 'replace_vars' ) ) {
				$post = get_post( $post_id );
				return $post ? (string) \RankMath\Helper::replace_vars( $value, $post ) : $value;
			}
		} catch ( Throwable $e ) {
			// A third-party failure must not stop the product sync.
			return $value;
		}
		return $value;
	}

	/** Deepest category, as "Parent > Child". */
	private static function category_path( WC_Product $product ) {
		$deepest = null;
		$depth   = -1;
		foreach ( $product->get_category_ids() as $term_id ) {
			$ancestors = get_ancestors( $term_id, 'product_cat', 'taxonomy' );
			if ( count( $ancestors ) > $depth ) {
				$depth   = count( $ancestors );
				$deepest = $term_id;
			}
		}
		if ( ! $deepest ) {
			return '';
		}
		$names = array();
		foreach ( array_reverse( get_ancestors( $deepest, 'product_cat', 'taxonomy' ) ) as $ancestor ) {
			$term = get_term( $ancestor, 'product_cat' );
			if ( $term && ! is_wp_error( $term ) ) {
				$names[] = $term->name;
			}
		}
		$term = get_term( $deepest, 'product_cat' );
		if ( $term && ! is_wp_error( $term ) ) {
			$names[] = $term->name;
		}
		return implode( ' > ', $names );
	}

	/**
	 * HTML → plain text with paragraphs kept: block ends become a blank line,
	 * <br>, </li> and a list start a line break; other whitespace collapses.
	 * Capped at 5000 characters.
	 */
	public static function plain_text( $html ) {
		$text = str_replace( array( "\r\n", "\r" ), "\n", (string) $html );
		$text = preg_replace( '#<br\s*/?>|</li\s*>|<(ul|ol)\b[^>]*>#i', "\n", $text );
		$text = preg_replace( '#</(p|div|h[1-6]|ul|ol|table|blockquote)\s*>#i', "\n\n", $text );
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/[ \t\x{00A0}]+/u', ' ', $text );
		$text = preg_replace( '/ ?\n ?/', "\n", $text );
		$text = trim( preg_replace( '/\n{3,}/', "\n\n", $text ) );
		return self::cap( $text, 5000 );
	}

	/** One line of plain text: tags and entities removed, whitespace collapsed. */
	private static function clean( $value ) {
		$text = html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES, 'UTF-8' );
		return trim( preg_replace( '/[\s\x{00A0}]+/u', ' ', $text ) );
	}

	private static function cap( $text, $max ) {
		return function_exists( 'mb_substr' ) ? trim( mb_substr( $text, 0, $max ) ) : trim( substr( $text, 0, $max ) );
	}

	/**
	 * Adds a percentage markup to a VAT-inclusive 2-decimal price, rounding
	 * half-up to the cent. Integer cent/basis-point maths, so 9.99 at 15%
	 * (11.4885) is 11.49 rather than whatever the float lands on.
	 */
	public static function apply_markup( $price, $percent ) {
		$cents = (int) round( (float) $price * 100 );
		$bp    = (int) round( (float) $percent * 100 );
		if ( $bp <= 0 ) {
			return self::money( $cents / 100 );
		}
		$marked = intdiv( $cents * ( 10000 + $bp ) + 5000, 10000 );
		return self::money( $marked / 100 );
	}

	public static function money( $amount ) {
		return number_format( (float) $amount, 2, '.', '' );
	}
}
