<?php
/**
 * Tests for the LiveBlogPosting structured data.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Post_Type;
use Newspack_Rolling_Coverage\Rolling_Coverage_Block;
use Newspack_Rolling_Coverage\Schema;
use Newspack_Rolling_Coverage\Taxonomy;

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
	 * to follow the newest entry to pick it up, whether or not the page's own
	 * date is the later one.
	 *
	 * @dataProvider data_page_dates_around_a_scheduled_entry
	 *
	 * @param string $page_date           The page's own date.
	 * @param string $date_before_go_live The `dateModified` expected before the entry goes live.
	 * @param string $date_after_go_live  The `dateModified` expected after.
	 */
	public function test_the_standalone_script_picks_up_a_scheduled_entry_going_live( string $page_date, string $date_before_go_live, string $date_after_go_live ) {
		$coverage_id = self::create_coverage();
		$host_id     = $this->create_host_post( [ $coverage_id ], $page_date );
		$this->create_dated_entry( $coverage_id, '2026-09-02 08:00:00' );
		$entry_id = $this->create_scheduled_entry( $coverage_id, '2026-09-02 09:00:00', '2026-09-02 10:00:00' );

		// The save that schedules an entry leaves the marker at the entry's edit time.
		update_term_meta( $coverage_id, Rolling_Coverage_Block::LAST_MODIFIED_META_KEY, '2026-09-02 09:00:00' );

		$before = $this->render_scripts( $host_id )[0];

		wp_publish_post( $entry_id );

		$after = $this->render_scripts( $host_id )[0];

		$this->assertSame( '2026-09-02 09:00:00', get_term_meta( $coverage_id, Rolling_Coverage_Block::LAST_MODIFIED_META_KEY, true ), 'Going live should leave the marker where it was.' );
		$this->assertSame( $date_before_go_live, $before['dateModified'] );
		$this->assertCount( 1, $before['liveBlogUpdate'] );
		$this->assertSame( $date_after_go_live, $after['dateModified'] );
		$this->assertCount( 2, $after['liveBlogUpdate'] );
	}

	/**
	 * A page last changed before its entries, and one last changed after them.
	 *
	 * @return array[]
	 */
	public function data_page_dates_around_a_scheduled_entry(): array {
		return [
			'page older than its entries' => [ '2026-09-01 10:00:00', '2026-09-02T08:00:00+00:00', '2026-09-02T10:00:00+00:00' ],
			'page newer than its entries' => [ '2026-09-05 10:00:00', '2026-09-05T10:00:00+00:00', '2026-09-05T10:00:00+00:00' ],
		];
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

		$this->assertSame( $sitemap_entry, apply_filters( 'wpseo_sitemap_entry', $sitemap_entry, 'post', $this->sitemap_post( $host_id ) ) );
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
		$yoast_entry  = apply_filters( 'wpseo_sitemap_entry', [ 'mod' => '2026-09-01 10:00:00' ], 'post', $this->sitemap_post( $host_id ) );
		$core_entry   = apply_filters( 'wp_sitemaps_posts_entry', [ 'lastmod' => '2026-09-01T06:00:00-04:00' ], get_post( $host_id ), 'post' );

		$this->assertSame( '2026-09-03T10:00:00+00:00', $presentation->open_graph_article_modified_time );
		$this->assertSame( '2026-09-03 10:00:00', $yoast_entry['mod'], 'Yoast reads this value as UTC.' );
		$this->assertSame( '2026-09-03T06:00:00-04:00', $core_entry['lastmod'] );
	}

	/**
	 * A password-protected page gives nothing away about its coverage until it
	 * is unlocked: no script with the entries' text, no merge into Yoast's
	 * Article, and no date taken from an entry.
	 */
	public function test_a_password_protected_page_reports_nothing_about_its_coverage() {
		$coverage_id = self::create_coverage();
		$host_id     = $this->create_host_post( [ $coverage_id ], '2026-09-01 10:00:00', [ 'post_password' => 'secret' ] );
		$this->create_dated_entry( $coverage_id, '2026-09-03 10:00:00' );
		$article = [
			'@type'    => 'Article',
			'headline' => 'Protected page',
		];

		$this->assertSame( [], $this->render_scripts( $host_id ) );
		$this->assertSame( $article, apply_filters( 'wpseo_schema_article', $article, $this->yoast_context( $host_id ) ) );
		$this->assertSame( '2026-09-01 10:00:00', get_the_modified_date( 'Y-m-d H:i:s', $host_id ) );
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

		$this->assertSame( $sitemap_entry, apply_filters( 'wpseo_sitemap_entry', $sitemap_entry, 'post', $this->sitemap_post( $post_id ) ) );
		$this->assertSame( $sitemap_entry, apply_filters( 'wp_sitemaps_posts_entry', $sitemap_entry, get_post( $post_id ), 'post' ) );
	}

	/**
	 * A post that only embeds a capped block shows a few entries and links to
	 * the coverage page, so it is not the live blog and its date stays its own;
	 * a full feed is.
	 */
	public function test_only_uncapped_blocks_make_a_live_blog() {
		$coverage_id = self::create_coverage();
		self::create_dated_entry( $coverage_id, '2026-09-03 10:00:00' );
		$capped = sprintf( '<!-- wp:%s {"coverageId":%d,"latestOnly":true} /-->', Schema::BLOCK_NAME, $coverage_id );
		$full   = sprintf( '<!-- wp:%s {"coverageId":%d} /-->', Schema::BLOCK_NAME, $coverage_id );

		$capped_id = $this->create_host_post( [], '2026-09-01 10:00:00', [ 'post_content' => $capped ] );
		$both_id   = $this->create_host_post( [], '2026-09-01 10:00:00', [ 'post_content' => $capped . $full ] );

		$this->assertSame( [], $this->render_scripts( $capped_id ) );
		$this->assertNull( Schema::get_page_date_modified( get_post( $capped_id ) ) );

		$scripts = $this->render_scripts( $both_id );
		$this->assertCount( 1, $scripts );
		$this->assertSame( 'LiveBlogPosting', $scripts[0]['@type'] );
	}

	/**
	 * The metadata is cached in the object cache, never as a database
	 * transient. Its key carries the schema group's own `last_changed` stamp,
	 * which only moves when something the schema shows changes — not with the
	 * site's writes at large; a transient would also write a new wp_options
	 * row per rotated key when no persistent object cache is installed.
	 */
	public function test_the_metadata_is_not_cached_as_a_database_transient() {
		global $wpdb;

		$coverage_id = self::create_coverage();
		$host_id     = $this->create_host_post( [ $coverage_id ], '2026-09-01 10:00:00' );
		$this->create_dated_entry( $coverage_id, '2026-09-02 10:00:00' );

		$this->render_scripts( $host_id );

		$transient_rows = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_nrc_%' OR option_name LIKE '_transient_timeout_nrc_%'"
		);

		$this->assertSame( 0, $transient_rows, 'The schema metadata must not be stored as a database transient.' );
	}

	/**
	 * The cached metadata is keyed off changes the coverage's own last-modified
	 * meta never records. Renaming the coverage changes the headline it emits.
	 */
	public function test_renaming_the_coverage_refreshes_the_cached_headline() {
		$coverage_id = self::create_coverage( '', [ 'name' => 'Original Name' ] );
		$host_id     = $this->create_host_post( [ $coverage_id ], '2026-09-01 10:00:00' );
		$this->create_dated_entry( $coverage_id, '2026-09-02 10:00:00' );

		$this->assertSame( 'Original Name', $this->render_scripts( $host_id )[0]['headline'] );

		// A rename bumps the schema group's stamp, not the coverage's last-modified meta.
		wp_update_term( $coverage_id, Taxonomy::TAXONOMY_SLUG, [ 'name' => 'Renamed Coverage' ] );

		$this->assertSame( 'Renamed Coverage', $this->render_scripts( $host_id )[0]['headline'] );
	}

	/**
	 * Moving a published entry out of the coverage changes which entries the
	 * liveBlogUpdate lists, without moving the coverage's last-modified meta.
	 *
	 * The moved entry is deliberately not the newest one: moving the newest
	 * would also move the "latest entry date" in the key and mask whether the
	 * term-relationship change invalidated the cache on its own.
	 */
	public function test_moving_an_entry_out_of_the_coverage_refreshes_the_cached_updates() {
		$coverage_id = self::create_coverage();
		$other_id    = self::create_coverage();
		$host_id     = $this->create_host_post( [ $coverage_id ], '2026-09-01 10:00:00' );

		$newest_id = $this->create_dated_entry( $coverage_id, '2026-09-03 10:00:00' );
		$older_id  = $this->create_dated_entry( $coverage_id, '2026-09-02 10:00:00' );

		$urls_before = array_column( $this->render_scripts( $host_id )[0]['liveBlogUpdate'], 'url' );
		$this->assertCount( 2, $urls_before );

		// A term-relationship change on the coverage taxonomy bumps the stamp.
		wp_set_object_terms( $older_id, [ $other_id ], Taxonomy::TAXONOMY_SLUG );

		$updates_after = $this->render_scripts( $host_id )[0]['liveBlogUpdate'];
		$this->assertCount( 1, $updates_after );
		$this->assertStringContainsString( 'entry-' . $newest_id, $updates_after[0]['url'] );
		$this->assertStringNotContainsString( 'entry-' . $older_id, $updates_after[0]['url'] );
	}

	/**
	 * Renaming an entry's author changes the author emitted in the schema,
	 * which bumps the users cache salt but no post or term cache.
	 */
	public function test_renaming_an_author_refreshes_the_cached_author() {
		$author_id = self::factory()->user->create(
			[
				'display_name' => 'Original Author',
				'role'         => 'author',
			]
		);

		$coverage_id = self::create_coverage();
		$host_id     = $this->create_host_post( [ $coverage_id ], '2026-09-01 10:00:00' );
		$this->create_dated_entry( $coverage_id, '2026-09-02 10:00:00', 'publish', $author_id );

		$this->assertSame( 'Original Author', $this->render_scripts( $host_id )[0]['liveBlogUpdate'][0]['author']['name'] );

		// A user rename bumps the stamp, but a user-meta write alone does not.
		wp_update_user(
			[
				'ID'           => $author_id,
				'display_name' => 'Renamed Author',
			]
		);

		$this->assertSame( 'Renamed Author', $this->render_scripts( $host_id )[0]['liveBlogUpdate'][0]['author']['name'] );
	}

	/**
	 * Term relationship changes outside the coverage taxonomy never reach the
	 * schema, so they don't rotate the stamp either.
	 */
	public function test_term_assignment_to_other_taxonomies_do_not_rotate_the_cache_key() {
		$coverage_id = self::create_coverage();
		$host_id     = $this->create_host_post( [ $coverage_id ], '2026-09-01 10:00:00' );
		$entry_id    = $this->create_dated_entry( $coverage_id, '2026-09-02 10:00:00' );

		$this->render_scripts( $host_id );

		$stamp_before = wp_cache_get_last_changed( Schema::CACHE_GROUP );
		$terms_before = wp_cache_get_last_changed( 'terms' );

		$category_id = self::factory()->category->create( [ 'name' => 'Unrelated' ] );
		wp_set_object_terms( $entry_id, [ $category_id ], 'category' );

		$this->assertNotSame( $terms_before, wp_cache_get_last_changed( 'terms' ), 'Test sanity: the terms salt moved.' );
		$this->assertSame( $stamp_before, wp_cache_get_last_changed( Schema::CACHE_GROUP ), 'An unrelated term assignment must not rotate the metadata cache key.' );
	}

	/**
	 * Deleting an author rotates the stamp even though nothing else records
	 * it: their entries' authors are reassigned through a direct query, so no
	 * other hook covers the change.
	 */
	public function test_deleting_an_author_refreshes_the_cached_author() {
		$author_id   = self::factory()->user->create(
			[
				'display_name' => 'Original Author',
				'role'         => 'author',
			]
		);
		$reassign_id = self::factory()->user->create(
			[
				'display_name' => 'Reassigned Author',
				'role'         => 'author',
			]
		);

		$coverage_id = self::create_coverage();
		$host_id     = $this->create_host_post( [ $coverage_id ], '2026-09-01 10:00:00' );
		$this->create_dated_entry( $coverage_id, '2026-09-02 10:00:00', 'publish', $author_id );

		$this->assertSame( 'Original Author', $this->render_scripts( $host_id )[0]['liveBlogUpdate'][0]['author']['name'] );

		wp_delete_user( $author_id, $reassign_id );

		$this->assertSame( 'Reassigned Author', $this->render_scripts( $host_id )[0]['liveBlogUpdate'][0]['author']['name'] );
	}

	/**
	 * Create a published post embedding the given coverages.
	 *
	 * @param int[]  $coverage_ids Coverage term IDs, one block each.
	 * @param string $date         Publish date, which is also its modified date.
	 * @param array  $args         Further post factory arguments.
	 * @return int Post ID.
	 */
	private function create_host_post( array $coverage_ids, string $date, array $args = [] ): int {
		$content = '';
		foreach ( $coverage_ids as $coverage_id ) {
			$content .= sprintf( '<!-- wp:%s {"coverageId":%d} /-->', Schema::BLOCK_NAME, $coverage_id );
		}

		return self::factory()->post->create(
			array_merge(
				[
					'post_content'  => $content,
					'post_date'     => $date,
					'post_date_gmt' => $date,
				],
				$args
			)
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
	 * A stand-in for the post Yoast's sitemap hands to its filter: built from
	 * the few columns its query selects, so it carries no password, and marked
	 * raw as Yoast's is, or WordPress would swap it for the stored post.
	 *
	 * @param int $post_id Post ID.
	 * @return WP_Post Partial post.
	 */
	private function sitemap_post( int $post_id ): WP_Post {
		$post = get_post( $post_id );

		return new WP_Post(
			(object) [
				'ID'                => $post->ID,
				'filter'            => 'raw',
				'post_type'         => $post->post_type,
				'post_content'      => $post->post_content,
				'post_status'       => $post->post_status,
				'post_date_gmt'     => $post->post_date_gmt,
				'post_modified_gmt' => $post->post_modified_gmt,
			]
		);
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

	/**
	 * Within one request, a second look at the page is served from the cache,
	 * and each change the schema shows — term rename, entry move, author
	 * rename — rotates the stamp and forces a rebuild on the next look, while
	 * a user-meta write on passive reader activity does not.
	 */
	public function test_the_cached_metadata_is_reused_and_rebuilt_only_when_its_inputs_change() {
		$author_id = self::factory()->user->create(
			[
				// An apostrophe, so a name comparison against the magic-quoted
				// `$userdata` the profile_update hook carries would look changed.
				'display_name' => "O'Brien",
				'role'         => 'author',
			]
		);

		$coverage_id = self::create_coverage( '', [ 'name' => 'Original Name' ] );
		$other_id    = self::create_coverage();
		$host_id     = $this->create_host_post( [ $coverage_id ], '2026-09-01 10:00:00' );
		$this->create_dated_entry( $coverage_id, '2026-09-03 10:00:00', 'publish', $author_id );
		$older_id = $this->create_dated_entry( $coverage_id, '2026-09-02 10:00:00' );

		$this->go_to( get_permalink( $host_id ) );

		$builds = 0;
		add_filter(
			'newspack_rolling_coverage_schema_metadata',
			function ( $metadata ) use ( &$builds ) {
				$builds++;
				return $metadata;
			}
		);

		$this->assertSame( 'Original Name', $this->print_scripts()[0]['headline'], 'First look builds.' );

		$this->assertSame( 'Original Name', $this->print_scripts()[0]['headline'], 'Second look is served from the cache.' );
		$this->assertSame( 1, $builds, 'The second look must reuse the cached metadata.' );

		// Passive reader activity writes user meta — nothing the schema shows.
		$users_before = wp_cache_get_last_changed( 'users' );
		update_user_meta( $author_id, 'newspack_reader_activity', 'x' );

		$this->print_scripts();

		$this->assertNotSame( $users_before, wp_cache_get_last_changed( 'users' ), 'Test sanity: the users salt moved.' );
		$this->assertSame( 1, $builds, 'A user-meta write must not rebuild the cached metadata.' );

		// Renaming the coverage changes the headline.
		wp_update_term( $coverage_id, Taxonomy::TAXONOMY_SLUG, [ 'name' => 'Renamed Coverage' ] );

		$this->assertSame( 'Renamed Coverage', $this->print_scripts()[0]['headline'], 'A rename rebuilds.' );
		$this->assertSame( 2, $builds );

		// Moving an entry out changes the liveBlogUpdate list.
		$updates_before = count( $this->print_scripts()[0]['liveBlogUpdate'] );
		wp_set_object_terms( $older_id, [ $other_id ], Taxonomy::TAXONOMY_SLUG );

		$this->assertCount( $updates_before - 1, $this->print_scripts()[0]['liveBlogUpdate'], 'An entry move rebuilds.' );
		$this->assertSame( 3, $builds );

		// Renaming the author changes the author in liveBlogUpdate.
		wp_update_user(
			[
				'ID'           => $author_id,
				'display_name' => "O'Renamed",
			]
		);

		$this->assertSame( "O'Renamed", $this->print_scripts()[0]['liveBlogUpdate'][0]['author']['name'], 'An author rename rebuilds.' );
		$this->assertSame( 4, $builds );

		// A user update that touches neither the headline, the list, nor a name.
		// The comparison must read stored data: a name with an apostrophe would
		// look changed against the magic-quoted values the hook carries.
		wp_update_user(
			[
				'ID'          => $author_id,
				'description' => 'New bio',
			]
		);

		$this->print_scripts();

		$this->assertSame( 4, $builds, 'A user update without a name change must not rebuild the cached metadata.' );
	}

	/**
	 * Print the standalone scripts for the post already navigated to, without
	 * `go_to()`, which resets the object cache and would mask what the cache
	 * actually serves.
	 *
	 * @return array[] Decoded JSON-LD objects.
	 */
	private function print_scripts(): array {
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
