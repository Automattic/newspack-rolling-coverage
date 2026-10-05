<?php
/**
 * Tests for the media title of an entry whose only content is media.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Entry_Bindings;
use Newspack_Rolling_Coverage\Post_Type;
use Newspack_Rolling_Coverage\Rolling_Coverage_Block;

/**
 * An entry with no words outside its media is described by its first media
 * block, e.g. "Photo: Crowds at the finish line": as its excerpt, and as its
 * title when it has none.
 */
class Test_Untitled_Media_Title extends Rolling_Coverage_TestCase {

	const TITLE_MARKUP = '<!-- wp:post-title {"level":4,"isLink":true,"className":"newspack-rolling-coverage-entry-link"} /-->';

	const EXCERPT_MARKUP = '<!-- wp:post-excerpt {"excerptLength":15,"moreText":""} /-->';

	const CAPTIONED_IMAGE = '<!-- wp:image --><figure class="wp-block-image"><img src="https://example.com/finish.jpg" alt="Runners at the line"/><figcaption class="wp-element-caption">Crowds <em>at</em> the finish &amp; line</figcaption></figure><!-- /wp:image -->';

	/**
	 * Create an untitled entry with the given content and no excerpt.
	 *
	 * @param string $content     Entry content.
	 * @param int    $coverage_id Coverage term ID, or 0 for a new coverage.
	 * @return int Entry post ID.
	 */
	private static function create_untitled_entry( string $content, int $coverage_id = 0 ): int {
		return self::create_entry(
			$coverage_id ? $coverage_id : self::create_coverage(),
			[
				'post_title'   => '',
				'post_excerpt' => '',
				'post_content' => $content,
			]
		);
	}

	/**
	 * The title an entry with the given content falls back to.
	 *
	 * @param string $content Entry content.
	 * @return string
	 */
	private static function fallback_title( string $content ): string {
		return Entry_Bindings::get_fallback_title( get_post( self::create_untitled_entry( $content ) ) );
	}

