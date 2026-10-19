<?php
/**
 * Tests for Slack entry titles in layouts that show no titles.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Post_Type;
use Newspack_Rolling_Coverage\Rolling_Coverage_Block;

/**
 * A Slack entry's title is the first line of the reporter's message, so a
 * layout without titles shows it rather than dropping the line. A title an
 * editor wrote stays hidden there, as those layouts intend.
 */
class Test_Slack_Entry_Title extends Rolling_Coverage_TestCase {

	/**
	 * An entry, from Slack unless told otherwise.
	 *
	 * @param string $title    Entry title.
	 * @param string $content  Entry content.
	 * @param bool   $is_slack Whether the entry came from Slack.
	 * @return WP_Post
	 */
	private static function entry( string $title, string $content, bool $is_slack = true ): WP_Post {
		$entry_id = self::create_entry(
			self::create_coverage(),
			[
				'post_title'   => $title,
				'post_content' => $content,
				'post_excerpt' => '',
			]
		);

		if ( $is_slack ) {
			update_post_meta( $entry_id, Post_Type::META_ENTRY_SOURCE, 'slack' );
		}

		return get_post( $entry_id );
	}

	/**
	 * In a layout whose entries show only their content, as Stream and
	 * Minute do, a Slack entry's title heads its content.
	 */
	public function test_slack_title_heads_the_content_where_no_title_shows() {
		$html = Rolling_Coverage_Block::render_entry( self::entry( 'Polls closed', '<!-- wp:paragraph --><p>Ward 1 is counted.</p><!-- /wp:paragraph -->' ), parse_blocks( '<!-- wp:post-content /-->' ) );

		$this->assertMatchesRegularExpression( '#<h4 class="wp-block-heading">Polls closed</h4>.*Ward 1 is counted\.#s', $html );
	}

	/**
	 * A layout that shows titles shows a Slack entry's title once, in its
	 * own title block.
	 */
	public function test_slack_title_shows_once_where_the_layout_has_a_title() {
		$html = Rolling_Coverage_Block::render_entry( self::entry( 'Polls closed', '<!-- wp:paragraph --><p>Ward 1 is counted.</p><!-- /wp:paragraph -->' ), parse_blocks( '<!-- wp:post-title /--><!-- wp:post-content /-->' ) );

		$this->assertSame( 1, substr_count( $html, 'Polls closed' ) );
	}

	/**
	 * A title an editor wrote stays hidden in a layout without titles.
	 */
	public function test_editor_title_stays_hidden_where_no_title_shows() {
		$html = Rolling_Coverage_Block::render_entry( self::entry( 'Editor headline', '<!-- wp:paragraph --><p>Ward 1 is counted.</p><!-- /wp:paragraph -->', false ), parse_blocks( '<!-- wp:post-content /-->' ) );

		$this->assertStringNotContainsString( 'Editor headline', $html );
	}

	/**
	 * In a layout that shows an excerpt and no title, as Flash does, a Slack
	 * entry with no content shows its title as the excerpt. With a title
	 * block too, the excerpt stays empty, so the title isn't shown twice.
	 */
	public function test_title_only_slack_entry_uses_its_title_as_the_excerpt_where_no_title_shows() {
		$entry = self::entry( 'Polls closed', '' );

		$this->assertStringContainsString( 'Polls closed', Rolling_Coverage_Block::render_entry( $entry, parse_blocks( '<!-- wp:post-date /--><!-- wp:post-excerpt /-->' ) ) );
		$this->assertSame( 1, substr_count( Rolling_Coverage_Block::render_entry( $entry, parse_blocks( '<!-- wp:post-title /--><!-- wp:post-excerpt /-->' ) ), 'Polls closed' ) );
	}

	/**
	 * The title is shown as text: encoded characters stay encoded and a
	 * shortcode in it doesn't run.
	 */
	public function test_slack_title_is_shown_as_text() {
		add_shortcode( 'rcprobe', fn() => 'SHORTCODE_RAN' );

		$html = do_shortcode( Rolling_Coverage_Block::render_entry( self::entry( 'Results &lt;live&gt; &#91;rcprobe&#93;', '' ), parse_blocks( '<!-- wp:post-content /-->' ) ) );

		$this->assertStringContainsString( 'Results &lt;live&gt;', $html );
		$this->assertStringNotContainsString( 'SHORTCODE_RAN', $html );

		remove_shortcode( 'rcprobe' );
	}
}
