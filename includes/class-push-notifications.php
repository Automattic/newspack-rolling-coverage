<?php
/**
 * Sends OneSignal push notifications for newly published coverage entries.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Notifies OneSignal subscribers when an entry is marked to notify and then
 * published.
 *
 * The opt-in is a REST meta field, set from the Push Notifications panel in
 * the entry editor while the entry isn't published.
 * Entries from a chat source such as Slack are opted in when they are
 * saved. Scoped to readers who followed the coverage via the Follow
 * Coverage block. No-ops when OneSignal isn't installed or configured.
 */
class Push_Notifications {

	// Entry post meta: editor opt-in checkbox, unchecked by default. Protected so the Custom Fields box can't write it back.
	const NOTIFY_META_KEY = '_rolling_coverage_notify_on_publish';

	// Read-only REST field: whether the entry's coverage has a canonical URL to send readers to.
	const NOTIFIABLE_FIELD = 'rolling_coverage_has_notifiable_coverage';

	// OneSignal tag key prefix written by the Follow Coverage block; sends are scoped to it via follow_tag().
	const FOLLOW_TAG_PREFIX = 'coverage_';

	// Cron hook that sends an entry's notification outside the request that published it.
	const SEND_HOOK = 'newspack_rolling_coverage_send_notification';

	// Option key prefix for the per-entry lock held while a notification is sent.
	const SEND_LOCK_PREFIX = 'rolling_coverage_notification_lock_';

	// Seconds after which a send lock left behind by a killed process is ignored.
	const SEND_LOCK_TTL = 60;

	// Seconds a send scheduled by a REST publish waits, so a route that
	// writes the opt-in after the status can still change it. The entries
	// route settles its own publishes sooner, in settle_rest_publish().
	const REST_SEND_DELAY = 60;

	/**
	 * Click-through URL for the in-flight send, read by override_notification_fields().
	 *
	 * @var string|null
	 */
	private static $pending_url = null;

	/**
	 * Follow tag key for the in-flight send, read by override_notification_fields()
	 * to scope the send to that coverage's followers.
	 *
	 * @var string|null
	 */
	private static $pending_tag = null;

	/**
	 * Entries this request published, by entry id.
	 *
	 * @var array<int, bool>
	 */
	private static $published_in_request = [];

