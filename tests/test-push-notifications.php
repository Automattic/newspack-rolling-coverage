<?php
/**
 * Tests for push notifications sent when an entry is published.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Push_Notifications;
use Newspack_Rolling_Coverage\Taxonomy;

/**
 * A notification reaches readers' devices and cannot be recalled, so these
 * tests cover when one is sent, who it is addressed to, and that it is sent
 * once. OneSignal is replaced by a stand-in that records the payload.
 */
class Test_Push_Notifications extends Rolling_Coverage_TestCase {

	/**
	 * Load the OneSignal stand-in.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once __DIR__ . '/mocks/onesignal.php';
	}

	/**
	 * Start with OneSignal configured and nothing sent.
	 */
	public function set_up() {
		parent::set_up();
		$_POST = [];
		add_filter( 'cron_request', [ $this, 'keep_cron_offline' ] );
		$GLOBALS['nrc_test_sent_notifications'] = [];
		update_option(
			'OneSignalWPSetting',
			[
				'app_id'           => 'test-app-id',
				'app_rest_api_key' => 'test-rest-api-key',
			]
		);
	}

	/**
	 * Point the cron spawn at an address that fails straight away, so tests
	 * make no loopback request.
	 *
	 * @param array $cron_request Cron request URL and arguments.
	 * @return array
	 */
	public function keep_cron_offline( $cron_request ) {
		$cron_request['url'] = 'http://0.0.0.0:1/';
		return $cron_request;
	}

	/**
	 * Create a coverage readers can be sent to.
	 *
	 * @return int Coverage term ID.
	 */
	private static function create_coverage_with_canonical_url() {
		$coverage_id = self::create_coverage();
		update_term_meta( $coverage_id, Taxonomy::CANONICAL_URL_META_KEY, home_url( '/live/election-night/' ) );
		return $coverage_id;
	}

	/**
	 * Create a draft entry, optionally opted in to notify on publish.
	 *
	 * @param int  $coverage_id  Coverage term ID.
	 * @param bool $is_opted_in  Whether the editor asked for a notification.
	 * @return int Entry post ID.
	 */
	private static function create_draft_entry( $coverage_id, $is_opted_in ) {
		$entry_id = self::create_entry(
			$coverage_id,
			[
				'post_status' => 'draft',
				'post_title'  => 'Polls have closed',
				'post_name'   => 'polls-have-closed',
			]
		);

		if ( $is_opted_in ) {
			update_post_meta( $entry_id, Push_Notifications::NOTIFY_META_KEY, true );
		}

		return $entry_id;
	}

	/**
	 * Save an entry the way Slack ingestion does: inserted first, then given
	 * its coverage, then announced.
	 *
	 * @param int  $coverage_id  Coverage term ID.
	 * @param bool $auto_publish Whether the channel publishes straight away.
	 * @return int Entry post ID.
	 */
	private static function ingest_slack_message( $coverage_id, $auto_publish ) {
		$entry_id = self::factory()->post->create(
			[
				'post_type'   => \Newspack_Rolling_Coverage\Post_Type::CPT_SLUG,
				'post_status' => $auto_publish ? 'publish' : 'draft',
				'post_title'  => 'Polls have closed',
			]
		);
		wp_set_object_terms( $entry_id, [ (int) $coverage_id ], Taxonomy::TAXONOMY_SLUG );

		do_action( 'newspack_rolling_coverage_entry_ingested', $entry_id );

		return $entry_id;
	}

	/**
	 * Save the classic meta box the way the block editor does after its REST
	 * save: a post.php request carrying the checkbox.
	 *
	 * @param int  $entry_id   Entry post ID.
	 * @param bool $is_checked Whether the editor left the box checked.
	 */
	private static function save_meta_box( $entry_id, $is_checked ) {
		$_POST[ Push_Notifications::NONCE_NAME ] = wp_create_nonce( Push_Notifications::NONCE_ACTION );

		if ( $is_checked ) {
			$_POST[ Push_Notifications::NOTIFY_META_KEY ] = '1';
		}

		wp_update_post(
			[
				'ID'          => $entry_id,
				'post_status' => 'publish',
			]
		);

		$_POST = [];
	}

	/**
	 * Notifications recorded by the OneSignal stand-in.
	 *
	 * @return array[]
	 */
	private static function get_sent_notifications() {
		return $GLOBALS['nrc_test_sent_notifications'];
	}

