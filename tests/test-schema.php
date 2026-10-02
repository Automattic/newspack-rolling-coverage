<?php
/**
 * Tests for the LiveBlogPosting structured data.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Schema;

/**
 * Pages showing a coverage's feed describe it as a live blog.
 */
class Test_Schema extends Rolling_Coverage_TestCase {

	/**
	 * The structured data printed on a post holding these blocks.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	private function schema_for( string $content ): string {
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => $content,
			]
		);
		$this->go_to( get_permalink( $post_id ) );

		ob_start();
		Schema::print_schema();

		return (string) ob_get_clean();
	}

	/**
	 * A post that only embeds a capped block shows a few entries and links to
	 * the coverage page, so it is not the live blog; a full feed is.
	 */
	public function test_only_uncapped_blocks_make_a_live_blog() {
		$coverage_id = self::create_coverage();
		self::create_entry( $coverage_id );
		$capped = '<!-- wp:newspack-rolling-coverage/rolling-coverage {"coverageId":' . $coverage_id . ',"latestOnly":true} /-->';
		$full   = '<!-- wp:newspack-rolling-coverage/rolling-coverage {"coverageId":' . $coverage_id . '} /-->';

		$this->assertSame( '', $this->schema_for( $capped ) );
		$this->assertSame( 1, substr_count( $this->schema_for( $capped . $full ), '"@type":"LiveBlogPosting"' ) );
	}
}