	/**
	 * Timestamps this request wrote to the send locks it holds, by entry id.
	 *
	 * @var array<int, int>
	 */
	private static $send_lock_stamps = [];

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_meta' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'register_rest_field' ] );
		add_action( 'enqueue_block_editor_assets', [ __CLASS__, 'enqueue_editor_panel' ] );
		add_action( 'transition_post_status', [ __CLASS__, 'maybe_notify' ], 10, 3 );
		add_action( 'rest_after_insert_' . Post_Type::CPT_SLUG, [ __CLASS__, 'settle_rest_publish' ] );
		add_action( 'newspack_rolling_coverage_entry_ingested', [ __CLASS__, 'opt_in_ingested_entry' ] );
		add_action( self::SEND_HOOK, [ __CLASS__, 'send_scheduled' ] );
	}

	/**
	 * Registers the opt-in for the REST API, so the editor panels can set it.
	 */
	public static function register_meta(): void {
		register_post_meta(
			Post_Type::CPT_SLUG,
			self::NOTIFY_META_KEY,
			[
				'type'          => 'boolean',
				'single'        => true,
				'default'       => false,
				'show_in_rest'  => [
					'schema' => [
						'context' => [ 'edit' ],
					],
				],
				'auth_callback' => [ Post_Type::class, 'can_edit_post_meta' ],
			]
		);
	}

	/**
	 * Registers the read-only field the editor panels use to warn that no
	 * notification will be sent.
	 */
	public static function register_rest_field(): void {
		register_rest_field(
			Post_Type::CPT_SLUG,
			self::NOTIFIABLE_FIELD,
			[
				'get_callback' => static function ( array $post ): bool {
					$entry = get_post( $post['id'] );
					return $entry instanceof WP_Post && self::has_notifiable_coverage( $entry );
				},
				'schema'       => [
					'type'     => 'boolean',
					'context'  => [ 'edit' ],
					'readonly' => true,
				],
			]
		);
	}

	/**
	 * Loads the Push Notifications panel on the entry edit screen, when
	 * OneSignal is configured.
	 */
	public static function enqueue_editor_panel(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || Post_Type::CPT_SLUG !== $screen->post_type || ! self::is_onesignal_configured() ) {
			return;
		}

		$asset_file = NEWSPACK_ROLLING_COVERAGE_PLUGIN_DIR . 'dist/entry-editor.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = include $asset_file;

		wp_enqueue_script(
			'newspack-rolling-coverage-entry-editor',
			NEWSPACK_ROLLING_COVERAGE_URL . 'dist/entry-editor.js',
			$asset['dependencies'] ?? [],
			$asset['version'],
			[ 'in_footer' => true ]
		);

		wp_set_script_translations(
			'newspack-rolling-coverage-entry-editor',
			'newspack-rolling-coverage',
			NEWSPACK_ROLLING_COVERAGE_PLUGIN_DIR . 'languages'
		);
	}

	/**
	 * Whether the entry's coverage has a canonical URL set.
	 *
	 * @param WP_Post $post Entry post.
	 * @return bool
	 */
	public static function has_notifiable_coverage( WP_Post $post ): bool {
		$term_ids = wp_get_post_terms( $post->ID, Taxonomy::TAXONOMY_SLUG, [ 'fields' => 'ids' ] );

		if ( is_wp_error( $term_ids ) || empty( $term_ids ) ) {
			return false;
		}

		foreach ( $term_ids as $term_id ) {
			if ( ! empty( get_term_meta( $term_id, Taxonomy::CANONICAL_URL_META_KEY, true ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * OneSignal tag key for a coverage's followers.
	 *
	 * @param int $coverage_id Coverage term id.
	 * @return string
	 */
	public static function follow_tag( int $coverage_id ): string {
		return self::FOLLOW_TAG_PREFIX . $coverage_id;
	}

	/**
	 * Whether OneSignal is installed and running its v3 architecture, which
	 * this integration requires.
	 *
	 * @return bool
	 */
	public static function is_onesignal_v3_active(): bool {
		// This function only exists once OneSignal's own bootstrap decides to
		// load its v3 files.
		return function_exists( 'onesignal_create_notification' );
	}

	/**
	 * Whether OneSignal v3 is active and has its app credentials configured.
	 *
	 * @return bool
	 */
	public static function is_onesignal_configured(): bool {
		if ( ! self::is_onesignal_v3_active() ) {
			return false;
		}

		$settings = get_option( 'OneSignalWPSetting' );

		return ! empty( $settings['app_id'] ) && ! empty( $settings['app_rest_api_key'] );
	}

	/**
	 * Sends a notification when the entry is opted in and published, or
	 * schedules it when publishing through a REST request.
	 *
	 * Skips entries with an existing os_notification_id to avoid duplicate sends.
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Previous post status.
	 * @param WP_Post $post       Post being transitioned.
	 */
	public static function maybe_notify( string $new_status, string $old_status, WP_Post $post ): void {
		if ( Post_Type::CPT_SLUG !== $post->post_type ) {
			return;
		}

		if ( 'publish' !== $new_status ) {
			return;
		}

		// OneSignal sets this meta after a successful send.
		if ( ! empty( get_post_meta( $post->ID, 'os_notification_id', true ) ) ) {
			return;
		}

		if ( 'publish' === $old_status || ! self::is_onesignal_configured() ) {
			return;
		}

		self::$published_in_request[ $post->ID ] = true;

		if ( ! get_post_meta( $post->ID, self::NOTIFY_META_KEY, true ) ) {
			return;
		}

		$is_rest = wp_is_rest_endpoint();

		/**
		 * Filters whether an entry's notification is scheduled instead of
		 * sent during the request that published it. REST requests, which is
		 * how the block editor and Slack publish, always
		 * schedule: OneSignal never sends during one, and the opt-in read here
		 * may be replaced by the request's own.
		 *
		 * @param bool    $defer Whether to schedule the send.
		 * @param WP_Post $post  Entry being published.
		 */
		if ( $is_rest || apply_filters( 'newspack_rolling_coverage_defer_notification', false, $post ) ) {
			self::schedule_send( $post->ID, self::REST_SEND_DELAY );
			return;
		}

		self::send( $post );
	}

	/**
	 * Settles the send for an entry the entries REST route just published,
	 * once the opt-in the request carries is written: maybe_notify() runs
	 * before it is. Sends now when it's ticked, and cancels the send
	 * scheduled from the old opt-in when it isn't.
	 *
	 * @param WP_Post $post Entry saved through the REST API.
	 */
	public static function settle_rest_publish( WP_Post $post ): void {
		if ( ! isset( self::$published_in_request[ $post->ID ] ) ) {
			return;
		}

		unset( self::$published_in_request[ $post->ID ] );
		wp_clear_scheduled_hook( self::SEND_HOOK, [ $post->ID ] );

		if (
			'publish' === $post->post_status
			&& get_post_meta( $post->ID, self::NOTIFY_META_KEY, true )
			&& empty( get_post_meta( $post->ID, 'os_notification_id', true ) )
		) {
			self::schedule_send( $post->ID, 0 );
		}
	}

	/**
	 * Opts an entry created from a chat source in to notify followers, and
	 * schedules the send when it was published straight away.
	 *
	 * @param int $post_id Entry post id.
	 */
	public static function opt_in_ingested_entry( int $post_id ): void {
		if ( ! self::is_onesignal_configured() ) {
			return;
		}

		$post = get_post( $post_id );

		// An entry holding only an image has no words for the notification to carry.
		if ( ! $post instanceof WP_Post || '' === self::build_notification_content( $post ) ) {
			return;
		}

		update_post_meta( $post_id, self::NOTIFY_META_KEY, true );

		if ( 'publish' === get_post_status( $post_id ) ) {
			self::schedule_send( $post_id, 0 );
		}
	}

	/**
	 * Sends a scheduled notification if the entry still wants one.
	 *
	 * @param int $post_id Entry post id.
	 */
	public static function send_scheduled( int $post_id ): void {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || Post_Type::CPT_SLUG !== $post->post_type || 'publish' !== $post->post_status ) {
			return;
		}

		if ( ! empty( get_post_meta( $post->ID, 'os_notification_id', true ) ) ) {
			return;
		}

		if ( ! get_post_meta( $post->ID, self::NOTIFY_META_KEY, true ) || ! self::is_onesignal_configured() ) {
			return;
		}

		self::send( $post );
	}

	/**
	 * Schedules an entry's notification, and starts cron straight away when
	 * it's due now so a live update doesn't wait for the next visit.
	 *
	 * @param int $post_id Entry post id.
	 * @param int $delay   Seconds to wait before sending.
	 */
	private static function schedule_send( int $post_id, int $delay ): void {
		if ( wp_next_scheduled( self::SEND_HOOK, [ $post_id ] ) ) {
			return;
		}

		wp_schedule_single_event( time() + $delay, self::SEND_HOOK, [ $post_id ] );

		if ( 0 === $delay && ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ) {
			spawn_cron();
		}
	}

	/**
	 * Notifies the followers of each of the entry's coverages, then spends the
	 * opt-in. A lock keeps a scheduled send and a publish outside a REST
	 * request, such as a scheduled entry going live, from both sending when
	 * they run at the same time.
	 *
	 * @param WP_Post $post Entry post.
	 */
	private static function send( WP_Post $post ): void {
		$term_ids = wp_get_post_terms( $post->ID, Taxonomy::TAXONOMY_SLUG, [ 'fields' => 'ids' ] );

		if ( is_wp_error( $term_ids ) || empty( $term_ids ) ) {
			return;
		}

		if ( ! self::acquire_send_lock( $post->ID ) ) {
			return;
		}

		try {
			// Another request may have sent, or the editor unchecked, since this
			// request read the entry's meta.
			wp_cache_delete( $post->ID, 'post_meta' );

			if ( ! empty( get_post_meta( $post->ID, 'os_notification_id', true ) ) || ! get_post_meta( $post->ID, self::NOTIFY_META_KEY, true ) ) {
				return;
			}

			$sent = false;

			foreach ( $term_ids as $term_id ) {
				if ( self::notify_coverage_subscribers( (int) $term_id, $post ) ) {
					$sent = true;
				}
			}

			if ( $sent ) {
				delete_post_meta( $post->ID, self::NOTIFY_META_KEY );
			}
		} finally {
			self::release_send_lock( $post->ID );
		}
	}

	/**
	 * Takes the entry's send lock, or a lock left behind by a request that
	 * died. Queries the table directly: an options-cache round trip would let
	 * two requests both take it.
	 *
	 * @param int $post_id Entry post id.
	 * @return bool Whether this request holds the lock.
	 */
	private static function acquire_send_lock( int $post_id ): bool {
		global $wpdb;

		$key = self::SEND_LOCK_PREFIX . $post_id;
		$now = time();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $key, $now ) );

		if ( 1 !== (int) $wpdb->rows_affected ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value < %d", $now, $key, $now - self::SEND_LOCK_TTL ) );

			if ( 1 !== (int) $wpdb->rows_affected ) {
				return false;
			}
		}

		self::$send_lock_stamps[ $post_id ] = $now;

		return true;
	}

	/**
	 * Releases the entry's send lock, unless another request has since
	 * reclaimed it as stale.
	 *
	 * @param int $post_id Entry post id.
	 */
	private static function release_send_lock( int $post_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$wpdb->options,
			[
				'option_name'  => self::SEND_LOCK_PREFIX . $post_id,
				'option_value' => (string) ( self::$send_lock_stamps[ $post_id ] ?? '' ),
			]
		);

		unset( self::$send_lock_stamps[ $post_id ] );
	}

	/**
	 * Sends one OneSignal notification for a single coverage/entry pairing.
	 *
	 * @param int     $coverage_id Coverage term id.
	 * @param WP_Post $entry       Entry post that triggered the notification.
	 * @return bool Whether a notification was actually dispatched.
	 */
	private static function notify_coverage_subscribers( int $coverage_id, WP_Post $entry ): bool {
		$url = self::resolve_coverage_url( $coverage_id, $entry );

		if ( empty( $url ) ) {
			return false;
		}

		$content = self::build_notification_content( $entry );

		// An untitled entry with no words everyone may read has nothing to announce, and nothing members-only may stand in.
		if ( '' === $content && '' === trim( wp_strip_all_tags( $entry->post_title ) ) ) {
			return false;
		}

		$title = self::build_notification_title( $entry, $coverage_id );

		self::$pending_url = $url;
		self::$pending_tag = self::follow_tag( $coverage_id );

		add_filter( 'onesignal_send_notification', [ __CLASS__, 'override_notification_fields' ], 10, 2 );

		onesignal_create_notification(
			$entry,
			[
				'title'   => $title,
				'content' => $content,
			]
		);

		remove_filter( 'onesignal_send_notification', [ __CLASS__, 'override_notification_fields' ], 10 );
		self::$pending_url = null;
		self::$pending_tag = null;

		return true;
	}

	/**
	 * Builds the notification title from the entry's own title, falling back
	 * to the coverage name, then the site name, if the entry is untitled.
	 *
	 * @param WP_Post $entry       Entry post.
	 * @param int     $coverage_id Coverage term id.
	 * @return string Notification title text.
	 */
	private static function build_notification_title( WP_Post $entry, int $coverage_id ): string {
		$entry_title = wp_strip_all_tags( get_the_title( $entry ) );

		if ( '' !== trim( $entry_title ) ) {
			return $entry_title;
		}

		$coverage_name = get_term_field( 'name', $coverage_id, Taxonomy::TAXONOMY_SLUG );

		if ( ! is_wp_error( $coverage_name ) && '' !== trim( (string) $coverage_name ) ) {
			return $coverage_name;
		}

		return get_bloginfo( 'name' );
	}

	/**
	 * Builds the notification body text from a short excerpt of the entry's
	 * written content, leaving out anything members-only or password
	 * protected: a notification reaches every follower.
	 *
	 * @param WP_Post $entry Entry post.
	 * @return string Notification body text.
	 */
	private static function build_notification_content( WP_Post $entry ): string {
		if ( '' !== $entry->post_password ) {
			return '';
		}

		if ( ! has_excerpt( $entry ) ) {
			return Entry_Bindings::public_summary( $entry, 15 );
		}

		return wp_trim_words( html_entity_decode( get_the_excerpt( $entry ), ENT_QUOTES, 'UTF-8' ), 15, '…' );
	}

	/**
	 * Overrides the outgoing notification's click-through URL and audience.
	 *
	 * Replaces the URL so the reader lands on the host page's live coverage,
	 * deep-linked to the entry, rather than the entry's standalone permalink;
	 * and replaces the segment with a tag filter so only followers of this
	 * coverage are notified.
	 *
	 * @param array $fields  Notification payload about to be sent to OneSignal.
	 * @param int   $post_id Entry post id (unused, required by the filter signature).
	 * @return array Modified payload.
	 */
	public static function override_notification_fields( array $fields, int $post_id ): array {
		if ( ! empty( self::$pending_url ) ) {
			$fields['url'] = self::$pending_url;
		}

		if ( ! empty( self::$pending_tag ) ) {
			// A newer update from the same coverage replaces the older one in the browser.
			$fields['web_push_topic'] = self::$pending_tag;
			unset( $fields['included_segments'] );
			$fields['filters'] = [
				[
					'field'    => 'tag',
					'key'      => self::$pending_tag,
					'relation' => '=',
					'value'    => '1',
				],
			];
		}

		return $fields;
	}

	/**
	 * Builds the notification URL from the coverage's canonical URL, using
	 * the deep-link format the social-sharing feature understands
	 * (`?rolling-coverage-entry={slug}#newspack-rolling-coverage-entry-{id}`).
	 *
	 * @param int     $coverage_id Coverage term id.
	 * @param WP_Post $entry       Entry post the notification is about.
	 * @return string Notification URL, or an empty string if no canonical URL is set.
	 */
	private static function resolve_coverage_url( int $coverage_id, WP_Post $entry ): string {
		$canonical_url = get_term_meta( $coverage_id, Taxonomy::CANONICAL_URL_META_KEY, true );

		if ( empty( $canonical_url ) ) {
			return '';
		}

		return Social_Sharing::get_entry_deep_link( $entry, $canonical_url );
	}
}
