<?php
/**
 * Shared polling: one poll URL for every reader of a page.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Poll_Cursor;
use Newspack_Rolling_Coverage\Post_Type;
use Newspack_Rolling_Coverage\Rolling_Coverage_Block;

/**
 * Shared poll replies and the cursor rules pages apply to them.
 */
class Test_Shared_Poll extends Rolling_Coverage_TestCase {

	/**
	 * Coverage term ID.
	 *
	 * @var int
	 */
	private $coverage_id;

	/**
	 * Template key of the coverage's default config.
	 *
	 * @var string
	 */
	private $template_key;

	/**
	 * Store the default config so requests carry a key the coverage knows.
	 */
	public function set_up() {
		parent::set_up();
		$this->coverage_id = self::create_coverage();
		$load              = new ReflectionMethod( Rolling_Coverage_Block::class, 'load_block_config' );
		$persist           = new ReflectionMethod( Rolling_Coverage_Block::class, 'persist_block_config' );
		$load->setAccessible( true );
		$persist->setAccessible( true );
		$defaults           = $load->invoke( null, $this->coverage_id, '' );
		$this->template_key = $persist->invoke( null, $this->coverage_id, $defaults['template'], $defaults['adsEnabled'], $defaults['adsInterval'] );
	}

	/**
	 * A shared poll of the test coverage.
	 *
	 * @param array $params Extra request parameters.
	 * @return WP_REST_Response
	 */
	private function share( array $params = [] ) {
		return self::dispatch(
			'GET',
			"/coverages/{$this->coverage_id}/entries",
			array_merge(
				[
					'recent'       => 1,
					'template_key' => $this->template_key,
				],
				$params
			)
		);
	}

	/**
	 * Create a published entry in the test coverage at a GMT time.
	 *
	 * @param string $gmt  `Y-m-d H:i:s`; the test site runs on UTC.
	 * @param array  $args Post factory arguments.
	 * @return int Entry ID.
	 */
	private function entry_at( $gmt, array $args = [] ) {
		return self::create_entry( $this->coverage_id, array_merge( [ 'post_date' => $gmt ], $args ) );
	}

	/**
	 * A GMT time some minutes ago.
	 *
	 * @param int $minutes Minutes.
	 * @return string `Y-m-d H:i:s`.
	 */
	private function minutes_ago( $minutes ) {
		return gmdate( 'Y-m-d H:i:s', time() - 60 * $minutes );
	}

	/**
	 * Render the test coverage's feed block with its defaults.
	 *
	 * @return string
	 */
	private function render_feed() {
		$attributes = [ 'coverageId' => $this->coverage_id ];
		$block      = parse_blocks( '<!-- wp:newspack-rolling-coverage/rolling-coverage ' . wp_json_encode( $attributes ) . ' /-->' )[0];

		return Rolling_Coverage_Block::render_block( $attributes, '', new WP_Block( $block ) );
	}

	/**
	 * The cases both copies of the cursor rules are checked against.
	 *
	 * @return array[]
	 */
	public function cursor_case_provider() {
		$cases = json_decode( file_get_contents( __DIR__ . '/fixtures/poll-cursor-cases.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		return array_combine( array_column( $cases, 'case' ), array_map( static fn( $c ) => [ $c ], $cases ) );
	}

	/**
	 * The server's rules for a change against a page's position, as a real
	 * cursor poll from that position applies them: it skips what changed
	 * before the position or that the position holds, sends a takedown only
	 * when it came in the position's second or later, and calls an entry
	 * new when it was first published after the position, or in its second
	 * without the position holding it. The page script applies the same
	 * file of cases.
	 *
	 * @dataProvider cursor_case_provider
	 *
	 * @param array $case One case from tests/fixtures/poll-cursor-cases.json.
	 */
	public function test_cursor_rules_match_the_shared_cases( $case ) {
		global $wpdb;

		$id = $this->entry_at( '2025-12-31 00:00:00' );

		if ( 'remove' === $case['type'] ) {
			wp_update_post(
				[
					'ID'          => $id,
					'post_status' => 'draft',
				]
			);
			update_post_meta( $id, Post_Type::META_UNPUBLISHED_GMT, $case['unpublished'] );
		} else {
			update_post_meta( $id, Post_Type::META_PUBLISHED_GMT, $case['published'] );
		}

		$wpdb->update( $wpdb->posts, [ 'post_modified_gmt' => $case['modified'] ], [ 'ID' => $id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		clean_post_cache( $id );

		// No `@marker`, so the poll always looks for changes; no `latest`, so
		// a takedown comes as a removal rather than a capped feed's burst.
		$poll    = self::dispatch(
			'GET',
			"/coverages/{$this->coverage_id}/entries",
			[
				'template_key' => $this->template_key,
				'cursor'       => ( $case['held'] ? $id : 0 ) . ':' . $case['cursorModified'],
			]
		)->get_data();
		$change  = wp_list_filter( $poll['entries'], [ 'id' => $id ] );
		$outcome = $change ? [
			'insert' => 'new',
			'update' => 'edit',
			'remove' => 'remove',
		][ reset( $change )['type'] ] : 'skip';

		$this->assertSame( $case['expected'], $outcome );
	}
}
