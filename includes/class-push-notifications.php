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
 * Opt-in checkbox lives in a classic meta box on the entry edit screen,
 * shown only while the entry isn't published, and unchecks itself after a
 * send. Entries from a chat source such as Slack are opted in when they are
 * saved. Scoped to readers who followed the coverage via the Follow
 * Coverage block. No-ops when OneSignal isn't installed or configured.
 */
class Push_Notifications {

	// Entry post meta: editor opt-in checkbox, unchecked by default.
	const NOTIFY_META_KEY = 'rolling_coverage_notify_on_publish';

	// Nonce action/field name for the meta box's checkbox.
	const NONCE_ACTION = 'rolling_coverage_push_notifications_save';
	const NONCE_NAME   = 'rolling_coverage_push_notifications_nonce';

	// OneSignal tag key prefix written by the Follow Coverage block; sends are scoped to it via follow_tag().
	const FOLLOW_TAG_PREFIX = 'coverage_';

	// Cron hook that sends an entry's notification outside the request that published it.
	const SEND_HOOK = 'newspack_rolling_coverage_send_notification';

	// Option key prefix for the per-entry lock held while a notification is sent.
	const SEND_LOCK_PREFIX = 'rolling_coverage_notification_lock_';

	// Seconds after which a send lock left behind by a killed process is ignored.
	const SEND_LOCK_TTL = 60;

