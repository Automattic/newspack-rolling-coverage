<?php
/**
 * Tests for the words a compact layout shows for an entry: its generated
 * excerpt, and the headline an untitled entry falls back to.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Entry_Bindings;
use Newspack_Rolling_Coverage\Rolling_Coverage_Block;

/**
 * Every layout that summarizes an entry reads the same words: the text of
 * its blocks without their media. The excerpt takes all of them, and an
 * untitled entry's headline takes the first block that has any.
 */
class Test_Entry_Summary extends Rolling_Coverage_TestCase {

	const TITLE_MARKUP = '<!-- wp:post-title {"level":4,"isLink":true,"className":"newspack-rolling-coverage-entry-link"} /-->';

	const EXCERPT_MARKUP = '<!-- wp:post-excerpt {"excerptLength":100,"moreText":""} /-->';

	const LIST_ENTRY = '<!-- wp:paragraph --><p>Roads closed as of 4pm:</p><!-- /wp:paragraph --><!-- wp:list --><ul class="wp-block-list"><!-- wp:list-item --><li>Coast Road between Gull Point and the lighthouse</li><!-- /wp:list-item --><!-- wp:list-item --><li>Station Road at the railway bridge</li><!-- /wp:list-item --></ul><!-- /wp:list -->';

	/**
	 * Create an untitled entry with the given content and no excerpt.
	 *
	 * @param string $content Entry content.
	 * @return int Entry post ID.
	 */
	private static function create_untitled_entry( string $content ): int {
		return self::create_entry(
			self::create_coverage(),
			[
				'post_title'   => '',
				'post_excerpt' => '',
				'post_content' => $content,
			]
		);
	}

	/**
	 * An embed block of the given URL.
	 *
	 * @param string $url     The embedded URL.
	 * @param string $caption Caption, if any.
	 * @return string Block markup.
	 */
	private static function embed( string $url, string $caption = '' ): string {
		return '<!-- wp:embed {"url":"' . $url . '","type":"rich","providerNameSlug":"example"} --><figure class="wp-block-embed is-type-rich is-provider-example"><div class="wp-block-embed__wrapper">
' . $url . '
</div>' . ( '' !== $caption ? '<figcaption class="wp-element-caption">' . $caption . '</figcaption>' : '' ) . '</figure><!-- /wp:embed -->';
	}

	/**
	 * A list's items count in the excerpt, which core's generated excerpt
	 * leaves out, and the headline stops at the end of the first block.
	 */
	public function test_list_items_count_in_the_excerpt() {
		$entry_id = self::create_untitled_entry( self::LIST_ENTRY );

		$this->assertSame( 'Roads closed as of 4pm: Coast Road between Gull Point and the lighthouse Station Road at the railway bridge', get_the_excerpt( $entry_id ) );
		$this->assertSame( 'Roads closed as of 4pm:', Entry_Bindings::get_fallback_title( get_post( $entry_id ) ) );
	}

	/**
	 * The site shows the same words in a title and an excerpt block.
	 */
	public function test_the_site_shows_the_headline_and_the_whole_excerpt() {
		$entry_id = self::create_untitled_entry( self::LIST_ENTRY );

		$html = Rolling_Coverage_Block::render_entry( get_post( $entry_id ), parse_blocks( self::TITLE_MARKUP . self::EXCERPT_MARKUP ) );

		$this->assertStringContainsString( '>Roads closed as of 4pm:</a></h4>', $html );
		$this->assertStringContainsString( 'wp-block-post-excerpt__excerpt">Roads closed as of 4pm: Coast Road between Gull Point and the lighthouse Station Road at the railway bridge', $html );
	}

	/**
	 * A heading is a headline on its own, and the excerpt reads on past it.
	 */
	public function test_headline_is_the_first_block_with_words() {
		$entry_id = self::create_untitled_entry( '<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">What the council said</h3><!-- /wp:heading --><!-- wp:paragraph --><p>The emergency committee met at 3pm.</p><!-- /wp:paragraph -->' );

		$this->assertSame( 'What the council said', Entry_Bindings::get_fallback_title( get_post( $entry_id ) ) );
		$this->assertSame( 'What the council said The emergency committee met at 3pm.', get_the_excerpt( $entry_id ) );
	}

	/**
	 * The first block with words can sit inside a group, after a photo.
	 */
	public function test_headline_looks_inside_wrappers_and_past_media() {
		$entry_id = self::create_untitled_entry( '<!-- wp:image --><figure class="wp-block-image"><img src="https://example.com/a.jpg" alt="Sandbags"/><figcaption class="wp-element-caption">Sandbags on the quay</figcaption></figure><!-- /wp:image --><!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>Shops have been sandbagging since noon.</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>More are available from the depot.</p><!-- /wp:paragraph --></div><!-- /wp:group -->' );

		$this->assertSame( 'Shops have been sandbagging since noon.', Entry_Bindings::get_fallback_title( get_post( $entry_id ) ) );
		$this->assertSame( 'Shops have been sandbagging since noon. More are available from the depot.', get_the_excerpt( $entry_id ) );
	}

