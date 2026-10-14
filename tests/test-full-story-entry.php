<?php
/**
 * Tests for the Full Story Entry group.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Archive_Mode;
use Newspack_Rolling_Coverage\Breakout;
use Newspack_Rolling_Coverage\Breakout_Card;
use Newspack_Rolling_Coverage\Breakout_Label;
use Newspack_Rolling_Coverage\Post_Type;
use Newspack_Rolling_Coverage\Rolling_Coverage_Block;
use Newspack_Rolling_Coverage\Social_Sharing;
use Newspack_Rolling_Coverage\Taxonomy;

/**
 * An unpinned entry whose breakout post is published renders the layout's
 * Full Story Entry group alone, its blocks showing the post's title,
 * summary and featured image, while its byline and Share stay the entry's.
 */
class Test_Full_Story_Entry extends Rolling_Coverage_TestCase {

	const ENTRY_TEXT = 'What the entry said.';

	const TITLE_MARKUP = '<!-- wp:post-title {"level":3} /-->';

	const EXCERPT_MARKUP = '<!-- wp:post-excerpt {"excerptLength":25,"moreText":""} /-->';

	const CONTENT_MARKUP = '<!-- wp:post-content /-->';

	const DATE_MARKUP = '<!-- wp:post-date {"format":"Y-m-d"} /-->';

	const SHARE_MARKUP = '<!-- wp:paragraph {"className":"newspack-rolling-coverage-share"} --><p class="newspack-rolling-coverage-share">Share</p><!-- /wp:paragraph -->';

	const READ_MORE_MARKUP = '<!-- wp:paragraph {"className":"newspack-rolling-coverage-read-more"} --><p class="newspack-rolling-coverage-read-more"><a href="#">Read more</a></p><!-- /wp:paragraph -->';

	const SEPARATOR_MARKUP = '<!-- wp:separator {"className":"is-style-wide"} --><hr class="wp-block-separator has-alpha-channel-opacity is-style-wide"/><!-- /wp:separator -->';

	/**
	 * A paragraph bound to the Full story label, as the layouts save it.
	 */
	const LABEL_MARKUP = '<!-- wp:paragraph {"className":"use-header-font","metadata":{"name":"Full Story Label","bindings":{"content":{"source":"newspack-rolling-coverage/entry","args":{"key":"breakoutLabel"}}}}} --><p class="use-header-font">Full story</p><!-- /wp:paragraph -->';

	/**
	 * The opening of the label paragraph, as core renders it.
	 */
	const LABEL_OPEN = '<p class="use-header-font wp-block-paragraph">';

	const BREAKOUT_RULE = 'border-top-color:#f00;border-top-width:2px;border-top-style:solid;';

	const COLUMN_RULE = 'border-top-color:#111;border-top-width:3px;border-top-style:solid;';

	/**
	 * A group of the given class around blocks.
	 *
	 * @param string $class_name The group's class.
	 * @param string $inner      Inner blocks' markup.
	 * @param string $style      The group's style attribute, as JSON.
	 * @param string $inline     The group's inline style.
	 * @return string
	 */
	private static function group( string $class_name, string $inner, string $style = '', string $inline = '' ): string {
		$attributes = [ 'className' => $class_name ];

		if ( '' !== $style ) {
			$attributes['style'] = json_decode( $style, true );
		}

		return '<!-- wp:group ' . wp_json_encode( $attributes ) . ' --><div class="wp-block-group ' . $class_name . '"' . ( '' !== $inline ? ' style="' . $inline . '"' : '' ) . '>' . $inner . '</div><!-- /wp:group -->';
	}

	/**
	 * A Full Story Entry group, its box holding the given blocks, followed
	 * by the entry's byline and Share, and closed by a separator.
	 *
	 * @param string $box_blocks The box's blocks.
	 * @return string
	 */
	private static function full_story_group( string $box_blocks = '' ): string {
		$box_blocks = '' !== $box_blocks ? $box_blocks : self::LABEL_MARKUP . self::image_columns() . self::TITLE_MARKUP . self::EXCERPT_MARKUP;

		return self::group(
			Rolling_Coverage_Block::BREAKOUT_ENTRY_CLASS,
			self::group( Breakout_Card::BOX_CLASS, $box_blocks ) . self::DATE_MARKUP . self::SHARE_MARKUP . self::SEPARATOR_MARKUP
		);
	}

