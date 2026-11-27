<?php
/**
 * Tests for entry dates shown only on earlier days.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Layout;
use Newspack_Rolling_Coverage\Layout_Templates;
use Newspack_Rolling_Coverage\Rolling_Coverage_Block;

/**
 * The date after an entry's time shows only for entries that weren't
 * published on the site's current day, in the site's timezone.
 */
class Test_Earlier_Day_Date extends Rolling_Coverage_TestCase {

	const EARLIER_DAY_DATE = '<!-- wp:post-date {"format":"F j, Y","className":"newspack-rolling-coverage-earlier-day-date","metadata":{"bindings":{"datetime":{"source":"core/post-data","args":{"field":"date"}}}}} /-->';

	/**
	 * Restore the request the feed test changes.
	 */
	public function tear_down() {
		$this->go_to( home_url( '/' ) );

		parent::tear_down();
	}

	/**
	 * Create an entry published at a Unix timestamp.
	 *
	 * @param int $coverage_id Coverage term ID.
	 * @param int $timestamp   When it was published.
	 * @return WP_Post
	 */
	private static function entry_at( int $coverage_id, int $timestamp ): WP_Post {
		return get_post(
			self::create_entry(
				$coverage_id,
				[
					'post_date'     => wp_date( 'Y-m-d H:i:s', $timestamp ),
					'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $timestamp ),
				]
			)
		);
	}

	/**
	 * The wrapper of the first earlier-day date in some HTML.
	 *
	 * @param string $html Rendered HTML.
	 * @return WP_HTML_Tag_Processor|null Positioned on the wrapper, or null without one.
	 */
	private static function earlier_day_date( string $html ): ?WP_HTML_Tag_Processor {
		$date = new WP_HTML_Tag_Processor( $html );

		return $date->next_tag( [ 'class_name' => Rolling_Coverage_Block::EARLIER_DAY_DATE_CLASS ] ) ? $date : null;
	}

	/**
	 * Today's entry renders its date hidden, an earlier day's shows it, and
	 * both carry the site's day they were published on.
	 */
	public function test_hides_todays_date_and_shows_an_earlier_days() {
		update_option( 'timezone_string', 'America/New_York' );

		$coverage_id = self::create_coverage();
		$now         = time();
		$template    = parse_blocks( self::EARLIER_DAY_DATE );
		$today       = self::earlier_day_date( Rolling_Coverage_Block::render_entry( self::entry_at( $coverage_id, $now ), $template ) );
		$earlier     = self::earlier_day_date( Rolling_Coverage_Block::render_entry( self::entry_at( $coverage_id, $now - 2 * DAY_IN_SECONDS ), $template ) );

		$this->assertNotNull( $today );
		$this->assertSame( wp_date( 'Y-m-d', $now ), $today->get_attribute( 'data-rc-day' ) );
		$this->assertTrue( $today->get_attribute( 'hidden' ), "Today's date should be hidden." );

		$this->assertNotNull( $earlier );
		$this->assertSame( wp_date( 'Y-m-d', $now - 2 * DAY_IN_SECONDS ), $earlier->get_attribute( 'data-rc-day' ) );
		$this->assertNull( $earlier->get_attribute( 'hidden' ), "An earlier day's date should show." );
	}

	/**
	 * The site's day turns over at the site's midnight, not UTC's.
	 */
	public function test_today_turns_over_at_the_sites_midnight() {
		update_option( 'timezone_string', 'America/New_York' );

		$now            = strtotime( '2026-03-05 00:05:00 America/New_York' );
		$after_midnight = strtotime( '2026-03-05 00:01:00 America/New_York' );
		$last_night     = strtotime( '2026-03-04 23:55:00 America/New_York' );

		$this->assertTrue( Rolling_Coverage_Block::is_site_today( $after_midnight, $now ) );
		$this->assertFalse( Rolling_Coverage_Block::is_site_today( $last_night, $now ), "An entry from before the site's midnight is from an earlier day." );
		$this->assertSame( '2026-03-04', Rolling_Coverage_Block::site_day( $last_night ) );

		update_option( 'timezone_string', 'UTC' );

		$this->assertTrue( Rolling_Coverage_Block::is_site_today( $last_night, $now ), 'Both moments fall on the same UTC day.' );
	}

	/**
	 * A site set to a UTC offset rather than a named timezone uses the offset.
	 */
	public function test_today_follows_a_utc_offset() {
		update_option( 'timezone_string', '' );
		update_option( 'gmt_offset', 5.5 );

		$now = strtotime( '2026-03-04 18:45:00 UTC' );

		$this->assertSame( '2026-03-05', Rolling_Coverage_Block::site_day( $now ) );
		$this->assertTrue( Rolling_Coverage_Block::is_site_today( strtotime( '2026-03-04 18:40:00 UTC' ), $now ) );
		$this->assertFalse( Rolling_Coverage_Block::is_site_today( strtotime( '2026-03-04 18:20:00 UTC' ), $now ) );
	}

