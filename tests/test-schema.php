<?php
/**
 * Tests for the LiveBlogPosting structured data.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Post_Type;
use Newspack_Rolling_Coverage\Rolling_Coverage_Block;
use Newspack_Rolling_Coverage\Schema;

/**
 * A page with a coverage should describe itself as one live blog with one
 * answer to when it last changed, with or without Yoast SEO.
 */
class Test_Schema extends Rolling_Coverage_TestCase {

	/**
	 * The standalone script's dateModified is the latest change to a published
	 * entry. Saving a draft entry doesn't count, since readers can't see it,
	 * and pinning an older entry doesn't hide a later change.
	 */
	public function test_date_modified_is_the_latest_published_entry_change() {
		$coverage_id = self::create_coverage();
		$host_id     = $this->create_host_post( [ $coverage_id ], '2026-09-01 10:00:00' );

		$pinned_id = $this->create_dated_entry( $coverage_id, '2026-09-02 10:00:00' );
		$latest_id = $this->create_dated_entry( $coverage_id, '2026-09-03 10:00:00' );
		$this->create_dated_entry( $coverage_id, '2026-09-04 10:00:00', 'draft' );
		update_option( Post_Type::PINNED_OPTION_KEY, [ $pinned_id ] );

		$this->assertSame( '2026-09-03 10:00:00', get_post( $latest_id )->post_modified_gmt );

		$scripts = $this->render_scripts( $host_id );

		$this->assertCount( 1, $scripts );
		$this->assertSame( 'LiveBlogPosting', $scripts[0]['@type'] );
		$this->assertSame( '2026-09-01T10:00:00+00:00', $scripts[0]['datePublished'] );
		$this->assertSame( '2026-09-03T10:00:00+00:00', $scripts[0]['dateModified'] );
	}

	/**
	 * An edit to the host post after the last entry change becomes the page's
	 * dateModified, even when the metadata was already cached.
	 */
	public function test_date_modified_follows_a_later_edit_to_the_host_post() {
		$coverage_id = self::create_coverage();
		$host_id     = $this->create_host_post( [ $coverage_id ], '2026-09-01 10:00:00' );
		$this->create_dated_entry( $coverage_id, '2026-09-02 10:00:00' );

		$this->assertSame( '2026-09-02T10:00:00+00:00', $this->render_scripts( $host_id )[0]['dateModified'] );

		wp_update_post(
			[
				'ID'         => $host_id,
				'post_title' => 'Edited headline',
			]
		);

		$this->assertSame(
			get_post_datetime( $host_id, 'modified', 'gmt' )->format( 'c' ),
			$this->render_scripts( $host_id )[0]['dateModified']
		);
	}

	/**
	 * Yoast's Article becomes the live blog: it keeps Yoast's type, headline,
	 * publish date and main entity, gains the coverage times and updates, and
	 * takes the live blog's dateModified.
	 */
	public function test_yoast_article_becomes_the_live_blog() {
		$coverage_id = self::create_coverage();
		$host_id     = $this->create_host_post( [ $coverage_id ], '2026-09-01 10:00:00' );
		$this->create_dated_entry( $coverage_id, '2026-09-02 10:00:00' );
		$this->create_dated_entry( $coverage_id, '2026-09-03 10:00:00' );

		$article = apply_filters(
			'wpseo_schema_article',
			[
				'@type'            => 'NewsArticle',
				'@id'              => get_permalink( $host_id ) . '#article',
				'headline'         => 'Host headline',
				'datePublished'    => '2026-09-01T10:00:00+00:00',
				'mainEntityOfPage' => [ '@id' => get_permalink( $host_id ) ],
			],
			$this->yoast_context( $host_id )
		);

		$this->assertSame( [ 'NewsArticle', 'LiveBlogPosting' ], $article['@type'] );
		$this->assertSame( 'Host headline', $article['headline'] );
		$this->assertSame( '2026-09-01T10:00:00+00:00', $article['datePublished'] );
		$this->assertSame( [ '@id' => get_permalink( $host_id ) ], $article['mainEntityOfPage'] );
		$this->assertSame( '2026-09-03T10:00:00+00:00', $article['dateModified'] );
		$this->assertArrayHasKey( 'coverageStartTime', $article );
		$this->assertCount( 2, $article['liveBlogUpdate'] );
		$this->assertArrayNotHasKey( '@context', $article );
	}

