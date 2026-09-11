<?php
/**
 * CornerCAD — auto-populate cross-sells from same design-name family.
 *
 * Products designed by h3li0 (and CornerCAD's own) often come in matched
 * families that share a name prefix -- Drift Planter / Drift Box / Drift
 * Lamp, Alvao Planter / Alvao Orchid Planter, Clarit Pen Holder / Clarit
 * Stackable Boxes, and so on. This groups all published products by the
 * first word of their title, and for any family of 2 or more, cross-sells
 * each member to its siblings (capped at 5, in case a family is large).
 *
 * Only fills in an EMPTY _crosssell_ids -- never overwrites a manually
 * curated cross-sell list, so a hand-picked pairing always wins over the
 * automatic one. Re-running is safe: already-set products are skipped, and
 * new products joining a family on a later sync get picked up then.
 *
 * Runs automatically after every Square sync (wc_square_products_synced),
 * matching the same hook the description-split and stock-fix snippets use.
 * Can also be triggered manually to backfill the existing catalog:
 *   wp eval 'echo cornercad_auto_crosssells() . " products updated\n";' --user=1
 *
 * Mirrored in git at snippets/wpcode-cornercad-auto-crosssells.php
 */

if ( ! function_exists( 'cornercad_auto_crosssells' ) ) {
	/**
	 * Group published products by the first word of their title and
	 * cross-sell each family member to its siblings.
	 *
	 * @return int Number of products updated.
	 */
	function cornercad_auto_crosssells() {
		$product_ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		// Group by the first word of the title (lowercased).
		$families = array();
		foreach ( $product_ids as $pid ) {
			$title = get_the_title( $pid );
			if ( ! $title ) {
				continue;
			}
			$first_word = strtok( trim( $title ), " \t\n\r" );
			if ( ! $first_word ) {
				continue;
			}
			$key                = strtolower( $first_word );
			$families[ $key ][] = $pid;
		}

		$updated = 0;
		foreach ( $families as $ids ) {
			if ( count( $ids ) < 2 ) {
				continue; // No siblings -- not a family.
			}
			foreach ( $ids as $pid ) {
				$existing = get_post_meta( $pid, '_crosssell_ids', true );
				if ( ! empty( $existing ) ) {
					continue; // Respect manual curation.
				}
				$siblings = array_values( array_diff( $ids, array( $pid ) ) );
				$siblings = array_slice( $siblings, 0, 5 );
				update_post_meta( $pid, '_crosssell_ids', $siblings );
				++$updated;
			}
		}

		return $updated;
	}

	add_action( 'wc_square_products_synced', 'cornercad_auto_crosssells' );
}
