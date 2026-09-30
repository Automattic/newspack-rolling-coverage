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
	 * Yoast's pieces are left alone on pages without a coverage, including
	 * pages that aren't posts.
	 */
	public function test_yoast_pieces_are_untouched_without_a_coverage() {
		$post_id = self::factory()->post->create();
		$article = [
			'@type'    => 'Article',
			'headline' => 'Plain post',
		];
		$webpage = [ '@type' => 'WebPage' ];

		foreach ( [ $this->yoast_context( $post_id ), (object) [ 'post' => null ] ] as $context ) {
			$this->assertSame( $article, apply_filters( 'wpseo_schema_article', $article, $context ) );
			$this->assertSame( $webpage, apply_filters( 'wpseo_schema_webpage', $webpage, $context ) );
		}
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
	 * Create an entry whose publish and modified dates are both `$date`.
	 *
	 * @param int    $coverage_id Coverage term ID.
	 * @param string $date        GMT date.
	 * @param string $status      Post status.
	 * @return int Entry post ID.
	 */
	private function create_dated_entry( int $coverage_id, string $date, string $status = 'publish' ): int {
		return self::create_entry(
			$coverage_id,
			[
				'post_status'   => $status,
				'post_content'  => 'Update at ' . $date,
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
