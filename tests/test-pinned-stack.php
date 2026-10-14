<?php
/**
 * Tests for the Pinned group, which stacks a layout's pinned entries.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Breakout;
use Newspack_Rolling_Coverage\Post_Type;
use Newspack_Rolling_Coverage\Rolling_Coverage_Block;

/**
 * A grid Feed whose Pinned group holds the pinned card and a Full Story
 * Entry group renders every pinned entry inside the group, newest first,
 * and every other entry beside it.
 */
class Test_Pinned_Stack extends Rolling_Coverage_TestCase {

	const STACKED = '{"layout":{"columnStart":1,"columnSpan":1,"rowSpan":1}}';

	const ENTRY_STACKED = '{"layout":{"columnStart":1,"columnSpan":1}}';

	const ENTRY_PLACEMENT = '"layout":{"columnStart":2,"columnSpan":2},"@tablet":' . self::ENTRY_STACKED . ',"@mobile":' . self::ENTRY_STACKED . ',';

	const RULE = '"border":{"top":{"color":"#111","width":"3px","style":"solid"}}';

	/**
	 * Forget the theme.json data a test changed.
	 */
	public function tear_down() {
		remove_filter( 'wp_theme_json_data_theme', [ $this, 'with_sticky_support' ] );
		wp_clean_theme_json_cache();

		parent::tear_down();
	}

	/**
	 * A group holding a paragraph that names it, so a test can tell which
	 * group an entry rendered through.
	 *
	 * @param string $class_name The group's class.
	 * @param string $name       The paragraph's text.
	 * @param string $style      The group's style attribute members, as JSON without braces.
	 * @param string $before     Markup ahead of the title.
	 * @return string
	 */
	private static function group( string $class_name, string $name, string $style = '', string $before = '' ): string {
		return '<!-- wp:group {"className":"' . $class_name . '"' . ( '' !== $style ? ',"style":{' . $style . '}' : '' ) . '} -->'
			. '<div class="wp-block-group ' . $class_name . '">'
			. $before
			. '<!-- wp:post-title {"level":4} /-->'
			. '<!-- wp:paragraph --><p>' . $name . '</p><!-- /wp:paragraph -->'
			. '</div><!-- /wp:group -->';
	}

	/**
	 * The Feed, as Split saves it: the Pinned group in the first column,
	 * holding the pinned card and a Full Story Entry group, then the entry
	 * group and the Full Story Entry group across the other two.
	 *
	 * @param string $entry_before Markup ahead of the entry group's title.
	 * @return string
	 */
	private static function feed_markup( string $entry_before = '' ): string {
		$stack_style = '{"layout":{"columnStart":1,"columnSpan":1,"rowSpan":100},"@tablet":' . self::STACKED . ',"@mobile":' . self::STACKED . ',"spacing":{"blockGap":"0"},"position":{"type":"sticky","top":"0px"}}';

		return '<!-- wp:group {"className":"newspack-rolling-coverage-feed","style":{"spacing":{"blockGap":{"top":"0","left":"var:preset|spacing|50"}}},"layout":{"type":"grid","columnCount":3}} --><div class="wp-block-group newspack-rolling-coverage-feed">'
			. '<!-- wp:group {"className":"newspack-rolling-coverage-pinned-stack","style":' . $stack_style . ',"layout":{"type":"flex","orientation":"vertical","justifyContent":"stretch"}} --><div class="wp-block-group newspack-rolling-coverage-pinned-stack">'
			. self::group( 'newspack-rolling-coverage-pinned-card', 'Pinned card', self::RULE )
			. self::group( 'newspack-rolling-coverage-breakout-entry', 'Pinned story', self::RULE )
			. '</div><!-- /wp:group -->'
			. self::group( 'newspack-rolling-coverage-regular-entry', 'Entry', self::ENTRY_PLACEMENT . '"border":{"top":{"color":"#ddd","width":"1px","style":"solid"}}', $entry_before )
			. self::group( 'newspack-rolling-coverage-breakout-entry', 'Column story', self::ENTRY_PLACEMENT . '"border":{"top":{"color":"#ddd","width":"1px","style":"solid"}}' )
			. '</div><!-- /wp:group -->';
	}

