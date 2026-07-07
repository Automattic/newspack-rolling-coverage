<?php
/**
 * Register the rolling_coverage taxonomy.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

defined( 'ABSPATH' ) || exit;

/**
 * Handles registration of the rolling_coverage taxonomy and its termmeta.
 */
class Taxonomy {

	// Config constants.
	const TAXONOMY_SLUG   = 'rolling_coverage';
	const REST_BASE       = 'rolling-coverage';

	// Related constants.
	const STATUS_META_KEY = 'rolling_coverage_status';

	// Slack integration term-meta keys.
	const META_SLACK_CHANNEL_ID   = 'rolling_coverage_slack_channel_id';
	const META_SLACK_CHANNEL_NAME = 'rolling_coverage_slack_channel_name';

	// Generic chat-source term-meta keys: link each term to a single chat source.
	// META_SOURCE     : the platform slug (e.g. 'slack', 'beeper', 'whatsapp', 'telegram').
	// META_SOURCE_REF : the platform-native conversation id.
	const META_SOURCE     = 'rolling_coverage_source';
	const META_SOURCE_REF = 'rolling_coverage_source_ref';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register' ] );
		add_action( 'created_' . self::TAXONOMY_SLUG, [ __CLASS__, 'set_term_created_date' ] );
		add_action( 'edited_' . self::TAXONOMY_SLUG, [ __CLASS__, 'update_term_modified_date' ] );
		add_filter( 'update_post_term_count_statuses', [ __CLASS__, 'count_all_visible_statuses' ], 10, 2 );
	}

	/**
	 * Register the taxonomy, termmeta and any other taxonomy-related functionality.
	 */
	public static function register() {
		register_taxonomy(
			self::TAXONOMY_SLUG,
			Post_Type::CPT_SLUG,
			[
				'labels'             => [
					'name'          => __( 'Rolling Coverage', 'newspack-rolling-coverage' ),
					'singular_name' => __( 'Rolling Coverage', 'newspack-rolling-coverage' ),
				],
				'public'             => true,
				'publicly_queryable' => true,
				'show_ui'            => false,
				'show_in_menu'       => false,
				'show_in_nav_menus'  => false,
				'show_in_rest'       => true,
				'rest_base'          => self::REST_BASE,
				'hierarchical'       => false,
				'rewrite'            => false,
				'query_var'          => true,
				'meta_box_cb'        => false,
			]
		);

		$term_meta = [
			// Coverage status — 'active', 'paused', or 'archived' (terminal); controls frontend polling vs static archive.
			self::STATUS_META_KEY         => [
				'show_in_rest' => true,
				'single'       => true,
				'type'         => 'string',
				'default'      => 'active',
			],
			// ISO 8601 timestamp the coverage term was first created (set once via the created_ hook).
			'created_at'                  => [
				'show_in_rest' => true,
				'single'       => true,
				'type'         => 'string',
				'default'      => '',
			],
			// ISO 8601 timestamp of the last edit (updated via the edited_ hook).
			'modified_at'                 => [
				'show_in_rest' => true,
				'single'       => true,
				'type'         => 'string',
				'default'      => '',
			],
			// Slack channel ID linked to this coverage term; the channel→coverage forward link. manage_options-gated via auth_callback.
			self::META_SLACK_CHANNEL_ID   => [
				'show_in_rest'  => true,
				'single'        => true,
				'type'          => 'string',
				'default'       => '',
				'auth_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			],
			// Slack channel display name cached alongside the ID for the DataViews Slack column; manage_options-gated via auth_callback.
			self::META_SLACK_CHANNEL_NAME => [
				'show_in_rest'  => true,
				'single'        => true,
				'type'          => 'string',
				'default'       => '',
				'auth_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			],
			// Generic source platform slug (e.g. 'slack', 'beeper', 'whatsapp', 'telegram'); manage_options-gated via auth_callback.
			self::META_SOURCE             => [
				'show_in_rest'  => true,
				'single'        => true,
				'type'          => 'string',
				'default'       => '',
				'auth_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			],
			// Generic source conversation id (Slack channel id, Beeper chat id, WhatsApp phone_jid, Telegram chat id); manage_options-gated via auth_callback.
			self::META_SOURCE_REF         => [
				'show_in_rest'  => true,
				'single'        => true,
				'type'          => 'string',
				'default'       => '',
				'auth_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			],
		];

		foreach ( $term_meta as $meta_key => $meta_args ) {
			register_term_meta( self::TAXONOMY_SLUG, $meta_key, $meta_args );
		}
	}

	/**
	 * Set created_at when a term is first created.
	 *
	 * @param int $term_id Term ID.
	 */
	public static function set_term_created_date( $term_id ) {
		$created = get_term_meta( $term_id, 'created_at', true );
		if ( empty( $created ) ) {
			$now = gmdate( 'c' );
			update_term_meta( $term_id, 'created_at', $now );
			update_term_meta( $term_id, 'modified_at', $now );
		}
	}

	/**
	 * Update modified_at when a term is edited.
	 *
	 * @param int $term_id Term ID.
	 */
	public static function update_term_modified_date( $term_id ) {
		update_term_meta( $term_id, 'modified_at', gmdate( 'c' ) );
	}

	/**
	 * Include all visible post statuses in term counts for this taxonomy.
	 *
	 * WordPress core's _update_post_term_count() only counts published posts.
	 * This plugin's admin UI shows entries of all statuses (draft, pending,
	 * private, etc.) so the count should reflect that.
	 *
	 * @param string[]     $post_statuses List of post statuses to include in the count.
	 * @param \WP_Taxonomy $taxonomy      Current taxonomy object.
	 * @return string[] Filtered list of post statuses.
	 */
	public static function count_all_visible_statuses( $post_statuses, $taxonomy ) {
		if ( self::TAXONOMY_SLUG !== $taxonomy->name ) {
			return $post_statuses;
		}

		return [ 'publish', 'draft', 'pending', 'future', 'private' ];
	}
}
