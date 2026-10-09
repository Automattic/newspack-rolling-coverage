<?php
/**
 * Tests for entries saved in the same second, as polls and load more see them.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Poll_Cursor;
use Newspack_Rolling_Coverage\Post_Type;
use Newspack_Rolling_Coverage\Rolling_Coverage_Block;

/**
 * Slack bursts and publishing a selection from the entries list save several
 * entries in one second, in no particular ID order. A page that already shows
 * some of them gets the rest by poll, as new entries, and load more continues
 * through them without skipping any.
 */
class Test_Same_Second_Entries extends Rolling_Coverage_TestCase {

	/**
	 * The second the entries share.
	 */
	const SECOND = '2026-01-01 12:00:00';

	/**
	 * Coverage the entries belong to.
	 *
	 * @var int
	 */
	private $coverage_id;

	/**
	 * Create the coverage. Requests are anonymous throughout.
	 */
	public function set_up() {
		parent::set_up();
		$this->coverage_id = self::create_coverage();
		wp_set_current_user( 0 );
	}

	/**
	 * Request the feed for the test coverage.
	 *
	 * @param array $params Query parameters.
	 * @return array Response data.
	 */
	private function get_feed( array $params ) {
		return self::dispatch( 'GET', "/coverages/{$this->coverage_id}/entries", array_merge( [ 'template_key' => 'test' ], $params ) )->get_data();
	}

	/**
	 * Create a published entry in the test coverage, saved at a fixed time.
	 *
	 * @param string $post_date Entry date, `Y-m-d H:i:s` GMT.
	 * @param array  $args      Post factory arguments.
	 * @return int Entry post ID.
	 */
	private function create_entry_at( $post_date, array $args = [] ) {
		return self::create_entry( $this->coverage_id, array_merge( [ 'post_date' => $post_date ], $args ) );
	}