	/**
	 * Render a coverage with the Feed.
	 *
	 * @param int    $coverage_id Coverage term ID.
	 * @param array  $attributes  Further block attributes.
	 * @param string $feed        Feed markup; the default Feed when empty.
	 * @return string Rendered block.
	 */
	private static function render( int $coverage_id, array $attributes = [], string $feed = '' ): string {
		$attributes = array_merge( [ 'coverageId' => $coverage_id ], $attributes );
		$block      = parse_blocks( '<!-- wp:newspack-rolling-coverage/rolling-coverage ' . wp_json_encode( $attributes ) . ' -->' . ( '' !== $feed ? $feed : self::feed_markup() ) . '<!-- /wp:newspack-rolling-coverage/rolling-coverage -->' )[0];

		return Rolling_Coverage_Block::render_block( $attributes, '', new WP_Block( $block ) );
	}

	/**
	 * Create entries an hour apart, oldest first.
	 *
	 * @param int $coverage_id Coverage term ID.
	 * @param int $count       How many.
	 * @return int[] Entry IDs, oldest first.
	 */
	private static function create_entries( int $coverage_id, int $count ): array {
		return array_map(
			static fn( int $number ) => self::create_entry(
				$coverage_id,
				[
					'post_title' => 'Update ' . $number,
					'post_date'  => gmdate( 'Y-m-d H:i:s', strtotime( '-' . ( $count + 1 - $number ) . ' hours' ) ),
				]
			),
			range( 1, $count )
		);
	}

	/**
	 * Give an entry a published breakout post.
	 *
	 * @param int $entry_id Entry ID.
	 */
	private static function publish_breakout( int $entry_id ): void {
		$breakout_id = self::factory()->post->create( [ 'post_title' => 'Breakout ' . $entry_id ] );

		update_post_meta( $entry_id, Breakout::ENTRY_BREAKOUT_POST_ID_META, $breakout_id );
		update_post_meta( $breakout_id, Breakout::BREAKOUT_SOURCE_ENTRY_META, $entry_id );
	}

	/**
	 * The Pinned group's opening tag and its articles' entry IDs, and the
	 * entry IDs of the articles after it, in order.
	 *
	 * @param string $html Rendered block.
	 * @return array{stack: string, inside: int[], outside: int[]}
	 */
	private static function placement( string $html ): array {
		$document = new DOMDocument();

		libxml_use_internal_errors( true );
		$document->loadHTML( '<?xml encoding="utf-8"?>' . $html );
		libxml_clear_errors();

		$xpath  = new DOMXPath( $document );
		$class  = "contains(concat(' ', normalize-space(@class), ' '), ' " . Rolling_Coverage_Block::PINNED_STACK_CLASS . " ')";
		$stack  = $xpath->query( '//div[' . $class . ']' )->item( 0 );
		$ids    = static fn( DOMNodeList $articles ) => array_map( static fn( DOMElement $article ) => (int) $article->getAttribute( 'data-entry-id' ), iterator_to_array( $articles ) );

		return [
			'stack'   => $stack instanceof DOMElement ? $stack->getAttribute( 'class' ) : '',
			'inside'  => $ids( $xpath->query( '//div[' . $class . ']/article' ) ),
			'outside' => $ids( $xpath->query( '//article[not(ancestor::div[' . $class . '])]' ) ),
		];
	}

	/**
	 * One entry's article.
	 *
	 * @param string $html     Rendered entries.
	 * @param int    $entry_id Entry ID.
	 * @return string
	 */
	private static function article( string $html, int $entry_id ): string {
		preg_match( '#<article[^>]*data-entry-id="' . $entry_id . '".*?</article>#s', $html, $article );

		return $article[0] ?? '';
	}

	/**
	 * Theme.json data supporting sticky blocks, as the Newspack Block Theme's.
	 *
	 * @param WP_Theme_JSON_Data $theme_json Theme.json data.
	 * @return WP_Theme_JSON_Data
	 */
	public function with_sticky_support( $theme_json ) {
		return $theme_json->update_with(
			[
				'version'  => WP_Theme_JSON::LATEST_SCHEMA,
				'settings' => [ 'position' => [ 'sticky' => true ] ],
			]
		);
	}

