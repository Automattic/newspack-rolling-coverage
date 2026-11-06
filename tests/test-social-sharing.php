<?php
/**
 * Tests for the social-sharing `rc_source` redirect and the entry deep link.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Placements;
use Newspack_Rolling_Coverage\Rolling_Coverage_Block;
use Newspack_Rolling_Coverage\Social_Sharing;
use Newspack_Rolling_Coverage\Taxonomy;

/**
 * The `rc_source` query var is attacker-controlled, so its target must be
 * publicly viewable before its permalink is reflected into the response.
 * The entry deep link is shared by the redirect, push notifications and the
 * Ticker's headlines, so it must carry exactly one fragment.
 */
class Test_Social_Sharing extends Rolling_Coverage_TestCase {

	/**
	 * `inject_redirect_script()` reflects a published source's URL, and emits
	 * nothing for an unpublished source in any status.
	 */
	public function test_rc_source_only_reflects_published_posts() {
		$entry_id = self::create_entry( self::create_coverage() );

		$unpublished = [
			'draft'   => [],
			'private' => [],
			'pending' => [],
			'future'  => [
				'post_date'     => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			],
			'trash'   => [],
		];

		foreach ( $unpublished as $status => $extra ) {
			$source_id = self::factory()->post->create( array_merge( [ 'post_status' => $status ], $extra ) );

			$this->assertSame( $status, get_post_status( $source_id ) );
			$this->assertSame(
				'',
				$this->render_redirect( $entry_id, $source_id ),
				"rc_source pointing at a {$status} post must emit no redirect script."
			);
		}

		$published_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );

		$this->assertStringContainsString(
			'window.location.replace(',
			$this->render_redirect( $entry_id, $published_id )
		);
	}

	/**
	 * Render `inject_redirect_script()` for a queried entry and source ID.
	 *
	 * @param int $entry_id  Entry post ID to query.
	 * @param int $source_id rc_source value.
	 * @return string Emitted markup.
	 */
	private function render_redirect( int $entry_id, int $source_id ): string {
		$this->go_to( get_permalink( $entry_id ) );

		// Plain permalinks put the entry's own `p` in $_GET; drop it so the
		// forwarded-params behavior can't mask what the source resolves to.
		$_GET = [];
		set_query_var( Social_Sharing::SOURCE_QUERY_VAR, (string) $source_id );

		ob_start();
		Social_Sharing::inject_redirect_script();
		$output = (string) ob_get_clean();

		set_query_var( Social_Sharing::SOURCE_QUERY_VAR, '' );

		return $output;
	}

	/**
	 * An entry's deep link keeps the page's query arguments and replaces its
	 * fragment, so the link carries one.
	 */
	public function test_entry_deep_link_replaces_the_page_fragment() {
		$entry_id = self::create_entry( self::create_coverage(), [ 'post_name' => 'bridge-closed' ] );

		$this->assertSame(
			'https://example.test/live/?ref=x&rolling-coverage-entry=bridge-closed#newspack-rolling-coverage-entry-' . $entry_id,
			Social_Sharing::get_entry_deep_link( get_post( $entry_id ), 'https://example.test/live/?ref=x#top' )
		);
	}

	/**
	 * An entry's deep link to a static front page loads that page, and the
	 * feed can still read which entry the link names.
	 */
	public function test_entry_deep_link_keeps_a_static_front_page() {
		$front_page_id = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_status' => 'publish',
			]
		);
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $front_page_id );

		$this->go_to( add_query_arg( Social_Sharing::ENTRY_QUERY_VAR, 'bridge-closed', home_url( '/' ) ) );

		$this->assertTrue( is_front_page(), 'The link should load the front page, not the blog posts page.' );
		$this->assertSame( $front_page_id, get_queried_object_id() );
		$this->assertSame( 'bridge-closed', get_query_var( Social_Sharing::ENTRY_QUERY_VAR ) );
	}

	/**
	 * With no canonical URL, a plain entry permalink resolves to the page
	 * showing the full feed; the canonical URL overrides it.
	 */
	public function test_entry_canonical_url_falls_back_to_the_coverage_page() {
		$coverage_id = self::create_coverage();
		$entry       = get_post( self::create_entry( $coverage_id ) );

		$this->assertSame( '', Social_Sharing::get_entry_canonical_url( $entry ), 'A liveblog with no page has nowhere to send readers.' );

		$page_id = self::publish_feed( $coverage_id );

		$this->assertSame( get_permalink( $page_id ), Social_Sharing::get_entry_canonical_url( $entry ) );

		update_term_meta( $coverage_id, Taxonomy::CANONICAL_URL_META_KEY, home_url( '/live/storm/' ) );

		$this->assertSame( home_url( '/live/storm/' ), Social_Sharing::get_entry_canonical_url( $entry ) );
	}

	/**
	 * Sharing from a capped feed sends readers to the page showing the full
	 * feed, not to the page holding the capped feed.
	 */
	public function test_capped_feed_shares_to_the_coverage_page() {
		$coverage_id = self::create_coverage();
		$entry_id    = self::create_entry( $coverage_id );
		$page_id     = self::publish_feed( $coverage_id );
		$host_id     = self::factory()->post->create();

		$this->assertSame(
			add_query_arg( Social_Sharing::SOURCE_QUERY_VAR, $page_id, get_permalink( $entry_id ) ),
			self::rendered_share_url( $coverage_id, $host_id, true )
		);
	}

	/**
	 * A canonical URL at a post takes over as the source of a capped feed's
	 * share links.
	 */
	public function test_capped_feed_shares_to_a_canonical_url_at_a_post() {
		$coverage_id  = self::create_coverage();
		$entry_id     = self::create_entry( $coverage_id );
		$canonical_id = self::factory()->post->create();
		$host_id      = self::factory()->post->create();

		self::publish_feed( $coverage_id );
		update_term_meta( $coverage_id, Taxonomy::CANONICAL_URL_META_KEY, get_permalink( $canonical_id ) );

		$this->assertSame(
			add_query_arg( Social_Sharing::SOURCE_QUERY_VAR, $canonical_id, get_permalink( $entry_id ) ),
			self::rendered_share_url( $coverage_id, $host_id, true )
		);
	}

	/**
	 * A canonical URL with no post behind it can't be an `rc_source`, so a
	 * capped feed shares the plain permalink, which redirects to it.
	 */
	public function test_capped_feed_shares_the_permalink_when_the_canonical_url_has_no_post() {
		$coverage_id = self::create_coverage();
		$entry_id    = self::create_entry( $coverage_id );

		update_term_meta( $coverage_id, Taxonomy::CANONICAL_URL_META_KEY, home_url( '/live/storm/' ) );

		$this->assertSame( get_permalink( $entry_id ), self::rendered_share_url( $coverage_id, self::factory()->post->create(), true ) );
	}

	/**
	 * With no page to send readers to, a capped feed shares the plain
	 * permalink: the page holding the feed may not show the entry, and the
	 * permalink starts redirecting once the coverage has a page.
	 */
	public function test_capped_feed_without_a_page_shares_the_permalink() {
		$coverage_id = self::create_coverage();
		$entry_id    = self::create_entry( $coverage_id );
		$host_id     = self::factory()->post->create();

		$this->assertSame(
			get_permalink( $entry_id ),
			self::rendered_share_url( $coverage_id, $host_id, true )
		);
	}

	/**
	 * With no page to send readers to, a capped feed's entry title links to
	 * the entry's own page, not back to the page holding the feed.
	 */
	public function test_capped_feed_without_a_page_links_titles_to_the_permalink() {
		$coverage_id = self::create_coverage();
		$entry_id    = self::create_entry( $coverage_id );
		$host_id     = self::factory()->post->create();

		$html = self::render_feed(
			$coverage_id,
			$host_id,
			true,
			'<!-- wp:post-title {"level":4,"className":"newspack-rolling-coverage-entry-link"} /-->'
		);

		$title = new WP_HTML_Tag_Processor( $html );
		$title->next_tag( [ 'class_name' => 'newspack-rolling-coverage-entry-link' ] );
		$title->next_tag( 'a' );

		$this->assertSame( get_permalink( $entry_id ), $title->get_attribute( 'href' ) );
	}

	/**
	 * A full feed shows every entry, so its share links stay on its own page.
	 */
	public function test_full_feed_shares_to_its_host() {
		$coverage_id = self::create_coverage();
		$entry_id    = self::create_entry( $coverage_id );
		$host_id     = self::factory()->post->create();

		self::publish_feed( $coverage_id );

		$this->assertSame(
			add_query_arg( Social_Sharing::SOURCE_QUERY_VAR, $host_id, get_permalink( $entry_id ) ),
			self::rendered_share_url( $coverage_id, $host_id, false )
		);
	}

	/**
	 * Publish a post showing a coverage's full feed and rebuild the map.
	 *
	 * @param int $coverage_id Coverage term ID.
	 * @return int Post ID.
	 */
	private static function publish_feed( int $coverage_id ): int {
		$page_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:newspack-rolling-coverage/rolling-coverage {"coverageId":' . $coverage_id . '} /-->',
			]
		);

		Placements::rebuild();

		return $page_id;
	}

	/**
	 * The share link of the entry a feed renders.
	 *
	 * @param int  $coverage_id Coverage term ID.
	 * @param int  $host_id     Post holding the feed.
	 * @param bool $is_capped   Whether the feed is capped.
	 * @return string Share URL.
	 */
	private static function rendered_share_url( int $coverage_id, int $host_id, bool $is_capped ): string {
		$html = self::render_feed(
			$coverage_id,
			$host_id,
			$is_capped,
			'<!-- wp:paragraph {"className":"newspack-rolling-coverage-share"} --><p class="newspack-rolling-coverage-share">Share</p><!-- /wp:paragraph -->'
		);

		$share = new WP_HTML_Tag_Processor( $html );
		$share->next_tag( [ 'class_name' => 'newspack-rolling-coverage-share' ] );
		$share->next_tag( 'a' );

		return (string) $share->get_attribute( 'href' );
	}

	/**
	 * Render a feed held by a post.
	 *
	 * @param int    $coverage_id Coverage term ID.
	 * @param int    $host_id     Post holding the feed.
	 * @param bool   $is_capped   Whether the feed is capped.
	 * @param string $template    The feed's inner blocks.
	 * @return string Rendered feed.
	 */
	private static function render_feed( int $coverage_id, int $host_id, bool $is_capped, string $template ): string {
		$attributes = [ 'coverageId' => $coverage_id ];

		if ( $is_capped ) {
			$attributes += [
				'latestOnly'  => true,
				'latestCount' => 1,
			];
		}

		$GLOBALS['post'] = get_post( $host_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$block = parse_blocks(
			'<!-- wp:newspack-rolling-coverage/rolling-coverage ' . wp_json_encode( $attributes ) . ' -->'
			. $template
			. '<!-- /wp:newspack-rolling-coverage/rolling-coverage -->'
		)[0];

		return Rolling_Coverage_Block::render_block( $attributes, '', new WP_Block( $block ) );
	}
}