	/**
	 * Once the first coverage is merged into Yoast's Article, only the page's
	 * other coverages are printed as their own scripts.
	 */
	public function test_merged_coverage_is_not_printed_again() {
		$first_id  = self::create_coverage( '', [ 'name' => 'First coverage' ] );
		$second_id = self::create_coverage( '', [ 'name' => 'Second coverage' ] );
		$host_id   = $this->create_host_post( [ $first_id, $second_id ], '2026-09-01 10:00:00' );
		$this->create_dated_entry( $first_id, '2026-09-02 10:00:00' );
		$this->create_dated_entry( $second_id, '2026-09-02 10:00:00' );

		apply_filters( 'wpseo_schema_article', [ '@type' => 'Article' ], $this->yoast_context( $host_id ) );

		$scripts = $this->render_scripts( $host_id );

		$this->assertCount( 1, $scripts );
		$this->assertSame( 'Second coverage', $scripts[0]['headline'] );
	}

	/**
	 * Yoast's WebPage takes the live blog's dateModified too.
	 */
	public function test_yoast_webpage_gets_the_live_blog_date_modified() {
		$coverage_id = self::create_coverage();
		$host_id     = $this->create_host_post( [ $coverage_id ], '2026-09-01 10:00:00' );
		$this->create_dated_entry( $coverage_id, '2026-09-03 10:00:00' );

		$webpage = apply_filters(
			'wpseo_schema_webpage',
			[
				'@type'         => 'WebPage',
				'datePublished' => '2026-09-01T10:00:00+00:00',
			],
			$this->yoast_context( $host_id )
		);

		$this->assertSame( '2026-09-03T10:00:00+00:00', $webpage['dateModified'] );
	}

	/**
	 * Themes asking for the page's modified date get the newest published
	 * entry's, in the site's timezone, while the stored date stays as it was.
	 * A post without a coverage keeps its own.
	 */
	public function test_themes_get_the_newest_published_entry_as_the_modified_date() {
		update_option( 'timezone_string', 'America/New_York' );

		$coverage_id = self::create_coverage();
		$host_id     = $this->create_host_post( [ $coverage_id ], '2026-09-01 10:00:00' );
		$plain_id    = $this->create_host_post( [], '2026-09-01 10:00:00' );
		$this->create_dated_entry( $coverage_id, '2026-09-03 10:00:00' );
		$this->create_dated_entry( $coverage_id, '2026-09-04 10:00:00', 'draft' );
		$entry_time = strtotime( '2026-09-03 10:00:00 UTC' );

		$this->assertSame( '2026-09-03 06:00', get_the_modified_date( 'Y-m-d H:i', $host_id ) );
		$this->assertSame( wp_date( get_option( 'time_format' ), $entry_time ), get_the_modified_time( '', $host_id ) );
		$this->assertSame( $entry_time - 4 * HOUR_IN_SECONDS, get_the_modified_time( 'U', $host_id ), 'Core adds the site offset to this format.' );
		$this->assertSame( '2026-09-01 10:00:00', get_post( $host_id )->post_modified_gmt, 'The stored date should be left alone.' );
		$this->assertSame( '2026-09-01 10:00', get_the_modified_date( 'Y-m-d H:i', $plain_id ) );
	}

	/**
	 * An entry published on schedule keeps the modified date of its last edit,
	 * so the page is dated by when the entry went live.
	 */
	public function test_a_scheduled_entry_counts_from_when_it_goes_live() {
		$coverage_id = self::create_coverage();
		$host_id     = $this->create_host_post( [ $coverage_id ], '2026-09-01 10:00:00' );
		$entry_id    = $this->create_scheduled_entry( $coverage_id, '2026-09-02 09:00:00', '2026-09-02 10:00:00' );

		$this->assertSame( 'future', get_post_status( $entry_id ) );
		$this->assertSame( '2026-09-01 10:00:00', get_the_modified_date( 'Y-m-d H:i:s', $host_id ), 'A scheduled entry is not a change readers see.' );

		wp_publish_post( $entry_id );

		$this->assertSame( '2026-09-02 10:00:00', get_the_modified_date( 'Y-m-d H:i:s', $host_id ) );
	}

	/**
	 * The standalone script is cached, and a scheduled entry going live leaves
	 * the coverage's own last-modified marker where it was, so the cache has
	 * to follow the page's date to pick the entry up.
	 */
	public function test_the_standalone_script_picks_up_a_scheduled_entry_going_live() {
		$coverage_id = self::create_coverage();
		$host_id     = $this->create_host_post( [ $coverage_id ], '2026-09-01 10:00:00' );
		$this->create_dated_entry( $coverage_id, '2026-09-02 08:00:00' );
		$entry_id = $this->create_scheduled_entry( $coverage_id, '2026-09-02 09:00:00', '2026-09-02 10:00:00' );

		// The save that schedules an entry leaves the marker at the entry's edit time.
		update_term_meta( $coverage_id, Rolling_Coverage_Block::LAST_MODIFIED_META_KEY, '2026-09-02 09:00:00' );

		$before = $this->render_scripts( $host_id )[0];

		wp_publish_post( $entry_id );

		$after = $this->render_scripts( $host_id )[0];

		$this->assertSame( '2026-09-02 09:00:00', get_term_meta( $coverage_id, Rolling_Coverage_Block::LAST_MODIFIED_META_KEY, true ), 'Going live should leave the marker where it was.' );
		$this->assertSame( '2026-09-02T08:00:00+00:00', $before['dateModified'] );
		$this->assertCount( 1, $before['liveBlogUpdate'] );
		$this->assertSame( '2026-09-02T10:00:00+00:00', $after['dateModified'] );
		$this->assertCount( 2, $after['liveBlogUpdate'] );
	}

