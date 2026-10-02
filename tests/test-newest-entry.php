<?php
/**
 * Tests for the newest entry date kept on each coverage.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Newest_Entry;
use Newspack_Rolling_Coverage\Taxonomy;

/**
 * Each coverage knows when its newest published entry went out, so the
 * status block and polls can say when it was last updated without a query.
 */
class Test_Newest_Entry extends Rolling_Coverage_TestCase {

	/**
	 * The newest published entry's date, whatever order entries arrive in.
	 */
	public function test_publishing_entries_keeps_the_newest_date() {
		$coverage_id = self::create_coverage();
		self::create_entry( $coverage_id, [ 'post_date' => '2026-01-01 10:00:00' ] );
		self::create_entry( $coverage_id, [ 'post_date' => '2026-01-01 12:00:00' ] );
		self::create_entry( $coverage_id, [ 'post_date' => '2026-01-01 11:00:00' ] );

		$this->assertSame( '2026-01-01 12:00:00', get_term_meta( $coverage_id, Newest_Entry::META_KEY, true ), 'The meta should be kept up to date as entries publish.' );
		$this->assertSame( '2026-01-01 12:00:00', Newest_Entry::get( $coverage_id ) );
		$this->assertSame( '2026-01-01T12:00:00+00:00', Newest_Entry::get_iso( $coverage_id ) );
	}

	/**
	 * Drafts and scheduled entries are not news yet, even when dated later.
	 */
	public function test_unpublished_entries_do_not_count() {
		$coverage_id = self::create_coverage();
		self::create_entry( $coverage_id, [ 'post_date' => '2026-01-01 10:00:00' ] );
		self::create_entry(
			$coverage_id,
			[
				'post_date'   => '2026-01-01 12:00:00',
				'post_status' => 'draft',
			]
		);
		self::create_entry(
			$coverage_id,
			[
				'post_date'   => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
				'post_status' => 'future',
			]
		);

		$this->assertSame( '2026-01-01 10:00:00', Newest_Entry::get( $coverage_id ) );
	}

	/**
	 * Unpublishing, trashing or deleting the newest entry falls back to the
	 * one before it.
	 *
	 * @dataProvider removal_provider
	 *
	 * @param callable $remove Takes the entry ID and removes it from the coverage's published entries.
	 */
	public function test_removing_the_newest_entry_falls_back( callable $remove ) {
		$coverage_id = self::create_coverage();
		self::create_entry( $coverage_id, [ 'post_date' => '2026-01-01 10:00:00' ] );
		$newest_id = self::create_entry( $coverage_id, [ 'post_date' => '2026-01-01 12:00:00' ] );

		$remove( $newest_id );

		$this->assertSame( '2026-01-01 10:00:00', get_term_meta( $coverage_id, Newest_Entry::META_KEY, true ) );
	}

	/**
	 * Ways an entry stops being a published entry of its coverage.
	 *
	 * @return array
	 */
	public function removal_provider() {
		return [
			'unpublished' => [
				static function ( $id ) {
					wp_update_post(
						[
							'ID'          => $id,
							'post_status' => 'draft',
						]
					);
				},
			],
			'trashed'     => [ 'wp_trash_post' ],
			'deleted'     => [
				static function ( $id ) {
					wp_delete_post( $id, true );
				},
			],
		];
	}

	/**
	 * Moving an entry to another coverage updates both.
	 */
	public function test_moving_an_entry_updates_both_coverages() {
		$from_id = self::create_coverage();
		$to_id   = self::create_coverage();
		self::create_entry( $from_id, [ 'post_date' => '2026-01-01 10:00:00' ] );
		$moved_id = self::create_entry( $from_id, [ 'post_date' => '2026-01-01 12:00:00' ] );

		wp_set_object_terms( $moved_id, [ $to_id ], Taxonomy::TAXONOMY_SLUG );

		$this->assertSame( '2026-01-01 10:00:00', Newest_Entry::get( $from_id ), 'The old coverage falls back.' );
		$this->assertSame( '2026-01-01 12:00:00', Newest_Entry::get( $to_id ), 'The new coverage gains it.' );
	}

	/**
	 * A coverage without published entries has no newest entry.
	 */
	public function test_coverage_without_entries_has_none() {
		$coverage_id = self::create_coverage();

		$this->assertSame( '', Newest_Entry::get( $coverage_id ) );
		$this->assertNull( Newest_Entry::get_iso( $coverage_id ) );
	}

	/**
	 * Coverages from before the meta existed get it on first read.
	 */
	public function test_missing_meta_is_filled_on_read() {
		$coverage_id = self::create_coverage();
		self::create_entry( $coverage_id, [ 'post_date' => '2026-01-01 12:00:00' ] );
		delete_term_meta( $coverage_id, Newest_Entry::META_KEY );

		$this->assertSame( '2026-01-01 12:00:00', Newest_Entry::get( $coverage_id ) );
		$this->assertTrue( metadata_exists( 'term', $coverage_id, Newest_Entry::META_KEY ), 'The value should be stored for next time.' );
	}
}
