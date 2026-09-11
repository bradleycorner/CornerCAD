<?php
/**
 * Plugin Name: FCMC Newsletter Display
 * Description: Makes the Newsletters page self-maintaining. Uploading a new "Vol-XX-Issue-YY-*.pdf"
 *              to the Media Library is the only step needed — [fcmc_latest_newsletter] and
 *              [fcmc_newsletter_archive] read the library directly, so no page edit is ever
 *              required again.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Find every Media Library PDF that matches the "Vol-XX-Issue-YY..." naming convention, parse
 * volume/issue/year out of the filename (NOT post_date — all 58 migrated PDFs share today's
 * upload timestamp, so post_date can't tell them apart), and return them sorted newest-first.
 */
function fcmc_get_newsletters() {
	static $cache = null;
	if ( null !== $cache ) {
		return $cache;
	}

	$attachments = get_posts( array(
		'post_type'      => 'attachment',
		'post_mime_type' => 'application/pdf',
		'posts_per_page' => -1,
		'orderby'        => 'ID',
	) );

	$newsletters = array();

	foreach ( $attachments as $attachment ) {
		$file = basename( get_attached_file( $attachment->ID ) );

		if ( ! preg_match( '/Vol[-_](\d+)[-_]Issue[-_](\d+)/i', $file, $m ) ) {
			continue; // Not a newsletter PDF (e.g. a renewal form) — skip.
		}

		// First 4-digit year token in the filename. Matches the existing archive's own
		// convention: "Vol-33-Issue-07-Dec-2022-Jan-2023.pdf" is filed under 2022, i.e. the
		// year of the issue's start month, not whichever year token appears last.
		// No \b after the digits: a filename like "..._V2.pdf" has an underscore right after
		// the year, and \w includes underscore, so a trailing \b silently fails to match there
		// (confirmed live — it fell through to today's upload date instead of the real year).
		preg_match( '/(20\d{2})/', $file, $ym );
		$year = $ym[1] ?? gmdate( 'Y', strtotime( $attachment->post_date ) );

		$title = str_replace( array( '-', '_' ), ' ', preg_replace( '/\.pdf$/i', '', $file ) );
		$title = preg_replace( '/\s+/', ' ', trim( $title ) );

		$newsletters[] = array(
			'id'     => $attachment->ID,
			'url'    => wp_get_attachment_url( $attachment->ID ),
			'title'  => $title,
			'volume' => (int) $m[1],
			'issue'  => (int) $m[2],
			'year'   => (int) $year,
			'sort'   => ( (int) $m[1] * 1000 ) + (int) $m[2],
		);
	}

	usort( $newsletters, function ( $a, $b ) {
		return $b['sort'] <=> $a['sort'];
	} );

	$cache = $newsletters;
	return $cache;
}

add_shortcode( 'fcmc_latest_newsletter', function () {
	$newsletters = fcmc_get_newsletters();
	if ( empty( $newsletters ) ) {
		return '<p><em>No newsletters uploaded yet.</em></p>';
	}
	$latest = $newsletters[0];

	$viewer = do_shortcode( sprintf(
		'[pdf-embedder url="%s" pdfID="%d"]',
		esc_url( $latest['url'] ),
		$latest['id']
	) );

	return $viewer . sprintf(
		'<p><strong><a href="%s">%s (PDF) — Download</a></strong></p>',
		esc_url( $latest['url'] ),
		esc_html( $latest['title'] )
	);
} );

add_shortcode( 'fcmc_newsletter_archive', function () {
	$newsletters = fcmc_get_newsletters();
	if ( empty( $newsletters ) ) {
		return '';
	}

	$by_year = array();
	foreach ( $newsletters as $n ) {
		$by_year[ $n['year'] ][] = $n;
	}
	krsort( $by_year );

	$html = '';
	foreach ( $by_year as $year => $issues ) {
		$html .= '<h2 class="wp-block-heading">RoadRunner Archive ' . esc_html( $year ) . '</h2>';
		$html .= '<ul class="wp-block-list">';
		foreach ( $issues as $n ) {
			$html .= sprintf(
				'<li><a href="%s">%s (pdf)</a></li>',
				esc_url( $n['url'] ),
				esc_html( $n['title'] )
			);
		}
		$html .= '</ul>';
	}

	return $html;
} );