	/**
	 * A page's date is asked for several times while it renders, so it is
	 * worked out once and reused, until an entry changes.
	 */
	public function test_the_page_date_is_worked_out_once_until_something_changes() {
		$coverage_id = self::create_coverage();
		$host_id     = $this->create_host_post( [ $coverage_id ], '2026-09-01 10:00:00' );
		$this->create_dated_entry( $coverage_id, '2026-09-03 10:00:00' );

		$entry_lookups = 0;
		add_action(
			'pre_get_posts',
			function ( $query ) use ( &$entry_lookups ) {
				if ( Post_Type::CPT_SLUG === $query->get( 'post_type' ) ) {
					++$entry_lookups;
				}
			}
		);

		get_the_modified_date( 'c', $host_id );
		$lookups_for_one_read = $entry_lookups;
		get_the_modified_time( 'U', $host_id );

		$this->assertGreaterThan( 0, $lookups_for_one_read );
		$this->assertSame( $lookups_for_one_read, $entry_lookups, 'A second read should reuse the first.' );

		$this->create_dated_entry( $coverage_id, '2026-09-04 10:00:00' );

		$this->assertSame( '2026-09-04T10:00:00+00:00', get_the_modified_date( 'c', $host_id ), 'A new entry should be picked up in the same request.' );
	}

	/**
	 * A page published on schedule has a publish date later than its last
	 * edit. An entry from before the page went live must not become its date,
	 * or the page would report a change from before it was published.
	 */
	public function test_an_entry_older_than_the_pages_publish_date_does_not_date_the_page() {
		global $wpdb;

		$coverage_id = self::create_coverage();
		$host_id     = $this->create_host_post( [ $coverage_id ], '2026-09-05 10:00:00' );
		$this->create_dated_entry( $coverage_id, '2026-09-03 10:00:00' );

		// Last edited on the 1st, set to go live on the 5th.
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->posts,
			[
				'post_modified'     => '2026-09-01 10:00:00',
				'post_modified_gmt' => '2026-09-01 10:00:00',
			],
			[ 'ID' => $host_id ]
		);
		clean_post_cache( $host_id );

		$sitemap_entry = [ 'mod' => '2026-09-05 10:00:00' ];

