<?php
/**
 * Tests for the card data of an entry's published breakout post, and for
 * compact layouts showing it.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Archive_Mode;
use Newspack_Rolling_Coverage\Breakout;
use Newspack_Rolling_Coverage\Breakout_Label;
use Newspack_Rolling_Coverage\Breakout_Card;
use Newspack_Rolling_Coverage\Lite_Feed;
use Newspack_Rolling_Coverage\Rolling_Coverage_Block;
use Newspack_Rolling_Coverage\Taxonomy;

/**
 * Once an entry's breakout post is published, a compact layout shows the
 * post's title and summary in its own blocks. The Full Story Entry group is
 * covered by Test_Full_Story_Entry.
 */
class Test_Breakout_Card extends Rolling_Coverage_TestCase {

	const TITLE_MARKUP = '<!-- wp:post-title {"level":4} /-->';

	const CONTENT_MARKUP = '<!-- wp:post-content {"fontSize":"small"} /-->';

	const FLASH_EXCERPT_MARKUP = '<!-- wp:post-excerpt {"excerptLength":100,"moreText":""} /-->';

	const WIRE_EXCERPT_MARKUP = '<!-- wp:post-excerpt {"excerptLength":15,"moreText":""} /-->';

	/**
	 * A compact layout's excerpt long enough to show a whole summary.
	 */
	const SUMMARY_MARKUP = '<!-- wp:post-excerpt {"excerptLength":100,"moreText":""} /-->';

	const SHARE_MARKUP = '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button {"metadata":{"bindings":{"url":{"source":"newspack-rolling-coverage/entry","args":{"key":"shareUrl"}}}}} --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button">Share</a></div><!-- /wp:button --></div><!-- /wp:buttons -->';

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
	 * The Full story label leading a title on the same line.
	 */
	const PREFIX = '<span class="newspack-rolling-coverage-breakout-label newspack-rolling-coverage-breakout-label--prefix">Full story:</span> ';

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
	 * The start of a compact layout's excerpt showing a summary.
	 *
	 * @param string $text The summary.
	 * @return string
	 */
	private static function excerpt( string $text ): string {
		return 'wp-block-post-excerpt__excerpt">' . $text;
	}

