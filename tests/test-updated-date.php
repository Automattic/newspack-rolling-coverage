<?php
/**
 * Tests for showing a live post's updated date.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Rolling_Coverage_Block;
use Newspack_Rolling_Coverage\Taxonomy;

/**
 * While its coverage hasn't ended, a post shows when it last changed in its
 * byline and on Homepage Posts cards, instead of looking as old as its
 * publish date.
 */
class Test_Updated_Date extends Rolling_Coverage_TestCase {

	/**
	 * A post shows its updated date while its coverage is live or paused, and
	 * leaves it to the site's settings once the coverage ends.
	 *
	 * @dataProvider data_coverage_statuses
	 *
	 * @param string $status   Coverage status.
	 * @param bool   $is_shown Whether the updated date is shown.
	 */
	public function test_a_post_shows_its_updated_date_until_its_coverage_ends( string $status, bool $is_shown ) {
		$coverage_id = self::create_coverage( $status );
		$host        = $this->create_host_post( '<!-- wp:' . Rolling_Coverage_Block::BLOCK_NAME . ' {"coverageId":' . $coverage_id . '} /-->', '2026-09-01 10:00:00' );
		self::create_dated_entry( $coverage_id, '2026-09-01 12:00:00' );

		$this->assertSame( $is_shown ? true : null, $this->byline_override( $host ), 'Byline.' );
		$this->assertSame( $is_shown, $this->card_display( $host ), 'Homepage Posts card.' );
	}

	/**
	 * Coverage statuses and whether a post embedding the coverage shows its
	 * updated date.
	 *
	 * @return array[]
	 */
	public function data_coverage_statuses(): array {
		return [
			'live'   => [ Taxonomy::STATUS_ACTIVE, true ],
			'paused' => [ Taxonomy::STATUS_PAUSED, true ],
			'ended'  => [ Taxonomy::STATUS_ARCHIVED, false ],
		];
	}

	/**
	 * A live post that hasn't changed since it was published has no updated
	 * date to show yet.
	 */
	public function test_nothing_is_shown_until_the_post_changes_after_publishing() {
		$coverage_id = self::create_coverage( Taxonomy::STATUS_ACTIVE );
		self::create_dated_entry( $coverage_id, '2026-09-01 09:00:00' );
		$host = $this->create_host_post( '<!-- wp:' . Rolling_Coverage_Block::BLOCK_NAME . ' {"coverageId":' . $coverage_id . '} /-->', '2026-09-01 10:00:00' );

		$this->assertNull( $this->byline_override( $host ), 'Byline.' );
		$this->assertFalse( $this->card_display( $host ), 'Homepage Posts card.' );
	}

	/**
	 * A block that only previews a coverage's latest entries doesn't make the
	 * post a live blog.
	 */
	public function test_a_capped_block_does_not_make_a_live_post() {
		$coverage_id = self::create_coverage( Taxonomy::STATUS_ACTIVE );
		$host        = $this->create_host_post( '<!-- wp:' . Rolling_Coverage_Block::BLOCK_NAME . ' {"coverageId":' . $coverage_id . ',"latestOnly":true} /-->', '2026-09-01 10:00:00' );
		self::create_dated_entry( $coverage_id, '2026-09-01 12:00:00' );

		$this->assertNull( $this->byline_override( $host ), 'Byline.' );
		$this->assertFalse( $this->card_display( $host ), 'Homepage Posts card.' );
	}

	/**
	 * A feed placed through a synced pattern doesn't turn the date on: the
	 * post's dates don't follow it, so the time shown would be the post's own
	 * last edit while entries keep arriving.
	 */
	public function test_a_feed_in_a_synced_pattern_does_not_turn_the_date_on() {
		$coverage_id = self::create_coverage( Taxonomy::STATUS_ACTIVE );
		$pattern_id  = self::factory()->post->create(
			[
				'post_type'    => 'wp_block',
				'post_content' => '<!-- wp:' . Rolling_Coverage_Block::BLOCK_NAME . ' {"coverageId":' . $coverage_id . '} /-->',
			]
		);
		$host        = $this->create_host_post( '<!-- wp:block {"ref":' . $pattern_id . '} /-->', '2026-09-01 10:00:00' );
		self::create_dated_entry( $coverage_id, '2026-09-01 12:00:00' );
		self::set_entry_modified( $host->ID, '2026-09-01 11:00:00' );
		$host = get_post( $host->ID );

		$this->assertNull( $this->byline_override( $host ), 'Byline.' );
		$this->assertFalse( $this->card_display( $host ), 'Homepage Posts card.' );
	}

	/**
	 * Posts without a live coverage keep whatever other code decided.
	 */
	public function test_other_posts_keep_the_existing_decision() {
		$host = $this->create_host_post( '<!-- wp:paragraph --><p>Story.</p><!-- /wp:paragraph -->', '2026-09-01 10:00:00' );

		$this->assertFalse( apply_filters( 'newspack_updated_date_override', false, $host ), 'Byline.' );
		$this->assertTrue( apply_filters( 'newspack_blocks_display_updated_date', true, $host ), 'Homepage Posts card.' );
	}

	/**
	 * What Newspack's updated date override decides for a post, when nothing
	 * else has decided.
	 *
	 * @param WP_Post $post Post.
	 * @return bool|null
	 */
	private function byline_override( WP_Post $post ) {
		return apply_filters( 'newspack_updated_date_override', null, $post );
	}

	/**
	 * Whether a Homepage Posts card shows the post's updated date, when
	 * nothing else has asked for it.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	private function card_display( WP_Post $post ): bool {
		return apply_filters( 'newspack_blocks_display_updated_date', false, $post );
	}

	/**
	 * Create a published post.
	 *
	 * @param string $content Post content.
	 * @param string $date    GMT publish date, which is also its modified date.
	 * @return WP_Post Post.
	 */
	private function create_host_post( string $content, string $date ): WP_Post {
		return get_post(
			self::factory()->post->create(
				[
					'post_content'  => $content,
					'post_date'     => $date,
					'post_date_gmt' => $date,
				]
			)
		);
	}
}