	// Seconds a send scheduled by a REST publish waits, so the block editor's
	// meta box save that follows it can change the opt-in first.
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
	 * Timestamps this request wrote to the send locks it holds, by entry id.
	 *
	 * @var array<int, int>
	 */
	private static $send_lock_stamps = [];

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'add_meta_boxes_' . Post_Type::CPT_SLUG, [ __CLASS__, 'add_meta_box' ] );
		add_action( 'save_post_' . Post_Type::CPT_SLUG, [ __CLASS__, 'save_meta' ] );
		add_action( 'transition_post_status', [ __CLASS__, 'maybe_notify' ], 10, 3 );
		add_action( 'newspack_rolling_coverage_entry_ingested', [ __CLASS__, 'opt_in_ingested_entry' ] );
		add_action( self::SEND_HOOK, [ __CLASS__, 'send_scheduled' ] );
	}

	/**
	 * Registers the "notify on publish" meta box.
	 *
	 * Only shown while the entry isn't published, and only when OneSignal is
	 * configured.
	 *
	 * @param WP_Post $post Entry post being edited.
	 */
	public static function add_meta_box( WP_Post $post ): void {
		if ( 'publish' === $post->post_status ) {
			return;
		}

		if ( ! self::is_onesignal_configured() ) {
			return;
		}

		add_meta_box(
			'rolling-coverage-push-notifications',
			__( 'Push Notifications', 'newspack-rolling-coverage' ),
			[ __CLASS__, 'render_meta_box' ],
			Post_Type::CPT_SLUG
		);
	}

	/**
	 * Renders the meta box: an opt-in checkbox, plus a warning if the entry's
	 * coverage doesn't have a canonical URL set.
	 *
	 * @param WP_Post $post Entry post being edited.
	 */
	public static function render_meta_box( WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		$checked = (bool) get_post_meta( $post->ID, self::NOTIFY_META_KEY, true );
		?>
		<?php if ( ! self::has_notifiable_coverage( $post ) ) : ?>
			<div class="notice notice-warning inline" style="margin: 0 0 14px;">
				<p>
					<?php esc_html_e( "This entry's coverage doesn't have a canonical URL set yet. Set one in the coverage's settings, or no notification will be sent.", 'newspack-rolling-coverage' ); ?>
				</p>
			</div>
		<?php endif; ?>
		<label for="<?php echo esc_attr( self::NOTIFY_META_KEY ); ?>">
			<input
				type="checkbox"
				name="<?php echo esc_attr( self::NOTIFY_META_KEY ); ?>"
				id="<?php echo esc_attr( self::NOTIFY_META_KEY ); ?>"
				value="1"
				<?php checked( $checked ); ?>
			/>
			<?php esc_html_e( 'Notify subscribers when this entry publishes', 'newspack-rolling-coverage' ); ?>
		</label>
		<?php
	}

	/**
	 * Whether the entry's coverage has a canonical URL set.
	 *
	 * @param WP_Post $post Entry post.
	 * @return bool
	 */
	private static function has_notifiable_coverage( WP_Post $post ): bool {
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
	 * Persists the editor's notification intent before publish.
	 *
	 * @param int $post_id Entry post id.
	 */
	public static function save_meta( int $post_id ): void {
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) );

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( 'publish' === get_post_status( $post_id ) ) {
			return;
		}

		$checked = isset( $_POST[ self::NOTIFY_META_KEY ] ) && ! empty( $_POST[ self::NOTIFY_META_KEY ] );

		update_post_meta( $post_id, self::NOTIFY_META_KEY, $checked );
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

		if ( ! self::resolve_notify_intent( $post, $old_status ) ) {
			return;
		}

		if ( ! self::is_onesignal_configured() ) {
			return;
		}

		/**
		 * Filters whether an entry's notification is scheduled instead of
		 * sent during the request that published it. OneSignal never sends
		 * during a REST request, which is how the block editor, the plugin's
		 * admin and Slack publish.
		 *
		 * @param bool    $defer Whether to schedule the send.
		 * @param WP_Post $post  Entry being published.
		 */
		if ( apply_filters( 'newspack_rolling_coverage_defer_notification', defined( 'REST_REQUEST' ) && REST_REQUEST, $post ) ) {
			self::schedule_send( $post->ID, self::REST_SEND_DELAY );
			return;
		}

		self::send( $post );
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
	 * opt-in. A lock keeps a scheduled send and an editor's save from both
	 * sending when they run at the same time.
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
	 * Resolves notification intent for this publish.
	 *
	 * Uses the current request when available, otherwise falls back to saved
	 * intent for scheduled or programmatic publishes.
	 *
	 * @param WP_Post $post       Post being transitioned.
	 * @param string  $old_status Previous post status.
	 * @return bool
	 */
	private static function resolve_notify_intent( WP_Post $post, string $old_status ): bool {
		if ( isset( $_POST[ self::NONCE_NAME ] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) );

			if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
				return false;
			}

			if ( ! current_user_can( 'edit_post', $post->ID ) ) {
				return false;
			}

			$has_intent = ! empty( $_POST[ self::NOTIFY_META_KEY ] );

			if ( $has_intent ) {
				update_post_meta( $post->ID, self::NOTIFY_META_KEY, true );
			} else {
				delete_post_meta( $post->ID, self::NOTIFY_META_KEY );
			}

			// The editor's choice here settles it, whatever the REST save before
			// it scheduled.
			wp_clear_scheduled_hook( self::SEND_HOOK, [ $post->ID ] );

			return $has_intent;
		}

		if ( 'publish' === $old_status ) {
			return false;
		}

		return (bool) get_post_meta( $post->ID, self::NOTIFY_META_KEY, true );
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

		$title   = self::build_notification_title( $entry, $coverage_id );
		$content = self::build_notification_content( $entry );

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
	 * written content.
	 *
	 * @param WP_Post $entry Entry post.
	 * @return string Notification body text.
	 */
	private static function build_notification_content( WP_Post $entry ): string {
		if ( ! has_excerpt( $entry ) ) {
			return Post_Type::get_entry_summary( $entry, 15 );
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

		return add_query_arg( Social_Sharing::ENTRY_QUERY_VAR, $entry->post_name, $canonical_url ) . '#' . Rolling_Coverage_Block::MARKUP_PREFIX . '-entry-' . $entry->ID;
	}
}
