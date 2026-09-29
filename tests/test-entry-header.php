<?php
/**
 * Tests for how an entry's header lines up with the Share button.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Rolling_Coverage_Block;

/**
 * The header row lines Share up with the top of the title, or centres it
 * against the date when the entry has no title.
 */
class Test_Entry_Header extends Rolling_Coverage_TestCase {

	/**
	 * The header: the date and title stacked, with a button opposite, as the
	 * editor saves it.
	 */
	const HEADER_MARKUP = '<!-- wp:group {"layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"space-between","verticalAlignment":"top"}} --><div class="wp-block-group">'
		. '<!-- wp:group {"layout":{"type":"flex","orientation":"vertical"}} --><div class="wp-block-group"><!-- wp:post-date /--><!-- wp:post-title /--></div><!-- /wp:group -->'
		. '<!-- wp:paragraph --><p>Share</p><!-- /wp:paragraph -->'
		. '</div><!-- /wp:group -->';

	/**
	 * The vertical alignment the header row renders with for an entry.
	 *
	 * @param string $title Entry title.
	 * @return string The row's `align-items` value.
	 */
	private static function header_alignment( string $title ): string {
		$entry_id = self::create_entry( self::create_coverage(), [ 'post_title' => $title ] );
		$html     = Rolling_Coverage_Block::render_entry( get_post( $entry_id ), parse_blocks( self::HEADER_MARKUP ) );

		preg_match( '/is-content-justification-space-between[^"]*(wp-container-core-group-is-layout-[0-9a-f]+)/', $html, $container );
		preg_match( '/\.' . preg_quote( $container[1], '/' ) . '\s*\{[^}]*align-items:\s*([a-z-]+)/', wp_style_engine_get_stylesheet_from_context( 'block-supports' ), $alignment );

		return $alignment[1] ?? '';
	}

	/**
	 * A titled entry keeps the row's top alignment; an entry without a title
	 * centres it.
	 */
	public function test_header_centres_only_without_a_title() {
		$this->assertSame( 'flex-start', self::header_alignment( 'A title' ), 'A titled entry should keep the top alignment.' );
		$this->assertSame( 'center', self::header_alignment( '' ), 'An entry without a title should centre the row.' );
		$this->assertStringNotContainsString( 'justify-content:center', wp_style_engine_get_stylesheet_from_context( 'block-supports' ), 'The date and title stack should keep its own alignment.' );
	}
}
