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

	// Source related meta key for any chat-source adapter (Slack now, others in future).
	const META_ENTRY_SOURCE = 'rolling_coverage_entry_source';

	// Slack integration post-meta keys.
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
				'supports'            => [ 'title', 'editor', 'author', 'revisions', 'custom-fields', 'thumbnail' ],
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
}
