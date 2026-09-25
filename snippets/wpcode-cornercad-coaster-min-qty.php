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
 * cornercad.com's cart page (id 15) uses the WooCommerce BLOCKS cart
 * (`<!-- wp:woocommerce/cart -->`, Store-API-driven), not the classic
 * shortcode cart -- confirmed live. That changes which hooks actually fire,
 * so enforcement here has THREE parts, all required:
 *   1. woocommerce_add_to_cart_validation -- blocks a below-floor quantity
 *      from being added to the cart in the first place, with a clear
 *      notice. Honored by the Blocks cart's CartController, so this one
 *      works as-is.
 *   2. woocommerce_store_api_product_quantity_minimum -- the Store-API
 *      equivalent of the classic quantity-stepper minimum. This is what the
 *      Blocks cart actually reads for both its displayed stepper minimum
 *      AND its "update quantity" Store API request
 *      (PUT wc/store/v1/cart/items/{key}) -- classic
 *      woocommerce_quantity_input_args does NOT cover the Blocks cart at
 *      all (kept below for the product page's classic-cart rendering path
 *      and any other classic-cart usage, but it is not sufficient alone on
 *      this store's Blocks-based cart).
 *   3. woocommerce_check_cart_items -- a backstop that runs on every
 *      cart/checkout page load regardless of which cart UI (classic or
 *      Blocks) touched the quantity, re-validating every line item and
 *      adding a notice for anything under the floor. This is what actually
 *      closes the gap: woocommerce_update_cart_validation (still present
 *      below) is a classic-cart-only hook fired by
 *      WC_Form_Handler::update_cart_action() -- the Blocks cart updates
 *      quantity via the Store API and NEVER fires it, so a customer could
 *      add 4 to the cart then edit the quantity down to 1 in the cart
 *      itself and bypass the floor silently before this backstop was
 *      added. It is kept here only because it's harmless and still covers
 *      a classic-cart request if one ever reaches this store.
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

	// Classic-cart-only hook (WC_Form_Handler::update_cart_action()). The
	// Blocks cart used on this store updates quantity via the Store API and
	// never fires this -- kept only in case a classic-cart request ever
	// reaches this store; the real cross-UI coverage is the
	// woocommerce_store_api_product_quantity_minimum filter and the
	// woocommerce_check_cart_items backstop below.
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

	// Store-API equivalent of woocommerce_quantity_input_args -- the Blocks
	// cart's stepper AND its update-quantity Store API request both read
	// their minimum from this filter, not from woocommerce_quantity_input_args.
	add_filter( 'woocommerce_store_api_product_quantity_minimum', function( $quantity_minimum, $product ) {
		if ( cornercad_coaster_is_min_qty_product( $product ) ) {
			return cornercad_coaster_min_qty();
		}
		return $quantity_minimum;
	}, 10, 2 );

	// Backstop: runs on every cart/checkout page load regardless of which
	// cart UI (classic or Blocks) touched the quantity, so a below-floor
	// line item can't silently survive to checkout no matter how it got
	// there (e.g. added at 4, then edited down to 1 via the Blocks cart's
	// Store API request, which woocommerce_update_cart_validation never
	// sees).
	add_action( 'woocommerce_check_cart_items', function() {
		if ( ! WC()->cart ) {
			return;
		}
		$floor = cornercad_coaster_min_qty();
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;
			if ( $product && cornercad_coaster_is_min_qty_product( $product ) ) {
				$quantity = isset( $cart_item['quantity'] ) ? (int) $cart_item['quantity'] : 0;
				if ( $quantity < $floor ) {
					wc_add_notice(
						sprintf( 'This coaster design has a minimum order quantity of %d.', $floor ),
						'error'
					);
				}
			}
		}
	} );
}