	/**
	 * An entry's excerpt as plain text.
	 *
	 * @param int $entry_id Entry post ID.
	 * @return string
	 */
	private static function excerpt( int $entry_id ): string {
		return html_entity_decode( get_the_excerpt( $entry_id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * An entry holding only media is titled after the kind of its first
	 * media block, and has that as its excerpt.
	 *
	 * @dataProvider data_media_labels
	 *
	 * @param string $content  Entry content.
	 * @param string $expected The title.
	 */
	public function test_media_only_entry_is_titled_after_its_media( string $content, string $expected ) {
		$entry_id = self::create_untitled_entry( $content );

		$this->assertSame( $expected, Entry_Bindings::get_fallback_title( get_post( $entry_id ) ), 'The title.' );
		$this->assertSame( $expected, self::excerpt( $entry_id ), 'The excerpt.' );
	}

	/**
	 * Media blocks without a caption or alt text, with the label each gets.
	 *
	 * @return array[]
	 */
	public function data_media_labels(): array {
		$embed = static fn( string $provider, string $type ) => '<!-- wp:embed {"url":"https://example.com/media","type":"' . $type . '","providerNameSlug":"' . $provider . '"} --><figure class="wp-block-embed is-type-' . $type . ' is-provider-' . $provider . '"><div class="wp-block-embed__wrapper">
https://example.com/media
</div></figure><!-- /wp:embed -->';

		return [
			'image'             => [ '<!-- wp:image --><figure class="wp-block-image"><img src="https://example.com/a.jpg" alt=""/></figure><!-- /wp:image -->', 'Photo' ],
			'gallery'           => [ '<!-- wp:gallery {"linkTo":"none"} --><figure class="wp-block-gallery has-nested-images"><!-- wp:image --><figure class="wp-block-image"><img src="https://example.com/a.jpg" alt="Inside the gallery"/><figcaption class="wp-element-caption">One photo of several</figcaption></figure><!-- /wp:image --></figure><!-- /wp:gallery -->', 'Gallery' ],
			'video'             => [ '<!-- wp:video --><figure class="wp-block-video"><video controls src="https://example.com/a.mp4"></video></figure><!-- /wp:video -->', 'Video' ],
			'audio'             => [ '<!-- wp:audio --><figure class="wp-block-audio"><audio controls src="https://example.com/a.mp3"></audio></figure><!-- /wp:audio -->', 'Audio' ],
			'video embed'       => [ $embed( 'youtube', 'video' ), 'Video' ],
			'other video embed' => [ $embed( 'example', 'video' ), 'Video' ],
			'audio embed'       => [ $embed( 'spotify', 'rich' ), 'Audio' ],
			'other embed'       => [ $embed( 'twitter', 'rich' ), 'Embed' ],
			'photo embed'       => [ $embed( 'flickr', 'photo' ), 'Photo' ],
			'spaces only'       => [ '<!-- wp:paragraph --><p>&nbsp; &nbsp; &nbsp;</p><!-- /wp:paragraph --><!-- wp:image --><figure class="wp-block-image"><img src="https://example.com/a.jpg" alt=""/></figure><!-- /wp:image -->', 'Photo' ],
			'image cover'       => [ '<!-- wp:cover {"url":"https://example.com/a.jpg","dimRatio":50} --><div class="wp-block-cover"><img class="wp-block-cover__image-background" alt="" src="https://example.com/a.jpg"/><span class="wp-block-cover__background has-background-dim"></span><div class="wp-block-cover__inner-container"><!-- wp:paragraph --><p></p><!-- /wp:paragraph --></div></div><!-- /wp:cover -->', 'Photo' ],
			'video cover'       => [ '<!-- wp:cover {"url":"https://example.com/a.mp4","backgroundType":"video"} --><div class="wp-block-cover"><video class="wp-block-cover__video-background" src="https://example.com/a.mp4"></video><div class="wp-block-cover__inner-container"></div></div><!-- /wp:cover -->', 'Video' ],
			'nested image'      => [ '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>&nbsp;</p><!-- /wp:paragraph --><!-- wp:image --><figure class="wp-block-image"><img src="https://example.com/a.jpg" alt=""/></figure><!-- /wp:image --></div><!-- /wp:group -->', 'Photo' ],
			'first media wins'  => [ '<!-- wp:video --><figure class="wp-block-video"><video controls src="https://example.com/a.mp4"></video></figure><!-- /wp:video -->' . self::CAPTIONED_IMAGE, 'Video' ],
		];
	}

	/**
	 * A media block's caption follows the label, as plain text cut to the
	 * untitled title's length, and wins over an image's alt text.
	 */
	public function test_caption_follows_the_label() {
		$this->assertSame( 'Photo: Crowds at the finish & line', self::fallback_title( self::CAPTIONED_IMAGE ) );
		$this->assertSame(
			'Audio: one two three four five six seven eight nine ten eleven twelve thirteen fourteen fifteen…',
			self::fallback_title( '<!-- wp:audio --><figure class="wp-block-audio"><audio controls src="https://example.com/a.mp3"></audio><figcaption class="wp-element-caption">one two three four five six seven eight nine ten eleven twelve thirteen fourteen fifteen sixteen</figcaption></figure><!-- /wp:audio -->' )
		);
		$this->assertSame(
			'Gallery: The parade route',
			self::fallback_title( '<!-- wp:gallery {"linkTo":"none"} --><figure class="wp-block-gallery has-nested-images"><!-- wp:image --><figure class="wp-block-image"><img src="https://example.com/a.jpg" alt=""/></figure><!-- /wp:image --><figcaption class="blocks-gallery-caption wp-element-caption">The parade route</figcaption></figure><!-- /wp:gallery -->' )
		);
	}

	/**
	 * Without a caption, an image's alt text follows the label, as does the
	 * alt text of a cover's image or the text over the cover.
	 */
	public function test_alt_text_follows_the_label_without_a_caption() {
		$this->assertSame( 'Photo: Runners & <b> crowd', self::fallback_title( '<!-- wp:image --><figure class="wp-block-image"><img src="https://example.com/a.jpg" alt="Runners &amp; &lt;b&gt; crowd"/></figure><!-- /wp:image -->' ) );
		$this->assertSame( 'Photo: The square at dawn', self::fallback_title( '<!-- wp:cover {"url":"https://example.com/a.jpg","alt":"The square at dawn"} --><div class="wp-block-cover"><img class="wp-block-cover__image-background" alt="The square at dawn" src="https://example.com/a.jpg"/><div class="wp-block-cover__inner-container"></div></div><!-- /wp:cover -->' ) );
		$this->assertSame( 'Photo: Over the line', self::fallback_title( '<!-- wp:cover {"url":"https://example.com/a.jpg","alt":"The square at dawn"} --><div class="wp-block-cover"><img class="wp-block-cover__image-background" alt="The square at dawn" src="https://example.com/a.jpg"/><div class="wp-block-cover__inner-container"><!-- wp:paragraph --><p>Over the line</p><!-- /wp:paragraph --></div></div><!-- /wp:cover -->' ) );
	}

	/**
	 * An entry with words outside its media keeps its opening words as its
	 * title and core's excerpt.
	 */
	public function test_entry_with_text_keeps_its_opening_words() {
		$entry_id = self::create_untitled_entry( '<!-- wp:paragraph --><p>Crews are clearing the road.</p><!-- /wp:paragraph -->' . self::CAPTIONED_IMAGE );

		$this->assertSame( 'Crews are clearing the road. Crowds at the finish & line', Entry_Bindings::get_fallback_title( get_post( $entry_id ) ) );
		$this->assertSame( 'Crews are clearing the road.', self::excerpt( $entry_id ) );
	}

	/**
	 * Text in a block core leaves out of the excerpt, or in a synced pattern,
	 * still counts as the entry's own words, so it gets no media title.
	 *
	 * @dataProvider data_unexcerpted_words
	 *
	 * @param string $content Entry content before the photo.
	 */
	public function test_words_core_leaves_out_still_count( string $content ) {
		$entry_id = self::create_untitled_entry( $content . self::CAPTIONED_IMAGE );

		$this->assertStringNotContainsString( 'Photo', Entry_Bindings::get_fallback_title( get_post( $entry_id ) ) );
		$this->assertStringNotContainsString( 'Photo', self::excerpt( $entry_id ) );
	}

	/**
	 * Words the generated excerpt leaves out.
	 *
	 * @return array[]
	 */
	public function data_unexcerpted_words(): array {
		return [
			'code'           => [ '<!-- wp:code --><pre class="wp-block-code"><code>npm run build</code></pre><!-- /wp:code -->' ],
			'synced pattern' => [ '<!-- wp:block {"ref":123} /-->' ],
		];
	}

	/**
	 * An embed's caption follows its label.
	 */
	public function test_embed_caption_follows_the_label() {
		$this->assertSame(
			'Video: Drone footage of the flood',
			self::fallback_title(
				'<!-- wp:embed {"url":"https://www.youtube.com/watch?v=1","type":"video","providerNameSlug":"youtube"} --><figure class="wp-block-embed is-type-video is-provider-youtube"><div class="wp-block-embed__wrapper">
https://www.youtube.com/watch?v=1
</div><figcaption class="wp-element-caption">Drone footage of the flood</figcaption></figure><!-- /wp:embed -->' 
			)
		);
	}

	/**
	 * Literal entity text in a caption or alt text survives as typed.
	 */
	public function test_literal_entities_survive() {
		$this->assertSame( 'Photo: R&amp;D lab', self::fallback_title( '<!-- wp:image --><figure class="wp-block-image"><img src="https://example.com/a.jpg" alt="R&amp;amp;D lab"/></figure><!-- /wp:image -->' ) );
	}

	/**
	 * A password-protected media entry gives nothing away: no title, and no
	 * excerpt on the site.
	 */
	public function test_protected_media_entry_has_no_title_or_excerpt() {
		$entry_id = self::create_untitled_entry( self::CAPTIONED_IMAGE );
		wp_update_post(
			[
				'ID'            => $entry_id,
				'post_password' => 'secret',
			]
		);

		$this->assertSame( '', Entry_Bindings::get_fallback_title( get_post( $entry_id ) ) );
		$this->assertStringNotContainsString( 'Crowds', get_the_excerpt( $entry_id ) );
	}

	/**
	 * A hand-written excerpt wins over the media, as title and excerpt.
	 */
	public function test_hand_written_excerpt_wins_over_the_media() {
		$entry_id = self::create_untitled_entry( self::CAPTIONED_IMAGE );
		wp_update_post(
			[
				'ID'           => $entry_id,
				'post_excerpt' => 'Thousands lined the route.',
			]
		);

		$this->assertSame( 'Thousands lined the route.', Entry_Bindings::get_fallback_title( get_post( $entry_id ) ) );
		$this->assertSame( 'Thousands lined the route.', self::excerpt( $entry_id ) );
	}

	/**
	 * An entry without media or words has no title or excerpt, as before.
	 */
	public function test_entry_without_media_or_words_has_no_title_or_excerpt() {
		$entry_id = self::create_untitled_entry( '<!-- wp:separator --><hr class="wp-block-separator has-alpha-channel-opacity"/><!-- /wp:separator -->' );

		$this->assertSame( '', Entry_Bindings::get_fallback_title( get_post( $entry_id ) ) );
		$this->assertSame( '', self::excerpt( $entry_id ) );
	}

	/**
	 * Posts other than entries keep core's empty excerpt.
	 */
	public function test_other_posts_keep_their_excerpt() {
		$post_id = self::factory()->post->create(
			[
				'post_excerpt' => '',
				'post_content' => self::CAPTIONED_IMAGE,
			]
		);

		$this->assertSame( '', get_the_excerpt( $post_id ) );
	}

	/**
	 * On the site, an untitled media entry shows the media title as its
	 * linked title, escaped, and as its excerpt; a titled one keeps its own
	 * title, with the media title as its excerpt.
	 */
	public function test_the_site_shows_the_media_title_as_title_and_excerpt() {
		$untitled = self::create_untitled_entry( self::CAPTIONED_IMAGE );
		$titled   = self::create_entry(
			self::create_coverage(),
			[
				'post_title'   => 'At the finish',
				'post_excerpt' => '',
				'post_content' => self::CAPTIONED_IMAGE,
			]
		);
		$template = parse_blocks( self::TITLE_MARKUP . self::EXCERPT_MARKUP );

		$html = Rolling_Coverage_Block::render_entry( get_post( $untitled ), $template );

		$this->assertStringContainsString( '>Photo: Crowds at the finish &amp; line</a></h4>', $html );
		$this->assertStringContainsString( 'wp-block-post-excerpt__excerpt">Photo: Crowds at the finish &amp; line', $html );

		$html = Rolling_Coverage_Block::render_entry( get_post( $titled ), $template );

		$this->assertStringContainsString( '>At the finish</a></h4>', $html );
		$this->assertStringContainsString( 'wp-block-post-excerpt__excerpt">Photo: Crowds at the finish &amp; line', $html );
	}

	/**
	 * The editor gets the media title of an untitled entry for its title,
	 * none for a titled one, and the media title as both entries' excerpt.
	 */
	public function test_the_editor_gets_the_media_title_and_excerpt() {
		$coverage_id = self::create_coverage();
		$untitled    = self::create_untitled_entry( self::CAPTIONED_IMAGE, $coverage_id );
		$titled      = self::create_entry(
			$coverage_id,
			[
				'post_title'   => 'At the finish',
				'post_excerpt' => '',
				'post_content' => self::CAPTIONED_IMAGE,
			]
		);
		self::log_in_as( 'editor' );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/' . NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE . '/coverages/' . $coverage_id . '/entries-preview' ) );
		$titles   = wp_list_pluck( $response->get_data(), 'fallbackTitle', 'id' );

		$this->assertSame( 'Photo: Crowds at the finish & line', $titles[ $untitled ] );
		$this->assertSame( '', $titles[ $titled ] );

		foreach ( [ $untitled, $titled ] as $entry_id ) {
			$request = new WP_REST_Request( 'GET', '/wp/v2/' . Post_Type::REST_BASE . '/' . $entry_id );
			$request->set_param( 'context', 'edit' );
			$excerpt = rest_get_server()->dispatch( $request )->get_data()['excerpt'];

			$this->assertSame( 'Photo: Crowds at the finish & line', $excerpt['raw'] );
			$this->assertStringContainsString( 'Photo: Crowds at the finish &amp; line', $excerpt['rendered'] );
		}
	}
}
