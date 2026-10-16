<?php
/**
 * Tests for the order of entries in AI prompts.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\AI_Service;
use Newspack_Rolling_Coverage\Post_Type;

/**
 * A prompt lists entries in the order the feed shows them, so the AI weighs
 * the entries readers see first.
 */
class Test_AI_Prompt_Entries extends Rolling_Coverage_TestCase {

	/**
	 * Pinned entries come first, most recently edited first, then the rest
	 * newest first.
	 */
	public function test_prompt_lists_pinned_entries_most_recently_edited_first() {
		$coverage_id = self::create_coverage();
		$edited_pin  = self::create_titled_entry( $coverage_id, 'Edited pin', '2026-01-01 10:00:00' );
		$newer_pin   = self::create_titled_entry( $coverage_id, 'Newer pin', '2026-01-01 11:00:00' );
		self::create_titled_entry( $coverage_id, 'Newest', '2026-01-01 12:00:00' );
		self::create_titled_entry( $coverage_id, 'Oldest', '2026-01-01 09:00:00' );
		Post_Type::pin_entry( $newer_pin );
		Post_Type::pin_entry( $edited_pin );
		self::set_entry_modified( $edited_pin, '2026-01-01 13:00:00' );

		preg_match_all( '/^Entry \d+ \([^)]*\): (.+)$/m', AI_Service::get_entries_for_prompt( $coverage_id ), $titles );

		$this->assertSame( [ 'Edited pin', 'Newer pin', 'Newest', 'Oldest' ], $titles[1] );
	}

	/**
	 * Create a published entry with a title, at a fixed time.
	 *
	 * @param int    $coverage_id Coverage term ID.
	 * @param string $title       Entry title.
	 * @param string $date        Entry date, `Y-m-d H:i:s`. The test site runs on UTC.
	 * @return int Entry post ID.
	 */
	private static function create_titled_entry( int $coverage_id, string $title, string $date ): int {
		return self::create_entry(
			$coverage_id,
			[
				'post_title' => $title,
				'post_date'  => $date,
			]
		);
	}
}
