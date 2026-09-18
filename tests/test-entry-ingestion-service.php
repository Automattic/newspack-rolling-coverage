<?php
/**
 * Tests for the chat-source ingestion pipeline.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Entry_Ingestion_Service;
use Newspack_Rolling_Coverage\Post_Type;
use Newspack_Rolling_Coverage\Source_Event_Payload;
use Newspack_Rolling_Coverage\Taxonomy;

/**
 * Covers the decisions the pipeline makes before and around the insert: when
 * a message is a duplicate, when it is skipped, and how the per-message lock
 * behaves when a request overlaps with, or dies before, another one.
 */
class Test_Entry_Ingestion_Service extends Rolling_Coverage_TestCase {

	const SOURCE_REF = '1767225600.000100';

	/**
	 * Build a normalized payload.
	 *
	 * @param array $overrides Constructor arguments to override, by name.
	 * @return Source_Event_Payload
	 */
	private static function payload( array $overrides = [] ) {
		$arguments = array_merge(
			[
				'source'              => 'slack',
				'source_ref'          => self::SOURCE_REF,
				'conversation_ref'    => 'C0TESTCHAN',
				'author_external_id'  => 'U0REPORTER',
				'author_display_name' => 'Riley Sample',
				'content_html'        => '<!-- wp:paragraph --><p>Polls have closed.</p><!-- /wp:paragraph -->',
				'content_plain'       => 'Polls have closed.',
				'thread_ref'          => null,
				'external_timestamp'  => '2026-01-01T00:00:00+00:00',
				'raw_payload'         => [],
			],
			$overrides
		);

		return new Source_Event_Payload( ...$arguments );
	}

	/**
	 * Ingest a payload as the given bot user, as a draft with no extra meta.
	 *
	 * @param Source_Event_Payload $payload     Payload to ingest.
	 * @param int                  $coverage_id Coverage term ID.
	 * @param int|null             $bot_user_id Bot user ID. Created when null.
	 * @return int|WP_Error Ingestion result.
	 */
	private static function ingest( Source_Event_Payload $payload, $coverage_id, $bot_user_id = null ) {
		if ( null === $bot_user_id ) {
			$bot_user_id = self::factory()->user->create( [ 'role' => 'author' ] );
		}

		return Entry_Ingestion_Service::ingest( $payload, $coverage_id, false, $bot_user_id, [] );
	}

	/**
	 * The option key locking a given message.
	 *
	 * @param Source_Event_Payload $payload Payload being ingested.
	 * @return string
	 */
	private static function lock_key( Source_Event_Payload $payload ) {
		return Entry_Ingestion_Service::MUTEX_PREFIX . md5( $payload->source . ':' . $payload->source_ref );
	}

	/**
	 * Number of entries in the database, of any status.
	 *
	 * @return int
	 */
	private static function count_entries() {
		return count(
			get_posts(
				[
					'post_type'   => Post_Type::CPT_SLUG,
					'post_status' => 'any',
					'fields'      => 'ids',
				]
			)
		);
	}

	/**
	 * An archived coverage accepts no new entries, and says so distinctly so
	 * the adapter can log the reason.
	 */
	public function test_archived_coverage_accepts_no_new_entries() {
		$coverage_id = self::create_coverage( Taxonomy::STATUS_ARCHIVED );

		$result = self::ingest( self::payload(), $coverage_id );

		$this->assertSame( Entry_Ingestion_Service::SKIP_ARCHIVED_COVERAGE, $result, 'The skip should be reported as an archived-coverage skip.' );
		$this->assertSame( 0, self::count_entries(), 'No entry should be created.' );
	}

	/**
	 * Deduplication is per coverage: a message id seen in one coverage does
	 * not block the same id arriving for another.
	 */
	public function test_duplicate_detection_is_scoped_to_the_coverage() {
		$first_coverage_id  = self::create_coverage();
		$second_coverage_id = self::create_coverage();

		$first_entry_id  = self::ingest( self::payload(), $first_coverage_id );
		$repeat_result   = self::ingest( self::payload(), $first_coverage_id );
		$second_entry_id = self::ingest( self::payload(), $second_coverage_id );

		$this->assertGreaterThan( 0, $first_entry_id, 'The first delivery should create an entry.' );
		$this->assertSame( 0, $repeat_result, 'A repeat delivery to the same coverage should be skipped.' );
		$this->assertGreaterThan( 0, $second_entry_id, 'The same message id in another coverage should create an entry.' );
		$this->assertNotSame( $first_entry_id, $second_entry_id, 'The two coverages should get separate entries.' );
	}