	/**
	 * Columns holding a featured image beside an empty text column, as
	 * Bulletin's box does.
	 *
	 * @param string $image_attributes The image's attributes, as JSON.
	 * @return string
	 */
	private static function image_columns( string $image_attributes = '{"aspectRatio":"4/3","scale":"cover"}' ): string {
		return '<!-- wp:columns --><div class="wp-block-columns">'
			. '<!-- wp:column {"width":"11rem","className":"zz-image-column"} --><div class="wp-block-column zz-image-column" style="flex-basis:11rem"><!-- wp:post-featured-image ' . $image_attributes . ' /--></div><!-- /wp:column -->'
			. '<!-- wp:column {"className":"zz-text-column"} --><div class="wp-block-column zz-text-column"><!-- wp:paragraph --><p>Beside the image.</p><!-- /wp:paragraph --></div><!-- /wp:column -->'
			. '</div><!-- /wp:columns -->';
	}

	/**
	 * A layout's entry template: the pinned card, the entry group and,
	 * unless left out, the Full Story Entry group.
	 *
	 * @param string|null $full_story The Full Story Entry group, null for the
	 *                                default one, '' for none.
	 * @return string
	 */
	private static function template( ?string $full_story = null ): string {
		return self::group( 'newspack-rolling-coverage-pinned-card', self::TITLE_MARKUP . self::CONTENT_MARKUP . self::READ_MORE_MARKUP )
			. self::group( 'newspack-rolling-coverage-regular-entry', self::TITLE_MARKUP . self::CONTENT_MARKUP . self::READ_MORE_MARKUP . self::SEPARATOR_MARKUP )
			. ( null === $full_story ? self::full_story_group() : $full_story );
	}

