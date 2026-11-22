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

// 3) Say "Coming soon", not "Out of stock". WooCommerce's stock-indicator block relabels an in-stock
//    VARIABLE product "Out of stock" when none of its variations is purchasable
//    (ProductAvailabilityUtils::get_product_availability -> has_purchasable_variations()), and rule 1
//    makes every variation unpurchasable. Only rewrites products that are genuinely in stock.
add_filter( 'render_block_woocommerce/product-stock-indicator', function ( $content, $block, $instance = null ) {
	if ( false === strpos( $content, 'out-of-stock' ) ) {
		return $content;
	}
	$id      = ( $instance && isset( $instance->context['postId'] ) ) ? $instance->context['postId'] : get_the_ID();
	$product = wc_get_product( $id );
	if ( ! $product || ! $product->is_in_stock() ) {
		return $content;
	}
	return str_replace(
		array( '--out-of-stock', esc_html__( 'Out of stock', 'woocommerce' ) ),
		array( '--coming-soon', 'Coming soon' ),
		$content
	);
}, 10, 3 );
