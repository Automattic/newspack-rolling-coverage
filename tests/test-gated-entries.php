<?php
/**
 * Tests that every path renders entries as posts set up in a loop.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Lite_Feed;
use Newspack_Rolling_Coverage\Rolling_Coverage_Block;

/**
 * Newspack's content gate puts a gated post's teaser in place of its body
 * only for a post set up in a loop (see
 * Rolling_Coverage_Block::setup_entry_postdata()). A path that set an entry
 * up any other way would send a gated entry in full to every reader. The
 * suite runs without Newspack, so these tests hold the feed to its half of
 * that contract: each entry reaches `the_post` in a loop.
 */
class Test_Gated_Entries extends Rolling_Coverage_TestCase {

	/**
	 * Content the Lite Site stand-in's filter swaps for the feed.
	 */
	const FEED_PLACEHOLDER = 'ROLLING_COVERAGE_FEED';

	/**
	 * Coverage under test.
	 *
	 * @var int
	 */
	private $coverage_id;

	/**
	 * Its one entry.
	 *
	 * @var int
	 */
	private $entry_id;

	/**
	 * Whether each `the_post` for the entry came from a loop, in order.
	 *
	 * @var bool[]
	 */
	private $entry_setups = [];

	/**
	 * A coverage with one entry, an anonymous reader, and a record of how
	 * the entry is set up.
	 */
	public function set_up() {
		parent::set_up();
		require_once __DIR__ . '/mocks/class-lite-site.php';
		wp_set_current_user( 0 );

		$this->coverage_id = self::create_coverage();
		$this->entry_id    = self::create_entry(
			$this->coverage_id,
			[
				'post_date'    => '2026-01-01 12:00:00',
				'post_content' => '<!-- wp:paragraph --><p>Entry text</p><!-- /wp:paragraph -->',
			]
		);

		add_action( 'the_post', [ $this, 'record_entry_setup' ], 10, 2 );
	}

	/**
	 * Record whether the entry was set up in a loop.
	 *
	 * @param WP_Post  $post  Post set up.
	 * @param WP_Query $query Query that set it up.
	 */
	public function record_entry_setup( $post, $query ) {
		if ( $this->entry_id === $post->ID ) {
			$this->entry_setups[] = (bool) $query->in_the_loop;
		}
	}

	/**
	 * Render the block for the test coverage with a layout showing each
	 * entry's content.
	 *
	 * @return string
	 */
	private function render_block_html(): string {
		$attributes = [ 'coverageId' => $this->coverage_id ];
		$markup     = '<!-- wp:newspack-rolling-coverage/rolling-coverage ' . wp_json_encode( $attributes ) . ' --><!-- wp:post-content /--><!-- /wp:newspack-rolling-coverage/rolling-coverage -->';

		return Rolling_Coverage_Block::render_block( $attributes, '', new WP_Block( parse_blocks( $markup )[0] ) );
	}

	/**
	 * Request the coverage's entries.
	 *
	 * @param array $params Query parameters.
	 * @return WP_REST_Response
	 */
	private function get_entries( array $params ): WP_REST_Response {
		return self::dispatch( 'GET', "/coverages/{$this->coverage_id}/entries", $params );
	}

	/**
	 * Every way an entry reaches a reader.
	 *
	 * @return array[]
	 */
	public function data_render_paths(): array {
		return [
			'page'           => [ 'page' ],
			'poll'           => [ 'poll' ],
			'load more'      => [ 'load_more' ],
			'lite page'      => [ 'lite_page' ],
			'lite poll'      => [ 'lite_poll' ],
			'lite load more' => [ 'lite_load_more' ],
		];
	}

	/**
	 * Render the entry along one path.
	 *
	 * @param string $path A key from data_render_paths().
	 * @return string The rendered entries.
	 */
	private function render_along( string $path ): string {
		$poll      = [ 'cursor' => '0:2025-12-31 00:00:00' ];
		$load_more = [ 'before' => '2026-01-02 00:00:00' ];
		$lite      = [
			'template_key' => 'test',
			'lite'         => true,
		];

		switch ( $path ) {
			case 'page':
				return $this->render_block_html();
			case 'poll':
			case 'load_more':
				preg_match( '/data-template-key="([^"]*)"/', $this->render_block_html(), $template_key );
				$this->entry_setups = [];
				$data               = $this->get_entries( array_merge( 'poll' === $path ? $poll : $load_more, [ 'template_key' => $template_key[1] ] ) )->get_data();
				return 'poll' === $path ? $data['entries'][0]['html'] : $data['html'];
			case 'lite_page':
				$render = function ( $content ) {
					return self::FEED_PLACEHOLDER === $content ? $this->render_block_html() : $content;
				};
				add_filter( Lite_Feed::CONTENT_FILTER, $render );
				try {
					return \Newspack_Lite_Site\Lite_Site::clean_content( self::FEED_PLACEHOLDER );
				} finally {
					remove_filter( Lite_Feed::CONTENT_FILTER, $render );
				}
			case 'lite_poll':
				return $this->get_entries( array_merge( $poll, $lite ) )->get_data()['entries'][0]['html'];
			case 'lite_load_more':
				return $this->get_entries( array_merge( $load_more, $lite ) )->get_data()['html'];
		}

		return '';
	}

	/**
	 * Each path sets the entry up in a loop.
	 *
	 * @dataProvider data_render_paths
	 *
	 * @param string $path How the entry reaches the reader.
	 */
	public function test_entries_render_as_loop_posts( string $path ) {
		$html = $this->render_along( $path );

		$this->assertStringContainsString( 'Entry text', $html, 'The entry should render.' );
		$this->assertNotEmpty( $this->entry_setups, 'The entry should be set up as the current post.' );
		$this->assertNotContains( false, $this->entry_setups, 'The entry should only be set up in a loop.' );
	}
}
