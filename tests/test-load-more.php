<?php
/**
 * Tests for the Load More block: the feed's Load more button where the
 * layout places it.
 *
 * @package Newspack_Rolling_Coverage
 */

/**
 * A feed set to load older entries with a button shows a Load More block in
 * its layout instead of its own button, lined up with the entries' text.
 */
class Test_Load_More extends Rolling_Coverage_TestCase {

	/**
	 * Register the blocks when the build isn't there.
	 */
	public function set_up() {
		parent::set_up();

		$this->register_feed_block();
		$this->register_load_more_block();
	}

	/**
	 * The block's markup, as the editor saves it.
	 *
	 * @param string $justify The Buttons block's justification; empty for the default.
	 * @return string
	 */
	private static function load_more_markup( string $justify = '' ): string {
		$attributes = '' !== $justify ? ' {"layout":{"type":"flex","justifyContent":"' . $justify . '"}}' : '';

		return '<!-- wp:newspack-rolling-coverage/load-more --><!-- wp:buttons' . $attributes . ' --><div class="wp-block-buttons"><!-- wp:button {"tagName":"button"} --><div class="wp-block-button"><button type="button" class="wp-block-button__link wp-element-button">Load more</button></div><!-- /wp:button --></div><!-- /wp:buttons --><!-- /wp:newspack-rolling-coverage/load-more -->';
	}

	/**
	 * A feed layout: an entry template holding the given markup, then the
	 * given footer.
	 *
	 * @param string $entry  Markup inside the entry group.
	 * @param string $footer Markup below the entries.
	 * @return string The Feed group's markup.
	 */
	private static function feed_markup( string $entry, string $footer ): string {
		return '<!-- wp:group {"className":"newspack-rolling-coverage-feed"} --><div class="wp-block-group newspack-rolling-coverage-feed">'
			. '<!-- wp:group {"className":"newspack-rolling-coverage-regular-entry"} --><div class="wp-block-group newspack-rolling-coverage-regular-entry">' . $entry . '</div><!-- /wp:group -->'
			. $footer
			. '</div><!-- /wp:group -->';
	}

	/**
	 * An entry row: a time column and a text column.
	 *
	 * @param array $row    The Columns block's attributes.
	 * @param array $time   The time column's attributes.
	 * @param array $text   The text column's attributes.
	 * @return string
	 */
	private static function row_markup( array $row, array $time, array $text ): string {
		$json = static fn( array $attributes ) => $attributes ? ' ' . wp_json_encode( $attributes ) : '';

		return '<!-- wp:columns' . $json( $row ) . ' --><div class="wp-block-columns">'
			. '<!-- wp:column' . $json( $time ) . ' --><div class="wp-block-column"><!-- wp:post-date /--></div><!-- /wp:column -->'
			. '<!-- wp:column' . $json( $text ) . ' --><div class="wp-block-column"><!-- wp:post-title /--></div><!-- /wp:column -->'
			. '</div><!-- /wp:columns -->';
	}

	/**
	 * A Rolling Coverage block holding a layout, for a coverage with more
	 * entries than its first page shows.
	 *
	 * @param array  $attributes Block attributes.
	 * @param string $layout     The block's inner markup.
	 * @return string Rendered HTML.
	 */
	private static function render_layout( array $attributes, string $layout ): string {
		$coverage_id = self::create_coverage();
		self::create_entry( $coverage_id );
		self::create_entry( $coverage_id );

		$attributes = array_merge(
			[
				'coverageId'     => $coverage_id,
				'entriesPerPage' => 1,
			],
			$attributes
		);

		return do_blocks( '<!-- wp:newspack-rolling-coverage/rolling-coverage ' . wp_json_encode( $attributes ) . ' -->' . $layout . '<!-- /wp:newspack-rolling-coverage/rolling-coverage -->' );
	}

	/**
	 * The opening tags of the HTML's Load more controls.
	 *
	 * @param string $html Rendered HTML.
	 * @return string[]
	 */
	private static function load_more_tags( string $html ): array {
		preg_match_all( '#<div[^>]*\bnewspack-rolling-coverage-load-more\b[^>]*>#', $html, $tags );

		return $tags[0];
	}

	/**
	 * Where the layout holds a Load More block, the feed shows it, hidden for
	 * the view script, after the entries, and doesn't add its own button.
	 */
	public function test_layout_block_takes_the_place_of_the_feeds_own_button() {
		$html = self::render_layout( [ 'olderEntries' => 'button' ], self::feed_markup( '<!-- wp:post-title /-->', self::load_more_markup() ) );
		$tags = self::load_more_tags( $html );

		$this->assertCount( 1, $tags );
		$this->assertStringContainsString( ' hidden', $tags[0] );
		$this->assertStringContainsString( 'wp-block-newspack-rolling-coverage-load-more', $tags[0] );
		$this->assertStringNotContainsString( 'wp-element-button wp-block-button__link">Load more', $html, 'The feed shouldn\'t add its own button.' );
		$this->assertGreaterThan( strpos( $html, 'class="newspack-rolling-coverage-entries"' ), strpos( $html, $tags[0] ) );
	}