	/**
	 * An embed beside text adds nothing: neither its URL nor its caption.
	 */
	public function test_embed_contributes_no_words() {
		$entry_id = self::create_untitled_entry( '<!-- wp:paragraph --><p>Our full report is now up.</p><!-- /wp:paragraph -->' . self::embed( 'https://example.com/2026/10/09/full-time/', 'The report' ) );

		$this->assertSame( 'Our full report is now up.', Entry_Bindings::get_fallback_title( get_post( $entry_id ) ) );
		$this->assertSame( 'Our full report is now up.', get_the_excerpt( $entry_id ) );
	}

	/**
	 * An entry holding only an embed of a post on this site is named after
	 * that post.
	 */
	public function test_embed_of_a_post_on_this_site_is_named_after_it() {
		$post_id  = self::factory()->post->create( [ 'post_title' => 'Full time: Riverbend 2, Fresno &amp; Verde 1' ] );
		$entry_id = self::create_untitled_entry( self::embed( get_permalink( $post_id ) ) );

		$this->assertSame( 'Full time: Riverbend 2, Fresno & Verde 1', Entry_Bindings::get_fallback_title( get_post( $entry_id ) ) );
		$this->assertSame( 'Full time: Riverbend 2, Fresno &amp; Verde 1', get_the_excerpt( $entry_id ) );
	}

	/**
	 * The post's pretty permalink, as the embed card of a breakout post
	 * stores it, resolves too.
	 */
	public function test_embed_of_a_post_on_this_site_resolves_a_pretty_permalink() {
		$this->set_permalink_structure( '/%year%/%monthnum%/%day%/%postname%/' );
		$post_id  = self::factory()->post->create( [ 'post_title' => 'Full time' ] );
		$entry_id = self::create_untitled_entry( self::embed( get_permalink( $post_id ) ) );

		$this->assertStringContainsString( '/full-time/', get_permalink( $post_id ) );
		$this->assertSame( 'Full time', Entry_Bindings::get_fallback_title( get_post( $entry_id ) ) );
	}

	/**
	 * A post readers can't open, or one without a title, isn't named: the
	 * embed names its host instead.
	 */
	public function test_embed_of_an_unpublished_or_untitled_post_names_the_host() {
		$draft_id    = self::factory()->post->create(
			[
				'post_title'  => 'Not yet',
				'post_status' => 'draft',
			]
		);
		$untitled_id = self::factory()->post->create( [ 'post_title' => '' ] );

		$this->assertSame( 'Embed from example.org', Entry_Bindings::get_fallback_title( get_post( self::create_untitled_entry( self::embed( get_permalink( $draft_id ) ) ) ) ) );
		$this->assertSame( 'Embed from example.org', Entry_Bindings::get_fallback_title( get_post( self::create_untitled_entry( self::embed( get_permalink( $untitled_id ) ) ) ) ) );
	}

	/**
	 * Two untitled entries that embed each other name each other's host
	 * rather than looping through the title filter.
	 */
	public function test_entries_embedding_each_other_do_not_recurse() {
		$first_id  = self::create_untitled_entry( '' );
		$second_id = self::create_untitled_entry( self::embed( get_permalink( $first_id ) ) );

		wp_update_post(
			[
				'ID'           => $first_id,
				'post_content' => self::embed( get_permalink( $second_id ) ),
			]
		);

		$html = Rolling_Coverage_Block::render_entry( get_post( $first_id ), parse_blocks( self::TITLE_MARKUP . self::EXCERPT_MARKUP ) );

		$this->assertStringContainsString( '>Embed from example.org</a></h4>', $html );
	}

	/**
	 * A block hidden with Hide block stays out of the excerpt and the
	 * headline, and doesn't stop a media entry getting its media title.
	 */
	public function test_hidden_blocks_add_no_words() {
		$hidden   = '<!-- wp:paragraph {"metadata":{"blockVisibility":false}} --><p>Hidden draft line.</p><!-- /wp:paragraph -->';
		$text_id  = self::create_untitled_entry( $hidden . '<!-- wp:paragraph --><p>The visible line.</p><!-- /wp:paragraph -->' );
		$photo_id = self::create_untitled_entry( $hidden . '<!-- wp:image --><figure class="wp-block-image"><img src="https://example.com/a.jpg" alt=""/></figure><!-- /wp:image -->' );

		$this->assertSame( 'The visible line.', get_the_excerpt( $text_id ) );
		$this->assertSame( 'The visible line.', Entry_Bindings::get_fallback_title( get_post( $text_id ) ) );
		$this->assertSame( 'Photo', Entry_Bindings::get_fallback_title( get_post( $photo_id ) ) );
	}