	/**
	 * Publishing an opted-in entry notifies the followers of its coverage,
	 * and nobody else, with a link into the live coverage page.
	 */
	public function test_publishing_an_opted_in_entry_notifies_only_the_coverage_followers() {
		$coverage_id = self::create_coverage_with_canonical_url();
		$entry_id    = self::create_draft_entry( $coverage_id, true );

		wp_publish_post( $entry_id );

		$sent_notifications = self::get_sent_notifications();

		$this->assertCount( 1, $sent_notifications, 'One notification should be sent.' );
		$this->assertArrayNotHasKey( 'included_segments', $sent_notifications[0], 'The notification should not go to every subscriber.' );
		$this->assertSame(
			[
				[
					'field'    => 'tag',
					'key'      => Push_Notifications::follow_tag( $coverage_id ),
					'relation' => '=',
					'value'    => '1',
				],
			],
			$sent_notifications[0]['filters'],
			'The notification should be addressed to followers of this coverage.'
		);
		$this->assertSame( home_url( '/live/election-night/?rolling-coverage-entry=polls-have-closed#polls-have-closed' ), $sent_notifications[0]['url'], 'The link should open the entry inside the coverage page.' );
		$this->assertSame( 'Polls have closed', $sent_notifications[0]['title'], 'The entry title should be the notification title.' );
		$this->assertSame( Push_Notifications::follow_tag( $coverage_id ), $sent_notifications[0]['web_push_topic'], 'A newer update from the coverage should replace this one in the browser.' );
	}

	/**
	 * An untitled entry is announced under the coverage name, with the first
	 * words of the entry as the text, kept apart across line breaks.
	 */
	public function test_untitled_entry_is_announced_by_its_first_words() {
		$coverage_id = self::create_coverage_with_canonical_url();
		$entry_id    = self::create_entry(
			$coverage_id,
			[
				'post_status'  => 'draft',
				'post_title'   => '',
				'post_excerpt' => '',
				'post_content' => "<!-- wp:paragraph -->\n<p><strong>Polls have closed</strong> across the county.<br>Counting starts at 9pm in the town hall, with results expected before midnight.</p>\n<!-- /wp:paragraph -->",
			]
		);
		update_post_meta( $entry_id, Push_Notifications::NOTIFY_META_KEY, true );

		wp_publish_post( $entry_id );

		$sent_notifications = self::get_sent_notifications();

		$this->assertCount( 1, $sent_notifications );
		$this->assertSame( get_term( $coverage_id )->name, $sent_notifications[0]['title'], 'The coverage name should stand in for the title.' );
		$this->assertSame( 'Polls have closed across the county. Counting starts at 9pm in the town hall, with…', $sent_notifications[0]['content'] );
	}

	/**
	 * The opt-in is spent by the send, so publishing the entry again after a
	 * trip back to draft does not notify readers a second time.
	 */
	public function test_republishing_does_not_notify_again() {
		$entry_id = self::create_draft_entry( self::create_coverage_with_canonical_url(), true );
		wp_publish_post( $entry_id );

		wp_update_post(
			[
				'ID'          => $entry_id,
				'post_status' => 'draft',
			]
		);
		wp_publish_post( $entry_id );

		$this->assertCount( 1, self::get_sent_notifications() );
	}

	/**
	 * Without the opt-in nothing is sent.
	 */
	public function test_publishing_without_the_opt_in_sends_nothing() {
		$entry_id = self::create_draft_entry( self::create_coverage_with_canonical_url(), false );

		wp_publish_post( $entry_id );

		$this->assertSame( [], self::get_sent_notifications() );
	}

	/**
	 * An entry OneSignal already notified about is left alone.
	 */
	public function test_entry_already_notified_by_onesignal_is_not_notified_again() {
		$entry_id = self::create_draft_entry( self::create_coverage_with_canonical_url(), true );
		update_post_meta( $entry_id, 'os_notification_id', 'test-notification-id' );

		wp_publish_post( $entry_id );

		$this->assertSame( [], self::get_sent_notifications() );
	}

	/**
	 * With no canonical URL there is nowhere to send readers, so nothing is
	 * sent. The opt-in is kept rather than spent on a send that did not happen.
	 */
	public function test_coverage_without_a_canonical_url_sends_nothing_and_keeps_the_opt_in() {
		$entry_id = self::create_draft_entry( self::create_coverage(), true );

		wp_publish_post( $entry_id );

		$this->assertSame( [], self::get_sent_notifications(), 'Nothing should be sent.' );
		$this->assertNotEmpty( get_post_meta( $entry_id, Push_Notifications::NOTIFY_META_KEY, true ), 'The opt-in should be kept.' );
	}

