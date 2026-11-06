<?php
/**
 * Shows when a live post last changed where its dates appear.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Turns on Newspack's "Updated" date for posts whose coverage hasn't ended.
 *
 * Bylines and Homepage Posts cards show a post's publish date, which stays
 * put while its coverage keeps updating, so a busy live blog looks hours old.
 * While a coverage the post embeds is live or paused, the byline and the card
 * show when the post last changed instead, whatever the site's "Last updated
 * date" setting says. That date is the one Schema works out: when an entry was
 * last published or edited, or the post's own last edit when that came later.
 * Once the coverage ends, the site's settings decide again.
 *
 * Newspack's own rules still have the last word: only post types with the
 * feature show it, and while "Last updated date" is set to Show, an editor can
 * hide it on a post.
 */
class Updated_Date {

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_filter( 'newspack_updated_date_override', [ __CLASS__, 'show_in_byline' ], 10, 2 );
		add_filter( 'newspack_blocks_display_updated_date', [ __CLASS__, 'show_on_card' ], 10, 2 );
	}

	/**
	 * Shows a live post's updated date in its byline.
	 *
	 * @param bool|null     $override Whether to show the updated date, or null to leave it to the site's settings.
	 * @param WP_Post|mixed $post     Post.
	 * @return bool|null
	 */
	public static function show_in_byline( $override, $post ) {
		return $post instanceof WP_Post && self::has_live_update( $post ) ? true : $override;
	}

	/**
	 * Shows a live post's updated date in place of its publish date on
	 * Homepage Posts cards.
	 *
	 * @param bool          $display Whether the card shows the updated date.
	 * @param WP_Post|mixed $post    Post.
	 * @return bool
	 */
	public static function show_on_card( $display, $post ) {
		return $display || ( $post instanceof WP_Post && self::has_live_update( $post ) );
	}

	/**
	 * Whether a post embeds a coverage that hasn't ended, and has changed
	 * since it was published.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	private static function has_live_update( WP_Post $post ): bool {
		$is_live = false;

		// Only the coverages the post's dates follow, or the time shown would
		// be the post's own last edit while entries keep arriving.
		foreach ( Schema::get_page_coverage_ids( $post ) as $coverage_id ) {
			if ( Page_Coverages::is_followable( $coverage_id ) && Taxonomy::STATUS_ARCHIVED !== Rolling_Coverage_Block::coverage_status( $coverage_id ) ) {
				$is_live = true;
				break;
			}
		}

		if ( ! $is_live ) {
			return false;
		}

		// A post just published has nothing newer than its publish date to show.
		return strtotime( (string) get_the_modified_date( DATE_W3C, $post ) ) > strtotime( (string) get_the_date( DATE_W3C, $post ) );
	}
}
