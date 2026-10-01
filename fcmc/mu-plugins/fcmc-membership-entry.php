<?php
/**
 * Plugin Name: FCMC Membership Entry
 * Description: One way in to membership — the "Membership" menu item → Join Our Club → signup form.
 *              The bare WooCommerce product page skips the signup form (no car details, newsletter
 *              preference or directory consents), so it redirects to the Join page, is dropped from
 *              the WordPress sitemap, and Storefront's product search (header + mobile footer bar) is removed.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// FCMC_MEMBERSHIP_PRODUCT_ID is defined in fcmc-membership-registration.php, which loads after this
// file — it is only read inside the hooks below, which run once every mu-plugin has loaded.
const FCMC_JOIN_PAGE_ID = 97;

/**
 * Send anyone who lands on the membership product page (old link, search result) to the Join page.
 * The signup form adds the product to the cart server-side, so it never needs this page.
 */
add_action(
	'template_redirect',
	function () {
		if ( is_singular( 'product' ) && FCMC_MEMBERSHIP_PRODUCT_ID === (int) get_queried_object_id() ) {
			wp_safe_redirect( get_permalink( FCMC_JOIN_PAGE_ID ), 301 );
			exit;
		}
	}
);

/**
 * Keep the product page out of the core sitemap — WooCommerce's "hidden" catalog visibility
 * does not apply to wp-sitemap.xml.
 */
add_filter(
	'wp_sitemaps_posts_query_args',
	function ( $args, $post_type ) {
		if ( 'product' === $post_type ) {
			$args['post__not_in']   = isset( $args['post__not_in'] ) ? (array) $args['post__not_in'] : array();
			$args['post__not_in'][] = FCMC_MEMBERSHIP_PRODUCT_ID;
		}
		return $args;
	},
	10,
	2
);

/**
 * Remove the "Search products…" box from the Storefront header. Storefront registers its header
 * hooks after mu-plugins load, so this runs on init.
 */
add_action(
	'init',
	function () {
		remove_action( 'storefront_header', 'storefront_product_search', 40 );
	}
);

/**
 * Storefront prints the same search box again in the mobile footer bar (Account / Search / Cart).
 */
add_filter(
	'storefront_handheld_footer_bar_links',
	function ( $links ) {
		unset( $links['search'] );
		return $links;
	}
);
