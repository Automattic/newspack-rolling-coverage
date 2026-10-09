<?php
/**
 * Tests for entries shown as a card for their published breakout post.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Archive_Mode;
use Newspack_Rolling_Coverage\Breakout;
use Newspack_Rolling_Coverage\Breakout_Label;
use Newspack_Rolling_Coverage\Breakout_Card;
use Newspack_Rolling_Coverage\Lite_Feed;
use Newspack_Rolling_Coverage\Post_Type;
use Newspack_Rolling_Coverage\Rolling_Coverage_Block;
use Newspack_Rolling_Coverage\Taxonomy;

/**
 * Once an entry's breakout post is published, every layout shows the entry
 * as a card for that post: the post's title and summary in place of the
 * entry's own, in the layout's own blocks.
 */
class Test_Breakout_Card extends Rolling_Coverage_TestCase {

	const TITLE_MARKUP = '<!-- wp:post-title {"level":4} /-->';

	const CONTENT_MARKUP = '<!-- wp:post-content {"fontSize":"small"} /-->';

	const FLASH_EXCERPT_MARKUP = '<!-- wp:post-excerpt {"excerptLength":100,"moreText":""} /-->';

	const WIRE_EXCERPT_MARKUP = '<!-- wp:post-excerpt {"excerptLength":15,"moreText":""} /-->';

	const READ_MORE_MARKUP = '<!-- wp:paragraph {"className":"newspack-rolling-coverage-read-more"} --><p class="newspack-rolling-coverage-read-more"><a href="#">Read more</a></p><!-- /wp:paragraph -->';

	const SHARE_MARKUP = '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button {"metadata":{"bindings":{"url":{"source":"newspack-rolling-coverage/entry","args":{"key":"shareUrl"}}}}} --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button">Share</a></div><!-- /wp:button --></div><!-- /wp:buttons -->';

	/**
	 * A header row with the title stacked under the date, Share opposite.
	 */
	const HEADER_MARKUP = '<!-- wp:group {"layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"space-between","verticalAlignment":"top"}} --><div class="wp-block-group">'
		. '<!-- wp:group {"layout":{"type":"flex","orientation":"vertical"}} --><div class="wp-block-group"><!-- wp:post-date /--><!-- wp:post-title /--></div><!-- /wp:group -->'
		. '<!-- wp:paragraph --><p>Share</p><!-- /wp:paragraph -->'
		. '</div><!-- /wp:group -->';

	const ENTRY_TEXT = 'What the entry said.';

	/**
	 * Ticker's headline, which links to its entry.
	 */
	const TICKER_TITLE_MARKUP = '<!-- wp:post-title {"level":4,"isLink":true,"className":"newspack-rolling-coverage-entry-link"} /-->';

	/**
	 * The Full story label on a line of its own.
	 */
	const KICKER = '<span class="use-header-font newspack-rolling-coverage-breakout-label">Full story</span> ';

	/**
	 * The Full story label as an entry's row, or in its Pinned row.
	 */
	const LABEL_ROW = '<p class="use-header-font newspack-rolling-coverage-breakout-label has-small-font-size wp-block-paragraph">Full story</p>';

	/**
	 * The opening of an entry group, with the inner container classic
	 * themes add.
	 */
	const GROUP_OPENING = '[^"]*"[^>]*>(?:<div class="wp-block-group__inner-container[^"]*">)?';

	/**
	 * The separator between the Pinned label and the Full story label.
	 */
	const LABEL_SEPARATOR = '<span class="use-header-font newspack-rolling-coverage-breakout-label-separator has-small-font-size" aria-hidden="true">/</span>';

	/**
	 * The pinned row: the Pinned label in a flex row with no gap of its own.
	 */
	const PINNED_ROW_MARKUP = '<!-- wp:group {"layout":{"type":"flex","flexWrap":"nowrap","verticalAlignment":"center"},"style":{"spacing":{"blockGap":"0"}}} --><div class="wp-block-group">'
		. '<!-- wp:paragraph {"className":"use-header-font newspack-rolling-coverage-pinned-label","fontSize":"small"} --><p class="use-header-font newspack-rolling-coverage-pinned-label has-small-font-size">Pinned</p><!-- /wp:paragraph -->'
		. '</div><!-- /wp:group -->';

	/**
	 * A byline row, as some Stream patterns open their entries with.
	 */
	const BYLINE_MARKUP = '<!-- wp:group {"className":"byline-row","layout":{"type":"flex","flexWrap":"nowrap"}} --><div class="wp-block-group byline-row"><!-- wp:post-date /--></div><!-- /wp:group -->';

	/**
	 * The Full story label leading a title on the same line.
	 */
	const PREFIX = '<span class="use-header-font newspack-rolling-coverage-breakout-label newspack-rolling-coverage-breakout-label--prefix">Full story:</span> ';

