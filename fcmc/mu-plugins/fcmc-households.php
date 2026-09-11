<?php
/**
 * Plugin Name: FCMC Households
 * Description: The club's membership roster as records independent of WordPress user
 *              accounts. A household is one membership: up to two people and any number
 *              of cars. It exists whether or not anyone has registered on the site, which
 *              is what lets the 2026 roster be imported without creating 43 accounts
 *              nobody asked for. A user account attaches later, by email.
 *
 *              Capabilities are mapped to `fcmc_manage_members` (defined in
 *              fcmc-roster.php), not the default `edit_posts`/`post` capability type. The
 *              `membership_officer` role holds exactly `read` and `fcmc_manage_members` —
 *              it does NOT have `edit_posts` — so the stock `capability_type => 'post'`
 *              would lock officers out of the household editor entirely, including the
 *              officer-facing edit screen a later task builds on this post type.
 *
 * @see docs/superpowers/specs/2026-09-10-fcmc-roster-import-design.md
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', function () {
	register_post_type( 'fcmc_household', array(
		'label'           => __( 'Households', 'fcmc' ),
		'public'          => false,          // never a front-end URL — this is member PII
		'show_ui'         => true,
		'show_in_menu'    => true,
		'menu_icon'       => 'dashicons-groups',
		'capability_type' => 'post',
		'capabilities'    => array(
			'edit_post'               => 'fcmc_manage_members',
			'read_post'               => 'fcmc_manage_members',
			'delete_post'             => 'fcmc_manage_members',
			'edit_posts'              => 'fcmc_manage_members',
			'edit_others_posts'       => 'fcmc_manage_members',
			'edit_published_posts'    => 'fcmc_manage_members',
			'edit_private_posts'      => 'fcmc_manage_members',
			'publish_posts'           => 'fcmc_manage_members',
			'read_private_posts'      => 'fcmc_manage_members',
			'delete_posts'            => 'fcmc_manage_members',
			'delete_others_posts'     => 'fcmc_manage_members',
			'delete_published_posts'  => 'fcmc_manage_members',
			'delete_private_posts'    => 'fcmc_manage_members',
			'create_posts'            => 'do_not_allow', // created by import or signup only
		),
		'map_meta_cap'    => true,
		'supports'        => array( 'title' ),
		'show_in_rest'    => false,          // keep it off the public REST surface
	) );
} );

/**
 * Normalise an email for comparison. One rule, used everywhere.
 *
 * @param string $email Raw email.
 * @return string
 */
function fcmc_normalise_email( $email ) {
	return strtolower( trim( (string) $email ) );
}

/**
 * Find an UNCLAIMED household by either member's email.
 *
 * @param string $email Email to match.
 * @return int|null Post ID, or null.
 */
function fcmc_household_find_by_email( $email ) {
	$email = fcmc_normalise_email( $email );
	if ( '' === $email ) {
		return null;
	}

	foreach ( fcmc_household_all() as $id ) {
		if ( get_post_meta( $id, 'claimed_by', true ) ) {
			continue;
		}
		foreach ( array( 'member1_email', 'member2_email' ) as $key ) {
			if ( fcmc_normalise_email( get_post_meta( $id, $key, true ) ) === $email ) {
				return (int) $id;
			}
		}
	}

	return null;
}

/**
 * All household IDs. ~48 records, so a plain query is fine.
 *
 * @param array $args Extra WP_Query args.
 * @return int[]
 */
function fcmc_household_all( $args = array() ) {
	return get_posts( array_merge( array(
		'post_type'      => 'fcmc_household',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'orderby'        => 'title',
		'order'          => 'ASC',
	), $args ) );
}

/**
 * A household as a flat array, with every meta key present.
 *
 * @param int $id Post ID.
 * @return array
 */
function fcmc_household_get( $id ) {
	$keys = array(
		'member1_name', 'member1_phone', 'member1_email',
		'member2_name', 'member2_phone', 'member2_email',
		'address', 'city', 'state', 'zip',
		'cars', 'paid_through', 'member_since',
		'fcmc_source', 'claimed_by', 'import_batch',
	);

	$out = array( 'id' => (int) $id );
	foreach ( $keys as $key ) {
		$out[ $key ] = get_post_meta( $id, $key, true );
	}
	$out['cars'] = is_array( $out['cars'] ) ? $out['cars'] : array();

	return $out;
}
