<?php
/**
 * Stand-in for the OneSignal plugin's v3 notification API.
 *
 * @package Newspack_Rolling_Coverage
 */

if ( ! function_exists( 'onesignal_create_notification' ) ) {
	/**
	 * Record a notification instead of sending it.
	 *
	 * Mirrors the two things the plugin relies on: the title and content it
	 * passes in, and the `onesignal_send_notification` filter OneSignal runs
	 * over the payload before sending, which starts out addressed to everyone.
	 *
	 * @param WP_Post $post Post the notification is about.
	 * @param array   $args Notification title and content.
	 */
	function onesignal_create_notification( $post, $args = [] ) {
		$payload = apply_filters(
			'onesignal_send_notification',
			[
				'included_segments' => [ 'All' ],
				'url'               => get_permalink( $post ),
				'title'             => $args['title'] ?? '',
				'content'           => $args['content'] ?? '',
			],
			$post->ID
		);

		$GLOBALS['nrc_test_sent_notifications'][] = $payload;
	}
}
