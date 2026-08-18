/**
 * CornerCAD — Square description split on a "---" separator.
 *
 * Square is the system of record for product copy. A description may contain a
 * line of three hyphens:
 *
 *     summary paragraph(s)
 *     ---
 *     safety / ordering / attribution details
 *
 * Everything BEFORE the separator becomes the excerpt (the summary beside Add to
 * Cart and in archive views); everything AFTER becomes the Description tab. That
 * removes the duplication you get when one Square description feeds both slots.
 *
 * Products with no separator are left completely alone.
 *
 * Square delivers the separator in one of three shapes depending on how it stored
 * the description, so all three are matched: its own paragraph, between line
 * breaks (when the whole description lands in one paragraph), or a bare "---"
 * line in plain text.
 *
 * Mirrored in git at snippets/wpcode-square-description-split.php
 */

if ( ! function_exists( 'cornercad_split_square_description' ) ) {
	/**
	 * Split raw product content on the separator.
	 *
	 * @param string $raw Raw post_content.
	 * @return array|null array( 'summary' => string, 'details' => string ), or null
	 *                    when there is no usable separator.
	 */
	function cornercad_split_square_description( $raw ) {
		if ( ! is_string( $raw ) || false === strpos( $raw, '---' ) ) {
			return null;
		}

		$patterns = array(
			'~\s*<p>\s*-{3,}\s*</p>\s*~i',
			'~\s*(?:<br\s*/?>\s*)+-{3,}\s*(?:<br\s*/?>\s*)+~i',
			'~(?:\r\n|\r|\n)\s*-{3,}\s*(?:\r\n|\r|\n)~',
		);

		foreach ( $patterns as $pattern ) {
			$parts = preg_split( $pattern, $raw, 2 );
			if ( ! is_array( $parts ) || 2 !== count( $parts ) ) {
				continue;
			}

			$summary = trim( $parts[0] );
			$details = trim( $parts[1] );

			// Both halves must have content — a separator with nothing after it is an
			// authoring mistake, and blanking the Description tab would hide the safety
			// copy. Leave those untouched so they stay visible and get fixed in Square.
			if ( '' !== $summary && '' !== $details ) {
				return array(
					'summary' => $summary,
					'details' => $details,
				);
			}
		}

		return null;
	}
}

/**
 * Excerpt = everything before the separator.
 *
 * Priority 9 runs this ahead of core's wp_trim_excerpt() at 10: once the excerpt is
 * non-empty, wp_trim_excerpt passes it straight through, so the summary is NOT cut
 * at the 55-word default. A manually-set excerpt always wins — the per-product
 * escape hatch.
 */
add_filter(
	'get_the_excerpt',
	function ( $excerpt, $post = null ) {
		if ( '' !== trim( (string) $excerpt ) ) {
			return $excerpt;
		}

		$post = get_post( $post );
		if ( ! $post || 'product' !== $post->post_type ) {
			return $excerpt;
		}

		$split = cornercad_split_square_description( $post->post_content );
		if ( ! $split ) {
			return $excerpt;
		}

		return wp_strip_all_tags( $split['summary'], true );
	},
	9,
	2
);

/**
 * Description tab = everything after the separator.
 *
 * Priority 9 so the literal separator is still intact — wptexturize() runs at 10
 * and would turn it into an em dash. Scoped to the queried product so related-product
 * loops and anything else running the_content() are untouched.
 */
add_filter(
	'the_content',
	function ( $content ) {
		if ( is_admin() || ! is_singular( 'product' ) ) {
			return $content;
		}

		$post = get_post();
		if ( ! $post || 'product' !== $post->post_type || (int) $post->ID !== (int) get_queried_object_id() ) {
			return $content;
		}

		$split = cornercad_split_square_description( $content );
		if ( ! $split ) {
			return $content;
		}

		return $split['details'];
	},
	9
);

/**
 * Product structured data reads the raw description directly, bypassing the_content,
 * so the separator leaks into the Product JSON-LD that search engines consume.
 * Rejoin both halves without it — schema wants the complete description, not a half.
 */
add_filter(
	'woocommerce_structured_data_product',
	function ( $markup, $product ) {
		if ( empty( $markup['description'] ) ) {
			return $markup;
		}

		$split = cornercad_split_square_description( $markup['description'] );
		if ( $split ) {
			$markup['description'] = $split['summary'] . ' ' . $split['details'];
		}

		return $markup;
	},
	10,
	2
);