	/**
	 * Create an entry and its breakout post, linked both ways.
	 *
	 * @param string $status     The breakout post's status.
	 * @param array  $post_args  The breakout post's factory arguments.
	 * @param array  $entry_args The entry's factory arguments.
	 * @param int    $coverage_id The entry's coverage; a new one when 0.
	 * @return int[] The entry ID and the breakout post ID.
	 */
	private static function create_breakout( string $status = 'publish', array $post_args = [], array $entry_args = [], int $coverage_id = 0 ): array {
		$entry_id    = self::create_entry(
			$coverage_id ? $coverage_id : self::create_coverage(),
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
	 * Give a post a featured image.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $file    The image's file name.
	 */
	private static function add_featured_image( int $post_id, string $file = 'breakout-image.jpg' ): void {
		set_post_thumbnail( $post_id, self::factory()->attachment->create_object( $file, $post_id, [ 'post_mime_type' => 'image/jpeg' ] ) );
	}

	/**
	 * Render an entry through a template.
	 *
	 * @param int    $entry_id Entry post ID.
	 * @param string $markup   Template markup.
	 * @param bool   $is_last  Whether the entry is the last to load.
	 * @return string
	 */
	private static function render( int $entry_id, string $markup = '', bool $is_last = false ): string {
		return Rolling_Coverage_Block::render_entry( get_post( $entry_id ), parse_blocks( '' !== $markup ? $markup : self::template() ), 'initial', $is_last );
	}

	/**
	 * Render a coverage's block with the given layout.
	 *
	 * @param int    $coverage_id Coverage term ID.
	 * @param string $layout      Layout markup.
	 * @return string
	 */
	private static function render_block( int $coverage_id, string $layout ): string {
		$attributes = [ 'coverageId' => $coverage_id ];
		$block      = parse_blocks( '<!-- wp:newspack-rolling-coverage/rolling-coverage ' . wp_json_encode( $attributes ) . ' -->' . $layout . '<!-- /wp:newspack-rolling-coverage/rolling-coverage -->' )[0];

		return Rolling_Coverage_Block::render_block( $attributes, '', new WP_Block( $block ) );
	}

	/**
	 * Render a coverage's block that has no layout of its own, through the
	 * render_block_data filter, as core does.
	 *
	 * @param int $coverage_id Coverage term ID.
	 * @return string
	 */
	private static function render_block_without_layout( int $coverage_id ): string {
		$attributes = [ 'coverageId' => $coverage_id ];
		$parsed     = parse_blocks( '<!-- wp:newspack-rolling-coverage/rolling-coverage ' . wp_json_encode( $attributes ) . ' /-->' )[0];
		$block      = apply_filters( 'render_block_data', $parsed, $parsed, null );

		return Rolling_Coverage_Block::render_block( $attributes, '', new WP_Block( $block ) );
	}

	/**
	 * Fetch a coverage's editor preview, as an editor.
	 *
	 * @param int $coverage_id Coverage term ID.
	 * @return array[] The previewed entries, by entry ID.
	 */
	private static function preview( int $coverage_id ): array {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$entries = rest_do_request( new WP_REST_Request( 'GET', '/' . NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE . '/coverages/' . $coverage_id . '/entries-preview' ) )->get_data();

		return array_column( $entries, null, 'id' );
	}

	/**
	 * An unpinned entry whose post is published renders the Full Story
	 * Entry group alone: neither the entry group nor the pinned card, nor
	 * the entry's own text.
	 */
	public function test_full_story_entry_renders_the_group_alone() {
		[ $entry_id, $breakout_id ] = self::create_breakout();

		$html = self::render( $entry_id );

		$this->assertStringContainsString( Rolling_Coverage_Block::BREAKOUT_ENTRY_CLASS, $html );
		$this->assertStringContainsString( Breakout_Card::BOX_CLASS, $html );
		$this->assertStringNotContainsString( 'newspack-rolling-coverage-regular-entry', $html );
		$this->assertStringNotContainsString( 'newspack-rolling-coverage-pinned-card', $html );
		$this->assertStringNotContainsString( self::ENTRY_TEXT, $html );
		$this->assertStringNotContainsString( '>Entry headline<', $html );
		$this->assertStringContainsString( '<a href="' . esc_url( get_permalink( $breakout_id ) ) . '">Post &amp; headline</a></h3>', $html );
	}

	/**
	 * Without a published post, the entry renders its entry group, the Full
	 * Story Entry group and its label left out.
	 */
	public function test_entry_without_a_published_breakout_renders_the_entry_group() {
		[ $draft_entry ] = self::create_breakout( 'draft' );
		$plain_entry     = self::create_entry( self::create_coverage(), [ 'post_content' => '<!-- wp:paragraph --><p>' . self::ENTRY_TEXT . '</p><!-- /wp:paragraph -->' ] );

		foreach ( [ $draft_entry, $plain_entry ] as $entry_id ) {
			$html = self::render( $entry_id );

			$this->assertStringContainsString( 'newspack-rolling-coverage-regular-entry', $html );
			$this->assertStringContainsString( self::ENTRY_TEXT, $html );
			$this->assertStringNotContainsString( Rolling_Coverage_Block::BREAKOUT_ENTRY_CLASS, $html );
			$this->assertStringNotContainsString( 'Full story', $html );
		}
	}

	/**
	 * A pinned entry with a published post renders the pinned card with its
	 * own text and its Read more linked to the post, with no card inside.
	 */
	public function test_pinned_entry_keeps_the_pinned_card_without_a_card() {
		[ $entry_id, $breakout_id ] = self::create_breakout();
		Post_Type::pin_entry( $entry_id );

		$html = self::render( $entry_id );

		$this->assertStringContainsString( 'newspack-rolling-coverage-pinned-card', $html );
		$this->assertStringContainsString( self::ENTRY_TEXT, $html );
		$this->assertStringContainsString( '<a href="' . esc_url( get_permalink( $breakout_id ) ) . '">Read more</a>', $html );
		$this->assertStringNotContainsString( Rolling_Coverage_Block::BREAKOUT_ENTRY_CLASS, $html );
		$this->assertStringNotContainsString( 'breakout-card', $html );
		$this->assertStringNotContainsString( 'Post &amp; headline', $html );
	}

	/**
	 * A Full Story Entry group whose box opens with the pinned row, the pin
	 * shown and the Pinned label for screen readers only, as Bulletin's does.
	 *
	 * @return string
	 */
	private static function full_story_group_with_pinned_row(): string {
		$pinned_row = '<!-- wp:group {"layout":{"type":"flex","flexWrap":"nowrap"}} --><div class="wp-block-group">'
			. '<!-- wp:icon {"icon":"newspack-rolling-coverage/pin-small"} /-->'
			. '<!-- wp:paragraph {"className":"use-header-font newspack-rolling-coverage-pinned-label screen-reader-text"} --><p class="use-header-font newspack-rolling-coverage-pinned-label screen-reader-text">Pinned</p><!-- /wp:paragraph -->'
			. '</div><!-- /wp:group -->';

		return self::full_story_group( $pinned_row . self::LABEL_MARKUP . self::TITLE_MARKUP . self::EXCERPT_MARKUP );
	}

	/**
	 * A pinned entry with a published post renders the Full Story Entry
	 * group when the group holds the pinned row, the row and its screen
	 * reader label kept, and neither the pinned card nor the entry's text.
	 */
	public function test_pinned_entry_takes_a_full_story_group_holding_the_pinned_row() {
		[ $entry_id, $breakout_id ] = self::create_breakout();
		Post_Type::pin_entry( $entry_id );

		$html = self::render( $entry_id, self::template( self::full_story_group_with_pinned_row() ) );

		$this->assertStringContainsString( Rolling_Coverage_Block::BREAKOUT_ENTRY_CLASS, $html );
		$this->assertStringContainsString( ' data-pinned', $html );
		$this->assertMatchesRegularExpression( '#<p class="[^"]*newspack-rolling-coverage-pinned-label screen-reader-text[^"]*"[^>]*>Pinned</p>#', $html );
		$this->assertStringContainsString( '<a href="' . esc_url( get_permalink( $breakout_id ) ) . '">Post &amp; headline</a></h3>', $html );
		$this->assertStringNotContainsString( 'newspack-rolling-coverage-pinned-card', $html );
		$this->assertStringNotContainsString( 'newspack-rolling-coverage-regular-entry', $html );
		$this->assertStringNotContainsString( self::ENTRY_TEXT, $html );
	}

	/**
	 * An unpinned entry rendered through a Full Story Entry group holding
	 * the pinned row drops the row, as does a pinned one in a capped feed,
	 * which shows every entry as unpinned.
	 */
	public function test_unpinned_full_story_drops_the_pinned_row() {
		[ $entry_id ] = self::create_breakout();
		$template     = parse_blocks( self::template( self::full_story_group_with_pinned_row() ) );

		[ $capped_id ] = self::create_breakout();
		Post_Type::pin_entry( $capped_id );

		$renders = [
			Rolling_Coverage_Block::render_entry( get_post( $entry_id ), $template ),
			Rolling_Coverage_Block::render_entry( get_post( $capped_id ), $template, 'initial', false, false, true ),
		];

		foreach ( $renders as $html ) {
			$this->assertStringContainsString( Rolling_Coverage_Block::BREAKOUT_ENTRY_CLASS, $html );
			$this->assertStringNotContainsString( 'newspack-rolling-coverage-pinned-label', $html );
			$this->assertStringNotContainsString( '>Pinned<', $html );
			$this->assertStringNotContainsString( ' data-pinned', $html );
		}
	}

	/**
	 * In a template without a Full Story Entry group, an entry with a
	 * published post renders as any other: its own title and text, both its
	 * title and Read more linked to the post.
	 */
	public function test_template_without_the_group_shows_a_normal_entry() {
		[ $entry_id, $breakout_id ] = self::create_breakout();
		$url                        = esc_url( get_permalink( $breakout_id ) );

		$html = self::render( $entry_id, self::template( '' ) );

		$this->assertStringContainsString( 'newspack-rolling-coverage-regular-entry', $html );
		$this->assertStringContainsString( self::ENTRY_TEXT, $html );
		$this->assertStringContainsString( '<a href="' . $url . '">Entry headline</a></h3>', $html );
		$this->assertStringContainsString( '<a href="' . $url . '">Read more</a>', $html );
		$this->assertStringNotContainsString( 'Post &amp; headline', $html );
		$this->assertStringNotContainsString( 'breakout-card', $html );
	}

	/**
	 * The last entry to load drops the separator closing its Full Story
	 * Entry group, as it does the entry group's.
	 */
	public function test_last_full_story_entry_drops_its_separator() {
		[ $entry_id ] = self::create_breakout();

		$this->assertStringContainsString( 'wp-block-separator', self::render( $entry_id ) );
		$this->assertStringNotContainsString( 'wp-block-separator', self::render( $entry_id, '', true ) );
	}

	/**
	 * An archived Full Story entry shows no archived notice, which speaks of
	 * the entry's own text.
	 */
	public function test_archived_full_story_entry_shows_no_notice() {
		[ $entry_id ] = self::create_breakout();
		update_post_meta( $entry_id, Archive_Mode::ENTRY_ARCHIVED_META_KEY, time() );

		$html = self::render(
			$entry_id,
			self::template( self::group( Rolling_Coverage_Block::BREAKOUT_ENTRY_CLASS, self::TITLE_MARKUP . self::CONTENT_MARKUP ) )
		);

		$this->assertStringContainsString( Rolling_Coverage_Block::BREAKOUT_ENTRY_CLASS, $html );
		$this->assertStringNotContainsString( 'archived', $html );
	}

	/**
	 * In a grid Feed placing the pinned card beside the entries, a Full
	 * Story entry's article takes its group's placement, the group no longer
	 * placing itself, and heading the column it carries the card's rule.
	 * The block hands the view script the group's own rule to restore.
	 */
	public function test_split_places_a_full_story_entry_and_rules_its_column() {
		$coverage_id = self::create_coverage();
		$pinned_id   = self::create_entry(
			$coverage_id,
			[
				'post_title' => 'Pinned',
				'post_date'  => gmdate( 'Y-m-d H:i:s', strtotime( '-3 hours' ) ),
			]
		);
		Post_Type::pin_entry( $pinned_id );
		[ $entry_id ] = self::create_breakout( 'publish', [], [ 'post_date' => gmdate( 'Y-m-d H:i:s', strtotime( '-1 hour' ) ) ], $coverage_id );

		$feed = '<!-- wp:group {"className":"newspack-rolling-coverage-feed","layout":{"type":"grid","columnCount":3}} --><div class="wp-block-group newspack-rolling-coverage-feed">'
			. self::group( 'newspack-rolling-coverage-pinned-card', self::TITLE_MARKUP, '{"layout":{"columnStart":1,"columnSpan":1,"rowSpan":100},"border":{"top":{"color":"#111","width":"3px","style":"solid"}}}', 'border-top-color:#111;border-top-style:solid;border-top-width:3px' )
			. self::group( 'newspack-rolling-coverage-regular-entry', self::TITLE_MARKUP, '{"layout":{"columnStart":2,"columnSpan":2},"border":{"top":{"color":"#ddd","width":"1px","style":"solid"}}}', 'border-top-color:#ddd;border-top-style:solid;border-top-width:1px' )
			. self::group( Rolling_Coverage_Block::BREAKOUT_ENTRY_CLASS, self::TITLE_MARKUP, '{"layout":{"columnStart":3,"columnSpan":1},"border":{"top":{"color":"#f00","width":"2px","style":"solid"}}}', 'border-top-color:#f00;border-top-style:solid;border-top-width:2px' )
			. '</div><!-- /wp:group -->';

		$html      = self::render_block( $coverage_id, $feed );
		$processor = new WP_HTML_Tag_Processor( $html );
		$processor->next_tag();

		$this->assertSame( self::BREAKOUT_RULE, $processor->get_attribute( 'data-breakout-rule' ) );

		$cell  = '';
		$style = null;

		while ( $processor->next_tag() ) {
			if ( 'ARTICLE' === $processor->get_tag() && (int) $processor->get_attribute( 'data-entry-id' ) === $entry_id ) {
				$this->assertNotNull( $processor->get_attribute( 'data-heads-column' ) );

				foreach ( $processor->class_list() as $class ) {
					$cell = str_starts_with( $class, 'wp-container-content-' ) ? $class : $cell;
				}
			} elseif ( '' !== $cell && null === $style && $processor->has_class( Rolling_Coverage_Block::BREAKOUT_ENTRY_CLASS ) ) {
				$style = (string) $processor->get_attribute( 'style' );

				foreach ( $processor->class_list() as $class ) {
					$this->assertStringStartsNotWith( 'wp-container-content-', $class, 'The group should not place itself inside the article.' );
				}
			}
		}

		$this->assertNotSame( '', $cell );
		$this->assertStringContainsString( '.' . $cell . '{grid-column:3 / span 1;}', wp_style_engine_get_stylesheet_from_context( 'block-supports', [ 'prettify' => false ] ) );
		$this->assertStringContainsString( 'border-top-color:#111', (string) $style, 'Heading the column, the group carries the card\'s rule.' );
		$this->assertStringNotContainsString( '#f00', (string) $style );
	}

	/**
	 * Entries a poll or Load more renders, through the stored template, show
	 * the Full Story Entry group as the page does.
	 */
	public function test_polls_render_the_full_story_entry() {
		[ $entry_id ] = self::create_breakout();
		$coverage_id  = wp_get_object_terms( $entry_id, Taxonomy::TAXONOMY_SLUG, [ 'fields' => 'ids' ] )[0];
		$page         = self::render_block( $coverage_id, self::template() );

		$this->assertSame( 1, preg_match( '/data-template-key="([^"]*)"/', $page, $template_key ) );

		$poll = self::dispatch(
			'GET',
			'/coverages/' . $coverage_id . '/entries',
			[
				'before'       => '2099-01-01 00:00:00',
				'template_key' => $template_key[1],
			]
		)->get_data()['html'];

		foreach ( [ $page, $poll ] as $html ) {
			$this->assertStringContainsString( Rolling_Coverage_Block::BREAKOUT_ENTRY_CLASS, $html );
			$this->assertStringContainsString( 'Post &amp; headline', $html );
			$this->assertStringNotContainsString( self::ENTRY_TEXT, $html );
		}
	}

	/**
	 * The group's Post Title shows the post's title linked to it, without
	 * the Full story label a compact layout puts inside the heading.
	 */
	public function test_title_is_the_posts_linked_without_a_kicker() {
		[ $entry_id, $breakout_id ] = self::create_breakout();

		$html = self::render( $entry_id );

		$this->assertMatchesRegularExpression( '#<h3 class="[^"]*wp-block-post-title[^"]*"><a href="' . preg_quote( esc_url( get_permalink( $breakout_id ) ), '#' ) . '">Post &amp; headline</a></h3>#', $html );
		$this->assertStringNotContainsString( 'newspack-rolling-coverage-breakout-label', $html );
	}

	/**
	 * The group's Post Excerpt shows the post's summary, cut to the block's
	 * own length. Without a Post Title, it leads with the post's title, and
	 * no label.
	 */
	public function test_excerpt_is_the_summary_cut_to_the_block_length() {
		[ $entry_id ] = self::create_breakout();
		$excerpt      = '<!-- wp:post-excerpt {"excerptLength":3,"moreText":""} /-->';

		$titled   = self::render( $entry_id, self::template( self::full_story_group( self::TITLE_MARKUP . $excerpt ) ) );
		$untitled = self::render( $entry_id, self::template( self::full_story_group( $excerpt ) ) );

		$this->assertStringContainsString( 'wp-block-post-excerpt__excerpt">What the post&hellip;', $titled );
		$this->assertStringContainsString( 'wp-block-post-excerpt__excerpt"><strong>Post &amp; headline</strong> What the post&hellip;', $untitled );
		$this->assertStringNotContainsString( 'breakout-label', $titled . $untitled );
	}

	/**
	 * The summary holds only what every reader may see: a members-only
	 * block never reaches it.
	 */
	public function test_summary_never_shows_members_only_text() {
		$this->use_block_visibility_stub();
		[ $entry_id ] = self::create_breakout(
			'publish',
			[
				'post_excerpt' => '',
				'post_content' => '<!-- wp:paragraph --><p>Everyone reads this.</p><!-- /wp:paragraph -->'
					. self::members_only_paragraph( 'Members read this.' ),
			]
		);

		$html = self::render( $entry_id );

		$this->assertStringContainsString( 'wp-block-post-excerpt__excerpt">Everyone reads this.', $html );
		$this->assertStringNotContainsString( 'Members read', $html );
	}

	/**
	 * A gated post's text stays out of the group: the summary is its free
	 * preview.
	 */
	public function test_restricted_post_text_stays_out() {
		$this->use_content_gate_stub();
		[ $entry_id, $breakout_id ] = self::create_breakout(
			'publish',
			[
				'post_excerpt' => '',
				'post_content' => '<!-- wp:paragraph --><p>The paywalled body.</p><!-- /wp:paragraph -->',
			]
		);
		$this->gate_entry( $breakout_id, '<p>The free opening.</p>' );

		$html = self::render( $entry_id );

		$this->assertStringContainsString( 'wp-block-post-excerpt__excerpt">The free opening.', $html );
		$this->assertStringNotContainsString( 'paywalled', $html );
	}

	/**
	 * The label paragraph shows the site's label, escaped, or the post's own
	 * over it.
	 */
	public function test_label_shows_the_site_or_post_label_escaped() {
		[ $entry_id, $breakout_id ] = self::create_breakout();

		$this->assertStringContainsString( self::LABEL_OPEN . 'Full story</p>', self::render( $entry_id ) );

		update_option( Breakout_Label::OPTION_KEY, 'Q < A & "B" <em>now</em>' );

		$this->assertStringContainsString( self::LABEL_OPEN . 'Q &lt; A &amp; &quot;B&quot; &lt;em&gt;now&lt;/em&gt;</p>', self::render( $entry_id ) );

		update_post_meta( $breakout_id, Breakout_Label::POST_META_KEY, 'Fish & chips' );
		$html = self::render( $entry_id );

		$this->assertStringContainsString( self::LABEL_OPEN . 'Fish &amp; chips</p>', $html );
		$this->assertStringNotContainsString( 'Q &lt; A', $html );
	}

	/**
	 * A label paragraph renders nothing for an entry without a published
	 * post, wherever the layout puts it, rather than the layout's own text.
	 */
	public function test_label_without_a_published_breakout_renders_nothing() {
		$markup          = self::group( 'newspack-rolling-coverage-regular-entry', self::LABEL_MARKUP . self::TITLE_MARKUP );
		[ $draft_entry ] = self::create_breakout( 'draft' );
		$plain_entry     = self::create_entry( self::create_coverage() );

		foreach ( [ $draft_entry, $plain_entry ] as $entry_id ) {
			$html = self::render( $entry_id, $markup );

			$this->assertStringNotContainsString( 'use-header-font', $html );
			$this->assertStringNotContainsString( 'Full story', $html );
		}
	}

	/**
	 * When neither the entry nor its post has a title, the label links to
	 * the post, as nothing else in the group does; otherwise it's plain.
	 */
	public function test_untitled_post_and_entry_link_the_label() {
		[ $titled_entry ]                       = self::create_breakout( 'publish', [ 'post_title' => '' ] );
		[ $untitled_entry, $untitled_breakout ] = self::create_breakout( 'publish', [ 'post_title' => '' ], [ 'post_title' => '' ] );

		$this->assertStringContainsString( self::LABEL_OPEN . 'Full story</p>', self::render( $titled_entry ) );
		$this->assertStringContainsString( self::LABEL_OPEN . '<a href="' . esc_url( get_permalink( $untitled_breakout ) ) . '">Full story</a></p>', self::render( $untitled_entry ) );
	}

	/**
	 * The group's Post Featured Image shows the post's image, not the
	 * entry's own.
	 */
	public function test_featured_image_is_the_posts() {
		[ $entry_id, $breakout_id ] = self::create_breakout();
		self::add_featured_image( $breakout_id );
		self::add_featured_image( $entry_id, 'entry-image.jpg' );

		$html = self::render( $entry_id );

		$this->assertMatchesRegularExpression( '#<figure class="[^"]*wp-block-post-featured-image[^"]*"><img [^>]*src="[^"]*breakout-image\.jpg"#', $html );
		$this->assertStringNotContainsString( 'entry-image', $html );
	}

	/**
	 * For a post without a featured image, the group drops its Post
	 * Featured Image and the column it leaves empty, so neither the entry's
	 * image nor the first image in its text shows in its place.
	 */
	public function test_post_without_an_image_drops_the_image_and_its_column() {
		[ $entry_id ] = self::create_breakout(
			'publish',
			[],
			[ 'post_content' => '<!-- wp:paragraph --><p>' . self::ENTRY_TEXT . '<img src="https://example.test/entry-inline.jpg" alt=""/></p><!-- /wp:paragraph -->' ]
		);
		self::add_featured_image( $entry_id, 'entry-image.jpg' );

		$html = self::render( $entry_id, self::template( self::full_story_group( self::image_columns( '{"useFirstImageFromPost":true}' ) . self::TITLE_MARKUP ) ) );

		$this->assertStringNotContainsString( 'wp-block-post-featured-image', $html );
		$this->assertStringNotContainsString( 'zz-image-column', $html );
		$this->assertStringContainsString( 'zz-text-column', $html );
		$this->assertStringNotContainsString( 'entry-inline', $html );
		$this->assertStringNotContainsString( 'entry-image', $html );
	}

	/**
	 * A compact layout, with no Full Story Entry, keeps the entry's own
	 * featured image, whether or not the post has one.
	 */
	public function test_compact_layout_keeps_the_entry_image() {
		[ $entry_id, $breakout_id ] = self::create_breakout();
		self::add_featured_image( $entry_id, 'entry-image.jpg' );
		$compact = self::TITLE_MARKUP . '<!-- wp:post-featured-image /-->';

		$this->assertStringContainsString( 'entry-image', self::render( $entry_id, $compact ) );

		self::add_featured_image( $breakout_id );
		$html = self::render( $entry_id, $compact );

		$this->assertStringContainsString( 'entry-image', $html );
		$this->assertStringNotContainsString( 'breakout-image', $html );
	}

	/**
	 * A Post Featured Image set to link points at the post, named by its
	 * title, without a second stop on the post for keyboard and screen
	 * reader users.
	 */
	public function test_linked_image_points_at_the_post() {
		[ $entry_id, $breakout_id ] = self::create_breakout();
		self::add_featured_image( $breakout_id );

		$html = self::render( $entry_id, self::template( self::full_story_group( '<!-- wp:post-featured-image {"isLink":true} /-->' . self::TITLE_MARKUP ) ) );

		$this->assertMatchesRegularExpression( '#<a [^>]*\bhref="' . preg_quote( esc_url( get_permalink( $breakout_id ) ), '#' ) . '"[^>]*><img [^>]*alt="Post &amp; headline"#', $html );
		$this->assertStringNotContainsString( 'alt="Entry headline"', $html );
		$this->assertMatchesRegularExpression( '#<a [^>]*\btabindex="-1"[^>]*><img #', $html, 'The image link is no second tab stop on the post.' );
		$this->assertMatchesRegularExpression( '#<a [^>]*\baria-hidden="true"[^>]*><img #', $html, 'Screen readers meet the post once, through the title.' );
		$this->assertStringNotContainsString( get_permalink( $entry_id ), $html );
	}

	/**
	 * The group's byline and Share are the entry's own: its date, and Share
	 * sharing the entry and named after it.
	 */
	public function test_byline_and_share_are_the_entrys_own() {
		[ $entry_id ] = self::create_breakout( 'publish', [ 'post_date' => '2025-02-02 10:00:00' ], [ 'post_date' => '2026-03-01 10:00:00' ] );

		$html  = self::render( $entry_id );
		$share = new WP_HTML_Tag_Processor( $html );
		$share->next_tag( [ 'class_name' => 'newspack-rolling-coverage-share' ] );
		$share->next_tag( 'a' );

		$this->assertStringContainsString( '2026-03-01', $html );
		$this->assertStringNotContainsString( '2025-02-02', $html );
		$this->assertSame( Social_Sharing::get_entry_share_url( $entry_id ), $share->get_attribute( 'href' ) );
		$this->assertSame( 'Share: Entry headline', $share->get_attribute( 'aria-label' ) );
	}

	/**
	 * The card's filters go once the group renders, so nothing rendered
	 * afterward takes the post's title, summary or image.
	 */
	public function test_filters_go_after_the_group_renders() {
		[ $entry_id ] = self::create_breakout();

		self::render( $entry_id );

		$this->assertFalse( has_filter( 'the_title', [ Breakout_Card::class, 'filter_title' ] ) );
		$this->assertFalse( has_filter( 'get_the_excerpt', [ Breakout_Card::class, 'filter_excerpt' ] ) );
		$this->assertFalse( has_filter( 'post_thumbnail_id', [ Breakout_Card::class, 'filter_thumbnail_id' ] ) );
		$this->assertFalse( has_filter( 'render_block_core/post-featured-image', [ Breakout_Card::class, 'render_featured_image' ] ) );
		$this->assertFalse( has_filter( 'render_block_core/post-title', [ Breakout_Card::class, 'label_title' ] ) );
	}

	/**
	 * The editor preview describes an entry's published post as plain text:
	 * title, summary, link, label and image ID; a draft or no post gives
	 * null. The summary holds only what every reader may see.
	 */
	public function test_preview_route_describes_the_published_breakout() {
		$this->use_block_visibility_stub();
		$coverage_id                = self::create_coverage();
		[ $published, $breakout_id ] = self::create_breakout(
			'publish',
			[
				'post_excerpt' => '',
				'post_content' => '<!-- wp:paragraph --><p>Everyone reads this.</p><!-- /wp:paragraph -->'
					. self::members_only_paragraph( 'Members read this.' ),
			],
			[],
			$coverage_id
		);
		[ $drafted ] = self::create_breakout( 'draft', [], [], $coverage_id );
		$none        = self::create_entry( $coverage_id );
		self::add_featured_image( $breakout_id );
		update_post_meta( $breakout_id, Breakout_Label::POST_META_KEY, 'Analysis' );

		$entries = self::preview( $coverage_id );

		$this->assertSame(
			[
				'title'   => 'Post & headline',
				'summary' => 'Everyone reads this.',
				'url'     => get_permalink( $breakout_id ),
				'label'   => 'Analysis',
				'imageId' => (int) get_post_thumbnail_id( $breakout_id ),
			],
			$entries[ $published ]['breakout']
		);
		$this->assertNotSame( 0, $entries[ $published ]['breakout']['imageId'] );
		$this->assertNull( $entries[ $drafted ]['breakout'] );
		$this->assertNull( $entries[ $none ]['breakout'] );
	}

	/**
	 * A block without a layout of its own renders Bulletin's, so an entry
	 * whose post is published shows as Bulletin's Full Story Entry.
	 */
	public function test_block_without_a_layout_renders_bulletins_full_story_entry() {
		$coverage_id      = self::create_coverage();
		[ , $breakout_id ] = self::create_breakout( 'publish', [], [], $coverage_id );

		$html = self::render_block_without_layout( $coverage_id );

		$this->assertStringContainsString( Rolling_Coverage_Block::BREAKOUT_ENTRY_CLASS, $html );
		$this->assertStringContainsString( Breakout_Card::BOX_CLASS, $html );
		$this->assertStringContainsString( '<a href="' . esc_url( get_permalink( $breakout_id ) ) . '">Post &amp; headline</a></h3>', $html );
		$this->assertMatchesRegularExpression( '#<a (?=[^>]*class="wp-block-button__link)(?=[^>]*href="' . preg_quote( esc_url( get_permalink( $breakout_id ) ), '#' ) . '")[^>]*>Read More</a>#', $html );
		$this->assertStringNotContainsString( self::ENTRY_TEXT, $html );
	}

}
