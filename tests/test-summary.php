<?php
/**
 * Tests for a coverage's Summary.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Entry_Bindings;
use Newspack_Rolling_Coverage\Newest_Entry;
use Newspack_Rolling_Coverage\Post_Type;
use Newspack_Rolling_Coverage\Rolling_Coverage_Block;
use Newspack_Rolling_Coverage\Summary;
use Newspack_Rolling_Coverage\Taxonomy;

/**
 * The Summary is one entry per coverage, shown once as the summary card and
 * left out of everything that lists or reacts to entries.
 */
class Test_Summary extends Rolling_Coverage_TestCase {

	/**
	 * A Feed with the summary card above an entry group, as the built-in
	 * layouts save it.
	 */
	const FEED_MARKUP = '<!-- wp:group {"className":"newspack-rolling-coverage-feed","layout":{"type":"flex","orientation":"vertical"}} --><div class="wp-block-group newspack-rolling-coverage-feed">'
		. '<!-- wp:group {"className":"newspack-rolling-coverage-summary-card"} --><div class="wp-block-group newspack-rolling-coverage-summary-card">'
		. '<!-- wp:post-title {"level":3} /-->'
		. '<!-- wp:post-content /-->'
		. '<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"newspack-rolling-coverage/entry","args":{"key":"summaryUpdated"}}}}} --><p>Updated</p><!-- /wp:paragraph -->'
		. '</div><!-- /wp:group -->'
		. '<!-- wp:group {"className":"newspack-rolling-coverage-regular-entry"} --><div class="wp-block-group newspack-rolling-coverage-regular-entry"><!-- wp:post-title /--></div><!-- /wp:group -->'
		. '</div><!-- /wp:group -->';

	/**
	 * Create a published Summary in a coverage.
	 *
	 * @param int   $coverage_id Coverage term ID.
	 * @param array $args        Post factory arguments.
	 * @return int The Summary's ID.
	 */
	private static function create_summary( int $coverage_id, array $args = [] ): int {
		return self::create_entry(
			$coverage_id,
			array_merge(
				[
					'post_title'   => 'What we know',
					'post_content' => '<!-- wp:list --><ul class="wp-block-list"><!-- wp:list-item --><li>River at 19.8 feet</li><!-- /wp:list-item --></ul><!-- /wp:list -->',
					'meta_input'   => [ Summary::META_KEY => true ],
				],
				$args
			)
		);
	}

	/**
	 * Render the block with the Feed.
	 *
	 * @param int   $coverage_id Coverage term ID.
	 * @param array $attributes  Further block attributes.
	 * @return string
	 */
	private static function render( int $coverage_id, array $attributes = [] ): string {
		$attributes = array_merge( [ 'coverageId' => $coverage_id ], $attributes );
		$block      = parse_blocks( '<!-- wp:newspack-rolling-coverage/rolling-coverage ' . wp_json_encode( $attributes ) . ' -->' . self::FEED_MARKUP . '<!-- /wp:newspack-rolling-coverage/rolling-coverage -->' )[0];

		return Rolling_Coverage_Block::render_block( $attributes, '', new WP_Block( $block ) );
	}

	/**
	 * The value of a data attribute on the block's wrapper.
	 *
	 * @param string $html Rendered block.
	 * @param string $name Attribute name.
	 * @return string
	 */
	private static function wrapper_attribute( string $html, string $name ): string {
		$processor = new WP_HTML_Tag_Processor( $html );
		$processor->next_tag();

		return (string) $processor->get_attribute( $name );
	}