	/**
	 * A control's label, such as a file's download link or a button, isn't
	 * one of the entry's words.
	 */
	public function test_controls_add_no_words() {
		$entry_id = self::create_untitled_entry( '<!-- wp:file {"href":"https://example.com/report.pdf"} --><div class="wp-block-file"><a href="https://example.com/report.pdf">report.pdf</a><a href="https://example.com/report.pdf" class="wp-block-file__button wp-element-button" download>Download</a></div><!-- /wp:file --><!-- wp:paragraph --><p>The full report is attached.</p><!-- /wp:paragraph --><!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="https://example.com/donate">Donate</a></div><!-- /wp:button --></div><!-- /wp:buttons -->' );

		$this->assertSame( 'The full report is attached.', get_the_excerpt( $entry_id ) );
		$this->assertSame( 'The full report is attached.', Entry_Bindings::get_fallback_title( get_post( $entry_id ) ) );
	}

	/**
	 * An entry Newspack's content gate withholds keeps the excerpt the
	 * filters before this one built.
	 */
	public function test_withheld_entry_keeps_the_upstream_excerpt() {
		$this->use_content_gate_stub();
		$entry_id = self::create_untitled_entry( self::LIST_ENTRY );

		\Newspack\Content_Gate::$withheld = [ $entry_id ];

		$this->assertSame( 'The teaser.', apply_filters( 'get_the_excerpt', 'The teaser.', get_post( $entry_id ) ) );
	}

	/**
	 * An entry a WooCommerce Memberships rule restricts keeps the excerpt
	 * the filters before this one built.
	 */
	public function test_membership_restricted_entry_keeps_the_upstream_excerpt() {
		$this->use_wc_memberships_stub();
		$entry_id = self::create_untitled_entry( self::LIST_ENTRY );

		$GLOBALS['newspack_rolling_coverage_restricted_posts'] = [ $entry_id ];

		$this->assertSame( 'The teaser.', apply_filters( 'get_the_excerpt', 'The teaser.', get_post( $entry_id ) ) );
	}

	/**
	 * The words everyone may read, for share names and push notifications,
	 * leave media out the same way, so a media-only entry has none; its
	 * breakout post is titled after its media instead.
	 */
	public function test_public_summary_reads_the_same_words() {
		$text_id  = self::create_untitled_entry( '<!-- wp:paragraph --><p>Our full report is now up.</p><!-- /wp:paragraph -->' . self::embed( 'https://example.com/2026/10/09/full-time/' ) );
		$photo_id = self::create_untitled_entry( '<!-- wp:image --><figure class="wp-block-image"><img src="https://example.com/a.jpg" alt="Crews on the quay"/><figcaption class="wp-element-caption">Crews on the quay</figcaption></figure><!-- /wp:image -->' );

		$this->assertSame( 'Our full report is now up.', Entry_Bindings::public_summary( get_post( $text_id ) ) );
		$this->assertSame( '', Entry_Bindings::public_summary( get_post( $photo_id ) ) );

		self::log_in_as( 'editor' );
		$response = rest_do_request( new WP_REST_Request( 'POST', '/' . NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE . '/entries/' . $photo_id . '/breakout' ) );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'Photo: Crews on the quay', get_post( $response->get_data()['breakoutPostId'] )->post_title );
	}

	/**
	 * An embed from elsewhere names the site it comes from, and a caption
	 * describes it instead.
	 */
	public function test_embed_from_elsewhere_names_its_host() {
		$this->assertSame( 'Embed from x.com', Entry_Bindings::get_fallback_title( get_post( self::create_untitled_entry( self::embed( 'https://www.x.com/example/status/1' ) ) ) ) );
		$this->assertSame( 'Embed: The thread in full', Entry_Bindings::get_fallback_title( get_post( self::create_untitled_entry( self::embed( 'https://www.x.com/example/status/1', 'The thread in full' ) ) ) ) );
	}

	/**
	 * The excerpt is cut to the site's length and ends with its "more" text.
	 */
	public function test_excerpt_honors_the_site_length_and_more_text() {
		$entry_id = self::create_untitled_entry( self::LIST_ENTRY );
		$length   = static fn() => 6;
		$more     = static fn() => ' [&hellip;]';

		add_filter( 'excerpt_length', $length );
		add_filter( 'excerpt_more', $more );
		try {
			$excerpt = get_the_excerpt( $entry_id );
		} finally {
			remove_filter( 'excerpt_length', $length );
			remove_filter( 'excerpt_more', $more );
		}

		$this->assertSame( 'Roads closed as of 4pm: Coast [&hellip;]', $excerpt );
	}

	/**
	 * A hand-written excerpt is left as it is.
	 */
	public function test_hand_written_excerpt_wins() {
		$entry_id = self::create_entry(
			self::create_coverage(),
			[
				'post_title'   => '',
				'post_excerpt' => 'All the closures',
				'post_content' => self::LIST_ENTRY,
			]
		);

		$this->assertSame( 'All the closures', get_the_excerpt( $entry_id ) );
		$this->assertSame( 'All the closures', Entry_Bindings::get_fallback_title( get_post( $entry_id ) ) );
	}
}