	/**
	 * Nothing is sent until OneSignal has its app credentials.
	 */
	public function test_nothing_is_sent_while_onesignal_is_not_configured() {
		delete_option( 'OneSignalWPSetting' );
		$entry_id = self::create_draft_entry( self::create_coverage_with_canonical_url(), true );

		wp_publish_post( $entry_id );

		$this->assertSame( [], self::get_sent_notifications() );
	}

	/**
	 * A Slack message published straight away notifies followers from the
	 * next cron run, since OneSignal sends nothing inside the webhook's REST
	 * request.
	 */
	public function test_slack_entry_published_straight_away_notifies_followers_from_cron() {
		$coverage_id = self::create_coverage_with_canonical_url();
		$entry_id    = self::ingest_slack_message( $coverage_id, true );

		$this->assertSame( [], self::get_sent_notifications(), 'Nothing should be sent while the message is saved.' );
		$scheduled_at = wp_next_scheduled( Push_Notifications::SEND_HOOK, [ $entry_id ] );
		$this->assertIsInt( $scheduled_at, 'The send should be scheduled.' );
		$this->assertLessThanOrEqual( time(), $scheduled_at, 'The send should be due straight away.' );

		do_action( Push_Notifications::SEND_HOOK, $entry_id );

		$sent_notifications = self::get_sent_notifications();

		$this->assertCount( 1, $sent_notifications, 'One notification should be sent.' );
		$this->assertSame( Push_Notifications::follow_tag( $coverage_id ), $sent_notifications[0]['filters'][0]['key'], 'The notification should be addressed to followers of this coverage.' );
		$this->assertSame( '', (string) get_post_meta( $entry_id, Push_Notifications::NOTIFY_META_KEY, true ), 'The opt-in should be spent.' );

		do_action( Push_Notifications::SEND_HOOK, $entry_id );

		$this->assertCount( 1, self::get_sent_notifications(), 'A second run should not notify again.' );
	}

	/**
	 * A Slack message saved as a draft is opted in, so followers are notified
	 * when an editor publishes it.
	 */
	public function test_slack_draft_notifies_followers_when_published() {
		$entry_id = self::ingest_slack_message( self::create_coverage_with_canonical_url(), false );

		$this->assertFalse( wp_next_scheduled( Push_Notifications::SEND_HOOK, [ $entry_id ] ), 'Nothing should be scheduled for a draft.' );
		$this->assertNotEmpty( get_post_meta( $entry_id, Push_Notifications::NOTIFY_META_KEY, true ), 'The draft should be opted in.' );

		wp_update_post(
			[
				'ID'          => $entry_id,
				'post_status' => 'publish',
			]
		);

		$this->assertCount( 1, self::get_sent_notifications() );
	}

	/**
	 * Nothing is opted in while OneSignal isn't set up, so an entry saved then
	 * can't notify about old news once it is.
	 */
	public function test_slack_entry_is_not_opted_in_while_onesignal_is_not_configured() {
		delete_option( 'OneSignalWPSetting' );
		$entry_id = self::ingest_slack_message( self::create_coverage_with_canonical_url(), false );

		$this->assertSame( '', (string) get_post_meta( $entry_id, Push_Notifications::NOTIFY_META_KEY, true ) );
	}

	/**
	 * In the block editor, a publish is a REST save followed by the meta box
	 * save. When the editor leaves the box checked, followers get one
	 * notification, not one from each.
	 */
	public function test_block_editor_publish_with_the_box_checked_sends_once() {
		self::log_in_as( 'editor' );
		$entry_id = self::create_entry( self::create_coverage_with_canonical_url() );
		update_post_meta( $entry_id, Push_Notifications::NOTIFY_META_KEY, true );
		wp_schedule_single_event( time() + Push_Notifications::REST_SEND_DELAY, Push_Notifications::SEND_HOOK, [ $entry_id ] );

		self::save_meta_box( $entry_id, true );
		do_action( Push_Notifications::SEND_HOOK, $entry_id );

		$this->assertCount( 1, self::get_sent_notifications(), 'One notification should be sent.' );
		$this->assertFalse( wp_next_scheduled( Push_Notifications::SEND_HOOK, [ $entry_id ] ), 'The scheduled send should be cleared.' );
	}

