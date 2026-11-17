<?php
/**
 * Scoreboard Gutenberg block: registration, bindings and SSR.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

use WP_Block;
use WP_Block_Type;
use WP_Block_Type_Registry;
use WP_HTML_Tag_Processor;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the `newspack-rolling-coverage/scoreboard` block, which shows a
 * coverage's score. Its inner blocks are core blocks bound to the
 * `newspack-rolling-coverage/score` source, so editors style each part with
 * block settings. The coverage is picked as for the Coverage Status block,
 * and handed to the inner blocks through the feed's coverage context.
 */
class Scoreboard_Block {

	// Block name, as registered in block.json.
	const BLOCK_NAME = 'newspack-rolling-coverage/scoreboard';

	// Block bindings source for the score's parts.
	const SOURCE_NAME = 'newspack-rolling-coverage/score';

	// Attribute naming the part a bound block shows, for the view script.
	const PART_ATTRIBUTE = 'data-rc-score';

	// The parts a block can bind to.
	const KEYS = [ 'team1Name', 'team1Score', 'team2Score', 'team2Name', 'status' ];

	// Seconds between a page's own score checks, as the feed's default poll interval.
	const POLL_INTERVAL = 30;

	/**
	 * Initialize hooks.
	 */
	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register_block' ] );
		add_action( 'init', [ __CLASS__, 'register_source' ] );
		add_action( 'enqueue_block_editor_assets', [ __CLASS__, 'localize_editor_config' ] );
		add_filter( 'render_block_context', [ __CLASS__, 'add_coverage_context' ], 10, 3 );
		add_filter( 'render_block', [ __CLASS__, 'mark_part' ], 10, 2 );
	}

	/**
	 * Registers the block type.
	 */
	public static function register_block(): void {
		register_block_type(
			NEWSPACK_ROLLING_COVERAGE_PLUGIN_DIR . 'dist/blocks/scoreboard',
			[
				'render_callback' => [ __CLASS__, 'render_block' ],
			]
		);
	}

	/**
	 * Registers the bindings source the inner blocks read the score from.
	 */
	public static function register_source(): void {
		register_block_bindings_source(
			self::SOURCE_NAME,
			[
				'label'              => __( 'Liveblog Score', 'newspack-rolling-coverage' ),
				'get_value_callback' => [ __CLASS__, 'get_value' ],
				'uses_context'       => [ Entry_Bindings::COVERAGE_ID_CONTEXT ],
			]
		);
	}

	/**
	 * Gives the editor script what its Liveblog panel and preview need.
	 */
	public static function localize_editor_config(): void {
		$block_type = WP_Block_Type_Registry::get_instance()->get_registered( self::BLOCK_NAME );

		if ( ! $block_type instanceof WP_Block_Type ) {
			return;
		}

		foreach ( $block_type->editor_script_handles as $handle ) {
			wp_localize_script(
				$handle,
				'newspackScoreboardBlock',
				[
					'scoreMetaKey'     => Score::META_KEY,
					'sourceEntryField' => Breakout::BREAKOUT_SOURCE_ENTRY_FIELD,
					'statusMetaKey'    => Taxonomy::STATUS_META_KEY,
					'taxonomySlug'     => Taxonomy::TAXONOMY_SLUG,
				]
			);
		}
	}

	/**
	 * Hands the scoreboard's coverage to its inner blocks, in the context key
	 * a feed sets for the blocks in its layout. Core passes it on down, so
	 * blocks nested in a group inside the scoreboard get it too.
	 *
	 * @param array         $context      Context of the block about to render.
	 * @param array         $parsed_block That block.
	 * @param WP_Block|null $parent_block The block it sits in directly.
	 * @return array
	 */
	public static function add_coverage_context( $context, $parsed_block = [], $parent_block = null ) {
		if ( ! $parent_block instanceof WP_Block || self::BLOCK_NAME !== $parent_block->name ) {
			return $context;
		}

		$context = (array) $context;

		$context[ Entry_Bindings::COVERAGE_ID_CONTEXT ] = self::coverage_id( $parent_block );

		return $context;
	}

	/**
	 * Resolves a bound part of the score of the coverage in context.
	 *
	 * @param array    $source_args Binding args; `key` names the part.
	 * @param WP_Block $block       The bound block.
	 * @return string|null The part, or null without a score.
	 */
	public static function get_value( array $source_args, WP_Block $block ): ?string {
		$score = Score::get( (int) ( $block->context[ Entry_Bindings::COVERAGE_ID_CONTEXT ] ?? 0 ) );
		$key   = (string) ( $source_args['key'] ?? '' );

		if ( ! $score || ! in_array( $key, self::KEYS, true ) ) {
			return null;
		}

		return esc_html( self::part( $score, $key ) );
	}

	/**
	 * One part of a score, as text.
	 *
	 * @param array  $score The score.
	 * @param string $key   One of KEYS.
	 * @return string
	 */
	public static function part( array $score, string $key ): string {
		switch ( $key ) {
			case 'team1Name':
				return $score['teams'][0]['name'];
			case 'team1Score':
				return (string) $score['teams'][0]['score'];
			case 'team2Score':
				return (string) $score['teams'][1]['score'];
			case 'team2Name':
				return $score['teams'][1]['name'];
			case 'status':
				return $score['status'];
		}

		return '';
	}

	/**
	 * Marks a block bound to a part of the score with the part's key, for
	 * the view script to update, and hides one left empty, such as a status
	 * line not set yet, so it takes no space until a poll fills it.
	 *
	 * @param string $block_content Rendered block.
	 * @param array  $block         Parsed block.
	 * @return string
	 */
	public static function mark_part( $block_content, $block ) {
		$binding = $block['attrs']['metadata']['bindings']['content'] ?? null;

		if ( ! is_string( $block_content ) || '' === $block_content || ! is_array( $binding ) || self::SOURCE_NAME !== ( $binding['source'] ?? '' ) ) {
			return $block_content;
		}

		$key  = (string) ( $binding['args']['key'] ?? '' );
		$tags = new WP_HTML_Tag_Processor( $block_content );

		if ( ! in_array( $key, self::KEYS, true ) || ! $tags->next_tag() ) {
			return $block_content;
		}

		$tags->set_attribute( self::PART_ATTRIBUTE, $key );

		if ( '' === trim( wp_strip_all_tags( $block_content ) ) ) {
			$tags->set_attribute( 'hidden', true );
		}

		return $tags->get_updated_html();
	}

	/**
	 * Server-side render callback.
	 *
	 * @param array    $attributes Block attributes.
	 * @param string   $content    The inner blocks, rendered.
	 * @param WP_Block $block      Block instance.
	 * @return string Rendered HTML, or '' when the coverage has no score.
	 */
	public static function render_block( array $attributes, string $content, WP_Block $block ): string {
		$coverage_id = self::coverage_id( $block );
		$score       = Score::get( $coverage_id );

		if ( ! $score ) {
			return '';
		}

		// Lite Site pages and syndication feeds run no script, so they get the score as it is.
		if ( is_feed() || Lite_Feed::is_lite_render() ) {
			return sprintf( '<div %s>%s</div>', get_block_wrapper_attributes(), $content );
		}

		return sprintf(
			'<div %s>%s<div class="screen-reader-text" aria-live="polite" aria-atomic="true" data-rc-score-announce></div></div>',
			get_block_wrapper_attributes(
				[
					'data-coverage-id'   => $coverage_id,
					'data-status'        => Rolling_Coverage_Block::coverage_status( $coverage_id ),
					'data-score-url'     => rest_url( sprintf( '%s/coverages/%d/score', NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE, $coverage_id ) ),
					'data-poll-interval' => max( self::POLL_INTERVAL, Rolling_Coverage_Block::get_min_poll_interval() ),
				]
			),
			$content
		);
	}

	/**
	 * The coverage a scoreboard shows, as Coverage Status picks it.
	 *
	 * @param WP_Block $block Scoreboard block instance.
	 * @return int Coverage term ID, or 0 for none.
	 */
	private static function coverage_id( WP_Block $block ): int {
		return Page_Coverages::coverage_for_block( $block, (int) ( $block->attributes['coverageId'] ?? 0 ) );
	}
}
