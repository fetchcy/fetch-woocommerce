<?php
/**
 * WooCommerce product → Fetch `PUT /stores/{storeId}/products` item.
 *
 * Fetch prices are what the customer pays, VAT included, as decimal strings.
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
	 * product (or variation) its result is recorded on.
	 *
	 * Live Fetch stores accept SIMPLE products only through PUT /products, so a
	 * variable product is sent as one simple product per variation: its own ref
	 * (the variation SKU), "Parent – Black / M" as the name, its own price,
	 * stock and image, and the parent's description, brand, category and tags.
	 *
	 * @return array<int,array{0:array<string,mixed>,1:WC_Product}>
	 */
	public static function to_fetch_items( WC_Product $product ) {
		if ( ! self::supports( $product ) ) {
			return array();
		}

		$base = self::shared_fields( $product );
		if ( ! $product->is_type( 'variable' ) ) {
			$item = array_merge(
				array(
					'ref'         => self::ref( $product ),
					'name'        => wp_strip_all_tags( $product->get_name() ),
					'productType' => 'SIMPLE',
					'isActive'    => self::is_active( $product ),
				),
				$base,
				self::price_and_stock( $product )
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

		$parent_active = self::is_active( $product );
		$parent_images = self::images( $product );
		$out           = array();
		foreach ( $product->get_children() as $child_id ) {
			$variation = wc_get_product( $child_id );
			if ( ! $variation instanceof WC_Product_Variation ) {
				continue;
			}
			$label = implode( ' / ', array_filter( array_values( self::variation_attributes( $variation ) ) ) );
			$item  = array_merge(
				array(
					'ref'         => self::ref( $variation ),
					'name'        => wp_strip_all_tags( $product->get_name() ) . ( '' !== $label ? ' – ' . $label : '' ),
					'productType' => 'SIMPLE',
					'isActive'    => $parent_active && 'publish' === $variation->get_status() && $variation->variation_is_visible(),
				),
				$base,
				self::price_and_stock( $variation )
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

	/** Deactivation payloads for a product about to be deleted (each variation for a variable one). */
	public static function deactivations( WC_Product $product ) {
		if ( ! $product->is_type( 'variable' ) ) {
			return array( self::deactivation( self::ref( $product ), $product->get_name() ) );
		}
		$out = array();
		foreach ( $product->get_children() as $child_id ) {
			$variation = wc_get_product( $child_id );
			if ( $variation instanceof WC_Product_Variation ) {
				$out[] = self::deactivation( self::ref( $variation ), $product->get_name() );
			}
		}
		return $out;
	}

	/** True once Fetch has accepted this product, or any of its variations. */
	public static function was_synced( WC_Product $product ) {
		if ( $product->get_meta( Fetch_WC_Product_Sync::META_PRODUCT_ID ) ) {
			return true;
		}
		foreach ( $product->is_type( 'variable' ) ? $product->get_children() : array() as $child_id ) {
			$variation = wc_get_product( $child_id );
			if ( $variation && $variation->get_meta( Fetch_WC_Product_Sync::META_PRODUCT_ID ) ) {
				return true;
			}
		}
		return false;
	}

	/** Description, brand, category and tags, shared by a product's variations. */
	private static function shared_fields( WC_Product $product ) {
		$fields      = array();
		$description = self::plain_text( $product->get_description() ? $product->get_description() : $product->get_short_description() );
		if ( '' !== $description ) {
			$fields['description'] = $description;
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

	/** Minimal payload that switches a deleted product off on Fetch. */
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

	/** price / salePrice / stockCount for a simple product or a variation. */
	private static function price_and_stock( WC_Product $product ) {
		$out     = array();
		$regular = $product->get_regular_price( 'edit' );
		if ( '' !== $regular && null !== $regular ) {
			$out['price'] = self::money( wc_get_price_including_tax( $product, array( 'price' => (float) $regular ) ) );
		}
		if ( $product->is_on_sale( 'edit' ) && '' !== $product->get_sale_price( 'edit' ) ) {
			$out['salePrice'] = self::money( wc_get_price_including_tax( $product, array( 'price' => (float) $product->get_sale_price( 'edit' ) ) ) );
		}

		$stock = self::stock( $product );
		if ( null !== $stock ) {
			$out['stockCount'] = $stock;
		}
		return $out;
	}

	/**
	 * Managed stock → its quantity (never negative). Unmanaged → 0 when out of
	 * stock, otherwise omitted so Fetch sells it without stock tracking.
	 */
	private static function stock( WC_Product $product ) {
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

	private static function brand( WC_Product $product ) {
		foreach ( array( 'product_brand', 'pwb-brand', 'yith_product_brand' ) as $taxonomy ) {
			if ( taxonomy_exists( $taxonomy ) ) {
				$terms = wp_get_post_terms( $product->get_id(), $taxonomy, array( 'fields' => 'names' ) );
				if ( ! is_wp_error( $terms ) && $terms ) {
					return (string) $terms[0];
				}
			}
		}
		$attribute = $product->get_attribute( 'pa_brand' );
		return $attribute ? trim( explode( ',', $attribute )[0] ) : '';
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

	private static function plain_text( $html ) {
		$text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $html ) ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 5000 ) : substr( $text, 0, 5000 );
	}

	public static function money( $amount ) {
		return number_format( (float) $amount, 2, '.', '' );
	}
}