	/**
	 * Each Add Summary starts a fresh auto-draft of the user's own, until a
	 * Summary is saved; then the route answers 409.
	 */
	public function test_create_route_starts_a_fresh_summary_until_one_is_saved() {
		$editor_id   = self::log_in_as( 'editor' );
		$coverage_id = self::create_coverage();

		$first = self::dispatch( 'POST', "/coverages/{$coverage_id}/entries", [ 'summary' => true ] );
		$this->assertSame( 201, $first->get_status() );

		$first_id = $first->get_data()['id'];
		$this->assertTrue( Summary::is_summary( $first_id ) );
		$this->assertSame( 'auto-draft', get_post_status( $first_id ) );

		$other_editor = self::log_in_as( 'editor' );
		$second       = self::dispatch( 'POST', "/coverages/{$coverage_id}/entries", [ 'summary' => true ] );
		$second_id    = $second->get_data()['id'];
		$this->assertNotSame( $first_id, $second_id, 'Another start should get an auto-draft of its own.' );
		$this->assertSame( $other_editor, (int) get_post( $second_id )->post_author );
		$this->assertSame( $editor_id, (int) get_post( $first_id )->post_author );

		wp_update_post(
			[
				'ID'          => $first_id,
				'post_status' => 'publish',
			]
		);

		$third = self::dispatch( 'POST', "/coverages/{$coverage_id}/entries", [ 'summary' => true ] );
		$this->assertSame( 409, $third->get_status() );
		$this->assertSame( 'rolling_coverage_summary_exists', $third->get_data()['code'] );

		$entry = self::dispatch( 'POST', "/coverages/{$coverage_id}/entries" );
		$this->assertSame( 201, $entry->get_status() );
		$this->assertFalse( Summary::is_summary( $entry->get_data()['id'] ), 'A plain new entry is not a Summary.' );
	}

	/**
	 * A stray auto-draft newer than the saved Summary doesn't hide it from the
	 * check that refuses a second one.
	 */
	public function test_a_newer_auto_draft_does_not_hide_the_saved_summary() {
		self::log_in_as( 'editor' );
		$coverage_id = self::create_coverage();
		$saved_id    = self::create_summary( $coverage_id );
		$second_id   = self::create_summary( $coverage_id, [ 'post_status' => 'draft' ] );
		self::set_entry_modified( $saved_id, '2026-01-01 08:00:00' );
		self::set_entry_modified( $second_id, '2026-01-01 09:00:00' );
		self::create_summary( $coverage_id, [ 'post_status' => 'auto-draft' ] );

		$request = new WP_REST_Request( 'POST', '/wp/v2/' . Post_Type::REST_BASE . '/' . $second_id );
		$request->set_param( 'status', 'publish' );

		$this->assertSame( 409, rest_get_server()->dispatch( $request )->get_status() );
	}

