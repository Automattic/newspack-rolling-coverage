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

		$this->assertSame( Entry_Ingestion_Service::SKIP_IN_PROGRESS, $result, 'The overlapping request should be skipped, and told why.' );
		$this->assertSame( 0, self::count_entries(), 'No entry should be created.' );
		$this->assertNotFalse( get_option( self::lock_key( $payload ) ), "The other request's lock should be left in place." );
	}

	/**
	 * Media is imported between the duplicate check and the insert, which can
	 * take long enough for another delivery of the same message to finish. The
	 * message still becomes one entry.
	 */
	public function test_message_saved_by_another_request_during_the_media_import_is_not_saved_twice() {
		$payload     = self::payload();
		$coverage_id = self::create_coverage();
		$bot_user_id = self::factory()->user->create( [ 'role' => 'author' ] );

		$result = Entry_Ingestion_Service::ingest(
			$payload,
			$coverage_id,
			false,
			$bot_user_id,
			[],
			static function () use ( $payload, $coverage_id ) {
				global $wpdb;

				// The other delivery's entry, saved while this one imports its media. Written
				// straight to the database, as a write by another request reaches this one:
				// without touching this request's caches.
				// phpcs:disable WordPress.DB.DirectDatabaseQuery
				$wpdb->insert(
					$wpdb->posts,
					[
						'post_type'   => Post_Type::CPT_SLUG,
						'post_status' => 'draft',
					]
				);
				$other_entry_id = $wpdb->insert_id;
				$wpdb->insert(
					$wpdb->postmeta,
					[
						'post_id'    => $other_entry_id,
						'meta_key'   => Post_Type::META_SOURCE_REF, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
						'meta_value' => $payload->source_ref, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					]
				);
				$wpdb->insert(
					$wpdb->term_relationships,
					[
						'object_id'        => $other_entry_id,
						'term_taxonomy_id' => get_term( $coverage_id, Taxonomy::TAXONOMY_SLUG )->term_taxonomy_id,
					]
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery

				return '<!-- wp:image --><figure class="wp-block-image"><img src="https://example.test/photo.jpg" alt=""/></figure><!-- /wp:image -->';
			}
		);

		$this->assertSame( 0, $result, 'The second save should be skipped as a duplicate.' );
		$this->assertSame( 1, self::count_entries(), 'The message should have one entry.' );
	}

	/**
	 * Media work can outlast the lock's lifetime. It keeps the lock as it
	 * progresses, so a redelivery arriving meanwhile still backs off.
	 */
	public function test_media_work_keeps_the_lock_while_it_progresses() {
		$payload     = self::payload();
		$coverage_id = self::create_coverage();
		$bot_user_id = self::factory()->user->create( [ 'role' => 'author' ] );
		$redelivery  = null;

		Entry_Ingestion_Service::ingest(
			$payload,
			$coverage_id,
			false,
			$bot_user_id,
			[],
			static function ( callable $keep_lock ) use ( $payload, $coverage_id, $bot_user_id, &$redelivery ) {
				// The work has been running for longer than the lock's lifetime.
				update_option( self::lock_key( $payload ), time() - Entry_Ingestion_Service::MUTEX_TTL - 1, false );
				$keep_lock();

				$redelivery = Entry_Ingestion_Service::ingest( $payload, $coverage_id, false, $bot_user_id, [] );

				return '';
			}
		);

		$this->assertSame( Entry_Ingestion_Service::SKIP_IN_PROGRESS, $redelivery, 'The redelivery should back off.' );
		$this->assertSame( 1, self::count_entries(), 'The message should have one entry.' );
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
	 * Entries from a chat source have no title; the message is the entry.
	 */
	public function test_entry_has_no_title() {
		$entry_id = self::ingest( self::payload(), self::create_coverage() );

		$this->assertSame( '', get_post( $entry_id )->post_title );
	}

	/**
	 * Backslashes in a message survive saving.
	 */
	public function test_keeps_backslashes() {
		$content = "<!-- wp:code -->\n<pre class=\"wp-block-code\"><code>C:\\Results\\final.csv \\o/</code></pre>\n<!-- /wp:code -->";

		$entry_id = self::ingest( self::payload( [ 'content_html' => $content ] ), self::create_coverage() );

		$this->assertSame( $content, get_post( $entry_id )->post_content );
	}

	/**
	 * Other features hear about a new entry once it has its coverage and
	 * meta, and only once for a message Slack sends twice.
	 */
	public function test_new_entry_is_announced_once_saved_with_its_coverage() {
		$coverage_id = self::create_coverage();
		$announced   = [];
		add_action(
			'newspack_rolling_coverage_entry_ingested',
			static function ( $post_id ) use ( &$announced ) {
				$announced[] = [
					'post_id'  => $post_id,
					'coverage' => wp_get_post_terms( $post_id, Taxonomy::TAXONOMY_SLUG, [ 'fields' => 'ids' ] ),
					'ref'      => get_post_meta( $post_id, Post_Type::META_SOURCE_REF, true ),
				];
			}
		);

		$entry_id = self::ingest( self::payload(), $coverage_id );
		self::ingest( self::payload(), $coverage_id );

		$this->assertSame(
			[
				[
					'post_id'  => $entry_id,
					'coverage' => [ $coverage_id ],
					'ref'      => self::SOURCE_REF,
				],
			],
			$announced
		);
	}
}