	/**
	 * Publish a draft as if in the given second: `wp_publish_post()` keeps the
	 * draft's modified date, and the plugin stamps the publish time with the
	 * clock, which a test can't hold still.
	 *
	 * @param int    $entry_id Draft entry ID.
	 * @param string $second   GMT `Y-m-d H:i:s`.
	 */
	private function publish_in( $entry_id, $second ) {
		global $wpdb;

		wp_publish_post( $entry_id );

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->posts,
			[
				'post_modified'     => $second,
				'post_modified_gmt' => $second,
			],
			[ 'ID' => $entry_id ]
		);
		clean_post_cache( $entry_id );
		update_post_meta( $entry_id, Post_Type::META_PUBLISHED_GMT, $second );
	}

	/**
	 * Render the feed.
	 *
	 * @param array $attributes Block attributes besides the coverage.
	 * @return string
	 */
	private function render_feed( array $attributes = [] ) {
		$attributes = array_merge( [ 'coverageId' => $this->coverage_id ], $attributes );
		$block      = parse_blocks( '<!-- wp:newspack-rolling-coverage/rolling-coverage ' . wp_json_encode( $attributes ) . ' /-->' )[0];

		return Rolling_Coverage_Block::render_block( $attributes, '', new WP_Block( $block ) );
	}

	/**
	 * A `data-*` attribute of the feed's wrapper.
	 *
	 * @param string $html Rendered feed.
	 * @param string $name Attribute name without `data-`.
	 * @return string
	 */
	private static function data_attribute( $html, $name ) {
		preg_match( '/data-' . $name . '="([^"]*)"/', $html, $matches );

		return html_entity_decode( $matches[1] ?? '' );
	}

	/**
	 * Entry IDs in a fragment, in order.
	 *
	 * @param string $html Entries markup.
	 * @return int[]
	 */
	private static function entry_ids_in( $html ) {
		preg_match_all( '/data-entry-id="(\d+)"/', $html, $matches );

		return array_map( 'intval', $matches[1] );
	}

	/**
	 * An entry published in the second of the newest entry a page shows
	 * reaches the page as new.
	 */
	public function test_entry_published_in_the_second_a_page_shows_reaches_it_as_new() {
		$this->create_entry_at( self::SECOND );

		$cursor = self::data_attribute( $this->render_feed(), 'cursor' );

		$later_id = $this->create_entry_at( self::SECOND );

		$this->assertSame( [ $later_id => 'insert' ], wp_list_pluck( $this->get_feed( [ 'cursor' => $cursor ] )['entries'], 'type', 'id' ) );
	}

	/**
	 * A lower ID doesn't hold an entry back: drafts published together
	 * become visible in any order.
	 */
	public function test_entry_with_a_lower_id_published_after_a_poll_reaches_the_page() {
		$lower_id  = $this->create_entry_at( self::SECOND, [ 'post_status' => 'draft' ] );
		$higher_id = $this->create_entry_at( self::SECOND );

		$poll = $this->get_feed( [ 'cursor' => '0:2026-01-01 00:00:00' ] );

		$this->assertSame( [ $higher_id ], wp_list_pluck( $poll['entries'], 'id' ) );

		$this->publish_in( $lower_id, self::SECOND );

		$this->assertSame( [ $lower_id => 'insert' ], wp_list_pluck( $this->get_feed( [ 'cursor' => $poll['cursor'] ] )['entries'], 'type', 'id' ) );
	}

	/**
	 * Entries a page holds from the cursor's second aren't sent again when a
	 * later change brings the next poll.
	 */
	public function test_entries_a_page_holds_are_not_sent_again() {
		$this->create_entry_at( self::SECOND );
		$this->create_entry_at( self::SECOND );

		$cursor = self::data_attribute( $this->render_feed(), 'cursor' );

		$later_id = $this->create_entry_at( '2026-01-01 12:05:00' );

		$this->assertSame( [ $later_id ], wp_list_pluck( $this->get_feed( [ 'cursor' => $cursor ] )['entries'], 'id' ) );
	}

	/**
	 * Pages that leave entries out: a capped feed, and a full page with more
	 * to load.
	 *
	 * @return array[]
	 */
	public function full_page_provider() {
		return [
			'capped feed' => [
				[
					'latestOnly'  => true,
					'latestCount' => 1,
				],
				[ 'latest' => 1 ],
			],
			'full page'   => [ [ 'entriesPerPage' => 1 ], [] ],
		];
	}

	/**
	 * Entries from a page's newest second that the page leaves out aren't
	 * new to it, including an older draft published in that second; an entry
	 * published in that second after the page is.
	 *
	 * @dataProvider full_page_provider
	 *
	 * @param array $attributes Block attributes besides the coverage.
	 * @param array $params     Poll parameters the page sends.
	 */
	public function test_entries_a_page_leaves_out_of_its_newest_second_are_not_new( $attributes, $params ) {
		$older_draft_id = $this->create_entry_at( '2026-01-01 08:00:00', [ 'post_status' => 'draft' ] );

		$this->publish_in( $older_draft_id, self::SECOND );
		$this->create_entry_at( self::SECOND );
		$this->create_entry_at( self::SECOND );

		$cursor = self::data_attribute( $this->render_feed( $attributes ), 'cursor' );

		$later_id = $this->create_entry_at( self::SECOND );

		$this->assertSame( [ $later_id => 'insert' ], wp_list_pluck( $this->get_feed( array_merge( $params, [ 'cursor' => $cursor ] ) )['entries'], 'type', 'id' ) );
	}

	/**
	 * How many entry queries a poll runs.
	 *
	 * @param string $cursor Cursor the page sends.
	 * @return int
	 */
	private function entry_queries_in_poll( $cursor ) {
		$queries = 0;
		$count   = static function ( WP_Query $query ) use ( &$queries ) {
			if ( Post_Type::CPT_SLUG === $query->get( 'post_type' ) ) {
				++$queries;
			}
		};

		add_action( 'pre_get_posts', $count );
		$this->get_feed( [ 'cursor' => $cursor ] );
		remove_action( 'pre_get_posts', $count );

		return $queries;
	}

	/**
	 * A page's cursor holds no more entries than a poll makes room for, even
	 * when more than a page of them share its newest second.
	 */
	public function test_page_cursor_holds_no_more_than_a_poll_makes_room_for() {
		for ( $i = 0; $i <= Rolling_Coverage_Block::PER_PAGE_MAX + 1; $i++ ) {
			$this->create_entry_at( self::SECOND );
		}

		$cursor = Poll_Cursor::parse( self::data_attribute( $this->render_feed( [ 'entriesPerPage' => 1 ] ), 'cursor' ) );

		$this->assertCount( Rolling_Coverage_Block::PER_PAGE_MAX, $cursor->ids );
	}

	/**
	 * A poll queries no entries until something readers see changes, so idle
	 * polls stay cheap and a draft save keeps open pages on the same poll URL.
	 */
	public function test_poll_queries_no_entries_until_a_published_entry_changes() {
		$this->create_entry_at( self::SECOND );

		$cursor = self::data_attribute( $this->render_feed(), 'cursor' );

		$this->assertSame( 0, $this->entry_queries_in_poll( $cursor ), 'Nothing changed since the page rendered.' );

		$draft_id = $this->create_entry_at( self::SECOND, [ 'post_status' => 'draft' ] );

		wp_update_post(
			[
				'ID'           => $draft_id,
				'post_content' => 'Still a draft.',
			]
		);

		$this->assertSame( 0, $this->entry_queries_in_poll( $cursor ), 'Readers see no drafts.' );

		$this->create_entry_at( self::SECOND );

		$this->assertGreaterThan( 0, $this->entry_queries_in_poll( $cursor ), 'A published entry should send the poll looking.' );
	}

	/**
	 * A coverage with no change marker yet polls idle too, while a cursor
	 * with no `@` looks for changes.
	 */
	public function test_coverage_without_a_change_marker_polls_idle() {
		$entry_id = $this->create_entry_at( self::SECOND );

		delete_term_meta( $this->coverage_id, Poll_Cursor::MARKER_META_KEY );

		$cursor = self::data_attribute( $this->render_feed(), 'cursor' );

		$this->assertSame( 0, $this->entry_queries_in_poll( $cursor ), 'Nothing changed since the page rendered.' );
		$this->assertGreaterThan( 0, $this->entry_queries_in_poll( $entry_id . ':' . self::SECOND ), 'A cursor with no `@` should look for changes.' );
	}

	/**
	 * Entries a page holds don't count toward the poll cap, or crowd out the
	 * ones it lacks: a page showing more than the cap from one second polls
	 * instead of reloading, and gets the entry published after it.
	 */
	public function test_entries_a_page_holds_dont_count_toward_the_cap() {
		// The lowest ID, which a poll lists last among entries saved in one second.
		$later_id = $this->create_entry_at( self::SECOND, [ 'post_status' => 'draft' ] );

		for ( $i = 0; $i <= Rolling_Coverage_Block::POLL_CAP; $i++ ) {
			$this->create_entry_at( self::SECOND );
		}

		$cursor = self::data_attribute( $this->render_feed( [ 'entriesPerPage' => Rolling_Coverage_Block::POLL_CAP + 1 ] ), 'cursor' );

		$this->publish_in( $later_id, self::SECOND );

		$poll = $this->get_feed( [ 'cursor' => $cursor ] );

		$this->assertFalse( $poll['overflow'] );
		$this->assertSame( [ $later_id ], wp_list_pluck( $poll['entries'], 'id' ) );
	}

	/**
	 * However many entries a cursor names, a poll loads at most a page of
	 * them beyond the cap: the cursor comes from the request.
	 */
	public function test_poll_loads_at_most_a_page_beyond_the_cap_for_held_entries() {
		$this->create_entry_at( self::SECOND );

		$limits = [];
		$record = static function ( WP_Query $query ) use ( &$limits ) {
			if ( Post_Type::CPT_SLUG === $query->get( 'post_type' ) ) {
				$limits[] = (int) $query->get( 'posts_per_page' );
			}
		};

		add_action( 'pre_get_posts', $record );
		$this->get_feed( [ 'cursor' => implode( ',', range( 1000, 3000 ) ) . ':' . self::SECOND ] );
		remove_action( 'pre_get_posts', $record );

		$this->assertNotEmpty( $limits );
		$this->assertLessThanOrEqual( Rolling_Coverage_Block::POLL_CAP + 1 + Rolling_Coverage_Block::PER_PAGE_MAX, max( $limits ) );
	}

	/**
	 * Load more continues through the entries that share a second with the
	 * last one shown, and then past it.
	 */
	public function test_load_more_continues_through_entries_from_the_same_second() {
		$older_id  = $this->create_entry_at( '2026-01-01 11:00:00' );
		$first_id  = $this->create_entry_at( self::SECOND );
		$second_id = $this->create_entry_at( self::SECOND );
		$third_id  = $this->create_entry_at( self::SECOND );

		$html   = $this->render_feed( [ 'entriesPerPage' => 1 ] );
		$shown  = self::entry_ids_in( $html );
		$before = self::data_attribute( $html, 'before' );

		while ( $before ) {
			$page = $this->get_feed(
				[
					'before'       => $before,
					'per_page'     => 1,
					'template_key' => self::data_attribute( $html, 'template-key' ),
				]
			);

			$shown  = array_merge( $shown, self::entry_ids_in( $page['html'] ) );
			$before = $page['hasMore'] ? $page['before'] : '';
		}

		$this->assertSame( [ $third_id, $second_id, $first_id, $older_id ], $shown );
	}

	/**
	 * The coverage's change marker moves only once what the poll reads about
	 * the change is saved: a poll that takes the new marker without the
	 * change would never look for it again.
	 */
	public function test_marker_moves_once_publishing_and_taking_down_are_recorded() {
		$entry_id = $this->create_entry_at( self::SECOND, [ 'post_status' => 'draft' ] );
		$recorded = [];
		$record   = static function ( $meta_id, $object_id, $meta_key ) use ( $entry_id, &$recorded ) {
			if ( Poll_Cursor::MARKER_META_KEY === $meta_key ) {
				$recorded[] = [
					'published'  => (bool) get_post_meta( $entry_id, Post_Type::META_PUBLISHED_GMT, true ),
					'taken down' => (bool) get_post_meta( $entry_id, Post_Type::META_UNPUBLISHED_GMT, true ),
				];
			}
		};

		add_action( 'added_term_meta', $record, 10, 3 );
		add_action( 'updated_term_meta', $record, 10, 3 );

		// Registered again, so they run last at their priority: the marker
		// writer has to come after them by priority, not by load order.
		foreach ( [ 'record_entry_published_gmt', 'record_entry_unpublished' ] as $recorder ) {
			remove_action( 'transition_post_status', [ Post_Type::class, $recorder ], 10 );
			add_action( 'transition_post_status', [ Post_Type::class, $recorder ], 10, 3 );
		}

		wp_publish_post( $entry_id );

		$this->assertNotEmpty( $recorded );
		$this->assertNotContains( false, array_column( $recorded, 'published' ), 'The publish time should be recorded first.' );

		$recorded = [];

		wp_update_post(
			[
				'ID'          => $entry_id,
				'post_status' => 'draft',
			]
		);

		$this->assertNotEmpty( $recorded );
		$this->assertNotContains( false, array_column( $recorded, 'taken down' ), 'The takedown should be recorded first.' );
	}
}