	/**
	 * Every layout that shows the time renders the date in the site's date
	 * format, hidden for today's entries, and the layouts that show a relative
	 * date or none have no earlier-day date.
	 *
	 * @dataProvider time_layouts
	 *
	 * @param string $slug Built-in layout slug.
	 */
	public function test_layouts_with_the_time_show_the_date_on_earlier_days( string $slug ) {
		update_option( 'date_format', 'd/m/Y' );

		$coverage_id = self::create_coverage();
		$earlier     = self::entry_at( $coverage_id, time() - 3 * DAY_IN_SECONDS );

		self::entry_at( $coverage_id, time() );

		$attributes = [ 'coverageId' => $coverage_id ];
		$block      = parse_blocks( '<!-- wp:newspack-rolling-coverage/rolling-coverage ' . wp_json_encode( $attributes ) . ' -->' . serialize_blocks( Layout_Templates::get_blocks( $slug ) ) . '<!-- /wp:newspack-rolling-coverage/rolling-coverage -->' )[0];
		$html       = Rolling_Coverage_Block::render_block( $attributes, '', new WP_Block( $block ) );
		$dates      = new WP_HTML_Tag_Processor( $html );
		$shown      = [];

		while ( $dates->next_tag( [ 'class_name' => Rolling_Coverage_Block::EARLIER_DAY_DATE_CLASS ] ) ) {
			$shown[] = true !== $dates->get_attribute( 'hidden' );
		}

		$this->assertSame( [ false, true ], $shown, "The newest entry's date should be hidden and the older one's shown." );
		$this->assertStringContainsString( '>' . get_the_date( 'd/m/Y', $earlier ) . '</time>', $html );
	}

	/**
	 * The built-in layouts that show each entry's time.
	 *
	 * @return array<string, string[]>
	 */
	public static function time_layouts(): array {
		$slugs = [ 'default', 'byline', 'clock', 'digest', 'flash', 'margin', 'rail', 'split', 'wire' ];

		return array_combine( $slugs, array_map( fn( $slug ) => [ $slug ], $slugs ) );
	}

	/**
	 * Stream and Ticker show relative dates, and Minute and Alert no date, so
	 * none has an earlier-day date.
	 */
	public function test_layouts_without_the_time_have_no_earlier_day_date() {
		$slugs = array_keys( self::time_layouts() );

		$this->assertSame( [ 'stream', 'minute', 'ticker', 'alert' ], array_values( array_diff( Layout::BUILT_IN_SLUGS, $slugs ) ) );

		foreach ( array_diff( Layout::BUILT_IN_SLUGS, $slugs ) as $slug ) {
			$this->assertStringNotContainsString( Rolling_Coverage_Block::EARLIER_DAY_DATE_CLASS, serialize_blocks( Layout_Templates::get_blocks( $slug ) ), "$slug should have no earlier-day date." );
		}
	}

	/**
	 * A Post Date without the class, or outside an entry, is left alone.
	 */
	public function test_leaves_other_dates_alone() {
		$entry = self::entry_at( self::create_coverage(), time() );
		$plain = Rolling_Coverage_Block::render_entry( $entry, parse_blocks( '<!-- wp:post-date {"format":"F j, Y"} /-->' ) );

		$this->assertStringNotContainsString( 'data-rc-day', $plain );
		$this->assertStringNotContainsString( 'hidden', $plain );

		$outside = ( new WP_Block(
			parse_blocks( self::EARLIER_DAY_DATE )[0],
			[
				'postId'   => $entry->ID,
				'postType' => $entry->post_type,
			]
		) )->render();

		$this->assertStringNotContainsString( 'data-rc-day', $outside, 'A date outside an entry should not be marked.' );
		$this->assertStringNotContainsString( 'hidden', $outside );
	}

	/**
	 * A syndication feed shows today's date too, as it is read on later days
	 * and no script there hides it.
	 */
	public function test_shows_todays_date_in_syndication_feeds() {
		$entry = self::entry_at( self::create_coverage(), time() );

		$this->go_to( get_feed_link() );
		$this->assertTrue( is_feed() );

		$date = self::earlier_day_date( Rolling_Coverage_Block::render_entry( $entry, parse_blocks( self::EARLIER_DAY_DATE ) ) );

		$this->assertNotNull( $date );
		$this->assertNull( $date->get_attribute( 'hidden' ) );
	}
}