	/**
	 * Every pinned entry renders inside the Pinned group, newest first
	 * whatever order they were pinned in, which takes the first column for
	 * the feed's length, and the entry after them heads the column beside
	 * it, with the card's rule.
	 */
	public function test_pinned_entries_stack_in_the_pinned_group() {
		WP_Style_Engine_CSS_Rules_Store::remove_all_stores();
		$coverage_id = self::create_coverage();
		[ $first, $second, $third, $newest ] = self::create_entries( $coverage_id, 4 );
		Post_Type::pin_entry( $first );
		Post_Type::pin_entry( $second );

		$html  = self::render( $coverage_id );
		$parts = self::placement( $html );

		preg_match( '/wp-container-content-[0-9a-f]+/', $parts['stack'], $cell );

		$this->assertSame( [ $second, $first ], $parts['inside'], 'Pinned entries should stack newest first.' );
		$this->assertSame( [ $newest, $third ], $parts['outside'], 'Other entries should follow the group, newest first.' );
		$this->assertNotEmpty( $cell, 'The group should take its placement.' );
		$this->assertStringContainsString( '.' . $cell[0] . '{grid-column:1 / span 1;grid-row:span 100;}', wp_style_engine_get_stylesheet_from_context( 'block-supports', [ 'prettify' => false ] ) );
		$this->assertStringContainsString( 'Pinned card', self::article( $html, $first ) );
		$this->assertStringNotContainsString( 'data-leads-column', $html, 'No single pinned entry leads the column.' );
		$this->assertStringContainsString( 'data-heads-column', self::article( $html, $newest ) );
		$this->assertStringContainsString( 'border-top-width:3px', self::article( $html, $newest ), 'The head of the column should carry the rule the pinned entries start with.' );
		$this->assertStringNotContainsString( 'data-heads-column', self::article( $html, $third ) );
	}

	/**
	 * A pinned entry whose breakout post is published renders through the
	 * Pinned group's Full Story Entry group, an unpinned one through the
	 * template's own.
	 */
	public function test_full_stories_take_the_group_for_where_they_show() {
		$coverage_id = self::create_coverage();
		[ $pinned, $unpinned ] = self::create_entries( $coverage_id, 2 );
		self::publish_breakout( $pinned );
		self::publish_breakout( $unpinned );
		Post_Type::pin_entry( $pinned );

		$html  = self::render( $coverage_id );
		$parts = self::placement( $html );

		$this->assertSame( [ $pinned ], $parts['inside'] );
		$this->assertStringContainsString( 'Pinned story', self::article( $html, $pinned ) );
		$this->assertStringNotContainsString( 'Pinned card', self::article( $html, $pinned ) );
		$this->assertStringContainsString( 'Breakout ' . $pinned, self::article( $html, $pinned ), "The pinned full story should show its breakout post's title." );
		$this->assertStringContainsString( 'Column story', self::article( $html, $unpinned ) );
		$this->assertStringNotContainsString( 'Pinned story', self::article( $html, $unpinned ) );
	}

	/**
	 * With nothing pinned, the group still renders, empty, so an entry pinned
	 * while the page is open has a place to go. A capped feed ignores pinning
	 * and renders no group.
	 */
	public function test_group_renders_empty_and_not_in_a_capped_feed() {
		$coverage_id = self::create_coverage();
		[ $entry ] = self::create_entries( $coverage_id, 2 );

		$parts = self::placement( self::render( $coverage_id ) );

		$this->assertNotSame( '', $parts['stack'] );
		$this->assertSame( [], $parts['inside'] );

		Post_Type::pin_entry( $entry );

		$capped = self::render(
			$coverage_id,
			[
				'latestOnly'  => true,
				'latestCount' => 3,
			]
		);

		$this->assertStringNotContainsString( Rolling_Coverage_Block::PINNED_STACK_CLASS, $capped );
		$this->assertStringContainsString( 'Entry', self::article( $capped, $entry ) );
	}

	/**
	 * A poll renders a pinned entry as the Pinned group renders it, for the
	 * view script to put inside the group, and leaves the group out.
	 */
	public function test_polled_pinned_entry_takes_the_groups_template() {
		$coverage_id = self::create_coverage();
		[ $entry ] = self::create_entries( $coverage_id, 2 );
		Post_Type::pin_entry( $entry );

		preg_match( '/data-template-key="([^"]+)"/', self::render( $coverage_id ), $key );

		$poll = self::dispatch(
			'GET',
			'/coverages/' . $coverage_id . '/entries',
			[
				'cursor'       => '0:2000-01-01 00:00:00',
				'template_key' => $key[1] ?? '',
			]
		)->get_data();
		$html = implode( '', wp_list_pluck( $poll['entries'], 'html' ) );

		$this->assertStringContainsString( 'Pinned card', self::article( $html, $entry ) );
		$this->assertStringNotContainsString( Rolling_Coverage_Block::PINNED_STACK_CLASS, $html );
	}

