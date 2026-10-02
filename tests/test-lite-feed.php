<?php
/**
 * Tests for Rolling Coverage feeds on Lite Site pages.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Archive_Mode;
use Newspack_Rolling_Coverage\Lite_Feed;
use Newspack_Rolling_Coverage\Post_Type;

/**
 * Lite Site renders a post's blocks through its own content filter, then
 * strips scripts and every attribute outside its allowlist. A feed on such a
 * page renders its entries as text and keeps what the view script polls
 * with, and polls from it get entries in the same form.
 */
class Test_Lite_Feed extends Rolling_Coverage_TestCase {

	/**
	 * Coverage the entries belong to.
	 *
	 * @var int
	 */
	private $coverage_id;

	/**
	 * Load the Lite Site stand-in and create the coverage. Requests are anonymous.
	 */
	public function set_up() {
		parent::set_up();
		require_once __DIR__ . '/mocks/class-lite-site.php';
		$this->coverage_id = self::create_coverage();
		wp_set_current_user( 0 );
	}

	/**
	 * Forget any lite feed the test served. It would otherwise last for the
	 * rest of the run, as it lasts for the rest of a request.
	 */
	public function tear_down() {
		$has_feed = new ReflectionProperty( Lite_Feed::class, 'has_feed' );
		$has_feed->setAccessible( true );
		$has_feed->setValue( null, false );

		parent::tear_down();
	}

	/**
	 * An entry renders as its time, title and body, without the layout.
	 */
	public function test_entry_renders_as_text_with_its_time_title_and_body() {
		$entry_id = self::create_entry(
			$this->coverage_id,
			[
				'post_title'   => 'Bridge reopens',
				'post_content' => '<!-- wp:paragraph --><p>Traffic is <strong>moving</strong>.</p><!-- /wp:paragraph -->',
				'post_date'    => '2026-01-01 12:00:00',
			]
		);

		$this->assertSame(
			'<article class="newspack-rolling-coverage-entry" data-entry-id="' . $entry_id . '" data-arrival="initial"><p class="newspack-rolling-coverage-entry-meta"><time datetime="2026-01-01T12:00:00+00:00">12:00 pm</time></p><h3>Bridge reopens</h3><p class="wp-block-paragraph">Traffic is <strong>moving</strong>.</p></article>',
			Lite_Feed::render_entry( get_post( $entry_id ), 'initial' )
		);
	}

	/**
	 * Pinned entries say so and carry the attribute the view script places
	 * them by, and an individually archived entry keeps its notice.
	 */
	public function test_pinned_and_archived_entries_say_so() {
		$entry_id = self::create_entry(
			$this->coverage_id,
			[
				'post_title' => 'Road closed',
				'post_date'  => '2026-01-01 08:00:00',
			]
		);
		Post_Type::pin_entry( $entry_id );
		update_post_meta( $entry_id, Archive_Mode::ENTRY_ARCHIVED_META_KEY, time() );

		$html = Lite_Feed::render_entry( get_post( $entry_id ), 'poll' );

		$this->assertStringStartsWith( '<article class="newspack-rolling-coverage-entry" data-entry-id="' . $entry_id . '" data-arrival="poll" data-pinned>', $html );
		$this->assertStringContainsString( '8:00 am</time> &middot; Pinned</p>', $html );
		$this->assertStringContainsString( '<p class="newspack-rolling-coverage-entry-archived-notice">', $html );
	}

	/**
	 * Entry bodies go through Lite Site's cleaner, like the rest of the page,
	 * so polls never deliver what the page itself would have stripped.
	 */
	public function test_entry_body_is_cleaned_like_the_rest_of_a_lite_page() {
		// Saved as an administrator, so core keeps the script and the iframe.
		self::log_in_as( 'administrator' );
		$entry_id = self::create_entry( $this->coverage_id, [ 'post_content' => '<p>Before</p><script>alert(1)</script><iframe src="https://example.test/embed"></iframe>' ] );
		wp_set_current_user( 0 );

		$html = Lite_Feed::render_entry( get_post( $entry_id ), 'initial' );

		$this->assertStringContainsString( '<p>Before</p>', $html );
		$this->assertStringNotContainsString( 'alert(1)', $html, 'Scripts are dropped with their contents.' );
		$this->assertStringNotContainsString( '<iframe', $html, 'Embeds are stripped.' );
	}

	/**
	 * The allowlist only grows once the request serves a lite feed, and then
	 * keeps what the view script reads.
	 */
	public function test_allowlist_grows_only_once_a_feed_is_served() {
		$allowed = [
			'div' => [ 'class' => true ],
			'a'   => [ 'href' => true ],
		];

		$this->assertSame( $allowed, apply_filters( 'newspack_lite_site_allowed_html', $allowed ), 'A lite page without a feed keeps the list it had.' );

		Lite_Feed::add_feed();
		$grown = apply_filters( 'newspack_lite_site_allowed_html', $allowed );

		$this->assertTrue( $grown['div']['class'], 'Attributes already on the list stay.' );
		$this->assertTrue( $grown['div']['data-*'], 'The feed settings survive.' );
		$this->assertTrue( $grown['div']['hidden'], 'The new-posts control stays hidden.' );
		$this->assertTrue( $grown['div']['role'], 'The status region keeps its role.' );
		$this->assertTrue( $grown['div']['aria-live'], 'The status region keeps announcing.' );
		$this->assertTrue( $grown['div']['aria-hidden'], 'The sentinel stays hidden from screen readers.' );
		$this->assertTrue( $grown['article']['class'], 'Entries keep their class.' );
		$this->assertTrue( $grown['article']['data-*'], 'Entries keep their IDs.' );
		$this->assertTrue( $grown['time']['datetime'], 'Entry times keep their machine-readable date.' );
		$this->assertTrue( $grown['a']['href'], 'Links keep their targets.' );
		$this->assertTrue( $grown['a']['data-*'], 'The new-posts link keeps its marker.' );
	}
}
