<?php
/**
 * The canonical permalink of the page being rendered — in the exact form the
 * site itself would emit as rel=canonical. This is what goes to AIVIS
 * unmodified (§07) and what the local key is derived from.
 *
 * @package AivisOS
 */

declare( strict_types=1 );

namespace AivisOS\Delivery;

final class UrlResolver {

	public function current(): ?string {
		$url = null;
		if ( is_singular() ) {
			$c   = wp_get_canonical_url();
			$url = is_string( $c ) && '' !== $c ? $c : get_permalink();
		} elseif ( is_front_page() ) {
			$url = home_url( '/' );
		} elseif ( is_home() ) {
			// The blog index may be a static page ("posts page"); its canonical is
			// that page's permalink, not the site root.
			$posts_page = (int) get_option( 'page_for_posts' );
			$link       = $posts_page ? get_permalink( $posts_page ) : null;
			$url        = is_string( $link ) && '' !== $link ? $link : home_url( '/' );
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			$link = $term ? get_term_link( $term ) : null;
			$url  = is_string( $link ) ? $link : null;
		} elseif ( is_post_type_archive() ) {
			$link = get_post_type_archive_link( (string) get_query_var( 'post_type' ) );
			$url  = is_string( $link ) ? $link : null;
		} elseif ( is_author() ) {
			$url = get_author_posts_url( (int) get_query_var( 'author' ) );
		}
		if ( ! is_string( $url ) || '' === $url ) {
			return null;
		}
		/**
		 * Filter the URL used to look up structured data for the current request.
		 *
		 * @param string    $url   Canonical absolute URL.
		 * @param \WP_Query $query The main query.
		 */
		$url = apply_filters( 'aivis_connector_current_url', $url, $GLOBALS['wp_query'] ?? null );
		return is_string( $url ) && preg_match( '#^https?://#i', $url ) ? $url : null;
	}
}
