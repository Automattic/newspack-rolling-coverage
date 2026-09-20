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
}
