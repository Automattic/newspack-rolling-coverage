<?php
/**
 * Tests for how entries sit inside the page.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Rolling_Coverage_Block;

/**
 * Entries render inside the host page's content, so their blocks are kept
 * clear of the page-level styles the theme gives that content.
 */
class Test_Entry_Layout extends Rolling_Coverage_TestCase {

	/**
	 * The date and title stack, as the editor saves it.
	 */
	const STACK_MARKUP = '<!-- wp:group {"style":{"spacing":{"blockGap":"0"}},"layout":{"type":"flex","orientation":"vertical"}} --><div class="wp-block-group"><!-- wp:post-date /--><!-- wp:post-title /--></div><!-- /wp:group -->';

	/**
	 * Render an entry through the given template markup.
	 *
	 * @param string $markup Template markup.
	 * @return string Rendered entry.
	 */
	private static function render( string $markup ): string {
		return Rolling_Coverage_Block::render_entry( get_post( self::create_entry( self::create_coverage() ) ), parse_blocks( $markup ) );
	}

	/**
	 * On the Newspack Theme, which doesn't support block spacing, the stack's
	 * own spacing is written onto it; other themes are left to core.
	 */
	public function test_entry_stack_spacing_is_written_only_on_the_newspack_theme() {
		$this->assertStringNotContainsString( 'gap:0', self::render( self::STACK_MARKUP ), 'Other themes should be left to core.' );

		add_filter( 'template', fn() => 'newspack-theme' );

		$this->assertMatchesRegularExpression( '#<div [^>]*style="[^"]*gap:0"#', self::render( self::STACK_MARKUP ) );
	}

	/**
	 * Entry content drops core's `entry-content` class, which themes style as
	 * the page's own content; post content elsewhere keeps it.
	 */
	public function test_entry_content_drops_the_page_content_class() {
		$markup = '<!-- wp:post-content /-->';

		$this->assertStringNotContainsString( 'entry-content', self::render( $markup ), 'Entry content should drop the class.' );

		$block = parse_blocks( $markup )[0];
		$html  = Rolling_Coverage_Block::drop_entry_content_class(
			'<div class="entry-content wp-block-post-content"></div>',
			$block,
			new WP_Block(
				$block,
				[
					'postId'   => self::factory()->post->create(),
					'postType' => 'post',
				]
			)
		);

		$this->assertStringContainsString( 'entry-content', $html, 'Other post content should keep it.' );
	}

	/**
	 * A post date saved with a fixed date and no binding, as the editor saved
	 * templates created without the binding, shows the entry's own date.
	 */
	public function test_entry_date_ignores_a_fixed_saved_date() {
		$entry_id = self::create_entry( self::create_coverage(), [ 'post_date' => '2026-03-14 09:30:00' ] );
		$html     = Rolling_Coverage_Block::render_entry( get_post( $entry_id ), parse_blocks( '<!-- wp:post-date {"datetime":"2001-01-01T00:00:00.000Z"} /-->' ) );

		$this->assertStringContainsString( 'datetime="' . get_the_date( 'c', $entry_id ) . '"', $html );
	}
}