	/**
	 * On a theme supporting sticky blocks the group sticks, under a stable
	 * class.
	 */
	public function test_group_sticks_where_the_theme_supports_it() {
		$coverage_id = self::create_coverage();
		[ $entry ] = self::create_entries( $coverage_id, 2 );
		Post_Type::pin_entry( $entry );

		$this->assertStringNotContainsString( 'is-position-sticky', self::render( $coverage_id ), 'The classic theme has no sticky support.' );

		add_filter( 'wp_theme_json_data_theme', [ $this, 'with_sticky_support' ] );
		wp_clean_theme_json_cache();

		$parts = self::placement( self::render( $coverage_id ) );

		$this->assertStringContainsString( 'is-position-sticky', $parts['stack'] );
		$this->assertMatchesRegularExpression( '/newspack-rolling-coverage-position-[0-9a-f]+/', $parts['stack'] );
	}

	/**
	 * No ad renders inside the Pinned group or above the column's first
	 * entry because of pinned entries ahead of it, so that entry still heads
	 * the column.
	 */
	public function test_pinned_entries_place_no_ad_beside_the_column() {
		self::enable_ad_placement();

		$coverage_id = self::create_coverage();
		[ $first, $second, $newest ] = self::create_entries( $coverage_id, 3 );
		Post_Type::pin_entry( $first );
		Post_Type::pin_entry( $second );

		$html = self::render(
			$coverage_id,
			[
				'enableAds'   => true,
				'adsInterval' => 1,
			]
		);

		preg_match( '#^(.*?)<article[^>]*data-entry-id="' . $newest . '"#s', $html, $before_newest );

		$this->assertStringNotContainsString( 'newspack_global_ad', $before_newest[1] ?? '', 'No ad should come before the first entry in the column.' );
		$this->assertStringContainsString( 'data-heads-column', self::article( $html, $newest ) );
		$this->assertStringContainsString( 'newspack_global_ad', $html, 'The entry in the column still takes its ad.' );
	}

	/**
	 * Load more takes the entries' placement, so it sits in their columns.
	 */
	public function test_load_more_sits_in_the_entries_columns() {
		$coverage_id = self::create_coverage();
		self::create_entries( $coverage_id, 3 );

		$html = self::render(
			$coverage_id,
			[
				'entriesPerPage' => 1,
				'olderEntries'   => 'button',
			]
		);

		preg_match( '#<div class="([^"]*newspack-rolling-coverage-load-more[^"]*)"#', $html, $load_more );

		$this->assertMatchesRegularExpression( '/wp-container-content-[0-9a-f]+/', $load_more[1] ?? '' );
		$this->assertStringContainsString( Rolling_Coverage_Block::BESIDE_PINNED_CLASS, $load_more[1] ?? '' );
	}

	/**
	 * Load more starts where the entries' text does: past the entry row's
	 * first column, whatever width it sets, and the space between its
	 * columns. A row whose first column sets no width leaves it at the
	 * column's start.
	 */
	public function test_load_more_starts_past_the_entry_rows_first_column() {
		$coverage_id = self::create_coverage();
		self::create_entries( $coverage_id, 3 );

		$row = static fn( string $width ) => '<!-- wp:columns {"style":{"spacing":{"blockGap":{"top":"var:preset|spacing|20","left":"var:preset|spacing|30"}}}} --><div class="wp-block-columns">'
			. '<!-- wp:column ' . ( '' !== $width ? '{"width":"' . $width . '"}' : '' ) . ' --><div class="wp-block-column"><!-- wp:post-date /--></div><!-- /wp:column -->'
			. '<!-- wp:column --><div class="wp-block-column"></div><!-- /wp:column -->'
			. '</div><!-- /wp:columns -->';
		$load_more = static function ( string $html ): string {
			preg_match( '#<div[^>]*\bnewspack-rolling-coverage-load-more\b[^>]*>#', $html, $tag );

			return $tag[0] ?? '';
		};
		$button    = [
			'entriesPerPage' => 1,
			'olderEntries'   => 'button',
		];

		$this->assertStringContainsString( '--newspack-rolling-coverage-load-more-inset:calc(9rem + var(--wp--preset--spacing--30))', $load_more( self::render( $coverage_id, $button, self::feed_markup( $row( '9rem' ) ) ) ) );
		$this->assertStringNotContainsString( 'load-more-inset', $load_more( self::render( $coverage_id, $button, self::feed_markup( $row( '' ) ) ) ) );
	}
}
