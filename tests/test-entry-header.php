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
	 * Start each test with no stored layout styles.
	 */
	public function set_up() {
		parent::set_up();
		WP_Style_Engine_CSS_Rules_Store::remove_all_stores();
	}

	/**
	 * Render an entry through the header template.
	 *
	 * @param string $title Entry title.
	 * @return string Rendered entry.
	 */
	private static function render_entry( string $title ): string {
		$entry_id = self::create_entry( self::create_coverage(), [ 'post_title' => $title ] );

		return Rolling_Coverage_Block::render_entry( get_post( $entry_id ), parse_blocks( self::HEADER_MARKUP ) );
	}

	/**
	 * The layout container class of the first group whose classes match.
	 *
	 * @param string $html    Rendered markup.
	 * @param string $pattern Pattern a class on the group matches.
	 * @return string Container class.
	 */
	private function container_class( string $html, string $pattern ): string {
		$this->assertSame( 1, preg_match( '/class="[^"]*' . $pattern . '[^"]*(wp-container-core-group-is-layout-[0-9a-f]+)/', $html, $matches ), 'The group should have a layout container class.' );

		return $matches[1];
	}

	/**
	 * The rules stored for a container class.
	 *
	 * @param string $stylesheet Stored block-support styles.
	 * @param string $class      Container class.
	 * @return string The class's declarations.
	 */
	private static function rules_for( string $stylesheet, string $class ): string {
		preg_match_all( '/\.' . preg_quote( $class, '/' ) . '\s*\{([^}]*)\}/', $stylesheet, $matches );

		return implode( ';', $matches[1] );
	}

	/**
	 * A titled entry keeps the row's top alignment; an entry without a title
	 * centres it, leaving the date and title stack alone.
	 */
	public function test_header_centres_only_without_a_title() {
		$titled   = self::render_entry( 'A title' );
		$untitled = self::render_entry( '' );
		$styles   = wp_style_engine_get_stylesheet_from_context( 'block-supports' );

		$this->assertStringContainsString( 'align-items:flex-start', self::rules_for( $styles, $this->container_class( $titled, 'is-content-justification-space-between' ) ), 'A titled entry should keep the top alignment.' );
		$this->assertStringContainsString( 'align-items:center', self::rules_for( $styles, $this->container_class( $untitled, 'is-content-justification-space-between' ) ), 'An entry without a title should centre the row.' );
		$this->assertStringNotContainsString( 'justify-content:center', self::rules_for( $styles, $this->container_class( $untitled, 'is-vertical' ) ), 'The date and title stack should keep its own alignment.' );
	}

	/**
	 * The first render stores both forms of the header, so entries with and
	 * without a title that arrive later are laid out.
	 */
	public function test_block_stores_both_header_forms() {
		foreach ( [
			'A title' => '',
			''        => 'A title',
		] as $shown => $arriving ) {
			WP_Style_Engine_CSS_Rules_Store::remove_all_stores();

			$coverage_id = self::create_coverage();
			self::create_entry( $coverage_id, [ 'post_title' => $shown ] );

			$attributes = [ 'coverageId' => $coverage_id ];
			$block      = parse_blocks( '<!-- wp:newspack-rolling-coverage/rolling-coverage ' . wp_json_encode( $attributes ) . ' -->' . self::HEADER_MARKUP . '<!-- /wp:newspack-rolling-coverage/rolling-coverage -->' )[0];

			Rolling_Coverage_Block::render_block( $attributes, '', new WP_Block( $block ) );

			$styles = wp_style_engine_get_stylesheet_from_context( 'block-supports' );
			$class  = $this->container_class( self::render_entry( $arriving ), 'is-content-justification-space-between' );

			$this->assertStringContainsString( '' === $arriving ? 'align-items:center' : 'align-items:flex-start', self::rules_for( $styles, $class ), 'The arriving entry\'s header should already be styled.' );
		}
	}

	/**
	 * The editor preview knows which entries have a title.
	 */
	public function test_the_editor_preview_flags_entries_with_a_title() {
		$coverage_id = self::create_coverage();
		$titled      = self::create_entry( $coverage_id, [ 'post_title' => 'A title' ] );
		$untitled    = self::create_entry( $coverage_id, [ 'post_title' => '' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/' . NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE . '/coverages/' . $coverage_id . '/entries-preview' ) );
		$flags    = wp_list_pluck( $response->get_data(), 'hasTitle', 'id' );

		$this->assertTrue( $flags[ $titled ], 'A titled entry should be flagged.' );
		$this->assertFalse( $flags[ $untitled ], 'An entry without a title should not be.' );
	}
}
