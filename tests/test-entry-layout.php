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

		$this->assertMatchesRegularExpression( '#<div [^>]*style="[^"]*gap:0;?"#', self::render( self::STACK_MARKUP ) );

		$preset = str_replace( '"blockGap":"0"', '"blockGap":"var:preset|spacing|40"', self::STACK_MARKUP );

		$this->assertStringContainsString( 'gap:var(--wp--preset--spacing--40)', self::render( $preset ), 'A preset should resolve to its variable.' );
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

		// An entry's own single page renders it as the main post, outside an entry render.
		$entry_id = self::create_entry( self::create_coverage() );
		$html     = Rolling_Coverage_Block::drop_entry_content_class(
			'<div class="entry-content wp-block-post-content"></div>',
			$block,
			new WP_Block(
				$block,
				[
					'postId'   => $entry_id,
					'postType' => get_post_type( $entry_id ),
				]
			)
		);

		$this->assertStringContainsString( 'entry-content', $html, "An entry's own page should keep it." );
	}

	/**
	 * A post date saved with a fixed date and no binding, as the editor saved
	 * templates created without the binding, shows the entry's own date.
	 */
	public function test_entry_date_ignores_a_fixed_saved_date() {
		$entry_id = self::create_entry(
			self::create_coverage(),
			[
				'post_date'    => '2026-03-14 09:30:00',
				'post_content' => '<!-- wp:post-date {"datetime":"2020-06-01T12:00:00.000Z"} /-->',
			]
		);
		$template = '<!-- wp:post-date {"datetime":"2001-01-01T00:00:00.000Z"} /--><!-- wp:post-content /-->';
		$html     = Rolling_Coverage_Block::render_entry( get_post( $entry_id ), parse_blocks( $template ) );

		$this->assertStringContainsString( 'datetime="' . get_the_date( 'c', $entry_id ) . '"', $html, 'The template date should show the entry\'s own date.' );
		$this->assertStringNotContainsString( '2001-01-01', $html, 'The template\'s saved date should be ignored.' );
		$this->assertStringContainsString( '2020-06-01', $html, 'A custom date written in the entry should stay.' );
	}

	/**
	 * The notice for a deep-linked older entry names an untitled entry by
	 * its first words.
	 */
	public function test_deep_link_notice_names_an_untitled_entry_by_its_first_words() {
		$coverage_id = self::create_coverage();
		self::create_entry(
			$coverage_id,
			[
				'post_title'   => '',
				'post_name'    => 'counting-update',
				'post_content' => "<!-- wp:paragraph -->\n<p>Counting starts at 9pm<br>in the town hall.</p>\n<!-- /wp:paragraph -->",
			]
		);
		set_query_var( \Newspack_Rolling_Coverage\Social_Sharing::ENTRY_QUERY_VAR, 'counting-update' );

		$render = new ReflectionMethod( Rolling_Coverage_Block::class, 'maybe_render_deep_link_cta' );
		$render->setAccessible( true );
		$html = $render->invoke(
			null,
			[],
			new WP_Block(
				[
					'blockName'   => 'newspack-rolling-coverage/rolling-coverage',
					'attrs'       => [ 'coverageId' => $coverage_id ],
					'innerBlocks' => [],
				]
			)
		);

		$this->assertStringContainsString( '<strong>Counting starts at 9pm in the town hall.</strong>', $html );
	}
}