	/**
	 * Create an entry and its breakout post, linked both ways.
	 *
	 * @param string $status     The breakout post's status.
	 * @param array  $post_args  The breakout post's factory arguments.
	 * @param array  $entry_args The entry's factory arguments.
	 * @return int[] The entry ID and the breakout post ID.
	 */
	private static function create_breakout( string $status = 'publish', array $post_args = [], array $entry_args = [] ): array {
		$entry_id    = self::create_entry(
			self::create_coverage(),
			array_merge(
				[
					'post_title'   => 'Entry headline',
					'post_content' => '<!-- wp:paragraph --><p>' . self::ENTRY_TEXT . '</p><!-- /wp:paragraph -->',
				],
				$entry_args
			)
		);
		$breakout_id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => $status,
					'post_title'   => 'Post & headline',
					'post_excerpt' => 'What the post sums up.',
					'post_content' => '<!-- wp:paragraph --><p>The whole post.</p><!-- /wp:paragraph -->',
				],
				$post_args
			)
		);
		update_post_meta( $entry_id, Breakout::ENTRY_BREAKOUT_POST_ID_META, $breakout_id );
		update_post_meta( $breakout_id, Breakout::BREAKOUT_SOURCE_ENTRY_META, $entry_id );

		return [ $entry_id, $breakout_id ];
	}

	/**
	 * Render an entry through a template.
	 *
	 * @param int    $entry_id Entry post ID.
	 * @param string $markup   Template markup.
	 * @return string
	 */
	private static function render( int $entry_id, string $markup ): string {
		return Rolling_Coverage_Block::render_entry( get_post( $entry_id ), parse_blocks( $markup ) );
	}

	/**
	 * A titled layout shows the post's title, linked to it, and its summary
	 * in the entry's content, which keeps its own wrapper and font size.
	 * Read more still links to the post.
	 */
	public function test_titled_layout_shows_the_post_title_and_summary() {
		[ $entry_id, $breakout_id ] = self::create_breakout();
		$url                        = get_permalink( $breakout_id );

		$html = self::render( $entry_id, self::TITLE_MARKUP . self::CONTENT_MARKUP . self::READ_MORE_MARKUP );

		$this->assertStringContainsString( '<a href="' . esc_url( $url ) . '">Post &amp; headline</a></h4>', $html );
		$this->assertMatchesRegularExpression( '#<div class="[^"]*wp-block-post-content[^"]*has-small-font-size[^"]*"><p>What the post sums up\.</p></div>#', $html );
		$this->assertStringContainsString( '<a href="' . esc_url( $url ) . '">Read more</a>', $html );
		$this->assertStringNotContainsString( 'Entry headline', $html );
		$this->assertStringNotContainsString( self::ENTRY_TEXT, $html );
		$this->assertFalse( has_filter( 'the_title', [ Breakout_Card::class, 'filter_title' ] ), 'The card filters go once the entry is rendered.' );
		$this->assertFalse( has_filter( 'render_block_core/post-content', [ Breakout_Card::class, 'render_content' ] ) );
	}

	/**
	 * Only the card's Post Title takes the post's title: Share still shares
	 * the entry and is named after it.
	 */
	public function test_share_keeps_the_entry_name() {
		[ $entry_id ] = self::create_breakout();

		$html = self::render( $entry_id, self::TITLE_MARKUP . self::SHARE_MARKUP );

		$this->assertStringContainsString( 'Post &amp; headline</a></h4>', $html );
		$this->assertStringContainsString( 'aria-label="Share: Entry headline"', $html );
	}

	/**
	 * A Stream or Minute style template: a pinned card and an entry group,
	 * neither with a Post Title, the card opening with the pinned row.
	 *
	 * @param string $opening What opens each group before its content, such
	 *                        as Stream's byline row.
	 * @return string
	 */
	private static function untitled_markup( string $opening = '' ): string {
		return '<!-- wp:group {"className":"newspack-rolling-coverage-pinned-card"} --><div class="wp-block-group newspack-rolling-coverage-pinned-card">'
			. self::PINNED_ROW_MARKUP . $opening . self::CONTENT_MARKUP
			. '</div><!-- /wp:group -->'
			. '<!-- wp:group {"className":"newspack-rolling-coverage-regular-entry"} --><div class="wp-block-group newspack-rolling-coverage-regular-entry">'
			. $opening . self::CONTENT_MARKUP
			. '</div><!-- /wp:group -->';
	}

	/**
	 * A layout without a title names the post in the content, linked, then
	 * gives its summary, with the Full story label as a row before it.
	 */
	public function test_untitled_layout_names_the_post_in_the_content() {
		[ $entry_id, $breakout_id ] = self::create_breakout();

		$html = self::render( $entry_id, self::CONTENT_MARKUP );

		$this->assertMatchesRegularExpression( '#<article [^>]*>' . preg_quote( self::LABEL_ROW, '#' ) . '<div class="[^"]*wp-block-post-content[^"]*"><p><strong><a href="' . preg_quote( esc_url( get_permalink( $breakout_id ) ), '#' ) . '">Post &amp; headline</a></strong></p><p>What the post sums up\.</p></div>#', $html );
		$this->assertSame( 1, substr_count( $html, 'newspack-rolling-coverage-breakout-label' ) );
		$this->assertStringNotContainsString( self::ENTRY_TEXT, $html );
	}

	/**
	 * In Stream and Minute, an entry that isn't pinned opens with the Full
	 * story label, above the byline row where there is one, as a row of the
	 * entry group, which spaces it with its gap.
	 */
	public function test_untitled_layouts_open_the_entry_with_the_label() {
		[ $entry_id ] = self::create_breakout();

		$stream = self::render( $entry_id, self::untitled_markup( self::BYLINE_MARKUP ) );
		$minute = self::render( $entry_id, self::untitled_markup() );

		$this->assertMatchesRegularExpression( '#<div class="[^"]*newspack-rolling-coverage-regular-entry' . self::GROUP_OPENING . preg_quote( self::LABEL_ROW, '#' ) . '<div class="[^"]*byline-row#', $stream );
		$this->assertMatchesRegularExpression( '#<div class="[^"]*newspack-rolling-coverage-regular-entry' . self::GROUP_OPENING . preg_quote( self::LABEL_ROW, '#' ) . '<div class="[^"]*wp-block-post-content[^"]*"><p><strong><a href=#', $minute );

		foreach ( [ $stream, $minute ] as $html ) {
			$this->assertSame( 1, substr_count( $html, 'newspack-rolling-coverage-breakout-label' ) );
			$this->assertStringNotContainsString( 'Pinned', $html );
			$this->assertStringNotContainsString( self::LABEL_SEPARATOR, $html );
		}
	}

	/**
	 * In Stream and Minute, a pinned entry's Full story label joins its
	 * Pinned row, after a slash hidden from screen readers, rather than
	 * taking a row of its own.
	 */
	public function test_untitled_layouts_put_a_pinned_label_in_the_pinned_row() {
		[ $entry_id ] = self::create_breakout();
		Post_Type::pin_entry( $entry_id );

		$stream = self::render( $entry_id, self::untitled_markup( self::BYLINE_MARKUP ) );
		$minute = self::render( $entry_id, self::untitled_markup() );

		foreach ( [ $stream, $minute ] as $html ) {
			$this->assertMatchesRegularExpression( '#<div class="[^"]*newspack-rolling-coverage-pinned-card' . self::GROUP_OPENING . '<div class="[^"]*is-layout-flex[^"]*"><p class="[^"]*newspack-rolling-coverage-pinned-label[^"]*">Pinned</p>' . preg_quote( self::LABEL_SEPARATOR . self::LABEL_ROW, '#' ) . '</div>#', $html );
			$this->assertSame( 1, substr_count( $html, 'newspack-rolling-coverage-breakout-label ' ) );
			$this->assertStringContainsString( '<p><strong><a href=', $html );
			$this->assertStringNotContainsString( 'newspack-rolling-coverage-pinned-status', $html );
		}
	}

	/**
	 * The Pinned row wraps once the Full story label joins it, so a long
	 * label can't overflow a narrow column, and the label is added once
	 * however many Pinned labels the template holds.
	 */
	public function test_pinned_row_wraps_and_places_the_label_once() {
		[ $entry_id ] = self::create_breakout();
		Post_Type::pin_entry( $entry_id );

		$html = self::render( $entry_id, str_replace( self::PINNED_ROW_MARKUP, self::PINNED_ROW_MARKUP . self::PINNED_ROW_MARKUP, self::untitled_markup() ) );

		$this->assertSame( 1, substr_count( $html, 'newspack-rolling-coverage-breakout-label ' ) );
		$this->assertSame( 1, substr_count( $html, 'breakout-label-separator' ) );
		$this->assertMatchesRegularExpression( '#<div class="wp-block-group is-layout-flex[^"]*"><p class="[^"]*pinned-label[^"]*">Pinned</p><span#', $html );
		$this->assertSame( 1, substr_count( $html, 'is-nowrap' ) );
	}

	/**
	 * A pinned entry whose template has no Pinned row is announced as
	 * pinned to screen readers, then opens with the Full story label.
	 */
	public function test_pinned_untitled_entry_without_a_pinned_row_opens_with_the_label() {
		[ $entry_id ] = self::create_breakout();
		Post_Type::pin_entry( $entry_id );
		$markup = '<!-- wp:group {"className":"newspack-rolling-coverage-pinned-card"} --><div class="wp-block-group newspack-rolling-coverage-pinned-card">'
			. self::CONTENT_MARKUP
			. '</div><!-- /wp:group -->';

		$html = self::render( $entry_id, $markup );

		$this->assertMatchesRegularExpression( '#<article [^>]*><span class="newspack-rolling-coverage-pinned-status">Pinned</span><div class="[^"]*newspack-rolling-coverage-pinned-card' . self::GROUP_OPENING . preg_quote( self::LABEL_ROW, '#' ) . '<div class="[^"]*wp-block-post-content#', $html );
		$this->assertStringNotContainsString( self::LABEL_SEPARATOR, $html );
	}

	/**
	 * An excerpt-only layout, like Flash, shows the label, the post's title
	 * in bold and the summary.
	 */
	public function test_excerpt_only_layout_shows_the_post_title_and_summary() {
		[ $entry_id ] = self::create_breakout();

		$html = self::render( $entry_id, self::FLASH_EXCERPT_MARKUP );

		$this->assertStringContainsString( 'wp-block-post-excerpt__excerpt">' . self::PREFIX . '<strong>Post &amp; headline</strong> What the post sums up.', $html );
	}

	/**
	 * Without a summary, Flash's line is the label and the title alone, and
	 * the block's length never cuts them.
	 */
	public function test_excerpt_only_layout_without_a_summary_shows_the_title_alone() {
		[ $entry_id ] = self::create_breakout(
			'publish',
			[
				'post_excerpt' => '',
				'post_content' => '',
			],
			[ 'post_content' => '' ]
		);

		$html = self::render( $entry_id, '<!-- wp:post-excerpt {"excerptLength":1,"moreText":""} /-->' );

		$this->assertMatchesRegularExpression( '#wp-block-post-excerpt__excerpt">' . preg_quote( self::PREFIX, '#' ) . '<strong>Post &amp; headline</strong>\s*</p>#', $html );
	}

	/**
	 * Flash's title and summary are escaped.
	 */
	public function test_excerpt_only_layout_escapes_title_and_summary() {
		[ $entry_id ] = self::create_breakout(
			'publish',
			[
				'post_title'   => 'Fish & chips',
				'post_excerpt' => 'Salt & vinegar.',
			]
		);

		$html = self::render( $entry_id, self::FLASH_EXCERPT_MARKUP );

		$this->assertStringContainsString( '<strong>Fish &amp; chips</strong> Salt &amp; vinegar.', $html );
	}

	/**
	 * A titled layout's card carries the Full story label inside the
	 * heading, on a line of its own before the link, so the link is named
	 * by the post's title alone. A layout with a title and an excerpt labels
	 * the title only.
	 */
	public function test_titled_card_labels_the_title() {
		[ $entry_id, $breakout_id ] = self::create_breakout();
		$url                        = preg_quote( esc_url( get_permalink( $breakout_id ) ), '#' );

		$html = self::render( $entry_id, self::TITLE_MARKUP . self::CONTENT_MARKUP . self::READ_MORE_MARKUP );

		$this->assertMatchesRegularExpression( '#<h4 class="[^"]*wp-block-post-title[^"]*">' . preg_quote( self::KICKER, '#' ) . '<a href="' . $url . '">Post &amp; headline</a></h4>#', $html );
		$this->assertSame( 1, substr_count( $html, 'newspack-rolling-coverage-breakout-label' ) );
		$this->assertFalse( has_filter( 'render_block_core/post-title', [ Breakout_Card::class, 'label_title' ] ), 'The label filter goes once the entry is rendered.' );

		$wire = self::render( $entry_id, self::TITLE_MARKUP . self::WIRE_EXCERPT_MARKUP );

		$this->assertStringContainsString( self::KICKER . '<a href="', $wire );
		$this->assertSame( 1, substr_count( $wire, 'newspack-rolling-coverage-breakout-label' ) );
		$this->assertStringContainsString( 'wp-block-post-excerpt__excerpt">What the post sums up.', $wire );
	}

	/**
	 * Ticker's one-line headline and Flash's excerpt lead with the label on
	 * the same line, outside the headline's link, and outside the words
	 * Flash's excerpt counts.
	 */
	public function test_one_line_cards_lead_with_the_label() {
		[ $entry_id, $breakout_id ] = self::create_breakout();

		$ticker = self::render( $entry_id, self::TICKER_TITLE_MARKUP );
		$flash  = self::render( $entry_id, '<!-- wp:post-excerpt {"excerptLength":2,"moreText":""} /-->' );

		$this->assertMatchesRegularExpression( '#<h4 class="[^"]*newspack-rolling-coverage-entry-link[^"]*">' . preg_quote( self::PREFIX, '#' ) . '<a href="' . preg_quote( esc_url( get_permalink( $breakout_id ) ), '#' ) . '"[^>]*>Post &amp; headline</a></h4>#', $ticker );
		$this->assertStringContainsString( 'wp-block-post-excerpt__excerpt">' . self::PREFIX . '<strong>Post &amp; headline</strong> What the&hellip; </p>', $flash );
		$this->assertStringNotContainsString( 'breakout-label">Full story</span>', $ticker . $flash, 'One-liners carry no label on a line of its own.' );
	}

	/**
	 * Flash's excerpt takes the label however many classes its paragraph has.
	 */
	public function test_excerpt_with_extra_classes_takes_the_label() {
		[ $entry_id ] = self::create_breakout();

		$filter = static fn( $block_content ) => str_replace( '<p class="wp-block-post-excerpt__excerpt">', '<p id="x" class="has-small-font-size wp-block-post-excerpt__excerpt extra">', $block_content );
		add_filter( 'render_block_core/post-excerpt', $filter, 5 );
		$html = self::render( $entry_id, '<!-- wp:post-excerpt {"excerptLength":2,"moreText":""} /-->' );
		remove_filter( 'render_block_core/post-excerpt', $filter, 5 );

		$this->assertStringContainsString( 'wp-block-post-excerpt__excerpt extra">' . self::PREFIX . '<strong>Post', $html );
	}

	/**
	 * A pinned card keeps its Pinned label and shows the Full story label
	 * too.
	 */
	public function test_pinned_card_keeps_both_labels() {
		[ $entry_id ] = self::create_breakout();
		Post_Type::pin_entry( $entry_id );
		$markup = '<!-- wp:group {"className":"newspack-rolling-coverage-pinned-card"} --><div class="wp-block-group newspack-rolling-coverage-pinned-card">'
			. '<!-- wp:paragraph {"className":"use-header-font newspack-rolling-coverage-pinned-label"} --><p class="use-header-font newspack-rolling-coverage-pinned-label">Pinned</p><!-- /wp:paragraph -->'
			. self::TITLE_MARKUP . self::CONTENT_MARKUP
			. '</div><!-- /wp:group -->';

		$html = self::render( $entry_id, $markup );

		$this->assertMatchesRegularExpression( '#<p class="[^"]*newspack-rolling-coverage-pinned-label[^"]*">Pinned</p>#', $html );
		$this->assertStringContainsString( self::KICKER . '<a href="', $html );
	}

	/**
	 * Cards show the site's own label, escaped, in every place they show
	 * it, full and lite; a blank one gives way to "Full story".
	 */
	public function test_cards_show_the_site_label_escaped() {
		require_once __DIR__ . '/mocks/class-lite-site.php';
		[ $entry_id ] = self::create_breakout();
		update_option( Breakout_Label::OPTION_KEY, 'Q < A & "B"' );

		$titled   = self::render( $entry_id, self::TITLE_MARKUP );
		$untitled = self::render( $entry_id, self::CONTENT_MARKUP );
		$flash    = self::render( $entry_id, self::FLASH_EXCERPT_MARKUP );
		$ticker   = self::render( $entry_id, self::TICKER_TITLE_MARKUP );
		$lite     = Lite_Feed::render_entry( get_post( $entry_id ), 'initial' );

		$this->assertStringContainsString( 'breakout-label">Q &lt; A &amp; &quot;B&quot;</span> <a href=', $titled );
		$this->assertStringContainsString( 'breakout-label has-small-font-size wp-block-paragraph">Q &lt; A &amp; &quot;B&quot;</p>', $untitled );
		$this->assertStringContainsString( 'breakout-label--prefix">Q &lt; A &amp; &quot;B&quot;:</span> <strong>Post', $flash );
		$this->assertStringContainsString( 'breakout-label--prefix">Q &lt; A &amp; &quot;B&quot;:</span> <a href=', $ticker );
		$this->assertStringContainsString( '<p class="newspack-rolling-coverage-breakout-label">Q &lt; A &amp; &quot;B&quot;</p><h3>', $lite );
		$this->assertStringNotContainsString( 'Full story', $titled . $untitled . $flash . $ticker . $lite );

		update_option( Breakout_Label::OPTION_KEY, '  ' );

		$this->assertStringContainsString( self::KICKER, self::render( $entry_id, self::TITLE_MARKUP ) );
	}

	/**
	 * A breakout post's own label, escaped, takes the site's place in every
	 * place a card shows it, full and lite. Cards for other posts keep the
	 * site's, and the post goes back to the site's once its own is removed.
	 */
	public function test_cards_show_the_post_label_over_the_site_label() {
		require_once __DIR__ . '/mocks/class-lite-site.php';
		[ $entry_id, $breakout_id ] = self::create_breakout();
		[ $other_entry ]            = self::create_breakout();
		update_option( Breakout_Label::OPTION_KEY, 'Site label' );
		update_post_meta( $breakout_id, Breakout_Label::POST_META_KEY, 'Q < A & "B"' );

		$titled   = self::render( $entry_id, self::TITLE_MARKUP );
		$untitled = self::render( $entry_id, self::CONTENT_MARKUP );
		$flash    = self::render( $entry_id, self::FLASH_EXCERPT_MARKUP );
		$ticker   = self::render( $entry_id, self::TICKER_TITLE_MARKUP );
		$lite     = Lite_Feed::render_entry( get_post( $entry_id ), 'initial' );

		$this->assertStringContainsString( 'breakout-label">Q &lt; A &amp; &quot;B&quot;</span> <a href=', $titled );
		$this->assertStringContainsString( 'breakout-label has-small-font-size wp-block-paragraph">Q &lt; A &amp; &quot;B&quot;</p>', $untitled );
		$this->assertStringContainsString( 'breakout-label--prefix">Q &lt; A &amp; &quot;B&quot;:</span> <strong>Post', $flash );
		$this->assertStringContainsString( 'breakout-label--prefix">Q &lt; A &amp; &quot;B&quot;:</span> <a href=', $ticker );
		$this->assertStringContainsString( '<p class="newspack-rolling-coverage-breakout-label">Q &lt; A &amp; &quot;B&quot;</p><h3>', $lite );
		$this->assertStringNotContainsString( 'Site label', $titled . $untitled . $flash . $ticker . $lite );

		$this->assertStringContainsString( 'breakout-label">Site label</span> <a href=', self::render( $other_entry, self::TITLE_MARKUP ), 'Another post keeps the site label.' );
		$this->assertStringContainsString( '<p class="newspack-rolling-coverage-breakout-label">Site label</p><h3>', Lite_Feed::render_entry( get_post( $other_entry ), 'initial' ) );

		delete_post_meta( $breakout_id, Breakout_Label::POST_META_KEY );

		$this->assertStringContainsString( 'breakout-label">Site label</span> <a href=', self::render( $entry_id, self::TITLE_MARKUP ) );
		$this->assertStringContainsString( 'breakout-label--prefix">Site label:</span> <strong>Post', self::render( $entry_id, self::FLASH_EXCERPT_MARKUP ) );

		delete_option( Breakout_Label::OPTION_KEY );

		$this->assertStringContainsString( self::KICKER, self::render( $entry_id, self::TITLE_MARKUP ) );
	}

	/**
	 * Only a card carries the label: not an entry whose post is a draft,
	 * not an entry without a breakout post, and not a card whose title
	 * renders nothing. A lite card without a title has none either.
	 */
	public function test_label_is_only_on_cards_with_a_title() {
		require_once __DIR__ . '/mocks/class-lite-site.php';
		$markup = self::TITLE_MARKUP . self::CONTENT_MARKUP . self::FLASH_EXCERPT_MARKUP . self::TICKER_TITLE_MARKUP;

		[ $draft_entry ] = self::create_breakout( 'draft' );
		$plain_entry     = self::create_entry( self::create_coverage(), [ 'post_title' => 'Plain entry' ] );

		[ $untitled_entry ] = self::create_breakout(
			'publish',
			[ 'post_title' => '' ],
			[
				'post_title'   => '',
				'post_content' => '',
			]
		);

		$this->assertStringNotContainsString( 'breakout-label', self::render( $draft_entry, $markup ) );
		$this->assertStringNotContainsString( 'breakout-label', self::render( $plain_entry, $markup ) );
		$this->assertStringNotContainsString( 'breakout-label', Lite_Feed::render_entry( get_post( $draft_entry ), 'initial' ) );
		$this->assertStringNotContainsString( 'breakout-label', self::render( $untitled_entry, self::TITLE_MARKUP . self::CONTENT_MARKUP . self::FLASH_EXCERPT_MARKUP ) );
		$this->assertStringNotContainsString( 'breakout-label', Lite_Feed::render_entry( get_post( $untitled_entry ), 'initial' ) );

		[ $card_entry ] = self::create_breakout();
		$meanwhile      = '';

		Breakout_Card::render(
			$card_entry,
			Breakout_Card::for_entry( $card_entry ),
			true,
			static function () use ( $plain_entry, &$meanwhile ) {
				$meanwhile = self::render( $plain_entry, self::TITLE_MARKUP );

				return '';
			}
		);

		$this->assertStringContainsString( 'Plain entry</h4>', $meanwhile );
		$this->assertStringNotContainsString( 'breakout-label', $meanwhile, 'An entry rendering while a card renders keeps its title unlabeled.' );
	}

	/**
	 * A layout with a title and an excerpt, like Wire, shows the summary in
	 * the excerpt, cut to the block's own length.
	 */
	public function test_titled_excerpt_layout_shows_the_summary() {
		[ $entry_id ] = self::create_breakout( 'publish', [ 'post_excerpt' => 'One two three four five six seven eight nine ten eleven twelve thirteen fourteen fifteen sixteen seventeen.' ] );

		$html = self::render( $entry_id, self::TITLE_MARKUP . self::WIRE_EXCERPT_MARKUP );

		$this->assertStringContainsString( 'Post &amp; headline</a></h4>', $html );
		$this->assertStringContainsString( 'wp-block-post-excerpt__excerpt">One two three four five six seven eight nine ten eleven twelve thirteen fourteen fifteen&hellip;', $html );
	}

	/**
	 * A post without a hand-written excerpt is summed up by its opening
	 * words, at the site's excerpt length, ending in an ellipsis rather than
	 * a theme's "Continue reading" link.
	 */
	public function test_summary_is_the_opening_words_at_the_site_length() {
		[ $entry_id ] = self::create_breakout(
			'publish',
			[
				'post_excerpt' => '',
				'post_content' => '<!-- wp:paragraph --><p>Alpha beta gamma delta epsilon zeta eta theta.</p><!-- /wp:paragraph -->',
			]
		);
		add_filter( 'excerpt_length', fn() => 4, 999 );
		add_filter( 'excerpt_more', fn() => ' <a href="#">Continue reading</a>' );

		$html = self::render( $entry_id, self::TITLE_MARKUP . self::CONTENT_MARKUP );

		$this->assertStringContainsString( '<p>Alpha beta gamma delta…</p>', $html );
		$this->assertStringNotContainsString( 'Continue reading', $html );
	}

	/**
	 * The summary holds only what every reader may see, whoever renders
	 * first: a members-only block never reaches it, even when a member's
	 * render would show that block, and a block shown to everyone stays in
	 * it even when hidden from the reader rendering first.
	 */
	public function test_summary_holds_only_what_every_reader_sees() {
		$this->use_block_visibility_stub();
		[ $entry_id ] = self::create_breakout(
			'publish',
			[
				'post_excerpt' => '',
				'post_content' => '<!-- wp:paragraph --><p>Everyone reads this.</p><!-- /wp:paragraph -->'
					. self::members_only_paragraph( 'Members read this.' )
					. '<!-- wp:paragraph --><p>Visitors read this too.</p><!-- /wp:paragraph -->',
			]
		);
		$as_member = static fn( $content ) => is_string( $content ) && str_contains( $content, 'Visitors read' ) ? '' : $content;
		add_filter( 'render_block_core/paragraph', $as_member );

		$first = self::render( $entry_id, self::TITLE_MARKUP . self::CONTENT_MARKUP );

		remove_filter( 'render_block_core/paragraph', $as_member );
		$later = self::render( $entry_id, self::TITLE_MARKUP . self::CONTENT_MARKUP );

		foreach ( [ $first, $later ] as $html ) {
			$this->assertStringContainsString( '<p>Everyone reads this. Visitors read this too.</p></div>', $html );
			$this->assertStringNotContainsString( 'Members read', $html );
		}
	}

	/**
	 * The summary is worked out only for a template that shows it, and only
	 * once while the post is unchanged and the excerpt length stays the
	 * same. The entry's own content never renders, and its Post Content
	 * keeps its layout classes.
	 */
	public function test_summary_is_worked_out_lazily_and_cached() {
		global $wpdb;

		[ $entry_id, $breakout_id ] = self::create_breakout(
			'publish',
			[
				'post_excerpt' => '',
				'post_content' => '<!-- wp:paragraph --><p>The whole post.</p><!-- /wp:paragraph -->',
			]
		);
		$asked = 0;
		add_filter(
			'excerpt_length',
			static function ( $length ) use ( &$asked ) {
				++$asked;
				return $length;
			}
		);

		self::render( $entry_id, self::TITLE_MARKUP );

		$this->assertSame( 0, $asked, 'A template without content or excerpt works out no summary.' );

		$html = self::render( $entry_id, self::TITLE_MARKUP . self::CONTENT_MARKUP . self::WIRE_EXCERPT_MARKUP );

		$this->assertMatchesRegularExpression( '#<div class="[^"]*wp-block-post-content[^"]*is-layout-flow[^"]*"><p>The whole post.</p></div>#', $html );

		$wpdb->update( $wpdb->posts, [ 'post_content' => '<!-- wp:paragraph --><p>Rewritten without a new modified time.</p><!-- /wp:paragraph -->' ], [ 'ID' => $breakout_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		clean_post_cache( $breakout_id );

		$html = self::render( $entry_id, self::TITLE_MARKUP . self::CONTENT_MARKUP );

		$this->assertStringContainsString( '<p>The whole post.</p></div>', $html, 'The summary is cached.' );

		add_filter( 'excerpt_length', fn() => 2, 999 );
		$html = self::render( $entry_id, self::TITLE_MARKUP . self::CONTENT_MARKUP );

		$this->assertStringContainsString( '<p>Rewritten without…</p></div>', $html, 'Another excerpt length gets its own summary.' );
	}

	/**
	 * The entry's own blocks never render under a card, for its content or
	 * for an excerpt built from them.
	 */
	public function test_the_entry_content_never_renders() {
		[ $entry_id ] = self::create_breakout( 'publish', [], [ 'post_excerpt' => '' ] );
		$rendered     = '';
		add_filter(
			'render_block_core/paragraph',
			static function ( $content ) use ( &$rendered ) {
				$rendered .= $content;
				return $content;
			}
		);

		self::render( $entry_id, self::TITLE_MARKUP . self::CONTENT_MARKUP . self::WIRE_EXCERPT_MARKUP );
		self::render( $entry_id, self::FLASH_EXCERPT_MARKUP );

		$this->assertStringNotContainsString( self::ENTRY_TEXT, $rendered );
	}

	/**
	 * A password protected post gets its title, without WordPress's
	 * "Protected:" prefix, over the entry's own words, which readers could
	 * already see, since the post gives no summary. An entry that is itself
	 * restricted gives none either, and the card shows the title alone.
	 */
	public function test_protected_post_shows_its_title_over_the_entry_words() {
		[ $entry_id, $breakout_id ] = self::create_breakout( 'publish', [ 'post_password' => 'secret' ], [ 'post_excerpt' => '' ] );

		$titled = self::render( $entry_id, self::TITLE_MARKUP . self::CONTENT_MARKUP . self::WIRE_EXCERPT_MARKUP );

		$this->assertStringContainsString( '>Post &amp; headline</a></h4>', $titled );
		$this->assertStringNotContainsString( 'Protected:', $titled );
		$this->assertStringNotContainsString( 'sums up', $titled );
		$this->assertStringContainsString( '<p>' . self::ENTRY_TEXT . '</p></div>', $titled );
		$this->assertStringContainsString( 'wp-block-post-excerpt__excerpt">' . self::ENTRY_TEXT, $titled );

		$untitled = self::render( $entry_id, self::CONTENT_MARKUP );

		$this->assertStringContainsString( '><p><strong><a href="' . esc_url( get_permalink( $breakout_id ) ) . '">Post &amp; headline</a></strong></p><p>' . self::ENTRY_TEXT . '</p></div>', $untitled );

		$this->gate_entry( $entry_id );
		$restricted = self::render( $entry_id, self::TITLE_MARKUP . self::CONTENT_MARKUP . self::WIRE_EXCERPT_MARKUP );

		$this->assertStringContainsString( '>Post &amp; headline</a></h4>', $restricted );
		$this->assertStringNotContainsString( self::ENTRY_TEXT, $restricted );
		$this->assertStringNotContainsString( 'wp-block-post-content', $restricted );
		$this->assertStringNotContainsString( 'wp-block-post-excerpt', $restricted );
	}

	/**
	 * A card with nothing for its Post Excerpt to show, such as a password
	 * protected post's over a restricted entry, never has core build an excerpt
	 * from the entry's content, which would render the entry's blocks only
	 * for them to be thrown away.
	 */
	public function test_empty_card_excerpt_never_renders_the_entry() {
		[ $entry_id ] = self::create_breakout( 'publish', [ 'post_password' => 'secret' ], [ 'post_excerpt' => '' ] );
		$this->gate_entry( $entry_id );
		$rendered = 0;
		add_filter(
			'render_block_core/paragraph',
			static function ( $content ) use ( &$rendered ) {
				$rendered += is_string( $content ) && str_contains( $content, self::ENTRY_TEXT ) ? 1 : 0;
				return $content;
			}
		);

		$html = self::render( $entry_id, self::TITLE_MARKUP . self::WIRE_EXCERPT_MARKUP );

		$this->assertSame( 0, $rendered );
		$this->assertStringNotContainsString( 'wp-block-post-excerpt', $html );
		$this->assertStringNotContainsString( 'newspack-rolling-coverage-no-excerpt', $html );
	}

	/**
	 * A free preview that Newspack's overlay gate ends in "[…]" ends in a
	 * plain ellipsis in the summary, cut or not.
	 */
	public function test_free_preview_ends_in_a_plain_ellipsis() {
		$this->use_content_gate_stub();

		foreach ( [ ' [&hellip;]', ' […]' ] as $marker ) {
			[ $entry_id, $breakout_id ] = self::create_breakout( 'publish', [ 'post_excerpt' => '' ] );
			$this->gate_entry( $breakout_id, '<p>The free opening.</p><p>Its second paragraph' . $marker . '</p>' );

			$html = self::render( $entry_id, self::TITLE_MARKUP . self::CONTENT_MARKUP );

			$this->assertStringContainsString( '<p>The free opening. Its second paragraph…</p></div>', $html );

			add_filter( 'excerpt_length', fn() => 3 );
			$html = self::render( $entry_id, self::TITLE_MARKUP . self::CONTENT_MARKUP );
			remove_all_filters( 'excerpt_length' );

			$this->assertStringContainsString( '<p>The free opening.…</p></div>', $html );
			$this->assertStringNotContainsString( '[', $html );
		}
	}

	/**
	 * A post a content gate or a membership rule restricts, or that a
	 * Newspack restriction callback reports, is summed up by its
	 * hand-written excerpt, else by its gate's free preview, else by the
	 * entry's own words, never by an excerpt built from its text.
	 */
	public function test_restricted_post_is_summed_up_by_its_written_excerpt_or_free_preview() {
		$this->use_content_gate_stub();
		$this->use_wc_memberships_stub();
		$body = '<!-- wp:paragraph --><p>The paywalled body.</p><!-- /wp:paragraph -->';

		[ $gated_entry, $gated ] = self::create_breakout(
			'publish',
			[
				'post_excerpt' => '',
				'post_content' => $body,
			]
		);
		$this->gate_entry( $gated, '<p>The free opening, which every reader sees before the gate.</p>' );

		[ $previewless_entry, $previewless ] = self::create_breakout(
			'publish',
			[
				'post_excerpt' => '',
				'post_content' => $body,
			]
		);
		$this->gate_entry( $previewless, '' );

		[ $member_entry, $member ] = self::create_breakout(
			'publish',
			[
				'post_excerpt' => '',
				'post_content' => $body,
			]
		);
		$GLOBALS['newspack_rolling_coverage_restricted_posts'][] = $member;

		[ $flagged_entry, $flagged ] = self::create_breakout(
			'publish',
			[
				'post_excerpt' => '',
				'post_content' => $body,
			]
		);
		add_filter( 'newspack_post_has_restrictions', fn( $restricted, $post_id ) => $restricted || $flagged === $post_id, 10, 2 );

		$html = self::render( $gated_entry, self::TITLE_MARKUP . self::CONTENT_MARKUP );

		$this->assertStringContainsString( '<p>The free opening, which every reader sees before the gate.</p></div>', $html );
		$this->assertStringNotContainsString( 'paywalled', $html );

		add_filter( 'excerpt_length', fn() => 3 );
		$html = self::render( $gated_entry, self::TITLE_MARKUP . self::CONTENT_MARKUP );
		remove_all_filters( 'excerpt_length' );

		$this->assertStringContainsString( '<p>The free opening,…</p></div>', $html );

		foreach ( [ $previewless_entry, $member_entry, $flagged_entry ] as $entry_id ) {
			$html = self::render( $entry_id, self::TITLE_MARKUP . self::CONTENT_MARKUP . self::WIRE_EXCERPT_MARKUP );

			$this->assertStringContainsString( 'Post &amp; headline</a></h4>', $html );
			$this->assertStringNotContainsString( 'paywalled', $html );
			$this->assertStringContainsString( '<p>' . self::ENTRY_TEXT . '</p></div>', $html );
			$this->assertStringContainsString( 'wp-block-post-excerpt__excerpt">' . self::ENTRY_TEXT, $html );
		}

		wp_update_post(
			[
				'ID'           => $gated,
				'post_excerpt' => 'What the <em>gated</em> post sums up.',
			]
		);

		$html = self::render( $gated_entry, self::TITLE_MARKUP . self::CONTENT_MARKUP );

		$this->assertStringContainsString( '<p>What the gated post sums up.</p></div>', $html );
		$this->assertStringNotContainsString( 'paywalled', $html );
	}

	/**
	 * Polls render entries in a REST request, where Newspack's excerpt
	 * filter stands down, and a restricted post's text stays out there too.
	 */
	public function test_restricted_post_text_stays_out_of_polls() {
		$this->use_content_gate_stub();
		[ $entry_id, $breakout_id ] = self::create_breakout(
			'publish',
			[
				'post_excerpt' => '',
				'post_content' => '<!-- wp:paragraph --><p>The paywalled body.</p><!-- /wp:paragraph -->',
			]
		);
		$this->gate_entry( $breakout_id );
		$coverage_id = wp_get_object_terms( $entry_id, Taxonomy::TAXONOMY_SLUG, [ 'fields' => 'ids' ] )[0];
		$attributes  = [ 'coverageId' => $coverage_id ];
		$block       = parse_blocks( '<!-- wp:newspack-rolling-coverage/rolling-coverage ' . wp_json_encode( $attributes ) . ' -->' . self::TITLE_MARKUP . self::CONTENT_MARKUP . '<!-- /wp:newspack-rolling-coverage/rolling-coverage -->' )[0];
		$page        = Rolling_Coverage_Block::render_block( $attributes, '', new WP_Block( $block ) );

		$this->assertSame( 1, preg_match( '/data-template-key="([^"]*)"/', $page, $template_key ) );

		$data = self::dispatch(
			'GET',
			'/coverages/' . $coverage_id . '/entries',
			[
				'before'       => '2099-01-01 00:00:00',
				'template_key' => $template_key[1],
			]
		)->get_data();

		$this->assertStringContainsString( 'Post &amp; headline</a></h4>', $data['html'] );
		$this->assertStringNotContainsString( 'paywalled', $data['html'] );
		$this->assertStringNotContainsString( 'paywalled', $page );
	}

	/**
	 * A password protected entry whose post isn't protected still shows the
	 * post's summary in its excerpt, not WordPress's protected post notice.
	 */
	public function test_protected_entry_shows_the_summary_in_its_excerpt() {
		[ $entry_id ] = self::create_breakout( 'publish', [], [ 'post_password' => 'secret' ] );

		$titled   = self::render( $entry_id, self::TITLE_MARKUP . self::WIRE_EXCERPT_MARKUP );
		$untitled = self::render( $entry_id, self::FLASH_EXCERPT_MARKUP );

		$this->assertStringContainsString( 'wp-block-post-excerpt__excerpt">What the post sums up. </p>', $titled );
		$this->assertStringContainsString( 'wp-block-post-excerpt__excerpt">' . self::PREFIX . '<strong>Post &amp; headline</strong> What the post sums up. </p>', $untitled );
		$this->assertStringNotContainsString( 'protected', $titled . $untitled );
	}

	/**
	 * A post without a title leaves the entry's title as it is: the entry's
	 * own, or for an untitled entry in a title that falls back to its
	 * opening words, those words, linked to the post.
	 */
	public function test_untitled_post_keeps_the_entry_title() {
		[ $entry_id, $breakout_id ] = self::create_breakout( 'publish', [ 'post_title' => '' ] );
		$url                        = esc_url( get_permalink( $breakout_id ) );

		$html = self::render( $entry_id, self::TITLE_MARKUP . self::CONTENT_MARKUP );

		$this->assertStringContainsString( '<a href="' . $url . '">Entry headline</a></h4>', $html );
		$this->assertStringContainsString( '<p>What the post sums up.</p></div>', $html );
		$this->assertStringNotContainsString( 'breakout-label', $html, 'A card without a title of its own leaves the heading unlabeled.' );

		[ $untitled_entry, $untitled_breakout ] = self::create_breakout(
			'publish',
			[ 'post_title' => '' ],
			[
				'post_title'   => '',
				'post_excerpt' => '',
			]
		);

		$html = self::render( $untitled_entry, '<!-- wp:post-title {"level":4,"className":"newspack-rolling-coverage-entry-link"} /-->' );

		$this->assertMatchesRegularExpression( '#<a href="' . preg_quote( esc_url( get_permalink( $untitled_breakout ) ), '#' ) . '"[^>]*>What the entry said\.</a></h4>#', $html );
	}

	/**
	 * A pinned entry's card keeps "Read more", linked to the post.
	 */
	public function test_pinned_card_keeps_read_more() {
		[ $entry_id, $breakout_id ] = self::create_breakout();
		Post_Type::pin_entry( $entry_id );
		$markup = '<!-- wp:group {"className":"newspack-rolling-coverage-pinned-card"} --><div class="wp-block-group newspack-rolling-coverage-pinned-card">'
			. self::TITLE_MARKUP . self::CONTENT_MARKUP . self::READ_MORE_MARKUP
			. '</div><!-- /wp:group -->';

		$html = self::render( $entry_id, $markup );

		$this->assertStringContainsString( 'newspack-rolling-coverage-pinned-card', $html );
		$this->assertStringContainsString( 'Post &amp; headline</a></h4>', $html );
		$this->assertStringContainsString( '<p>What the post sums up.</p></div>', $html );
		$this->assertStringContainsString( '<a href="' . esc_url( get_permalink( $breakout_id ) ) . '">Read more</a>', $html );
	}

	/**
	 * A feed in an entry's content renders its own entries as cards, while
	 * the entry holding it keeps its own title and text.
	 */
	public function test_feed_nested_in_an_entry_renders_its_entries_as_cards() {
		$this->register_feed_block();
		[ $inner_entry ] = self::create_breakout();
		$coverage_id     = wp_get_object_terms( $inner_entry, Taxonomy::TAXONOMY_SLUG, [ 'fields' => 'ids' ] )[0];
		$outer_entry     = self::create_entry(
			self::create_coverage(),
			[
				'post_title'   => 'Outer headline',
				'post_content' => '<!-- wp:paragraph --><p>The outer text.</p><!-- /wp:paragraph -->'
					. '<!-- wp:newspack-rolling-coverage/rolling-coverage {"coverageId":' . $coverage_id . '} -->' . self::TITLE_MARKUP . self::CONTENT_MARKUP . '<!-- /wp:newspack-rolling-coverage/rolling-coverage -->',
			]
		);

		$html = self::render( $outer_entry, self::TITLE_MARKUP . self::CONTENT_MARKUP );

		$this->assertStringContainsString( '>Outer headline</h4>', $html );
		$this->assertStringContainsString( 'The outer text.', $html );
		$this->assertStringContainsString( 'Post &amp; headline</a></h4>', $html );
		$this->assertStringContainsString( '<p>What the post sums up.</p></div>', $html );
		$this->assertStringNotContainsString( self::ENTRY_TEXT, $html );
	}

	/**
	 * The card's filters go even when the entry's render fails.
	 */
	public function test_filters_go_when_a_render_fails() {
		[ $entry_id ] = self::create_breakout();
		$card         = Breakout_Card::for_entry( $entry_id );

		try {
			Breakout_Card::render(
				$entry_id,
				$card,
				true,
				static function () {
					throw new RuntimeException( 'Render failed.' );
				}
			);
			$this->fail( 'The failure should reach the caller.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'Render failed.', $e->getMessage() );
		}

		foreach ( [ 'the_title', 'get_the_excerpt', 'the_content', 'render_block_core/post-excerpt', 'render_block_core/post-content' ] as $hook ) {
			$this->assertFalse( has_filter( $hook, [ Breakout_Card::class, 'filter_title' ] ) );
			$this->assertFalse( has_filter( $hook, [ Breakout_Card::class, 'filter_excerpt' ] ) );
			$this->assertFalse( has_filter( $hook, [ Breakout_Card::class, 'hold_excerpt' ] ) );
			$this->assertFalse( has_filter( $hook, [ Breakout_Card::class, 'stand_in_content' ] ) );
			$this->assertFalse( has_filter( $hook, [ Breakout_Card::class, 'render_excerpt' ] ) );
			$this->assertFalse( has_filter( $hook, [ Breakout_Card::class, 'render_content' ] ) );
		}
	}

	/**
	 * Until the post is published, the entry shows its own title and text.
	 */
	public function test_draft_breakout_leaves_the_entry_as_it_is() {
		[ $entry_id ] = self::create_breakout( 'draft' );

		$html = self::render( $entry_id, self::TITLE_MARKUP . self::CONTENT_MARKUP . self::FLASH_EXCERPT_MARKUP );

		$this->assertStringContainsString( 'Entry headline', $html );
		$this->assertStringContainsString( self::ENTRY_TEXT, $html );
		$this->assertStringNotContainsString( 'Post &amp; headline', $html );
		$this->assertStringNotContainsString( 'sums up', $html );
	}

	/**
	 * Unpublishing the post brings the entry's own text back.
	 */
	public function test_unpublishing_the_post_restores_the_entry() {
		[ $entry_id, $breakout_id ] = self::create_breakout();

		$this->assertStringNotContainsString( self::ENTRY_TEXT, self::render( $entry_id, self::TITLE_MARKUP . self::CONTENT_MARKUP ), 'Precondition: the card shows.' );

		wp_update_post(
			[
				'ID'          => $breakout_id,
				'post_status' => 'draft',
			]
		);

		$html = self::render( $entry_id, self::TITLE_MARKUP . self::CONTENT_MARKUP );

		$this->assertStringContainsString( 'Entry headline', $html );
		$this->assertStringContainsString( self::ENTRY_TEXT, $html );
		$this->assertStringContainsString( self::ENTRY_TEXT, get_post( $entry_id )->post_content, 'The entry is never changed.' );
	}

	/**
	 * An untitled entry's card is titled, so its header keeps the titled
	 * alignment, and an entry whose content renders nothing still gets the
	 * summary in the content's wrapper.
	 */
	public function test_untitled_entry_renders_as_a_titled_card() {
		WP_Style_Engine_CSS_Rules_Store::remove_all_stores();
		[ $entry_id ] = self::create_breakout(
			'publish',
			[],
			[
				'post_title'   => '',
				'post_content' => '',
			]
		);

		$html   = self::render( $entry_id, self::HEADER_MARKUP . self::CONTENT_MARKUP );
		$styles = wp_style_engine_get_stylesheet_from_context( 'block-supports' );

		$this->assertSame( 1, preg_match( '/class="[^"]*is-content-justification-space-between[^"]*(wp-container-core-group-is-layout-[0-9a-f]+)/', $html, $matches ) );
		$this->assertStringContainsString( '.' . $matches[1] . '{', $styles );
		$this->assertMatchesRegularExpression( '/\.' . preg_quote( $matches[1], '/' ) . '\s*\{[^}]*align-items:flex-start/', $styles );
		$this->assertStringContainsString( 'Post &amp; headline</a></h2>', $html );
		$this->assertMatchesRegularExpression( '#<div class="[^"]*wp-block-post-content[^"]*"><p>What the post sums up\.</p></div>#', $html );
	}

	/**
	 * An archived entry's card shows the summary without the archived
	 * notice, which speaks of the entry's own text, on full and lite pages.
	 */
	public function test_archived_entry_card_drops_the_notice() {
		require_once __DIR__ . '/mocks/class-lite-site.php';
		[ $entry_id ] = self::create_breakout();
		update_post_meta( $entry_id, Archive_Mode::ENTRY_ARCHIVED_META_KEY, time() );

		$html = self::render( $entry_id, self::TITLE_MARKUP . self::CONTENT_MARKUP );
		$lite = Lite_Feed::render_entry( get_post( $entry_id ), 'initial' );

		$this->assertMatchesRegularExpression( '#<div [^>]*><p>What the post sums up\.</p></div>#', $html );
		$this->assertStringNotContainsString( 'archived', $html );
		$this->assertStringNotContainsString( self::ENTRY_TEXT, $html );
		$this->assertStringContainsString( 'What the post sums up.', $lite );
		$this->assertStringNotContainsString( 'archived', $lite );
	}

	/**
	 * A lite page shows the post's title, linked to its lite page, and its
	 * summary. A post type without lite pages links to the post.
	 */
	public function test_lite_entry_shows_the_post_title_and_summary() {
		require_once __DIR__ . '/mocks/class-lite-site.php';
		[ $entry_id, $breakout_id ] = self::create_breakout();

		$html = Lite_Feed::render_entry( get_post( $entry_id ), 'initial' );

		$this->assertStringContainsString( '</p><p class="newspack-rolling-coverage-breakout-label">Full story</p><h3><a href="' . esc_url( home_url( '/lite/' . $breakout_id ) ) . '">Post &amp; headline</a></h3><p>What the post sums up.</p></article>', $html );
		$this->assertStringNotContainsString( 'Entry headline', $html );
		$this->assertStringNotContainsString( self::ENTRY_TEXT, $html );

		add_filter( 'newspack_lite_site_supported_post_types', fn() => [ 'page' ] );

		$this->assertStringContainsString( '<h3><a href="' . esc_url( get_permalink( $breakout_id ) ) . '">', Lite_Feed::render_entry( get_post( $entry_id ), 'initial' ) );
	}

	/**
	 * Editing a published post's title, excerpt, content, password or slug
	 * touches its entry, so open pages get the new card; other edits, and
	 * edits to a draft, don't.
	 */
	public function test_editing_the_published_post_touches_the_entry() {
		[ $entry_id, $breakout_id ] = self::create_breakout();

		foreach ( [ 'post_title', 'post_excerpt', 'post_content', 'post_password', 'post_name' ] as $field ) {
			self::backdate_modified( $entry_id );
			wp_update_post(
				[
					'ID'   => $breakout_id,
					$field => 'Edited ' . $field,
				]
			);

			$this->assertGreaterThan( '2026-01-01 12:00:00', get_post( $entry_id )->post_modified_gmt, "Editing {$field} should touch the entry." );
		}

		self::backdate_modified( $entry_id );
		wp_update_post(
			[
				'ID'         => $breakout_id,
				'menu_order' => 3,
			]
		);

		$this->assertSame( '2026-01-01 12:00:00', get_post( $entry_id )->post_modified_gmt, 'An edit the card does not show leaves the entry alone.' );

		[ $draft_entry_id, $draft_id ] = self::create_breakout( 'draft' );
		self::backdate_modified( $draft_entry_id );
		wp_update_post(
			[
				'ID'         => $draft_id,
				'post_title' => 'Edited draft',
			]
		);

		$this->assertSame( '2026-01-01 12:00:00', get_post( $draft_entry_id )->post_modified_gmt, 'Editing a draft leaves the entry alone.' );

		$touches = 0;
		add_action(
			'post_updated',
			static function ( $post_id ) use ( $draft_entry_id, &$touches ) {
				$touches += $draft_entry_id === $post_id ? 1 : 0;
			}
		);
		wp_update_post(
			[
				'ID'          => $draft_id,
				'post_title'  => 'Published at last',
				'post_status' => 'publish',
			]
		);

		$this->assertSame( 1, $touches, 'Publishing with an edit touches the entry once, for the publish.' );
	}

	/**
	 * Setting, changing or removing a published post's own label touches its
	 * entry, through REST or not, so open pages get the new label; saving
	 * the same label, an empty one on a post without one, or a draft's
	 * label doesn't. Deleting a labeled post touches the entry once, even
	 * when its label goes before its link to the entry.
	 */
	public function test_changing_the_post_label_touches_the_entry() {
		[ $entry_id, $breakout_id ] = self::create_breakout();
		$touched                    = static fn() => '2026-01-01 12:00:00' !== get_post( $entry_id )->post_modified_gmt;

		self::backdate_modified( $entry_id );
		update_post_meta( $breakout_id, Breakout_Label::POST_META_KEY, 'Analysis' );
		$this->assertTrue( $touched(), 'Setting the label touches the entry.' );

		self::backdate_modified( $entry_id );
		update_post_meta( $breakout_id, Breakout_Label::POST_META_KEY, 'Analysis' );
		$this->assertFalse( $touched(), 'Saving the same label leaves the entry alone.' );

		self::backdate_modified( $entry_id );
		update_post_meta( $breakout_id, Breakout_Label::POST_META_KEY, 'Explainer' );
		$this->assertTrue( $touched(), 'Changing the label touches the entry.' );

		self::backdate_modified( $entry_id );
		delete_post_meta( $breakout_id, Breakout_Label::POST_META_KEY );
		$this->assertTrue( $touched(), 'Removing the label touches the entry.' );

		self::backdate_modified( $entry_id );
		update_post_meta( $breakout_id, Breakout_Label::POST_META_KEY, '' );
		$this->assertFalse( $touched(), 'An empty label on a post without one leaves the entry alone.' );

		self::log_in_as( 'editor' );
		self::backdate_modified( $entry_id );
		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $breakout_id );
		$request->set_param( 'meta', [ Breakout_Label::POST_META_KEY => 'Analysis' ] );
		$this->assertSame( 200, rest_get_server()->dispatch( $request )->get_status() );
		$this->assertTrue( $touched(), 'Saving the label from the editor touches the entry.' );

		[ $draft_entry_id, $draft_id ] = self::create_breakout( 'draft' );
		self::backdate_modified( $draft_entry_id );
		update_post_meta( $draft_id, Breakout_Label::POST_META_KEY, 'Analysis' );
		delete_post_meta( $draft_id, Breakout_Label::POST_META_KEY );

		$this->assertSame( '2026-01-01 12:00:00', get_post( $draft_entry_id )->post_modified_gmt, 'A draft\'s label leaves the entry alone.' );

		delete_post_meta( $breakout_id, Breakout::BREAKOUT_SOURCE_ENTRY_META );
		update_post_meta( $breakout_id, Breakout::BREAKOUT_SOURCE_ENTRY_META, $entry_id );
		$touches = 0;
		add_action(
			'post_updated',
			static function ( $post_id ) use ( $entry_id, &$touches ) {
				$touches += $entry_id === $post_id ? 1 : 0;
			}
		);
		wp_delete_post( $breakout_id, true );

		$this->assertSame( 1, $touches, 'Deleting a labeled post touches the entry once.' );
	}

	/**
	 * Set an entry's modified date back, as if it was last touched long ago.
	 *
	 * @param int $entry_id Entry post ID.
	 */
	private static function backdate_modified( int $entry_id ): void {
		global $wpdb;

		$wpdb->update( $wpdb->posts, [ 'post_modified_gmt' => '2026-01-01 12:00:00' ], [ 'ID' => $entry_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		clean_post_cache( $entry_id );
		( new ReflectionProperty( Breakout::class, 'touched' ) )->setValue( null, [] );
	}
}