		$this->assertSame( $sitemap_entry, apply_filters( 'wpseo_sitemap_entry', $sitemap_entry, 'post', $this->sitemap_row( $host_id ) ) );
		$this->assertSame( '2026-09-05T10:00:00+00:00', $this->render_scripts( $host_id )[0]['dateModified'] );
	}

	/**
	 * Yoast's `article:modified_time` and both sitemaps report the same moment
	 * as the structured data, each in the form its reader expects: Yoast takes
	 * UTC, WordPress's sitemap the site's timezone.
	 */
	public function test_yoast_meta_and_sitemaps_report_the_newest_published_entry() {
		update_option( 'timezone_string', 'America/New_York' );

		$coverage_id = self::create_coverage();
		$host_id     = $this->create_host_post( [ $coverage_id ], '2026-09-01 10:00:00' );
		$this->create_dated_entry( $coverage_id, '2026-09-03 10:00:00' );

		$presentation = apply_filters( 'wpseo_frontend_presentation', (object) [ 'open_graph_article_modified_time' => '' ], $this->yoast_context( $host_id ) );
		$yoast_entry  = apply_filters( 'wpseo_sitemap_entry', [ 'mod' => '2026-09-01 10:00:00' ], 'post', $this->sitemap_row( $host_id ) );
		$core_entry   = apply_filters( 'wp_sitemaps_posts_entry', [ 'lastmod' => '2026-09-01T06:00:00-04:00' ], get_post( $host_id ), 'post' );

		$this->assertSame( '2026-09-03T10:00:00+00:00', $presentation->open_graph_article_modified_time );
		$this->assertSame( '2026-09-03 10:00:00', $yoast_entry['mod'], 'Yoast reads this value as UTC.' );
		$this->assertSame( '2026-09-03T06:00:00-04:00', $core_entry['lastmod'] );
	}

	/**
	 * Other plugins' values are left alone on pages without a coverage,
	 * including pages that aren't posts.
	 */
	public function test_other_plugins_values_are_untouched_without_a_coverage() {
		$post_id = $this->create_host_post( [], '2026-09-01 10:00:00' );
		$this->create_dated_entry( self::create_coverage(), '2026-09-03 10:00:00' );
		$article = [
			'@type'    => 'Article',
			'headline' => 'Plain post',
		];
		$webpage = [ '@type' => 'WebPage' ];

		foreach ( [ $this->yoast_context( $post_id ), (object) [ 'post' => null ] ] as $context ) {
			$this->assertSame( $article, apply_filters( 'wpseo_schema_article', $article, $context ) );
			$this->assertSame( $webpage, apply_filters( 'wpseo_schema_webpage', $webpage, $context ) );
			$this->assertSame( '', apply_filters( 'wpseo_frontend_presentation', (object) [ 'open_graph_article_modified_time' => '' ], $context )->open_graph_article_modified_time );
		}

		$sitemap_entry = [ 'mod' => '2026-09-01 10:00:00' ];

		$this->assertSame( $sitemap_entry, apply_filters( 'wpseo_sitemap_entry', $sitemap_entry, 'post', $this->sitemap_row( $post_id ) ) );
		$this->assertSame( $sitemap_entry, apply_filters( 'wp_sitemaps_posts_entry', $sitemap_entry, get_post( $post_id ), 'post' ) );
	}

	/**
	 * Create a published post embedding the given coverages.
	 *
	 * @param int[]  $coverage_ids Coverage term IDs, one block each.
	 * @param string $date         Publish date, which is also its modified date.
	 * @return int Post ID.
	 */
	private function create_host_post( array $coverage_ids, string $date ): int {
		$content = '';
		foreach ( $coverage_ids as $coverage_id ) {
			$content .= sprintf( '<!-- wp:%s {"coverageId":%d} /-->', Schema::BLOCK_NAME, $coverage_id );
		}

		return self::factory()->post->create(
			[
				'post_content'  => $content,
				'post_date'     => $date,
				'post_date_gmt' => $date,
			]
		);
	}

	/**
	 * Create an entry whose scheduled time has come but which cron hasn't
	 * published yet: last edited at `$edited`, set to go live at `$goes_live`.
	 *
	 * Core refuses to schedule an entry in the past, so it is scheduled ahead
	 * and its dates are then moved back.
	 *
	 * @param int    $coverage_id Coverage term ID.
	 * @param string $edited      GMT date of its last edit.
	 * @param string $goes_live   GMT date it is scheduled for.
	 * @return int Entry post ID.
	 */
	private function create_scheduled_entry( int $coverage_id, string $edited, string $goes_live ): int {
		global $wpdb;

		$entry_id = self::create_dated_entry( $coverage_id, gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ), 'future' );

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->posts,
			[
				'post_modified'     => $edited,
				'post_modified_gmt' => $edited,
				'post_date'         => $goes_live,
				'post_date_gmt'     => $goes_live,
			],
			[ 'ID' => $entry_id ]
		);
		clean_post_cache( $entry_id );

		return $entry_id;
	}

	/**
	 * A stand-in for Yoast's meta tags context, which exposes the page's post.
	 *
	 * @param int $post_id Post ID.
	 * @return object Context.
	 */
	private function yoast_context( int $post_id ) {
		return (object) [ 'post' => get_post( $post_id ) ];
	}

	/**
	 * A stand-in for the database row Yoast's sitemap hands to its filter.
	 *
	 * @param int $post_id Post ID.
	 * @return object Row.
	 */
	private function sitemap_row( int $post_id ) {
		$post = get_post( $post_id );

		return (object) [
			'ID'                => $post->ID,
			'post_content'      => $post->post_content,
			'post_status'       => $post->post_status,
			'post_date_gmt'     => $post->post_date_gmt,
			'post_modified_gmt' => $post->post_modified_gmt,
		];
	}

	/**
	 * Render the standalone scripts for a post and decode them.
	 *
	 * @param int $post_id Post ID to query.
	 * @return array[] Decoded JSON-LD objects.
	 */
	private function render_scripts( int $post_id ): array {
		$this->go_to( get_permalink( $post_id ) );

		ob_start();
		Schema::print_schema();
		$html = ob_get_clean();

		preg_match_all( '#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches );

		return array_map(
			function ( $json ) {
				return json_decode( $json, true );
			},
			$matches[1]
		);
	}
}