	/**
	 * Adding a Summary takes Editors and above, as pinning does.
	 */
	public function test_authors_cannot_start_a_summary() {
		self::log_in_as( 'author' );
		$coverage_id = self::create_coverage();

		$response = self::dispatch( 'POST', "/coverages/{$coverage_id}/entries", [ 'summary' => true ] );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * A trashed Summary frees the coverage for a new one, and can't come back
	 * while the new one is there.
	 */
	public function test_trashed_summary_frees_the_coverage_and_cannot_return_beside_another() {
		self::log_in_as( 'editor' );
		$coverage_id = self::create_coverage();
		$old_id      = self::create_summary( $coverage_id );

		wp_trash_post( $old_id );

		$new = self::dispatch( 'POST', "/coverages/{$coverage_id}/entries", [ 'summary' => true ] );
		$this->assertSame( 201, $new->get_status() );
		wp_update_post(
			[
				'ID'          => $new->get_data()['id'],
				'post_status' => 'publish',
			]
		);

		$restore = self::dispatch( 'POST', "/entries/{$old_id}/restore" );
		$this->assertSame( 409, $restore->get_status() );
		$this->assertSame( 'trash', get_post_status( $old_id ) );
	}

	/**
	 * The core entries route refuses to publish a second Summary.
	 */
	public function test_core_route_refuses_a_second_summary() {
		self::log_in_as( 'editor' );
		$coverage_id = self::create_coverage();
		self::create_summary( $coverage_id );
		$second_id = self::create_summary( $coverage_id, [ 'post_status' => 'draft' ] );

		$request = new WP_REST_Request( 'POST', '/wp/v2/' . Post_Type::REST_BASE . '/' . $second_id );
		$request->set_param( 'status', 'publish' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'draft', get_post_status( $second_id ) );
	}

	/**
	 * Saving a coverage's only Summary through the core entries route goes
	 * through: it doesn't collide with itself.
	 */
	public function test_core_route_saves_the_only_summary() {
		self::log_in_as( 'editor' );
		$summary_id = self::create_summary( self::create_coverage() );

		$request = new WP_REST_Request( 'POST', '/wp/v2/' . Post_Type::REST_BASE . '/' . $summary_id );
		$request->set_param( 'title', 'What we know now' );

		$this->assertSame( 200, rest_get_server()->dispatch( $request )->get_status() );
		$this->assertSame( 'What we know now', get_post( $summary_id )->post_title );
	}

	/**
	 * The core entries route refuses to move a Summary into a coverage that
	 * already has one.
	 */
	public function test_core_route_refuses_moving_a_summary_into_a_coverage_with_one() {
		self::log_in_as( 'editor' );
		$target_id = self::create_coverage();
		self::create_summary( $target_id );
		$moving_id = self::create_summary( self::create_coverage() );

		$request = new WP_REST_Request( 'POST', '/wp/v2/' . Post_Type::REST_BASE . '/' . $moving_id );
		$request->set_param( Taxonomy::REST_BASE, [ $target_id ] );

		$this->assertSame( 409, rest_get_server()->dispatch( $request )->get_status() );
		$this->assertNotContains( $target_id, wp_get_post_terms( $moving_id, Taxonomy::TAXONOMY_SLUG, [ 'fields' => 'ids' ] ) );
	}

	/**
	 * Entry queries leave the Summary out unless they ask for it.
	 */
	public function test_entry_queries_leave_the_summary_out() {
		$coverage_id = self::create_coverage();
		$entry_id    = self::create_entry( $coverage_id );
		$summary_id  = self::create_summary( $coverage_id );
		$base        = [
			'post_type'      => Post_Type::CPT_SLUG,
			'fields'         => 'ids',
			'posts_per_page' => 10,
		];

		$this->assertSame( [ $entry_id ], get_posts( $base ) );
		$this->assertSame( [ $summary_id ], get_posts( array_merge( $base, [ Summary::QUERY_VAR => 'only' ] ) ) );
		$this->assertEqualsCanonicalizing( [ $entry_id, $summary_id ], get_posts( array_merge( $base, [ Summary::QUERY_VAR => 'include' ] ) ) );
		$this->assertSame( $summary_id, Summary::get_published( $coverage_id )->ID );

		$post_id = self::factory()->post->create();
		$this->assertEqualsCanonicalizing( [ $entry_id, $post_id ], get_posts( array_merge( $base, [ 'post_type' => [ 'post', Post_Type::CPT_SLUG ] ] ) ), 'A query for entries and other posts leaves the Summary out too.' );
	}

	/**
	 * The Summary's own permalink still finds it, so it can send readers on
	 * to its coverage page.
	 */
	public function test_summary_permalink_still_resolves() {
		$summary_id = self::create_summary( self::create_coverage() );

		$this->go_to( get_permalink( $summary_id ) );

		$this->assertTrue( is_singular( Post_Type::CPT_SLUG ) );
		$this->assertSame( $summary_id, get_queried_object_id() );
	}

	/**
	 * The coverage's entry count and newest entry leave the Summary out.
	 */
	public function test_counts_and_newest_entry_leave_the_summary_out() {
		$coverage_id = self::create_coverage();
		self::create_dated_entry( $coverage_id, '2026-01-01 10:00:00' );
		self::create_summary(
			$coverage_id,
			[
				'post_date'     => '2026-01-01 12:00:00',
				'post_date_gmt' => '2026-01-01 12:00:00',
			]
		);

		$this->assertSame( 1, get_term( $coverage_id, Taxonomy::TAXONOMY_SLUG )->count );
		$this->assertSame( '2026-01-01 10:00:00', Newest_Entry::refresh( $coverage_id ) );
	}

	/**
	 * The Summary can't be pinned or given a breakout post.
	 */
	public function test_summary_cannot_be_pinned_or_broken_out() {
		self::log_in_as( 'editor' );
		$summary_id = self::create_summary( self::create_coverage() );

		$pin = self::dispatch( 'POST', "/entries/{$summary_id}/pin" );
		$this->assertSame( 400, $pin->get_status() );
		$this->assertFalse( Post_Type::is_pinned( $summary_id ) );

		$breakout = self::dispatch( 'POST', "/entries/{$summary_id}/breakout" );
		$this->assertSame( 'rolling_coverage_summary_unsupported', $breakout->get_data()['code'] );
	}

	/**
	 * The core route reports the Summary, and the entries list puts it first
	 * and names it, whatever its filters.
	 */
	public function test_rest_exposes_the_summary_and_lists_it_first() {
		self::log_in_as( 'editor' );
		$coverage_id = self::create_coverage();
		$summary_id  = self::create_summary( $coverage_id, [ 'post_date' => '2026-01-01 08:00:00' ] );
		$entry_id    = self::create_entry( $coverage_id, [ 'post_date' => '2026-01-02 08:00:00' ] );
		Post_Type::pin_entry( $entry_id );

		$core = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/wp/v2/' . Post_Type::REST_BASE . '/' . $summary_id ) );
		$this->assertTrue( $core->get_data()[ Summary::REST_FIELD ] );

		$list = self::dispatch( 'GET', "/coverages/{$coverage_id}/entries-view" )->get_data();
		$this->assertSame( [ $summary_id, $entry_id ], wp_list_pluck( $list['entries'], 'id' ) );
		$this->assertTrue( $list['entries'][0]['is_summary'] );
		$this->assertFalse( $list['entries'][1]['is_summary'] );
		$this->assertSame( $summary_id, $list['summaryId'] );
	}

	/**
	 * The feed shows the Summary once as the summary card, never as an entry.
	 */
	public function test_feed_renders_the_summary_card_and_leaves_the_summary_out_of_its_entries() {
		$coverage_id = self::create_coverage();
		$entry_id    = self::create_entry( $coverage_id, [ 'post_title' => 'An update' ] );
		$summary_id  = self::create_summary( $coverage_id );

		$html = self::render( $coverage_id );

		$this->assertStringContainsString( 'data-rc-summary="' . $summary_id . '"', $html );
		$this->assertStringContainsString( '<h3 class="wp-block-post-title">What we know</h3>', $html );
		$this->assertStringContainsString( 'River at 19.8 feet', $html );
		$this->assertMatchesRegularExpression( '#Updated <time datetime="\d{4}-\d{2}-\d{2}T[^"]+">[^<]+</time>#', $html );
		$this->assertStringContainsString( 'data-entry-id="' . $entry_id . '"', $html );
		$this->assertStringNotContainsString( 'data-entry-id="' . $summary_id . '"', $html );
	}

	/**
	 * Without a Summary the card leaves only an empty, hidden placeholder,
	 * and a capped feed leaves the card out.
	 */
	public function test_no_summary_leaves_a_hidden_placeholder_and_a_capped_feed_none() {
		$coverage_id = self::create_coverage();
		self::create_entry( $coverage_id );

		$this->assertStringContainsString( '<div class="newspack-rolling-coverage-summary-card" data-rc-summary="0" hidden></div>', self::render( $coverage_id ) );

		self::create_summary( $coverage_id );
		$capped = self::render(
			$coverage_id,
			[
				'latestOnly'  => true,
				'latestCount' => 3,
			]
		);

		$this->assertStringNotContainsString( 'data-rc-summary', $capped );
		$this->assertStringNotContainsString( 'What we know', $capped );
	}

	/**
	 * Polls carry the Summary for the card, null once it is gone.
	 */
	public function test_polls_carry_the_summary() {
		$coverage_id = self::create_coverage();
		self::create_entry( $coverage_id );

		$html   = self::render( $coverage_id );
		$params = [
			'template_key' => self::wrapper_attribute( $html, 'data-template-key' ),
			'cursor'       => self::wrapper_attribute( $html, 'data-cursor' ),
		];

		$this->assertNull( self::dispatch( 'GET', "/coverages/{$coverage_id}/entries", $params )->get_data()['summary'] );

		$summary_id = self::create_summary( $coverage_id );
		$poll       = self::dispatch( 'GET', "/coverages/{$coverage_id}/entries", $params )->get_data();

		$this->assertSame( $summary_id, $poll['summary']['id'] );
		$this->assertStringContainsString( 'data-rc-summary="' . $summary_id . '"', $poll['summary']['html'] );
		$this->assertStringStartsWith( 'Updated ', $poll['summary']['updated'] );
		$this->assertStringContainsString( 'data-rc-updated="' . esc_attr( $poll['summary']['updated'] ) . '"', $poll['summary']['html'] );
		$this->assertNotContains( $summary_id, wp_list_pluck( $poll['entries'], 'id' ), 'The Summary is never a new entry.' );

		$shared = self::dispatch(
			'GET',
			"/coverages/{$coverage_id}/entries",
			[
				'template_key' => $params['template_key'],
				'recent'       => 1,
			] 
		)->get_data();
		$this->assertSame( $summary_id, $shared['summary']['id'] );
	}

	/**
	 * Polls render the summary card once, and again after an edit or once its
	 * "Updated" line reads differently.
	 */
	public function test_polls_reuse_the_rendered_card_until_the_summary_changes() {
		$coverage_id = self::create_coverage();
		self::create_entry( $coverage_id );
		$summary_id = self::create_summary( $coverage_id );
		$html       = self::render( $coverage_id );
		$params     = [
			'template_key' => self::wrapper_attribute( $html, 'data-template-key' ),
			'recent'       => 1,
		];
		$renders    = 0;
		$count      = static function ( $content ) use ( &$renders ) {
			++$renders;
			return $content;
		};

		add_filter( 'render_block_core/post-content', $count );
		$first  = self::dispatch( 'GET', "/coverages/{$coverage_id}/entries", $params )->get_data()['summary'];
		$second = self::dispatch( 'GET', "/coverages/{$coverage_id}/entries", $params )->get_data()['summary'];
		$this->assertSame( 1, $renders );
		$this->assertSame( $first, $second );

		self::set_entry_modified( $summary_id, '2026-01-05 15:42:00' );
		self::dispatch( 'GET', "/coverages/{$coverage_id}/entries", $params );
		$this->assertSame( 2, $renders, 'An edit renders the card again.' );

		update_option( 'time_format', 'H:i' === get_option( 'time_format' ) ? 'g:i a' : 'H:i' );
		$third = self::dispatch( 'GET', "/coverages/{$coverage_id}/entries", $params )->get_data()['summary'];
		remove_filter( 'render_block_core/post-content', $count );

		$this->assertSame( 3, $renders, 'A new time format renders the card again, since its Updated line reads differently.' );
		$this->assertStringContainsString( 'data-rc-updated="' . esc_attr( $third['updated'] ) . '"', $third['html'] );
	}

	/**
	 * A renamed coverage, another host page or a new time format renders
	 * the card again.
	 */
	public function test_polls_render_the_card_again_for_what_it_shows() {
		$coverage_id = self::create_coverage();
		self::create_entry( $coverage_id );
		self::create_summary( $coverage_id );
		$host_id = self::factory()->post->create();
		$html    = self::render( $coverage_id );
		$params  = [
			'template_key' => self::wrapper_attribute( $html, 'data-template-key' ),
			'recent'       => 1,
			'host_post_id' => $host_id,
		];
		$renders = 0;
		$count   = static function ( $content ) use ( &$renders ) {
			++$renders;
			return $content;
		};
		$poll    = static fn() => self::dispatch( 'GET', "/coverages/{$coverage_id}/entries", $params );

		add_filter( 'render_block_core/post-content', $count );
		$poll();
		$poll();
		$this->assertSame( 1, $renders );

		wp_update_term( $coverage_id, Taxonomy::TAXONOMY_SLUG, [ 'name' => 'Renamed liveblog' ] );
		$poll();
		$this->assertSame( 2, $renders, 'A renamed coverage renders the card again.' );

		$params['host_post_id'] = self::factory()->post->create();
		$poll  = static fn() => self::dispatch( 'GET', "/coverages/{$coverage_id}/entries", $params );
		$terms = wp_cache_get_last_changed( 'terms' );
		$posts = wp_cache_get_last_changed( 'posts' );
		$poll();
		remove_filter( 'render_block_core/post-content', $count );

		$this->assertSame( [ $terms, $posts ], [ wp_cache_get_last_changed( 'terms' ), wp_cache_get_last_changed( 'posts' ) ], 'Nothing else changed between the polls.' );
		$this->assertSame( 3, $renders, 'Another host page renders the card again.' );
	}

	/**
	 * The "Updated" line gives the time alone on the day of the edit, and the
	 * date with it after that.
	 */
	public function test_updated_text_follows_the_site_formats() {
		update_option( 'time_format', 'g:i a' );
		update_option( 'date_format', 'F j, Y' );
		$summary_id = self::create_summary( self::create_coverage() );

		self::set_entry_modified( $summary_id, gmdate( 'Y-m-d' ) . ' 00:00:00' );
		$this->assertSame( 'Updated 12:00 am', Entry_Bindings::updated_text( get_post( $summary_id ) ) );

		self::set_entry_modified( $summary_id, '2026-01-05 15:42:00' );
		$this->assertSame( 'Updated January 5, 2026, 3:42 pm', Entry_Bindings::updated_text( get_post( $summary_id ) ) );
	}
}
