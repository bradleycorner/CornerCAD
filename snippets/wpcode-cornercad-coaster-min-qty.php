<?php
/**
 * CornerCAD — enforce a minimum order quantity of 4 on laser-engraved
 * coaster products.
 *
 * Scope: any product (or product variation) that carries a "Design"
 * attribute -- this is exactly the coaster-line parent products (Engraved
 * Slate Coaster today, a future Engraved Birch Coaster, etc.), per
 * docs/superpowers/specs/2026-09-05-laser-coaster-launch-design.md §3 and
 * §7. Deliberately NOT scoped by product ID or SKU prefix, so a new
 * material's parent product picks up the rule the moment it has a Design
 * attribute -- no snippet edit needed when Bradley ships a new material.
 *
 * Does NOT apply to the FDM (3D-printed) coaster products (Cova Coasters,
 * Hex Coaster Set) -- they have no Design attribute, so they're untouched.
 *
 * Two enforcement points, both required -- the quantity-input filter alone
 * is a UI nicety a customer can bypass by editing the request directly:
 *   1. woocommerce_quantity_input_args -- sets the visible minimum in the
 *      quantity stepper on the product page and in the cart.
 *   2. woocommerce_add_to_cart_validation /
 *      woocommerce_update_cart_validation -- actually blocks a below-floor
 *      quantity from being added or updated in the cart, with a clear
 *      notice.
 *
 * Mirrored in git at snippets/wpcode-cornercad-coaster-min-qty.php
 *
 * Manual verification (no PHPUnit harness in this repo -- see the project
 * CLAUDE.md's "no local php binary" note):
 *   wp eval 'var_dump(function_exists("cornercad_coaster_min_qty"));' --user=1
 *   wp eval 'echo cornercad_coaster_min_qty();' --user=1   # expect: 4
 *   wp eval 'var_dump(cornercad_coaster_is_min_qty_product(wc_get_product(4787)));' --user=1
 *     # expect: bool(true) once the Engraved Slate Coaster (id 4787) has a
 *     # Design attribute; bool(false) before that attribute exists.
 */

if ( ! function_exists( 'cornercad_coaster_min_qty' ) ) {

	function cornercad_coaster_min_qty() {
		return 4;
	}

	/**
	 * True when $product (or its parent, for a variation) has a non-empty
	 * "design" attribute.
	 *
	 * @param WC_Product $product
	 * @return bool
	 */
	function cornercad_coaster_is_min_qty_product( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return false;
		}
		$target = $product;
		if ( $product->is_type( 'variation' ) ) {
			$parent_id = $product->get_parent_id();
			$target    = $parent_id ? wc_get_product( $parent_id ) : null;
			if ( ! $target ) {
				return false;
			}
		}
		$design = $target->get_attribute( 'design' );
		return '' !== trim( (string) $design );
	}

	add_filter( 'woocommerce_quantity_input_args', function( $args, $product ) {
		if ( cornercad_coaster_is_min_qty_product( $product ) ) {
			$floor              = cornercad_coaster_min_qty();
			$args['min_value']  = $floor;
			if ( (int) $args['input_value'] < $floor ) {
				$args['input_value'] = $floor;
			}
		}
		return $args;
	}, 10, 2 );

	add_filter( 'woocommerce_add_to_cart_validation', function( $passed, $product_id, $quantity, $variation_id = 0 ) {
		$id      = $variation_id ? $variation_id : $product_id;
		$product = wc_get_product( $id );
		if ( $product && cornercad_coaster_is_min_qty_product( $product ) ) {
			$floor = cornercad_coaster_min_qty();
			if ( (int) $quantity < $floor ) {
				wc_add_notice(
					sprintf( 'This coaster design has a minimum order quantity of %d.', $floor ),
					'error'
				);
				return false;
			}
		}
		return $passed;
	}, 10, 4 );

	add_filter( 'woocommerce_update_cart_validation', function( $passed, $cart_item_key, $values, $quantity ) {
		$product = $values['data'];
		if ( $product && cornercad_coaster_is_min_qty_product( $product ) ) {
			$floor = cornercad_coaster_min_qty();
			if ( (int) $quantity < $floor ) {
				wc_add_notice(
					sprintf( 'This coaster design has a minimum order quantity of %d.', $floor ),
					'error'
				);
				return false;
			}
		}
		return $passed;
	}, 10, 4 );
}
