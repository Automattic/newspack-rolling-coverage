<?php
/**
 * WordPress Abilities API integration.
 *
 * Registers the plugin's capabilities as discoverable abilities so they
 * can be discovered and executed by AI agents, automation tools, and
 * other plugins via the WordPress Abilities API (WP 6.9+).
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and manages WordPress Abilities for the Rolling Coverage plugin.
 *
 * On WordPress 6.9+ the Abilities API provides a standardised, machine-
 * readable registry of site capabilities. This class exposes the plugin's
 * AI-powered key takeaways generation as a discoverable ability.
 *
 * On older WordPress versions the class is a safe no-op — the existing
 * REST routes in Post_Type continue to work independently.
 */
class Abilities {

	// Ability name for key takeaways generation.
	const GENERATE_KEY_TAKEAWAYS = 'rolling-coverage/generate-key-takeaways';

	// Category slug for all Rolling Coverage abilities.
	const CATEGORY_SLUG = 'rolling-coverage';

	/**
	 * Initialize hooks.
	 *
	 * Silently no-ops on WordPress versions that do not support the
	 * Abilities API (pre-6.9), so the plugin remains backward-compatible.
	 */
	public static function init() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', [ __CLASS__, 'register_categories' ] );
		add_action( 'wp_abilities_api_init', [ __CLASS__, 'register_abilities' ] );
	}

	/**
	 * Register ability categories.
	 *
	 * Must be called during the wp_abilities_api_categories_init action.
	 */
	public static function register_categories() {
		wp_register_ability_category(
			self::CATEGORY_SLUG,
			[
				'label'       => __( 'Rolling Coverage', 'newspack-rolling-coverage' ),
				'description' => __( 'Abilities for managing and generating content from rolling coverage entries.', 'newspack-rolling-coverage' ),
			]
		);
	}

	/**
	 * Register abilities.
	 *
	 * Must be called during the wp_abilities_api_init action.
	 */
	public static function register_abilities() {
		wp_register_ability(
			self::GENERATE_KEY_TAKEAWAYS,
			[
				'label'               => __( 'Generate Key Takeaways', 'newspack-rolling-coverage' ),
				'description'         => __( 'Generates key takeaways from published entries in a rolling coverage using AI. Pass a coverage_id to specify which coverage to summarize, and optionally max_takeaways (1-10) to control the number of takeaways generated.', 'newspack-rolling-coverage' ),
				'category'            => self::CATEGORY_SLUG,
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'coverage_id'   => [
							'type'        => 'integer',
							'description' => __( 'The ID of the coverage term to generate takeaways from.', 'newspack-rolling-coverage' ),
							'minimum'     => 1,
						],
						'max_takeaways' => [
							'type'        => 'integer',
							'description' => __( 'Maximum number of takeaways to generate.', 'newspack-rolling-coverage' ),
							'minimum'     => 1,
							'maximum'     => 10,
							'default'     => AI_Service::DEFAULT_MAX_TAKEAWAYS,
						],
					],
					'required'             => [ 'coverage_id' ],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'                 => 'object',
					'properties'           => [
						'result' => [
							'type'        => 'string',
							'description' => __( 'The generated key takeaways text.', 'newspack-rolling-coverage' ),
						],
					],
					'required'             => [ 'result' ],
					'additionalProperties' => false,
				],
				'execute_callback'    => [ __CLASS__, 'execute_generate_key_takeaways' ],
				'permission_callback' => [ __CLASS__, 'can_generate_key_takeaways' ],
				'meta'                => [
					'show_in_rest' => true,
					'annotations'  => [
						'readonly'    => false,
						'destructive' => false,
					],
				],
			]
		);
	}

	/**
	 * Execute callback for the generate-key-takeaways ability.
	 *
	 * Delegates to AI_Service::generate_key_takeaways(), which is the
	 * single source of truth for the entry aggregation + prompt building
	 * + AI generation pipeline. The same method is called by the REST
	 * route handler in Post_Type, eliminating code duplication.
	 *
	 * @param array $input Input data for the ability.
	 * @return array|WP_Error
	 */
	public static function execute_generate_key_takeaways( array $input ) {
		if ( empty( $input['coverage_id'] ) ) {
			return new WP_Error(
				'rolling_coverage_missing_coverage_id',
				__( 'A coverage_id is required.', 'newspack-rolling-coverage' ),
				[ 'status' => 400 ]
			);
		}

		$coverage_id   = (int) $input['coverage_id'];
		$max_takeaways = isset( $input['max_takeaways'] )
			? (int) $input['max_takeaways']
			: AI_Service::DEFAULT_MAX_TAKEAWAYS;

		$result = AI_Service::generate_key_takeaways( $coverage_id, $max_takeaways );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return [ 'result' => $result ];
	}

	/**
	 * Permission callback for the generate-key-takeaways ability.
	 *
	 * @param array $input Input data (same as execute callback).
	 * @return bool
	 */
	public static function can_generate_key_takeaways( array $input ): bool {
		return current_user_can( 'edit_posts' );
	}
}
