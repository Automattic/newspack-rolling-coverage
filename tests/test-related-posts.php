<?php
/**
 * Tests that Jetpack's Related Posts stays with the post holding the feed.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Post_Type;

/**
 * On a single post's page, Jetpack adds its Related Posts placeholder to
 * every `the_content` the request runs, and the feed runs each entry's
 * content through `the_content` inside the post's own. Its script fills the
 * first placeholder on the page, so a placeholder in an entry shows the
 * post's related posts in the feed's first entry, usually the pinned one.
 */
class Test_Related_Posts extends Rolling_Coverage_TestCase {

	/**
	 * What marks the placeholder in rendered content.
	 */
	const PLACEHOLDER = "id='jp-relatedposts'";

	/**
	 * Load the stand-in for the module, and register the feed block so a
	 * post's content renders it.
	 */
	public function set_up() {
		parent::set_up();
		require_once __DIR__ . '/mocks/class-jetpack-relatedposts.php';
		$this->register_feed_block();
	}

	/**
	 * Render the content of a post holding a feed of a pinned entry and a
	 * newer one, as the post's own page does with the module running.
	 *
	 * @return string The post's rendered content.
	 */
	private function render_post_with_feed(): string {
		$coverage_id   = self::create_coverage();
		$entry_content = '<!-- wp:paragraph --><p>Entry text</p><!-- /wp:paragraph -->';

		self::create_entry(
			$coverage_id,
			[
				'post_date'    => '2026-01-01 12:00:00',
				'post_content' => $entry_content,
			]
		);
		Post_Type::pin_entry(
			self::create_entry(
				$coverage_id,
				[
					'post_date'    => '2026-01-01 11:00:00',
					'post_content' => $entry_content,
				]
			)
		);

		$post_id = self::factory()->post->create(
			[
				'post_content' => '<!-- wp:paragraph --><p>Post text</p><!-- /wp:paragraph -->'
					. '<!-- wp:newspack-rolling-coverage/rolling-coverage {"coverageId":' . $coverage_id . '} --><!-- wp:post-content /--><!-- /wp:newspack-rolling-coverage/rolling-coverage -->',
			]
		);

		$this->go_to( get_permalink( $post_id ) );
		the_post();
		Jetpack_RelatedPosts::init()->action_frontend_init();

		return apply_filters( 'the_content', get_the_content() );
	}

	/**
	 * The post's placeholder is the only one, and it follows the feed, so the
	 * module fills it rather than one in an entry.
	 */
	public function test_only_the_post_gets_the_related_posts_placeholder() {
		$html = $this->render_post_with_feed();

		$this->assertSame( 2, substr_count( $html, 'Entry text' ), "The feed should show both entries' content." );
		$this->assertSame( 1, substr_count( $html, self::PLACEHOLDER ), 'Only the post should get a placeholder.' );
		$this->assertGreaterThan( strrpos( $html, '</article>' ), strpos( $html, self::PLACEHOLDER ), 'The placeholder should follow the feed.' );
	}
}
