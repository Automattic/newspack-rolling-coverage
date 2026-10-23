<?php
/**
 * Tests for the New Label block.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Breakout;
use Newspack_Rolling_Coverage\New_Label_Block;
use Newspack_Rolling_Coverage\Post_Type;
use Newspack_Rolling_Coverage\Rolling_Coverage_Block;

/**
 * The block renders hidden and tagged in every entry, for the view script to
 * reveal, and nothing anywhere else.
 */
class Test_New_Label_Block extends Rolling_Coverage_TestCase {

	/**
	 * Whether this test registered the block itself.
	 *
	 * @var bool
	 */
	private $registered_block = false;

	/**
	 * Register the block from its metadata when the build isn't there.
	 */
	public function set_up() {
		parent::set_up();

		if ( ! WP_Block_Type_Registry::get_instance()->is_registered( New_Label_Block::BLOCK_NAME ) ) {
			$metadata = wp_json_file_decode( NEWSPACK_ROLLING_COVERAGE_PLUGIN_DIR . 'src/blocks/new-label/block.json', [ 'associative' => true ] );

			register_block_type(
				New_Label_Block::BLOCK_NAME,
				array_merge(
					New_Label_Block::block_type_args(),
					[
						'attributes' => $metadata['attributes'],
						'supports'   => $metadata['supports'],
					]
				)
			);
			$this->registered_block = true;
		}
	}

	/**
	 * Forget the block if the test registered it.
	 */
	public function tear_down() {
		if ( $this->registered_block ) {
			unregister_block_type( New_Label_Block::BLOCK_NAME );
			$this->registered_block = false;
		}

		parent::tear_down();
	}

	/**
	 * The block's markup.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	private static function markup( array $attributes = [] ): string {
		return '<!-- wp:' . New_Label_Block::BLOCK_NAME . ( $attributes ? ' ' . wp_json_encode( $attributes ) : '' ) . ' /-->';
	}

	/**
	 * An entry rendered with a template holding only the block.
	 *
	 * @param int   $entry_id   Entry post ID.
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	private static function render_in_entry( int $entry_id, array $attributes = [] ): string {
		return Rolling_Coverage_Block::render_entry( get_post( $entry_id ), parse_blocks( self::markup( $attributes ) ) );
	}

	/**
	 * The label's tag in rendered markup, or null when there is none.
	 *
	 * @param string $html Rendered markup.
	 * @return WP_HTML_Tag_Processor|null
	 */
	private static function label_tag( string $html ): ?WP_HTML_Tag_Processor {
		$tags = new WP_HTML_Tag_Processor( $html );

		return $tags->next_tag( [ 'class_name' => 'wp-block-newspack-rolling-coverage-new-label' ] ) ? $tags : null;
	}

	/**
	 * The label renders hidden and tagged as the marker on regular, pinned
	 * and Full story entries.
	 */
	public function test_renders_hidden_with_the_marker_on_every_entry() {
		$coverage_id = self::create_coverage();
		$regular_id  = self::create_entry( $coverage_id );
		$pinned_id   = self::create_entry( $coverage_id );
		$story_id    = self::create_entry( $coverage_id );
		$breakout_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );

		Post_Type::pin_entry( $pinned_id );
		update_post_meta( $story_id, Breakout::ENTRY_BREAKOUT_POST_ID_META, $breakout_id );
		update_post_meta( $breakout_id, Breakout::BREAKOUT_SOURCE_ENTRY_META, $story_id );

		foreach ( [ $regular_id, $pinned_id, $story_id ] as $entry_id ) {
			$tag = self::label_tag( self::render_in_entry( $entry_id ) );

			$this->assertNotNull( $tag, 'The label should render.' );
			$this->assertSame( 'P', $tag->get_tag() );
			$this->assertNotNull( $tag->get_attribute( 'hidden' ), 'It should start hidden.' );
			$this->assertNotNull( $tag->get_attribute( 'data-rc-new-marker' ), 'It should be tagged as the marker.' );
		}
	}

	/**
	 * The block's own text shows as plain text, its entities as typed: the
	 * editor stores a typed "&lt;" as `&amp;lt;`, which must not render as "<".
	 */
	public function test_custom_text_is_escaped() {
		$entry_id = self::create_entry( self::create_coverage() );
		$html     = self::render_in_entry( $entry_id, [ 'content' => 'Fresh &amp; <em>hot</em>' ] );

		$this->assertStringContainsString( '>Fresh &amp; hot</p>', $html );
		$this->assertStringNotContainsString( '<em>', $html );
		$this->assertStringContainsString( '>&amp;lt;b&amp;gt;</p>', self::render_in_entry( $entry_id, [ 'content' => '&amp;lt;b&amp;gt;' ] ) );
	}

	/**
	 * Empty text, or only spaces, no-break spaces included, shows the
	 * translated "New".
	 */
	public function test_empty_text_shows_new() {
		$entry_id = self::create_entry( self::create_coverage() );

		foreach ( [ [], [ 'content' => '' ], [ 'content' => '  ' ], [ 'content' => "\u{00A0}" ], [ 'content' => ' &nbsp; ' ] ] as $attributes ) {
			$this->assertStringContainsString( '>New</p>', self::render_in_entry( $entry_id, $attributes ) );
		}
	}

	/**
	 * Visually hidden renders the label for screen readers only.
	 */
	public function test_visually_hidden_is_for_screen_readers_only() {
		$entry_id = self::create_entry( self::create_coverage() );

		$this->assertTrue( self::label_tag( self::render_in_entry( $entry_id, [ 'visuallyHidden' => true ] ) )->has_class( 'screen-reader-text' ) );
		$this->assertFalse( self::label_tag( self::render_in_entry( $entry_id ) )->has_class( 'screen-reader-text' ) );
	}

	/**
	 * A syndication feed leaves the label out, as no script there can manage it.
	 */
	public function test_is_left_out_of_syndication_feeds() {
		$entry_id = self::create_entry( self::create_coverage() );

		$this->go_to( get_feed_link() );

		$this->assertTrue( is_feed() );
		$this->assertNull( self::label_tag( self::render_in_entry( $entry_id ) ) );
	}

	/**
	 * Outside an entry, the block renders nothing.
	 */
	public function test_renders_nothing_outside_an_entry() {
		$this->assertSame( '', trim( do_blocks( self::markup() ) ) );
	}
}
