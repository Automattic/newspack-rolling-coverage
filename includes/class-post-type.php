<?php
/**
 * Register the rolling_coverage_entry custom post type.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

defined( 'ABSPATH' ) || exit;

/**
 * Handles registration of the rolling_coverage custom post type.
 */
class Post_Type {

	const CPT_SLUG  = 'rolling_cov_entry'; // allows 20 characters max hence the disparity.
	const REST_BASE = 'rolling-coverage-entries';

	// Stores the term ID for the new entry being created, keyed in user meta.
	const NEW_ENTRY_TERM_META_KEY = 'rolling_coverage_new_entry_term';

	// Slack integration post-meta keys.
	const META_ENTRY_SOURCE      = 'rolling_coverage_entry_source';
	const META_SLACK_TS          = 'rolling_coverage_slack_ts';
	const META_SLACK_USER_ID     = 'rolling_coverage_slack_user_id';
	const META_SLACK_AUTHOR_NAME = 'rolling_coverage_slack_author_name';
	const META_SLACK_CHANNEL_ID  = 'rolling_coverage_slack_channel_id';
	const META_SLACK_THREAD_TS   = 'rolling_coverage_slack_thread_ts';

	// Generic chat-source post-meta key: the canonical dedup key for any chat-source adapter
	// Used by Entry_Ingestion_Service::ingest()'s transient mutex and meta_query dedup.
	const META_SOURCE_REF = 'rolling_coverage_source_ref';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register' ] );
		add_action( 'load-post-new.php', [ __CLASS__, 'capture_new_entry_term' ] );
		add_action( 'edit_form_after_title', [ __CLASS__, 'render_hidden_term_field' ] );
		add_action( 'save_post_' . self::CPT_SLUG, [ __CLASS__, 'auto_set_rolling_coverage_term' ], 10, 3 );
	}

	/**
	 * Register the custom post type and other related functionality.
	 */
	public static function register() {
		register_post_type(
			self::CPT_SLUG,
			[
				'labels'              => [
					'name'          => __( 'Entries', 'newspack-rolling-coverage' ),
					'singular_name' => __( 'Entry', 'newspack-rolling-coverage' ),
					'add_new_item'  => __( 'Add Entry', 'newspack-rolling-coverage' ),
					'edit_item'     => __( 'Edit Entry', 'newspack-rolling-coverage' ),
					'new_item'      => __( 'New Entry', 'newspack-rolling-coverage' ),
					'view_item'     => __( 'View Entry', 'newspack-rolling-coverage' ),
					'search_items'  => __( 'Search Entries', 'newspack-rolling-coverage' ),
					'not_found'     => __( 'No entries found.', 'newspack-rolling-coverage' ),
					'all_items'     => __( 'All Entries', 'newspack-rolling-coverage' ),
				],
				'description'         => __( 'Individual entries within a Rolling Coverage.', 'newspack-rolling-coverage' ),
				'public'              => false,
				'publicly_queryable'  => true,
				'exclude_from_search' => false,
				'show_in_rest'        => true,
				'show_ui'             => true,
				'show_in_menu'        => false,
				'show_in_nav_menus'   => false,
				'query_var'           => true,
				'rest_base'           => self::REST_BASE,
				'supports'            => [ 'title', 'editor', 'author', 'revisions', 'custom-fields' ],
				'taxonomies'          => [
					Taxonomy::TAXONOMY_SLUG,
					'category',
					'post_tag',
				],
				'rewrite'             => false,
				'can_export'          => true,
				'delete_with_user'    => false,
			]
		);

		// Post-meta for any chat-source adapter (Slack now, others in future).
		$source_meta = [
			// Entry origin — 'slack' for Slack-ingested entries vs 'wordpress' for admin-created; drives the Source column icon in DataViews.
			self::META_ENTRY_SOURCE      => [
				'type'         => 'string',
				'single'       => true,
				'show_in_rest' => true,
				'default'      => 'wordpress',
			],
			// Original Slack message timestamp; used to deduplicate entries on Slack webhook retries.
			self::META_SLACK_TS          => [
				'type'         => 'string',
				'single'       => true,
				'show_in_rest' => true,
			],
			// Slack user ID of the message author; provenance only (the WP post author is always the bot user).
			self::META_SLACK_USER_ID     => [
				'type'         => 'string',
				'single'       => true,
				'show_in_rest' => true,
			],
			// Display name of the Slack message author; shown via the author-display filter as "Slack: {name}".
			self::META_SLACK_AUTHOR_NAME => [
				'type'         => 'string',
				'single'       => true,
				'show_in_rest' => true,
			],
			// Slack channel ID the entry was ingested from; links an entry back to its source channel.
			self::META_SLACK_CHANNEL_ID  => [
				'type'         => 'string',
				'single'       => true,
				'show_in_rest' => true,
			],
			// Thread timestamp when the message was a threaded reply.
			self::META_SLACK_THREAD_TS   => [
				'type'         => 'string',
				'single'       => true,
				'show_in_rest' => true,
			],

			// Generic source-ref: the platform-agnostic canonical dedup key written with unique=true
			// by Entry_Ingestion_Service::ingest() — REST-exposed for REST-side filter/dedup queries.
			self::META_SOURCE_REF        => [
				'type'         => 'string',
				'single'       => true,
				'show_in_rest' => true,
			],
		];

		foreach ( $source_meta as $meta_key => $meta_args ) {
			register_post_meta( self::CPT_SLUG, $meta_key, $meta_args );
		}
	}

	/**
	 * Detect the rolling_coverage term from the URL when the new post screen loads
	 * and persist it to user meta so the editor form and save handler can access it.
	 *
	 * @return void
	 */
	public static function capture_new_entry_term() {
		global $typenow;

		if ( self::CPT_SLUG !== $typenow ) {
			return;
		}

		if ( empty( $_GET['rolling_coverage'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$term_id = absint( $_GET['rolling_coverage'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $term_id || ! term_exists( $term_id, Taxonomy::TAXONOMY_SLUG ) ) {
			return;
		}

		update_user_meta( get_current_user_id(), self::NEW_ENTRY_TERM_META_KEY, $term_id );
	}

	/**
	 * Render a hidden field in the post editor form carrying the rolling_coverage
	 * term ID. This embeds the term directly in the form so each browser tab
	 * carries its own value — no cross-tab contamination and no TTL expiry.
	 *
	 * @param \WP_Post $post The current post object.
	 */
	public static function render_hidden_term_field( $post ) {
		if ( self::CPT_SLUG !== $post->post_type ) {
			return;
		}

		$term_id = get_user_meta( get_current_user_id(), self::NEW_ENTRY_TERM_META_KEY, true );

		if ( ! $term_id ) {
			return;
		}

		wp_nonce_field( 'rolling_coverage_set_term_' . $post->ID, 'rolling_coverage_nonce' );

		printf(
			'<input type="hidden" name="rolling_coverage" value="%d" />',
			esc_attr( $term_id )
		);
	}

	/**
	 * Automatically set the rolling_coverage term when creating a new entry.
	 *
	 * Reads the term ID from the hidden form field (classic editor) with nonce
	 * verification. Falls back to user meta for REST API saves. Cleans up
	 * user meta after consumption regardless of outcome.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @param bool     $update  Whether this is an update.
	 */
	public static function auto_set_rolling_coverage_term( $post_id, $post, $update ) {
		if ( $update ) {
			return;
		}

		$user_id   = get_current_user_id();
		$meta_key   = self::NEW_ENTRY_TERM_META_KEY;
		$term_id    = 0;
		$from_form  = false;

		// Primary source: hidden form field with nonce verification (classic editor).
		if (
			isset( $_POST['rolling_coverage_nonce'] ) &&
			wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['rolling_coverage_nonce'] ) ), 'rolling_coverage_set_term_' . $post_id )
		) {
			$term_id   = isset( $_POST['rolling_coverage'] ) ? absint( $_POST['rolling_coverage'] ) : 0;
			$from_form = true;
		}

		// Fallback: user meta (REST API saves that bypass the classic editor form).
		if ( ! $from_form || ! $term_id ) {
			$term_id = (int) get_user_meta( $user_id, $meta_key, true );
		}

		// Always clean up user meta so stale values don't persist.
		delete_user_meta( $user_id, $meta_key );

		if ( ! $term_id || ! term_exists( $term_id, Taxonomy::TAXONOMY_SLUG ) ) {
			return;
		}

		wp_set_object_terms( $post_id, $term_id, Taxonomy::TAXONOMY_SLUG );
	}
}
