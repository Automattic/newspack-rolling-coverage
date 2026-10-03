<?php
/**
 * Tests for the social-sharing `rc_source` redirect script.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Social_Sharing;

/**
 * The `rc_source` query var is attacker-controlled, so its target must be
 * publicly viewable before its permalink is reflected into the response.
 */
class Test_Social_Sharing extends Rolling_Coverage_TestCase {

	/**
	 * `inject_redirect_script()` reflects a published source's URL, and emits
	 * nothing for an unpublished source in any status.
	 */
	public function test_rc_source_only_reflects_published_posts() {
		$entry_id = self::create_entry( self::create_coverage() );

		$unpublished = [
			'draft'   => [],
			'private' => [],
			'pending' => [],
			'future'  => [
				'post_date'     => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			],
			'trash'   => [],
		];

		foreach ( $unpublished as $status => $extra ) {
			$source_id = self::factory()->post->create( array_merge( [ 'post_status' => $status ], $extra ) );

			$this->assertSame( $status, get_post_status( $source_id ) );
			$this->assertSame(
				'',
				$this->render_redirect( $entry_id, $source_id ),
				"rc_source pointing at a {$status} post must emit no redirect script."
			);
		}

		$published_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );

		$this->assertStringContainsString(
			'window.location.replace(',
			$this->render_redirect( $entry_id, $published_id )
		);
	}

	/**
	 * Render `inject_redirect_script()` for a queried entry and source ID.
	 *
	 * @param int $entry_id  Entry post ID to query.
	 * @param int $source_id rc_source value.
	 * @return string Emitted markup.
	 */
	private function render_redirect( int $entry_id, int $source_id ): string {
		$this->go_to( get_permalink( $entry_id ) );

		// Plain permalinks put the entry's own `p` in $_GET; drop it so the
		// forwarded-params behavior can't mask what the source resolves to.
		$_GET = [];
		set_query_var( Social_Sharing::SOURCE_QUERY_VAR, (string) $source_id );

		ob_start();
		Social_Sharing::inject_redirect_script();
		$output = (string) ob_get_clean();

		set_query_var( Social_Sharing::SOURCE_QUERY_VAR, '' );

		return $output;
	}

	/**
	 * An entry's deep link keeps the page's query arguments and replaces its
	 * fragment, so the link carries one.
	 */
	public function test_entry_deep_link_replaces_the_page_fragment() {
		$entry_id = self::create_entry( self::create_coverage(), [ 'post_name' => 'bridge-closed' ] );

		$this->assertSame(
			'https://example.test/live/?ref=x&rolling-coverage-entry=bridge-closed#newspack-rolling-coverage-entry-' . $entry_id,
			Social_Sharing::get_entry_deep_link( get_post( $entry_id ), 'https://example.test/live/?ref=x#top' )
		);
	}
}
