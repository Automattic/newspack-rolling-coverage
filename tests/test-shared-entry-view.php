<?php
/**
 * Tests for the feed that opens at a shared entry.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Post_Type;
use Newspack_Rolling_Coverage\Rolling_Coverage_Block;
use Newspack_Rolling_Coverage\Social_Sharing;

/**
 * A link to an entry older than the first page opens the feed at that entry,
 * without pinned entries; anything else keeps the normal feed.
 */
class Test_Shared_Entry_View extends Rolling_Coverage_TestCase {

	const HIDDEN_BUTTON = '<button type="button" class="newspack-rolling-coverage-new-entries" hidden>';

	/**
	 * Coverage term ID.
	 *
	 * @var int
	 */
	private $coverage_id;

	/**
	 * Host page ID.
	 *
	 * @var int
	 */
	private $page_id;

	/**
	 * Entry IDs keyed by slug, entry-1 (oldest) to entry-6.
	 *
	 * @var int[]
	 */
	private $entries = [];

	/**
	 * Build a coverage of six entries, one minute apart, and a host page.
	 */
	public function set_up() {
		parent::set_up();

		$this->coverage_id = self::create_coverage();

		for ( $i = 1; $i <= 6; $i++ ) {
			$this->entries[ 'entry-' . $i ] = self::create_entry(
				$this->coverage_id,
				[
					'post_date'  => sprintf( '2026-01-01 10:%02d:00', $i ),
					'post_name'  => 'entry-' . $i,
					'post_title' => 'Entry ' . $i,
				]
			);
		}

		$this->page_id = self::factory()->post->create( [ 'post_type' => 'page' ] );

		$GLOBALS['post'] = get_post( $this->page_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Makes the host page the current post.
		setup_postdata( $GLOBALS['post'] );
	}

	/**
	 * Clear the query var.
	 */
	public function tear_down() {
		set_query_var( Social_Sharing::ENTRY_QUERY_VAR, '' );
		parent::tear_down();
	}

	/**
	 * Render the block with a shared entry in the query.
	 *
	 * @param string|array $slug Value of the entry query var.
	 * @return string
	 */
	private function render_with_shared( $slug ): string {
		set_query_var( Social_Sharing::ENTRY_QUERY_VAR, $slug );

		$attributes = [
			'coverageId'     => $this->coverage_id,
			'entriesPerPage' => 2,
		];
		$parsed     = parse_blocks( '<!-- wp:newspack-rolling-coverage/rolling-coverage ' . wp_json_encode( $attributes ) . ' /-->' )[0];

		return Rolling_Coverage_Block::render_block( $attributes, '', new WP_Block( $parsed ) );
	}

	/**
	 * Entry IDs in the order the HTML lists them.
	 *
	 * @param string $html Rendered HTML.
	 * @return int[]
	 */
	private function entry_ids_in( string $html ): array {
		preg_match_all( '/data-entry-id="(\d+)"/', $html, $matches );

		return array_map( 'intval', $matches[1] );
	}

	/**
	 * Entry IDs for slugs.
	 *
	 * @param string ...$slugs Entry slugs.
	 * @return int[]
	 */
	private function ids( string ...$slugs ): array {
		return array_map( fn( $slug ) => $this->entries[ $slug ], $slugs );
	}

	/**
	 * Value of a data attribute on the block wrapper.
	 *
	 * @param string $html Rendered HTML.
	 * @param string $name Attribute name without the data- prefix.
	 * @return string
	 */
	private function data_attribute( string $html, string $name ): string {
		preg_match( '/data-' . preg_quote( $name, '/' ) . '="([^"]*)"/', $html, $match );

		return html_entity_decode( $match[1] ?? '' );
	}

	/**
	 * A link to an entry the first page already shows changes nothing.
	 */
	public function test_recent_shared_entry_keeps_the_normal_view() {
		$html = $this->render_with_shared( 'entry-5' );

		$this->assertStringNotContainsString( 'data-view=', $html );
		$this->assertStringContainsString( self::HIDDEN_BUTTON, $html );
		$this->assertSame( $this->ids( 'entry-6', 'entry-5' ), $this->entry_ids_in( $html ) );
	}

	/**
	 * A link to an older entry starts the feed at that entry and offers a link back to the live feed.
	 */
	public function test_older_shared_entry_opens_the_feed_at_that_entry() {
		$html = $this->render_with_shared( 'entry-3' );

		$this->assertStringContainsString( 'data-view="entry"', $html );
		$this->assertSame( $this->ids( 'entry-3', 'entry-2' ), $this->entry_ids_in( $html ) );
		$this->assertStringContainsString( 'data-has-more="1"', $html );
		$this->assertStringContainsString( '<a class="newspack-rolling-coverage-new-entries button wp-element-button" href="' . esc_url( get_permalink( $this->page_id ) ) . '">Jump to latest</a>', $html );
		$this->assertStringNotContainsString( '<button type="button" class="newspack-rolling-coverage-new-entries"', $html );
	}

	/**
	 * The shared view holds no pinned entries, and its page stays full when one sat in its date range.
	 */
	public function test_shared_view_leaves_pinned_entries_out() {
		Post_Type::pin_entry( $this->entries['entry-2'] );

		$html = $this->render_with_shared( 'entry-3' );

		$this->assertSame( $this->ids( 'entry-3', 'entry-1' ), $this->entry_ids_in( $html ) );
		$this->assertStringContainsString( 'data-has-more="0"', $html );
	}

	/**
	 * Skipped pinned entries do not hide the older entries that remain to load.
	 */
	public function test_shared_view_keeps_more_to_load_when_pinned_entries_are_skipped() {
		Post_Type::pin_entry( $this->entries['entry-3'] );

		$html = $this->render_with_shared( 'entry-4' );

		$this->assertSame( $this->ids( 'entry-4', 'entry-2' ), $this->entry_ids_in( $html ) );
		$this->assertStringContainsString( 'data-has-more="1"', $html );
	}

	/**
	 * The oldest entry opens alone, with nothing more to load and no closing separator.
	 */
	public function test_oldest_shared_entry_is_shown_alone() {
		$html = $this->render_with_shared( 'entry-1' );

		$this->assertSame( $this->ids( 'entry-1' ), $this->entry_ids_in( $html ) );
		$this->assertStringContainsString( 'data-has-more="0"', $html );

		$entries_html = substr( $html, (int) strpos( $html, 'newspack-rolling-coverage-entries' ) );

		$this->assertStringNotContainsString( 'wp-block-separator', $entries_html );
	}

	/**
	 * Links that cannot open the feed at an entry leave the normal feed.
	 *
	 * @dataProvider unusable_shared_entries
	 *
	 * @param string $case Fixture to set up.
	 */
	public function test_unusable_shared_entries_keep_the_normal_view( string $case ) {
		$shared = 'entry-3';

		switch ( $case ) {
			case 'unknown':
				$shared = 'no-such-entry';
				break;
			case 'draft':
				wp_update_post(
					[
						'ID'          => $this->entries['entry-3'],
						'post_status' => 'draft',
					]
				);
				break;
			case 'other coverage':
				$other = self::create_entry(
					self::create_coverage(),
					[
						'post_date' => '2026-01-01 09:00:00',
						'post_name' => 'elsewhere',
					]
				);
				$this->assertGreaterThan( 0, $other );
				$shared = 'elsewhere';
				break;
			case 'pinned':
				foreach ( [ 'entry-1', 'entry-2', 'entry-3' ] as $slug ) {
					Post_Type::pin_entry( $this->entries[ $slug ] );
				}
				$shared = 'entry-3';
				break;
			case 'array':
				$shared = [ 'entry-3' ];
				break;
		}

		$html = $this->render_with_shared( $shared );

		$this->assertStringNotContainsString( 'data-view=', $html );
		$this->assertStringContainsString( self::HIDDEN_BUTTON, $html );
	}

	/**
	 * Fixture names for the unusable shared entries.
	 *
	 * @return array[]
	 */
	public static function unusable_shared_entries(): array {
		return [
			'unknown slug'              => [ 'unknown' ],
			'draft entry'               => [ 'draft' ],
			'entry of another coverage' => [ 'other coverage' ],
			'pinned older entry'        => [ 'pinned' ],
			'array value'               => [ 'array' ],
		];
	}

	/**
	 * The shared view polls from the newest entry of the coverage, so entries older than the page's start are not reported as new.
	 */
	public function test_shared_view_polls_from_the_coverage_not_the_page() {
		$html   = $this->render_with_shared( 'entry-2' );
		$cursor = $this->data_attribute( $html, 'cursor' );
		$latest = get_post( $this->entries['entry-6'] );

		$this->assertSame( $latest->ID . ':' . $latest->post_modified_gmt, $cursor );

		$params = [
			'cursor'       => $cursor,
			'template_key' => $this->data_attribute( $html, 'template-key' ),
		];
		$path   = '/coverages/' . $this->coverage_id . '/entries';

		$this->assertSame( [], self::dispatch( 'GET', $path, $params )->get_data()['entries'] );

		self::create_entry(
			$this->coverage_id,
			[
				'post_date'  => current_time( 'mysql' ),
				'post_name'  => 'entry-7',
				'post_title' => 'Entry 7',
			]
		);

		$entries = self::dispatch( 'GET', $path, $params )->get_data()['entries'];

		$this->assertCount( 1, $entries );
		$this->assertSame( 'insert', $entries[0]['type'] );
	}

	/**
	 * Load more can leave pinned entries out, and keeps them by default.
	 */
	public function test_load_more_can_leave_pinned_entries_out() {
		Post_Type::pin_entry( $this->entries['entry-2'] );

		$html   = $this->render_with_shared( 'entry-3' );
		$params = [
			'before'       => get_post( $this->entries['entry-4'] )->post_date_gmt,
			'per_page'     => 2,
			'template_key' => $this->data_attribute( $html, 'template-key' ),
		];
		$path   = '/coverages/' . $this->coverage_id . '/entries';

		$data = self::dispatch( 'GET', $path, array_merge( $params, [ 'skip_pinned' => true ] ) )->get_data();

		$this->assertSame( $this->ids( 'entry-3', 'entry-1' ), $this->entry_ids_in( $data['html'] ) );
		$this->assertSame( 2, $data['count'] );
		$this->assertFalse( $data['hasMore'] );

		$data = self::dispatch( 'GET', $path, $params )->get_data();

		$this->assertSame( $this->ids( 'entry-3', 'entry-2' ), $this->entry_ids_in( $data['html'] ) );
	}
}
