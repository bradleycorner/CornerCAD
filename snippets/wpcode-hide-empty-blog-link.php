/**
 * Hide the "Blog" menu link until there is at least one published post.
 *
 * The posts page (Settings > Reading, page_for_posts = /blog/) is linked from the header menu
 * (wp_navigation 12) and the footer "Quick Links" (wp_navigation 79). With zero posts it is an
 * empty page. This drops any navigation-link block pointing at the posts page while there are no
 * published posts, and the link comes back by itself when the first post is published.
 * Nothing in either menu is edited. Added 2026-11-23.
 */
add_filter( 'render_block_core/navigation-link', function ( $content, $block ) {
	$blog_page = (int) get_option( 'page_for_posts' );
	if ( ! $blog_page || (int) ( $block['attrs']['id'] ?? 0 ) !== $blog_page ) {
		return $content;
	}
	return ( (int) wp_count_posts( 'post' )->publish > 0 ) ? $content : '';
}, 10, 2 );