	/**
	 * In a template that shows the entry's text only on its pinned card, an
	 * unpinned entry still shows the post, as a compact layout does.
	 */
	public function test_entry_kind_without_text_shows_the_post_as_a_compact_layout() {
		[ $entry_id ] = self::create_breakout();

		$html = self::render(
			$entry_id,
			'<!-- wp:group {"className":"newspack-rolling-coverage-pinned-card"} --><div class="wp-block-group newspack-rolling-coverage-pinned-card">' . self::CONTENT_MARKUP . '</div><!-- /wp:group -->'
				. '<!-- wp:group {"className":"newspack-rolling-coverage-regular-entry"} --><div class="wp-block-group newspack-rolling-coverage-regular-entry">' . self::TITLE_MARKUP . self::WIRE_EXCERPT_MARKUP . '</div><!-- /wp:group -->'
		);

		$this->assertStringContainsString( 'Post &amp; headline', $html );
		$this->assertStringNotContainsString( 'Entry headline', $html );
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
	 * A compact layout with a title and an excerpt, like Wire, carries the
	 * Full story label inside the heading, on a line of its own before the
	 * link, so the link is named by the post's title alone, and labels the
	 * title only.
	 */
	public function test_compact_card_labels_the_title() {
		[ $entry_id, $breakout_id ] = self::create_breakout();

		$html = self::render( $entry_id, self::TITLE_MARKUP . self::WIRE_EXCERPT_MARKUP );

		$this->assertMatchesRegularExpression( '#<h4 class="[^"]*wp-block-post-title[^"]*">' . preg_quote( self::KICKER, '#' ) . '<a href="' . preg_quote( esc_url( get_permalink( $breakout_id ) ), '#' ) . '">Post &amp; headline</a></h4>#', $html );
		$this->assertSame( 1, substr_count( $html, 'newspack-rolling-coverage-breakout-label' ) );
		$this->assertStringContainsString( 'wp-block-post-excerpt__excerpt">What the post sums up.', $html );
		$this->assertFalse( has_filter( 'render_block_core/post-title', [ Breakout_Card::class, 'label_title' ] ), 'The label filter goes once the entry is rendered.' );
	}

	/**
	 * Ticker's headline carries the label on a line of its own, outside the
	 * headline's link, and Flash's excerpt leads with it on the same line,
	 * outside the words the excerpt counts.
	 */
	public function test_one_line_cards_carry_the_label() {
		[ $entry_id, $breakout_id ] = self::create_breakout();

		$ticker = self::render( $entry_id, self::TICKER_TITLE_MARKUP );
		$flash  = self::render( $entry_id, '<!-- wp:post-excerpt {"excerptLength":2,"moreText":""} /-->' );

		$this->assertMatchesRegularExpression( '#<h4 class="[^"]*newspack-rolling-coverage-entry-link[^"]*">' . preg_quote( self::KICKER, '#' ) . '<a href="' . preg_quote( esc_url( get_permalink( $breakout_id ) ), '#' ) . '"[^>]*>Post &amp; headline</a></h4>#', $ticker );
		$this->assertStringContainsString( 'wp-block-post-excerpt__excerpt">' . self::PREFIX . '<strong>Post &amp; headline</strong> What the&hellip; </p>', $flash );
		$this->assertStringNotContainsString( 'breakout-label">Full story</span>', $flash, 'Flash carries no label on a line of its own.' );
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
	 * Compact cards show the site's own label, escaped, in every place they
	 * show it, full and lite; a blank one gives way to "Full story".
	 */
	public function test_cards_show_the_site_label_escaped() {
		require_once __DIR__ . '/mocks/class-lite-site.php';
		[ $entry_id ] = self::create_breakout();
		update_option( Breakout_Label::OPTION_KEY, 'Q < A & "B"' );

		$titled = self::render( $entry_id, self::TITLE_MARKUP );
		$flash  = self::render( $entry_id, self::FLASH_EXCERPT_MARKUP );
		$ticker = self::render( $entry_id, self::TICKER_TITLE_MARKUP );
		$lite   = Lite_Feed::render_entry( get_post( $entry_id ), 'initial' );

		$this->assertStringContainsString( 'breakout-label">Q &lt; A &amp; &quot;B&quot;</span> <a href=', $titled );
		$this->assertStringContainsString( 'breakout-label--prefix">Q &lt; A &amp; &quot;B&quot;:</span> <strong>Post', $flash );
		$this->assertStringContainsString( 'newspack-rolling-coverage-breakout-label">Q &lt; A &amp; &quot;B&quot;</span> <a href=', $ticker );
		$this->assertStringContainsString( '<p class="newspack-rolling-coverage-breakout-label">Q &lt; A &amp; &quot;B&quot;</p><h3>', $lite );
		$this->assertStringNotContainsString( 'Full story', $titled . $flash . $ticker . $lite );

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

		$titled = self::render( $entry_id, self::TITLE_MARKUP );
		$flash  = self::render( $entry_id, self::FLASH_EXCERPT_MARKUP );
		$ticker = self::render( $entry_id, self::TICKER_TITLE_MARKUP );
		$lite   = Lite_Feed::render_entry( get_post( $entry_id ), 'initial' );

		$this->assertStringContainsString( 'breakout-label">Q &lt; A &amp; &quot;B&quot;</span> <a href=', $titled );
		$this->assertStringContainsString( 'breakout-label--prefix">Q &lt; A &amp; &quot;B&quot;:</span> <strong>Post', $flash );
		$this->assertStringContainsString( 'newspack-rolling-coverage-breakout-label">Q &lt; A &amp; &quot;B&quot;</span> <a href=', $ticker );
		$this->assertStringContainsString( '<p class="newspack-rolling-coverage-breakout-label">Q &lt; A &amp; &quot;B&quot;</p><h3>', $lite );
		$this->assertStringNotContainsString( 'Site label', $titled . $flash . $ticker . $lite );

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
	 * not an entry without a breakout post, and not a compact card whose
	 * title renders nothing. A lite card without a title has none either.
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
		$this->assertStringNotContainsString( 'breakout-label', self::render( $untitled_entry, self::TITLE_MARKUP . self::FLASH_EXCERPT_MARKUP ) );
		$this->assertStringNotContainsString( 'breakout-label', Lite_Feed::render_entry( get_post( $untitled_entry ), 'initial' ) );
		$this->assertStringContainsString( '<h3><a href="', Lite_Feed::render_entry( get_post( $untitled_entry ), 'initial' ), 'A lite card for a post without a title still links to the post.' );

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

		$html = self::render( $entry_id, self::TITLE_MARKUP . self::SUMMARY_MARKUP );

		$this->assertStringContainsString( self::excerpt( 'Alpha beta gamma delta…' ), $html );
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

		$first = self::render( $entry_id, self::TITLE_MARKUP . self::SUMMARY_MARKUP );

		remove_filter( 'render_block_core/paragraph', $as_member );
		$later = self::render( $entry_id, self::TITLE_MARKUP . self::SUMMARY_MARKUP );

		foreach ( [ $first, $later ] as $html ) {
			$this->assertStringContainsString( self::excerpt( 'Everyone reads this. Visitors read this too.' ), $html );
			$this->assertStringNotContainsString( 'Members read', $html );
		}
	}

	/**
	 * The summary is worked out only for a template that shows it, not for
	 * one without an excerpt nor for one showing the entry's own text, and
	 * only once while the post is unchanged and the excerpt length stays the
	 * same.
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

		$this->assertSame( 0, $asked, 'A template without an excerpt works out no summary.' );

		self::render( $entry_id, self::TITLE_MARKUP . self::CONTENT_MARKUP . self::WIRE_EXCERPT_MARKUP );

		$this->assertSame( 0, $asked, 'A template showing the entry\'s own text works out no summary.' );

		$html = self::render( $entry_id, self::TITLE_MARKUP . self::SUMMARY_MARKUP );

		$this->assertStringContainsString( self::excerpt( 'The whole post.' ), $html );

		$wpdb->update( $wpdb->posts, [ 'post_content' => '<!-- wp:paragraph --><p>Rewritten without a new modified time.</p><!-- /wp:paragraph -->' ], [ 'ID' => $breakout_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		clean_post_cache( $breakout_id );

		$html = self::render( $entry_id, self::TITLE_MARKUP . self::SUMMARY_MARKUP );

		$this->assertStringContainsString( self::excerpt( 'The whole post.' ), $html, 'The summary is cached.' );

		add_filter( 'excerpt_length', fn() => 2, 999 );
		$html = self::render( $entry_id, self::TITLE_MARKUP . self::SUMMARY_MARKUP );

		$this->assertStringContainsString( self::excerpt( 'Rewritten without…' ), $html, 'Another excerpt length gets its own summary.' );
	}

	/**
	 * The entry's own blocks never render under a compact card, for an
	 * excerpt built from them.
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

		self::render( $entry_id, self::TITLE_MARKUP . self::WIRE_EXCERPT_MARKUP );
		self::render( $entry_id, self::FLASH_EXCERPT_MARKUP );

		$this->assertStringNotContainsString( self::ENTRY_TEXT, $rendered );
	}

	/**
	 * A password protected post gets its title, without WordPress's
	 * "Protected:" prefix, over the entry's own words, which readers could
	 * already see, since the post gives no summary, in a compact layout's
	 * excerpt. An entry that is itself restricted gives none either, and the
	 * card shows the title alone.
	 */
	public function test_protected_post_shows_its_title_over_the_entry_words() {
		[ $entry_id ] = self::create_breakout( 'publish', [ 'post_password' => 'secret' ], [ 'post_excerpt' => '' ] );

		$compact = self::render( $entry_id, self::TITLE_MARKUP . self::WIRE_EXCERPT_MARKUP );

		$this->assertStringContainsString( '>Post &amp; headline</a></h4>', $compact );
		$this->assertStringNotContainsString( 'Protected:', $compact );
		$this->assertStringNotContainsString( 'sums up', $compact );
		$this->assertStringContainsString( self::excerpt( self::ENTRY_TEXT ), $compact );

		$this->gate_entry( $entry_id );
		$restricted = self::render( $entry_id, self::TITLE_MARKUP . self::WIRE_EXCERPT_MARKUP );

		$this->assertStringContainsString( '>Post &amp; headline</a></h4>', $restricted );
		$this->assertStringNotContainsString( self::ENTRY_TEXT, $restricted );
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

			$html = self::render( $entry_id, self::TITLE_MARKUP . self::SUMMARY_MARKUP );

			$this->assertStringContainsString( self::excerpt( 'The free opening. Its second paragraph…' ), $html );

			add_filter( 'excerpt_length', fn() => 3 );
			$html = self::render( $entry_id, self::TITLE_MARKUP . self::SUMMARY_MARKUP );
			remove_all_filters( 'excerpt_length' );

			$this->assertStringContainsString( self::excerpt( 'The free opening.…' ), $html );
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

		$html = self::render( $gated_entry, self::TITLE_MARKUP . self::SUMMARY_MARKUP );

		$this->assertStringContainsString( self::excerpt( 'The free opening, which every reader sees before the gate.' ), $html );
		$this->assertStringNotContainsString( 'paywalled', $html );

		add_filter( 'excerpt_length', fn() => 3 );
		$html = self::render( $gated_entry, self::TITLE_MARKUP . self::SUMMARY_MARKUP );
		remove_all_filters( 'excerpt_length' );

		$this->assertStringContainsString( self::excerpt( 'The free opening,…' ), $html );

		foreach ( [ $previewless_entry, $member_entry, $flagged_entry ] as $entry_id ) {
			$compact = self::render( $entry_id, self::TITLE_MARKUP . self::WIRE_EXCERPT_MARKUP );

			$this->assertStringContainsString( self::excerpt( self::ENTRY_TEXT ), $compact );
			$this->assertStringNotContainsString( 'paywalled', $compact );
		}

		wp_update_post(
			[
				'ID'           => $gated,
				'post_excerpt' => 'What the <em>gated</em> post sums up.',
			]
		);

		$html = self::render( $gated_entry, self::TITLE_MARKUP . self::SUMMARY_MARKUP );

		$this->assertStringContainsString( self::excerpt( 'What the gated post sums up.' ), $html );
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
		$block       = parse_blocks( '<!-- wp:newspack-rolling-coverage/rolling-coverage ' . wp_json_encode( $attributes ) . ' -->' . self::TITLE_MARKUP . self::SUMMARY_MARKUP . '<!-- /wp:newspack-rolling-coverage/rolling-coverage -->' )[0];
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
	 * post's summary in its excerpt, titled or not, not WordPress's protected
	 * post notice.
	 */
	public function test_protected_entry_shows_the_summary_in_its_excerpt() {
		[ $entry_id ] = self::create_breakout( 'publish', [], [ 'post_password' => 'secret' ] );

		$titled   = self::render( $entry_id, self::TITLE_MARKUP . self::WIRE_EXCERPT_MARKUP );
		$untitled = self::render( $entry_id, self::FLASH_EXCERPT_MARKUP );

		$this->assertStringContainsString( 'wp-block-post-excerpt__excerpt">What the post sums up. </p>', $titled );
		$this->assertStringContainsString( 'wp-block-post-excerpt__excerpt">' . self::PREFIX . '<strong>Post &amp; headline</strong> What the post sums up. </p>', $untitled );
		$this->assertStringNotContainsString( 'protected', $titled . $untitled );

		[ $untitled_entry ] = self::create_breakout( 'publish', [ 'post_title' => '' ], [ 'post_password' => 'secret' ] );

		$untitled_post = self::render( $untitled_entry, self::TITLE_MARKUP . self::WIRE_EXCERPT_MARKUP );

		$this->assertStringContainsString( 'wp-block-post-excerpt__excerpt">What the post sums up. </p>', $untitled_post );
		$this->assertStringNotContainsString( 'protected', $untitled_post );
	}

	/**
	 * In a compact layout, a post without a title leaves the entry's title
	 * as it is: the entry's own, or for an untitled entry in a title that
	 * falls back to its opening words, those words, linked to the post.
	 */
	public function test_untitled_post_keeps_the_entry_title_in_compact_layouts() {
		[ $entry_id, $breakout_id ] = self::create_breakout( 'publish', [ 'post_title' => '' ] );

		$html = self::render( $entry_id, self::TITLE_MARKUP . self::WIRE_EXCERPT_MARKUP );

		$this->assertStringContainsString( '<a href="' . esc_url( get_permalink( $breakout_id ) ) . '">Entry headline</a></h4>', $html );
		$this->assertStringContainsString( 'wp-block-post-excerpt__excerpt">What the post sums up.', $html );
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
	 * A feed in an entry's content renders its own entries as compact cards,
	 * while the entry holding it keeps its own title and text.
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
					. '<!-- wp:newspack-rolling-coverage/rolling-coverage {"coverageId":' . $coverage_id . '} -->' . self::TITLE_MARKUP . self::SUMMARY_MARKUP . '<!-- /wp:newspack-rolling-coverage/rolling-coverage -->',
			]
		);

		$html = self::render( $outer_entry, self::TITLE_MARKUP . self::CONTENT_MARKUP );

		$this->assertStringContainsString( '>Outer headline</h4>', $html );
		$this->assertStringContainsString( 'The outer text.', $html );
		$this->assertStringContainsString( 'Post &amp; headline</a></h4>', $html );
		$this->assertStringContainsString( self::excerpt( 'What the post sums up.' ), $html );
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

		$this->assertFalse( has_filter( 'the_title', [ Breakout_Card::class, 'filter_title' ] ) );
		$this->assertFalse( has_filter( 'get_the_excerpt', [ Breakout_Card::class, 'hold_excerpt' ] ) );
		$this->assertFalse( has_filter( 'get_the_excerpt', [ Breakout_Card::class, 'filter_excerpt' ] ) );
		$this->assertFalse( has_filter( 'render_block_core/post-excerpt', [ Breakout_Card::class, 'render_excerpt' ] ) );
		$this->assertFalse( has_filter( 'render_block_core/post-title', [ Breakout_Card::class, 'label_title' ] ) );
		$this->assertFalse( has_filter( 'post_thumbnail_id', [ Breakout_Card::class, 'filter_thumbnail_id' ] ) );
		$this->assertFalse( has_filter( 'render_block_core/post-featured-image', [ Breakout_Card::class, 'render_featured_image' ] ) );
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

		$this->assertStringContainsString( 'Post &amp; headline', self::render( $entry_id, self::TITLE_MARKUP . self::WIRE_EXCERPT_MARKUP ), 'Precondition: the card shows.' );

		wp_update_post(
			[
				'ID'          => $breakout_id,
				'post_status' => 'draft',
			]
		);

		$html = self::render( $entry_id, self::TITLE_MARKUP . self::WIRE_EXCERPT_MARKUP );

		$this->assertStringContainsString( 'Entry headline', $html );
		$this->assertStringNotContainsString( 'sums up', $html );
		$this->assertStringNotContainsString( 'Post &amp; headline', $html );
		$this->assertStringContainsString( self::ENTRY_TEXT, get_post( $entry_id )->post_content, 'The entry is never changed.' );
	}

	/**
	 * An archived entry's compact card shows the summary without the
	 * archived notice, which speaks of the entry's own text, on full and lite
	 * pages.
	 */
	public function test_archived_entry_card_drops_the_notice() {
		require_once __DIR__ . '/mocks/class-lite-site.php';
		[ $entry_id ] = self::create_breakout();
		update_post_meta( $entry_id, Archive_Mode::ENTRY_ARCHIVED_META_KEY, time() );

		$html = self::render( $entry_id, self::TITLE_MARKUP . self::SUMMARY_MARKUP );
		$lite = Lite_Feed::render_entry( get_post( $entry_id ), 'initial' );

		$this->assertStringContainsString( self::excerpt( 'What the post sums up.' ), $html );
		$this->assertStringNotContainsString( 'archived', $html );
		$this->assertStringNotContainsString( self::ENTRY_TEXT, $html );
		$this->assertStringContainsString( 'What the post sums up.', $lite );
		$this->assertStringNotContainsString( 'archived', $lite );
	}

	/**
	 * An archived entry with a published post that keeps its own text, in a
	 * template without a Full Story Entry, keeps the archived notice too.
	 */
	public function test_archived_entry_showing_its_text_keeps_the_notice() {
		[ $entry_id ] = self::create_breakout();
		update_post_meta( $entry_id, Archive_Mode::ENTRY_ARCHIVED_META_KEY, time() );

		$html = self::render( $entry_id, self::TITLE_MARKUP . self::CONTENT_MARKUP );

		$this->assertMatchesRegularExpression( '#<p class="newspack-rolling-coverage-entry-archived-notice">[^<]+</p><div class="[^"]*wp-block-post-content[^"]*"><p[^>]*>' . preg_quote( self::ENTRY_TEXT, '#' ) . '</p></div>#', $html );
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
