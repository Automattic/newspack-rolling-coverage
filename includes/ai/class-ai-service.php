<?php
/**
 * AI service abstraction over the WordPress 7.0 AI Client.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

use WP_AI_Client_Prompt_Builder;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper around wp_ai_client_prompt() with feature detection,
 * sensible defaults, and unified WP_Error handling.
 *
 * Also provides higher-level helpers (e.g. generate_key_takeaways) that
 * combine entry aggregation, prompt building, and AI generation.
 */
class AI_Service {

	// Maximum number of entries included when building a prompt.
	const MAX_PROMPT_ENTRIES = 20;

	// Default maximum number of takeaways when none is specified.
	const DEFAULT_MAX_TAKEAWAYS = 5;

	/**
	 * Cached positive availability result. Only true is cached;
	 * false is re-checked on each call since AI provider config
	 * may be loaded later in the request lifecycle.
	 *
	 * @var bool
	 */
	private static $is_available = false;

	/**
	 * Default generation options applied to every call.
	 *
	 * @var array
	 */
	private static $defaults = [
		'temperature' => 0.3,
		'max_tokens'  => 2000,
	];

	/**
	 * No-op to match the plugin's init() convention.
	 */
	public static function init() {}

	/**
	 * Whether the AI Client is available with a text-generation provider.
	 * Safe on WP < 7.0.
	 *
	 * Only caches a positive result — a negative result is re-checked
	 * on each call, since AI provider configuration may be loaded later
	 * in the request lifecycle.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		if ( self::$is_available ) {
			return true;
		}

		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return false;
		}

		if ( ! function_exists( 'wp_supports_ai' ) || ! wp_supports_ai() ) {
			return false;
		}

		if ( true === wp_ai_client_prompt()->is_supported_for_text_generation() ) {
			self::$is_available = true;
			return true;
		}

		return false;
	}

	/**
	 * Return a configured prompt builder for advanced use cases.
	 *
	 * @param string|null $prompt  Optional initial prompt text.
	 * @param array       $options See generate_text().
	 * @return WP_AI_Client_Prompt_Builder|WP_Error
	 */
	public static function prompt( ?string $prompt = null, array $options = [] ) {
		if ( ! self::is_available() ) {
			return self::unavailable_error();
		}

		return self::build_prompt( $prompt ?? '', $options );
	}

	/**
	 * Standard unavailable error.
	 *
	 * @return WP_Error
	 */
	private static function unavailable_error(): WP_Error {
		return new WP_Error(
			'rolling_coverage_ai_unavailable',
			__( 'AI features are not available on this site.', 'newspack-rolling-coverage' )
		);
	}

	/**
	 * Build a configured prompt builder from a prompt and options.
	 *
	 * @param string $prompt  The user prompt.
	 * @param array  $options Generation options.
	 * @return WP_AI_Client_Prompt_Builder
	 */
	private static function build_prompt( string $prompt, array $options ): WP_AI_Client_Prompt_Builder {
		$merged  = array_merge( self::$defaults, $options );
		$builder = wp_ai_client_prompt( $prompt );

		if ( ! empty( $merged['system_instruction'] ) ) {
			$builder->using_system_instruction( $merged['system_instruction'] );
		}

		if ( isset( $merged['temperature'] ) ) {
			$builder->using_temperature( (float) $merged['temperature'] );
		}

		if ( isset( $merged['max_tokens'] ) ) {
			$builder->using_max_tokens( (int) $merged['max_tokens'] );
		}

		if ( ! empty( $merged['model_preferences'] ) && is_array( $merged['model_preferences'] ) ) {
			$builder->using_model_preference( ...$merged['model_preferences'] );
		}

		return $builder;
	}

	/**
	 * Generate plain text from a prompt.
	 *
	 * @param string $prompt  The user prompt.
	 * @param array  $options Generation options.
	 * @return string|WP_Error
	 */
	public static function generate_text( string $prompt, array $options = [] ) {
		if ( ! self::is_available() ) {
			return self::unavailable_error();
		}

		return self::build_prompt( $prompt, $options )->generate_text();
	}

	/**
	 * Generate structured JSON from a prompt.
	 *
	 * @param string $prompt  The user prompt.
	 * @param array  $schema  JSON Schema for the response.
	 * @param array  $options See generate_text().
	 * @return array|WP_Error
	 */
	public static function generate_json( string $prompt, array $schema, array $options = [] ) {
		if ( ! self::is_available() ) {
			return self::unavailable_error();
		}

		$raw = self::build_prompt( $prompt, $options )
			->as_json_response( $schema )
			->generate_text();

		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		$decoded = json_decode( $raw, true );

		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			return new WP_Error(
				'rolling_coverage_ai_json_decode',
				sprintf(
					/* translators: %s: JSON decode error message. */
					__( 'Failed to decode AI response as JSON: %s', 'newspack-rolling-coverage' ),
					json_last_error_msg()
				)
			);
		}