	/**
	 * When the editor unchecks the box on publish, the send the REST save
	 * scheduled from the earlier opt-in is cancelled.
	 */
	public function test_block_editor_publish_with_the_box_unchecked_sends_nothing() {
		self::log_in_as( 'editor' );
		$entry_id = self::create_entry( self::create_coverage_with_canonical_url() );
		update_post_meta( $entry_id, Push_Notifications::NOTIFY_META_KEY, true );
		wp_schedule_single_event( time() + Push_Notifications::REST_SEND_DELAY, Push_Notifications::SEND_HOOK, [ $entry_id ] );

		self::save_meta_box( $entry_id, false );

		$this->assertFalse( wp_next_scheduled( Push_Notifications::SEND_HOOK, [ $entry_id ] ), 'The scheduled send should be cancelled.' );

		do_action( Push_Notifications::SEND_HOOK, $entry_id );

		$this->assertSame( [], self::get_sent_notifications() );
	}

	/**
	 * A send that started from stale meta stops once it holds the lock and
	 * finds another request already sent.
	 */
	public function test_send_rechecks_for_a_notification_sent_meanwhile() {
		$entry_id = self::create_draft_entry( self::create_coverage_with_canonical_url(), true );
		add_action(
			'transition_post_status',
			static function () use ( $entry_id ) {
				get_post_meta( $entry_id );
				add_filter(
					'option_OneSignalWPSetting',
					static function ( $settings ) use ( $entry_id ) {
						global $wpdb;
						// Written behind the meta cache, as another request would.
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
						$wpdb->insert(
							$wpdb->postmeta,
							[
								'post_id'    => $entry_id,
								'meta_key'   => 'os_notification_id',
								'meta_value' => 'sent-elsewhere', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
							]
						);
						return $settings;
					}
				);
			},
			5
		);

		wp_publish_post( $entry_id );

		$this->assertSame( [], self::get_sent_notifications() );
	}

	/**
	 * An editor who unchecks the opt-in before the scheduled send runs stops it.
	 */
	public function test_scheduled_send_respects_an_opt_in_removed_since() {
		$entry_id = self::ingest_slack_message( self::create_coverage_with_canonical_url(), true );
		delete_post_meta( $entry_id, Push_Notifications::NOTIFY_META_KEY );

		do_action( Push_Notifications::SEND_HOOK, $entry_id );

		$this->assertSame( [], self::get_sent_notifications() );
	}

	/**
	 * The scheduled send leaves alone an entry OneSignal already notified about.
	 */
	public function test_scheduled_send_skips_an_entry_already_notified_by_onesignal() {
		$entry_id = self::ingest_slack_message( self::create_coverage_with_canonical_url(), true );
		update_post_meta( $entry_id, 'os_notification_id', 'test-notification-id' );

		do_action( Push_Notifications::SEND_HOOK, $entry_id );

		$this->assertSame( [], self::get_sent_notifications() );
	}

	/**
	 * While another request is sending an entry's notification, a second
	 * send for it does nothing and keeps the opt-in.
	 */
	public function test_send_in_progress_elsewhere_blocks_a_second_send() {
		$entry_id = self::create_draft_entry( self::create_coverage_with_canonical_url(), true );
		add_option( Push_Notifications::SEND_LOCK_PREFIX . $entry_id, time(), '', false );

		wp_publish_post( $entry_id );

		$this->assertSame( [], self::get_sent_notifications(), 'Nothing should be sent.' );
		$this->assertNotEmpty( get_post_meta( $entry_id, Push_Notifications::NOTIFY_META_KEY, true ), 'The opt-in should be kept.' );
	}

	/**
	 * A lock left behind by a request that died is ignored once it's old.
	 */
	public function test_stale_send_lock_does_not_block_sending() {
		$entry_id = self::create_draft_entry( self::create_coverage_with_canonical_url(), true );
		add_option( Push_Notifications::SEND_LOCK_PREFIX . $entry_id, time() - Push_Notifications::SEND_LOCK_TTL - 1, '', false );

		wp_publish_post( $entry_id );

		$this->assertCount( 1, self::get_sent_notifications() );
	}

	/**
	 * Publishing during a REST request, as the block editor and the plugin's
	 * admin do, schedules the send instead of losing it.
	 */
	public function test_publishing_during_a_rest_request_schedules_the_send() {
		add_filter( 'newspack_rolling_coverage_defer_notification', '__return_true' );
		$entry_id = self::create_draft_entry( self::create_coverage_with_canonical_url(), true );

		wp_publish_post( $entry_id );

		$this->assertSame( [], self::get_sent_notifications(), 'Nothing should be sent during the request.' );
		$this->assertGreaterThan( time(), wp_next_scheduled( Push_Notifications::SEND_HOOK, [ $entry_id ] ), 'The send should wait for the meta box save that follows.' );
		$this->assertNotEmpty( get_post_meta( $entry_id, Push_Notifications::NOTIFY_META_KEY, true ), 'The opt-in should be kept for the scheduled send.' );
	}
}