	/**
	 * A feed that loads older entries on scroll, not at all, or caps its
	 * entries drops the block.
	 */
	public function test_block_drops_out_where_older_entries_dont_load_with_a_button() {
		$layout = self::feed_markup( '<!-- wp:post-title /-->', self::load_more_markup() );

		foreach ( [
			[ 'olderEntries' => 'scroll' ],
			[ 'olderEntries' => 'none' ],
			[
				'olderEntries' => 'button',
				'latestOnly'   => true,
			],
		] as $attributes ) {
			$this->assertSame( [], self::load_more_tags( self::render_layout( $attributes, $layout ) ), wp_json_encode( $attributes ) );
		}
	}

	/**
	 * Outside a Rolling Coverage block, no coverage reaches the block, so it
	 * renders nothing.
	 */
	public function test_block_renders_nothing_outside_a_feed() {
		$this->assertSame( '', trim( do_blocks( self::load_more_markup() ) ) );
	}

	/**
	 * The button starts where the entries' text does: past the time column,
	 * the gap, and the text column's border and padding. A row that stays
	 * side by side on phones keeps the inset there too; a row that stacks
	 * keeps it to wider screens.
	 */
	public function test_button_lines_up_with_the_entries_text() {
		$text = [
			'style' => [
				'border'  => [
					'left' => [
						'width' => '1px',
						'style' => 'solid',
					],
				],
				'spacing' => [ 'padding' => [ 'left' => 'var:preset|spacing|40' ] ],
			],
		];
		$side_by_side = self::row_markup(
			[
				'isStackedOnMobile' => false,
				'style'             => [ 'spacing' => [ 'blockGap' => [ 'left' => '0' ] ] ],
			],
			[ 'width' => '9.5rem' ],
			$text
		);
		$stacking     = self::row_markup( [ 'style' => [ 'spacing' => [ 'blockGap' => [ 'left' => 'var:preset|spacing|40' ] ] ] ], [ 'width' => '9.5rem' ], [] );

		$tag = self::load_more_tags( self::render_layout( [ 'olderEntries' => 'button' ], self::feed_markup( $side_by_side, self::load_more_markup() ) ) )[0] ?? '';

		$this->assertStringContainsString( '--newspack-rolling-coverage-load-more-inset:calc(9.5rem + 0px + 1px + var(--wp--preset--spacing--40))', $tag );
		$this->assertStringContainsString( '--newspack-rolling-coverage-load-more-inset-narrow:calc(9.5rem + 0px + 1px + var(--wp--preset--spacing--40))', $tag );

		$tag = self::load_more_tags( self::render_layout( [ 'olderEntries' => 'button' ], self::feed_markup( $stacking, self::load_more_markup() ) ) )[0] ?? '';

		$this->assertStringContainsString( '--newspack-rolling-coverage-load-more-inset:calc(9.5rem + var(--wp--preset--spacing--40))', $tag );
		$this->assertStringNotContainsString( 'inset-narrow', $tag );
	}

	/**
	 * Columns that all set percentage widths shrink to fit the gap between
	 * them, so the inset takes off the first column's share of the overflow.
	 */
	public function test_percentage_columns_take_off_their_share_of_the_gap() {
		$row  = self::row_markup( [ 'style' => [ 'spacing' => [ 'blockGap' => [ 'left' => 'var:preset|spacing|50' ] ] ] ], [ 'width' => '25%' ], [ 'width' => '75%' ] );
		$tag  = self::load_more_tags( self::render_layout( [ 'olderEntries' => 'button' ], self::feed_markup( $row, self::load_more_markup() ) ) )[0] ?? '';
		$gap  = 'var(--wp--preset--spacing--50)';

		$this->assertStringContainsString( '--newspack-rolling-coverage-load-more-inset:calc(25% + ' . $gap . ' - max(0px, 100% - 100% + ' . $gap . ') * 0.25)', $tag );
	}

	/**
	 * A button the layout centers, or sets at the end, keeps its place
	 * rather than starting at the entries' text.
	 */
	public function test_button_set_away_from_the_start_keeps_its_place() {
		$row = self::row_markup( [], [ 'width' => '9.5rem' ], [] );

		foreach ( [ 'center', 'right' ] as $justify ) {
			$tag = self::load_more_tags( self::render_layout( [ 'olderEntries' => 'button' ], self::feed_markup( $row, self::load_more_markup( $justify ) ) ) )[0] ?? '';

			$this->assertNotSame( '', $tag, $justify );
			$this->assertStringNotContainsString( 'load-more-inset', $tag, $justify );
		}
	}
}