		return $decoded;
	}

	/**
	 * Generate key takeaways for a rolling coverage.
	 *
	 * @param int         $coverage_id             Coverage term ID.
	 * @param int         $max_takeaways           Maximum number of takeaways (1-10).
	 * @param string|null $custom_system_prompt    Optional override for the system prompt.
	 * @param string|null $custom_takeaways_prompt Optional override for the key takeaways prompt.
	 * @return string|WP_Error Generated takeaways text, or WP_Error on failure.
	 */
	public static function generate_key_takeaways(
		int $coverage_id,
		int $max_takeaways = self::DEFAULT_MAX_TAKEAWAYS,
		?string $custom_system_prompt = null,
		?string $custom_takeaways_prompt = null
	) {
		$max_takeaways = max( 1, min( 10, $max_takeaways ) );

		if ( ! term_exists( $coverage_id, Taxonomy::TAXONOMY_SLUG ) ) {
			return new WP_Error(
				'rolling_coverage_coverage_not_found',
				__( 'Coverage not found.', 'newspack-rolling-coverage' ),
				[ 'status' => 404 ]
			);
		}

		if ( ! self::is_available() ) {
			return new WP_Error(
				'rolling_coverage_ai_unavailable',
				__( 'AI features are not available on this site.', 'newspack-rolling-coverage' ),
				[ 'status' => 503 ]
			);
		}

		$entries_content = self::get_entries_for_prompt( $coverage_id );

		if ( is_wp_error( $entries_content ) ) {
			return $entries_content;
		}

		$system_prompt        = $custom_system_prompt ?? AI_Settings::get( 'system_prompt' );
		$key_takeaways_prompt = $custom_takeaways_prompt ?? AI_Settings::get( 'key_takeaways_prompt' );

		if ( empty( $key_takeaways_prompt ) || empty( $system_prompt ) ) {
			return new WP_Error(
				'rolling_coverage_ai_prompts_not_configured',
				__( 'AI prompts are not configured. Configure them in the AI settings page.', 'newspack-rolling-coverage' ),
				[ 'status' => 500 ]
			);
		}

		$prompt = str_replace(
			'{max_takeaways}',
			(string) $max_takeaways,
			$key_takeaways_prompt
		) . "\n\n" . $entries_content;

		return self::generate_text(
			$prompt,
			[
				'system_instruction' => $system_prompt,
				'temperature'        => 0.2,
			]
		);
	}

	/**
	 * Aggregate published entries for a coverage into a prompt-ready string.
	 *
	 * Pinned entries appear first automatically via the global
	 * posts_orderby filter. Only title and excerpt are included.
	 * Maximum MAX_PROMPT_ENTRIES are returned.
	 *
	 * @param int $coverage_id Coverage term ID.
	 * @return string|WP_Error Prompt-ready text, or WP_Error if no entries found.
	 */
	public static function get_entries_for_prompt( int $coverage_id ) {
		$entries = self::query_prompt_entries(
			$coverage_id,
			[
				'posts_per_page' => self::MAX_PROMPT_ENTRIES,
				'orderby'        => 'date',
				'order'          => 'ASC',
			]
		);

		if ( empty( $entries ) ) {
			return new WP_Error(
				'rolling_coverage_no_entries',
				__( 'No published entries found for this coverage.', 'newspack-rolling-coverage' ),
				[ 'status' => 400 ]
			);
		}

		$parts = [];

		foreach ( $entries as $index => $entry ) {
			$num     = $index + 1;
			$date    = get_the_date( 'Y-m-d H:i', $entry );
			$title   = $entry->post_title ? $entry->post_title : __( '(No title)', 'newspack-rolling-coverage' );
			$excerpt = has_excerpt( $entry )
				? wp_strip_all_tags( $entry->post_excerpt )
				: wp_trim_words( wp_strip_all_tags( $entry->post_content ), 55, '…' );

			$parts[] = sprintf( "Entry %d (%s): %s\n%s", $num, $date, $title, $excerpt );
		}

		return implode( "\n\n", $parts );
	}

	/**
	 * Query published entries for a coverage with overridable args.
	 *
	 * @param int   $coverage_id Coverage term ID.
	 * @param array $overrides   WP_Query args to merge on top of defaults.
	 * @return WP_Post[]
	 */
	private static function query_prompt_entries( int $coverage_id, array $overrides ): array {
		return get_posts(
			array_merge(
				[
					'post_type'           => Post_Type::CPT_SLUG,
					'post_status'         => 'publish',
					'no_found_rows'       => true,
					'ignore_sticky_posts' => true,
					'tax_query'           => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
						[
							'taxonomy' => Taxonomy::TAXONOMY_SLUG,
							'field'    => 'term_id',
							'terms'    => $coverage_id,
						],
					],
				],
				$overrides
			)
		);
	}
}
