<?php
/**
 * Tests for the LiveBlogPosting structured data.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Post_Type;
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
		global $wpdb;

		$coverage_id = self::create_coverage();
		$host_id     = $this->create_host_post( [ $coverage_id ], '2026-09-01 10:00:00' );
		$entry_id    = $this->create_dated_entry( $coverage_id, gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ), 'future' );

		$this->assertSame( 'future', get_post_status( $entry_id ) );
		$this->assertSame( '2026-09-01 10:00:00', get_the_modified_date( 'Y-m-d H:i:s', $host_id ), 'A scheduled entry is not a change readers see.' );

		// The scheduled time has come: last edited at 09:00, set to go live at 10:00.
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->posts,
			[
				'post_modified'     => '2026-09-02 09:00:00',
				'post_modified_gmt' => '2026-09-02 09:00:00',
				'post_date'         => '2026-09-02 10:00:00',
				'post_date_gmt'     => '2026-09-02 10:00:00',
			],
			[ 'ID' => $entry_id ]
		);
		clean_post_cache( $entry_id );

		wp_publish_post( $entry_id );

		$this->assertSame( '2026-09-02 10:00:00', get_the_modified_date( 'Y-m-d H:i:s', $host_id ) );
	}

	/**
	 * Yoast's `article:modified_time` and both sitemaps report the same date
	 * as the structured data.
	 */
	public function test_yoast_meta_and_sitemaps_report_the_newest_published_entry() {
		$coverage_id = self::create_coverage();
		$host_id     = $this->create_host_post( [ $coverage_id ], '2026-09-01 10:00:00' );
		$this->create_dated_entry( $coverage_id, '2026-09-03 10:00:00' );

		$presentation = apply_filters( 'wpseo_frontend_presentation', (object) [ 'open_graph_article_modified_time' => '' ], $this->yoast_context( $host_id ) );
		$yoast_entry  = apply_filters( 'wpseo_sitemap_entry', [ 'mod' => '2026-09-01 10:00:00' ], 'post', $this->sitemap_row( $host_id ) );
		$core_entry   = apply_filters( 'wp_sitemaps_posts_entry', [ 'lastmod' => '2026-09-01T10:00:00+00:00' ], get_post( $host_id ), 'post' );

		$this->assertSame( '2026-09-03T10:00:00+00:00', $presentation->open_graph_article_modified_time );
		$this->assertSame( '2026-09-03 10:00:00', $yoast_entry['mod'] );
		$this->assertSame( '2026-09-03T10:00:00+00:00', $core_entry['lastmod'] );
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
