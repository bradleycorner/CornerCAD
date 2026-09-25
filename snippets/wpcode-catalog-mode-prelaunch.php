/**
 * Catalog mode (pre-launch) — block purchasing + hide Add to Cart.
 * Deactivate this snippet at launch.
 */

// 1) Backend safety: nothing is purchasable.
add_filter( 'woocommerce_is_purchasable', '__return_false' );

// 2) Hide the Add to Cart blocks (catalog grid + single product) — block-theme aware.
add_filter( 'render_block', function ( $content, $block ) {
	$hide = array(
		'woocommerce/product-button',    // "Add to Cart" in the catalog grid
		'woocommerce/add-to-cart-form',  // Add to Cart form on the product page
	);
	if ( ! empty( $block['blockName'] ) && in_array( $block['blockName'], $hide, true ) ) {
		return '';
	}
	return $content;
}, 10, 2 );