	/**
	 * While another request holds a fresh lock on the same message, this one
	 * backs off without creating an entry and without releasing the lock it
	 * does not own.
	 */
	public function test_backs_off_while_another_request_is_ingesting_the_same_message() {
		$payload = self::payload();
		add_option( self::lock_key( $payload ), time(), '', false );

		$result = self::ingest( $payload, self::create_coverage() );

		$this->assertSame( 0, $result, 'The overlapping request should be skipped.' );
		$this->assertSame( 0, self::count_entries(), 'No entry should be created.' );
		$this->assertNotFalse( get_option( self::lock_key( $payload ) ), "The other request's lock should be left in place." );
	}

	/**
	 * A lock left behind by a request that died is reclaimed once it is older
	 * than the TTL, so the message is not blocked forever.
	 */
	public function test_reclaims_a_lock_abandoned_by_a_dead_request() {
		$payload = self::payload();
		add_option( self::lock_key( $payload ), time() - Entry_Ingestion_Service::MUTEX_TTL - 1, '', false );

		$result = self::ingest( $payload, self::create_coverage() );

		$this->assertGreaterThan( 0, $result, 'The message should be ingested.' );
		$this->assertFalse( get_option( self::lock_key( $payload ) ), 'The reclaimed lock should be released afterwards.' );
	}

	/**
	 * The lock is released when ingestion ends in a skip, so a corrected
	 * retry of the same message can go through.
	 */
	public function test_releases_the_lock_when_the_message_is_skipped() {
		$this->silence_error_log();
		$empty_payload = self::payload( [ 'content_html' => '' ] );

		$result = self::ingest( $empty_payload, self::create_coverage() );

		$this->assertSame( 0, $result, 'A message with no content should be skipped.' );
		$this->assertFalse( get_option( self::lock_key( $empty_payload ) ), 'The lock should be released.' );
	}

	/**
	 * Without a bot user there is nobody to attribute the entry to, so it is
	 * skipped rather than inserted with no author.
	 */
	public function test_skips_the_message_when_no_bot_user_is_available() {
		$this->silence_error_log();

		$result = self::ingest( self::payload(), self::create_coverage(), 0 );

		$this->assertSame( 0, $result, 'The message should be skipped.' );
		$this->assertSame( 0, self::count_entries(), 'No entry should be created.' );
	}

	/**
	 * The entry is assigned to the coverage and carries the source reference
	 * that later deliveries are deduplicated against.
	 */
	public function test_entry_is_assigned_to_the_coverage_and_keyed_by_its_source_reference() {
		$coverage_id = self::create_coverage();

		$entry_id = Entry_Ingestion_Service::ingest(
			self::payload(),
			$coverage_id,
			false,
			self::factory()->user->create( [ 'role' => 'author' ] ),
			[
				'rolling_coverage_slack_channel_id' => 'C0TESTCHAN',
				''                                  => 'dropped: empty key',
				7                                   => 'dropped: numeric key',
			]
		);

		$this->assertSame( [ $coverage_id ], wp_get_post_terms( $entry_id, Taxonomy::TAXONOMY_SLUG, [ 'fields' => 'ids' ] ), 'The entry should belong to the coverage.' );
		$this->assertSame( self::SOURCE_REF, get_post_meta( $entry_id, Post_Type::META_SOURCE_REF, true ), 'The source reference should be stored.' );
		$this->assertSame( 'C0TESTCHAN', get_post_meta( $entry_id, 'rolling_coverage_slack_channel_id', true ), 'Adapter meta should be stored.' );
		$this->assertSame( [], get_post_meta( $entry_id, '7', false ), 'Meta with a non-string key should be ignored.' );
	}

	/**
	 * Long messages get a title cut at the limit, with whitespace collapsed
	 * first and multibyte characters counted as one.
	 */
	public function test_title_is_the_collapsed_message_cut_at_the_limit() {
		$long_message    = "Résultats  définitifs:\n" . str_repeat( 'é', 60 );
		$collapsed_start = 'Résultats définitifs: ';

		$entry_id = self::ingest( self::payload( [ 'content_plain' => $long_message ] ), self::create_coverage() );

		$expected_title = $collapsed_start . str_repeat( 'é', Entry_Ingestion_Service::TITLE_LENGTH - mb_strlen( $collapsed_start ) ) . '…';

		$this->assertSame( $expected_title, get_post( $entry_id )->post_title );
	}

	/**
	 * A message that fits is used as the title unchanged.
	 */
	public function test_short_message_is_used_as_the_title_unchanged() {
		$entry_id = self::ingest( self::payload(), self::create_coverage() );

		$this->assertSame( 'Polls have closed.', get_post( $entry_id )->post_title );
	}
}
