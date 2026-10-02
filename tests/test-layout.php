<?php
/**
 * Tests for the shared layout Rolling Coverage blocks sync to.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Layout;
use Newspack_Rolling_Coverage\Rolling_Coverage_Block;

/**
 * A synced block renders the pattern's inner blocks; a detached one keeps
 * its own; anything unusable falls back to the built-in default.
 */
class Test_Layout extends Rolling_Coverage_TestCase {

	const MARKER = 'Shared layout marker';

	/**
	 * Markup for a layout pattern holding one paragraph as its entry template.
	 *
	 * @param string $text Paragraph text.
	 * @return string
	 */
	private static function layout_markup( string $text = self::MARKER ): string {
		return '<!-- wp:newspack-rolling-coverage/rolling-coverage --><!-- wp:paragraph --><p>' . $text . '</p><!-- /wp:paragraph --><!-- /wp:newspack-rolling-coverage/rolling-coverage -->';
	}

	/**
	 * Create a layout pattern.
	 *
	 * @param string $content Pattern content.
	 * @param string $status  Post status.
	 * @return int Pattern ID.
	 */
	private static function create_layout( string $content, string $status = 'publish' ): int {
		return self::factory()->post->create(
			[
				'post_type'    => 'wp_block',
				'post_status'  => $status,
				'post_content' => $content,
			]
		);
	}

	/**
	 * Render a story block through the render_block_data filter, as core does.
	 *
	 * @param array  $attributes Block attributes.
	 * @param string $inner      Saved inner markup.
	 * @return string
	 */
	private static function render_story( array $attributes, string $inner = '' ): string {
		$markup = '' === $inner
			? '<!-- wp:newspack-rolling-coverage/rolling-coverage ' . wp_json_encode( $attributes ) . ' /-->'
			: '<!-- wp:newspack-rolling-coverage/rolling-coverage ' . wp_json_encode( $attributes ) . ' -->' . $inner . '<!-- /wp:newspack-rolling-coverage/rolling-coverage -->';
		$parsed = parse_blocks( $markup )[0];
		$block  = apply_filters( 'render_block_data', $parsed, $parsed, null );

		// Called directly: the block type registers from the built assets, which the test run doesn't have.
		return Rolling_Coverage_Block::render_block( $attributes, '', new WP_Block( $block ) );
	}

	/**
	 * A synced block renders the layout its pattern holds.
	 */
	public function test_synced_block_renders_the_pattern_layout() {
		$coverage_id = self::create_coverage();
		self::create_entry( $coverage_id );
		$layout_id = self::create_layout( self::layout_markup() );

		$html = self::render_story(
			[
				'coverageId' => $coverage_id,
				'layoutId'   => $layout_id,
			]
		);

		$this->assertStringContainsString( self::MARKER, $html );
	}

	/**
	 * A block without a layoutId keeps its own inner blocks.
	 */
	public function test_detached_block_keeps_its_own_layout() {
		$coverage_id = self::create_coverage();
		self::create_entry( $coverage_id );
		self::create_layout( self::layout_markup() );

		$html = self::render_story( [ 'coverageId' => $coverage_id ], '<!-- wp:paragraph --><p>Local layout</p><!-- /wp:paragraph -->' );

		$this->assertStringContainsString( 'Local layout', $html );
		$this->assertStringNotContainsString( self::MARKER, $html );
	}

	/**
	 * Editing the pattern changes what synced blocks render.
	 */
	public function test_pattern_edits_reach_synced_blocks() {
		$coverage_id = self::create_coverage();
		self::create_entry( $coverage_id );
		$layout_id  = self::create_layout( self::layout_markup() );
		$attributes = [
			'coverageId' => $coverage_id,
			'layoutId'   => $layout_id,
		];

		self::render_story( $attributes );
		wp_update_post(
			[
				'ID'           => $layout_id,
				'post_content' => self::layout_markup( 'Edited layout' ),
			]
		);

		$this->assertStringContainsString( 'Edited layout', self::render_story( $attributes ) );
	}

	/**
	 * A layout that cannot be used renders the built-in layout.
	 *
	 * @dataProvider unusable_layouts
	 *
	 * @param string $content Pattern content.
	 * @param string $status  Pattern status.
	 */
	public function test_unusable_pattern_falls_back_to_the_default_layout( string $content, string $status ) {
		$coverage_id = self::create_coverage();
		self::create_entry( $coverage_id );
		$layout_id = self::create_layout( $content, $status );

		$html = self::render_story(
			[
				'coverageId' => $coverage_id,
				'layoutId'   => $layout_id,
			]
		);

		$this->assertStringNotContainsString( self::MARKER, $html );
		$this->assertStringContainsString( 'data-rc-share', $html, 'The built-in default layout should render.' );
	}

	/**
	 * Layouts that cannot be used.
	 *
	 * @return array<string,array{string,string}>
	 */
	public function unusable_layouts(): array {
		return [
			'trashed'         => [ self::layout_markup(), 'trash' ],
			'no layout block' => [ '<!-- wp:paragraph --><p>' . self::MARKER . '</p><!-- /wp:paragraph -->', 'publish' ],
		];
	}

	/**
	 * Only published patterns resolve.
	 */
	public function test_unpublished_pattern_is_never_rendered() {
		foreach ( [ 'draft', 'private', 'pending' ] as $status ) {
			$layout_id = self::create_layout( self::layout_markup(), $status );

			$this->assertNull( Layout::get_layout_blocks( $layout_id ), "A {$status} pattern should not resolve." );
		}

		$this->assertNull( Layout::get_layout_blocks( self::factory()->post->create( [ 'post_content' => self::layout_markup() ] ) ), 'A regular post should not resolve.' );
		$this->assertNull( Layout::get_layout_blocks( 999999 ), 'A missing post should not resolve.' );
	}

	/**
	 * A synced block nested inside the layout renders once, detached, instead
	 * of pulling the layout into itself again.
	 */
	public function test_nested_synced_block_does_not_recurse() {
		$coverage_id = self::create_coverage();
		self::create_entry( $coverage_id );
		$layout_id = self::create_layout( '' );
		wp_update_post(
			[
				'ID'           => $layout_id,
				'post_content' => '<!-- wp:newspack-rolling-coverage/rolling-coverage --><!-- wp:paragraph --><p>' . self::MARKER . '</p><!-- /wp:paragraph --><!-- wp:group --><div class="wp-block-group"><!-- wp:newspack-rolling-coverage/rolling-coverage ' . wp_json_encode(
					[
						'coverageId' => $coverage_id,
						'layoutId'   => $layout_id,
					]
				) . ' /--></div><!-- /wp:group --><!-- /wp:newspack-rolling-coverage/rolling-coverage -->',
			]
		);

		$html = self::render_story(
			[
				'coverageId' => $coverage_id,
				'layoutId'   => $layout_id,
			]
		);

		$this->assertStringContainsString( self::MARKER, $html );

		$nested = Layout::get_layout_blocks( $layout_id )[1]['innerBlocks'][0];
		$this->assertSame( Rolling_Coverage_Block::BLOCK_NAME, $nested['blockName'] );
		$this->assertSame( $coverage_id, $nested['attrs']['coverageId'] );
		$this->assertArrayNotHasKey( 'layoutId', $nested['attrs'] );
	}

	/**
	 * A synced block nested inside a detached story renders its own inner
	 * blocks, matching the editor, which treats every nested block as detached.
	 */
	public function test_synced_block_nested_in_a_detached_story_renders_detached() {
		$coverage_id = self::create_coverage();
		self::create_entry( $coverage_id );
		$layout_id = self::create_layout( self::layout_markup() );
		$nested    = '<!-- wp:newspack-rolling-coverage/rolling-coverage ' . wp_json_encode(
			[
				'coverageId' => $coverage_id,
				'layoutId'   => $layout_id,
			]
		) . ' --><!-- wp:paragraph --><p>Nested local layout</p><!-- /wp:paragraph --><!-- /wp:newspack-rolling-coverage/rolling-coverage -->';

		$html = self::render_story(
			[ 'coverageId' => $coverage_id ],
			'<!-- wp:group --><div class="wp-block-group">' . $nested . '</div><!-- /wp:group -->'
		);

		$this->assertStringContainsString( 'Nested local layout', $html );
		$this->assertStringNotContainsString( self::MARKER, $html );
	}

	/**
	 * Pages still cached with the previous layout's key keep loading it, so
	 * only the config from two layout edits ago is dropped.
	 */
	public function test_pattern_edit_keeps_the_previous_block_config() {
		$coverage_id = self::create_coverage();
		$persist     = new ReflectionMethod( Rolling_Coverage_Block::class, 'persist_block_config' );
		$load        = new ReflectionMethod( Rolling_Coverage_Block::class, 'load_block_config' );
		$persist->setAccessible( true );
		$load->setAccessible( true );

		$templates = [];
		$keys      = [];
		foreach ( [ 'First', 'Second', 'Third' ] as $text ) {
			$template    = parse_blocks( '<!-- wp:paragraph --><p>' . $text . '</p><!-- /wp:paragraph -->' );
			$templates[] = $template;
			$keys[]      = $persist->invoke( null, $coverage_id, $template, true, 4 );
		}

		$this->assertFalse( get_option( Rolling_Coverage_Block::TEMPLATE_OPTION_PREFIX . $coverage_id . '_' . $keys[0] ) );
		$this->assertIsArray( get_option( Rolling_Coverage_Block::TEMPLATE_OPTION_PREFIX . $coverage_id . '_' . $keys[1] ) );
		$this->assertIsArray( get_option( Rolling_Coverage_Block::TEMPLATE_OPTION_PREFIX . $coverage_id . '_' . $keys[2] ) );
		$this->assertSame( $templates[1], $load->invoke( null, $coverage_id, $keys[1] )['template'] );
		$this->assertSame( $templates[2], $load->invoke( null, $coverage_id, $keys[2] )['template'] );
	}

	/**
	 * A layoutId pointing at nothing renders the built-in layout.
	 */
	public function test_missing_pattern_falls_back_to_the_default_layout() {
		$coverage_id = self::create_coverage();
		self::create_entry( $coverage_id );

		$html = self::render_story(
			[
				'coverageId' => $coverage_id,
				'layoutId'   => 999999,
			]
		);

		$this->assertStringContainsString( 'data-rc-share', $html );
	}

	/**
	 * The first create publishes and records the default layout.
	 */
	public function test_create_makes_the_default_layout_once() {
		self::log_in_as( 'editor' );

		$first = self::dispatch( 'POST', '/layouts/default', [ 'content' => self::layout_markup() ] );
		$this->assertSame( 201, $first->get_status() );
		$id = $first->get_data()['id'];

		$this->assertSame( $id, (int) get_option( Layout::option_name( 'default' ) ) );
		$this->assertSame( 'publish', get_post_status( $id ) );
		$this->assertSame( 'wp_block', get_post_type( $id ) );
		$this->assertSame( [ Layout::PATTERN_CATEGORY ], wp_get_object_terms( $id, 'wp_pattern_category', [ 'fields' => 'slugs' ] ) );
		$this->assertSame( $id, Layout::get_layout_id( 'default' ) );
		$this->assertSame( 'Rolling Coverage: Bulletin', get_the_title( $id ) );
		$this->assertSame( Layout::get_pattern_category_id(), $first->get_data()['categoryId'] );
		$this->assertGreaterThan( 0, $first->get_data()['categoryId'] );
	}

	/**
	 * A second create returns the existing layout instead of adding one.
	 */
	public function test_create_returns_the_existing_layout() {
		self::log_in_as( 'editor' );
		$id           = self::dispatch( 'POST', '/layouts/default', [ 'content' => self::layout_markup() ] )->get_data()['id'];
		$count_before = (int) wp_count_posts( 'wp_block' )->publish;

		$second = self::dispatch( 'POST', '/layouts/default', [ 'content' => self::layout_markup( 'Other' ) ] );

		$this->assertSame( 200, $second->get_status() );
		$this->assertSame( $id, $second->get_data()['id'] );
		$this->assertSame( $count_before, (int) wp_count_posts( 'wp_block' )->publish );
	}

	/**
	 * A trashed default no longer resolves, so create makes a new one.
	 */
	public function test_create_recreates_a_trashed_default() {
		self::log_in_as( 'editor' );
		$id = self::dispatch( 'POST', '/layouts/default', [ 'content' => self::layout_markup() ] )->get_data()['id'];
		wp_trash_post( $id );

		$this->assertSame( 0, Layout::get_layout_id( 'default' ) );

		$response = self::dispatch( 'POST', '/layouts/default', [ 'content' => self::layout_markup() ] );

		$this->assertSame( 201, $response->get_status() );
		$this->assertNotSame( $id, $response->get_data()['id'] );
	}

	/**
	 * Posted content must be exactly one Rolling Coverage block.
	 *
	 * @dataProvider invalid_layout_content
	 *
	 * @param string $content Posted content.
	 */
	public function test_create_rejects_content_that_is_not_one_layout_block( string $content ) {
		self::log_in_as( 'editor' );

		$response = self::dispatch( 'POST', '/layouts/default', [ 'content' => $content ] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 0, (int) get_option( Layout::option_name( 'default' ), 0 ) );
	}

	/**
	 * Content that is not one layout block.
	 *
	 * @return array<string,array{string}>
	 */
	public function invalid_layout_content(): array {
		$layout = '<!-- wp:newspack-rolling-coverage/rolling-coverage --><!-- wp:paragraph --><p>x</p><!-- /wp:paragraph --><!-- /wp:newspack-rolling-coverage/rolling-coverage -->';

		return [
			'empty'           => [ '' ],
			'other block'     => [ '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' ],
			'two layouts'     => [ $layout . $layout ],
			'layout and more' => [ $layout . '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' ],
		];
	}

	/**
	 * A category already named Rolling Coverage under another slug doesn't
	 * stop the layout getting one.
	 */
	public function test_create_assigns_a_category_despite_a_name_clash() {
		self::factory()->term->create(
			[
				'taxonomy' => Layout::PATTERN_TAXONOMY,
				'name'     => 'Rolling Coverage',
				'slug'     => 'live-coverage',
			]
		);
		self::log_in_as( 'editor' );

		$id = self::dispatch( 'POST', '/layouts/default', [ 'content' => self::layout_markup() ] )->get_data()['id'];

		$this->assertSame( [ 'Rolling Coverage' ], wp_get_object_terms( $id, Layout::PATTERN_TAXONOMY, [ 'fields' => 'names' ] ) );
	}

	/**
	 * When creating the category reports that it already exists, the
	 * existing term is assigned.
	 */
	public function test_create_assigns_the_existing_category_when_insert_reports_it() {
		$existing = self::factory()->term->create(
			[
				'taxonomy' => Layout::PATTERN_TAXONOMY,
				'name'     => 'Rolling Coverage',
				'slug'     => 'live-coverage',
			]
		);
		add_filter(
			'pre_insert_term',
			static fn() => new WP_Error( 'term_exists', 'A term with the name provided already exists.', $existing )
		);
		self::log_in_as( 'editor' );

		$id = self::dispatch( 'POST', '/layouts/default', [ 'content' => self::layout_markup() ] )->get_data()['id'];

		$this->assertSame( [ $existing ], wp_get_object_terms( $id, Layout::PATTERN_TAXONOMY, [ 'fields' => 'ids' ] ) );
	}

	/**
	 * Users who cannot publish patterns cannot create the layout.
	 */
	public function test_create_is_closed_to_users_who_cannot_publish_patterns() {
		self::log_in_as( 'contributor' );

		$response = self::dispatch( 'POST', '/layouts/default', [ 'content' => self::layout_markup() ] );

		$this->assertContains( $response->get_status(), [ 401, 403 ] );
		$this->assertSame( 0, (int) get_option( Layout::option_name( 'default' ), 0 ) );
	}

	/**
	 * The first stream create publishes and records it under its own option.
	 */
	public function test_create_makes_the_stream_layout_once() {
		self::log_in_as( 'editor' );

		$response = self::dispatch( 'POST', '/layouts/stream', [ 'content' => self::layout_markup() ] );
		$this->assertSame( 201, $response->get_status() );
		$id = $response->get_data()['id'];

		$this->assertSame( $id, (int) get_option( 'rolling_coverage_stream_layout_id' ) );
		$this->assertSame( 'publish', get_post_status( $id ) );
		$this->assertSame( 'Rolling Coverage: Stream', get_the_title( $id ) );
		$this->assertSame( [ Layout::PATTERN_CATEGORY ], wp_get_object_terms( $id, 'wp_pattern_category', [ 'fields' => 'slugs' ] ) );
		$this->assertSame( $id, Layout::get_layout_id( 'stream' ) );
	}

	/**
	 * The first rail create publishes and records it under its own option.
	 */
	public function test_create_makes_the_rail_layout_once() {
		self::log_in_as( 'editor' );

		$response = self::dispatch( 'POST', '/layouts/rail', [ 'content' => self::layout_markup() ] );
		$this->assertSame( 201, $response->get_status() );
		$id = $response->get_data()['id'];

		$this->assertSame( $id, (int) get_option( 'rolling_coverage_rail_layout_id' ) );
		$this->assertSame( 'Rolling Coverage: Rail', get_the_title( $id ) );
		$this->assertSame( $id, Layout::get_layout_id( 'rail' ) );
		$this->assertSame( 200, self::dispatch( 'POST', '/layouts/rail', [ 'content' => self::layout_markup( 'Other' ) ] )->get_status() );
	}

	/**
	 * The first clock and margin creates each publish their layout under its
	 * own option and title.
	 *
	 * @dataProvider data_clock_and_margin
	 *
	 * @param string $slug  Built-in layout slug.
	 * @param string $title Expected pattern title.
	 */
	public function test_create_makes_the_clock_and_margin_layouts_once( string $slug, string $title ) {
		self::log_in_as( 'editor' );

		$response = self::dispatch( 'POST', "/layouts/{$slug}", [ 'content' => self::layout_markup() ] );
		$this->assertSame( 201, $response->get_status() );
		$id = $response->get_data()['id'];

		$this->assertSame( $id, (int) get_option( "rolling_coverage_{$slug}_layout_id" ) );
		$this->assertSame( $title, get_the_title( $id ) );
		$this->assertSame( $id, Layout::get_layout_id( $slug ) );

		$second = self::dispatch( 'POST', "/layouts/{$slug}", [ 'content' => self::layout_markup( 'Other' ) ] );
		$this->assertSame( 200, $second->get_status() );
		$this->assertSame( $id, $second->get_data()['id'] );
	}

	/**
	 * The Clock and Margin layouts with their pattern titles.
	 *
	 * @return array[]
	 */
	public function data_clock_and_margin(): array {
		return [
			'clock'  => [ 'clock', 'Rolling Coverage: Clock' ],
			'margin' => [ 'margin', 'Rolling Coverage: Margin' ],
		];
	}

	/**
	 * A second stream create returns the existing pattern.
	 */
	public function test_create_returns_the_existing_stream_layout() {
		self::log_in_as( 'editor' );
		$id           = self::dispatch( 'POST', '/layouts/stream', [ 'content' => self::layout_markup() ] )->get_data()['id'];
		$count_before = (int) wp_count_posts( 'wp_block' )->publish;

		$second = self::dispatch( 'POST', '/layouts/stream', [ 'content' => self::layout_markup( 'Other' ) ] );

		$this->assertSame( 200, $second->get_status() );
		$this->assertSame( $id, $second->get_data()['id'] );
		$this->assertSame( $count_before, (int) wp_count_posts( 'wp_block' )->publish );
	}

	/**
	 * A trashed stream layout no longer resolves, so create makes a new one.
	 */
	public function test_create_recreates_a_trashed_stream_layout() {
		self::log_in_as( 'editor' );
		$id = self::dispatch( 'POST', '/layouts/stream', [ 'content' => self::layout_markup() ] )->get_data()['id'];
		wp_trash_post( $id );

		$this->assertSame( 0, Layout::get_layout_id( 'stream' ) );

		$response = self::dispatch( 'POST', '/layouts/stream', [ 'content' => self::layout_markup() ] );

		$this->assertSame( 201, $response->get_status() );
		$this->assertNotSame( $id, $response->get_data()['id'] );
		$this->assertSame( $response->get_data()['id'], (int) get_option( 'rolling_coverage_stream_layout_id' ) );
	}

	/**
	 * Each built-in layout keeps its own option.
	 */
	public function test_stream_and_default_layouts_are_independent() {
		self::log_in_as( 'editor' );

		$stream = self::dispatch( 'POST', '/layouts/stream', [ 'content' => self::layout_markup() ] )->get_data()['id'];
		$this->assertSame( 0, (int) get_option( Layout::option_name( 'default' ), 0 ) );

		$default = self::dispatch( 'POST', '/layouts/default', [ 'content' => self::layout_markup() ] );
		$this->assertSame( 201, $default->get_status() );
		$this->assertNotSame( $stream, $default->get_data()['id'] );
		$this->assertSame( $stream, (int) get_option( 'rolling_coverage_stream_layout_id' ) );
		$this->assertSame( 'Rolling Coverage: Bulletin', get_the_title( $default->get_data()['id'] ) );
	}

	/**
	 * Only built-in slugs have a route.
	 */
	public function test_create_rejects_an_unknown_layout_slug() {
		self::log_in_as( 'editor' );

		$response = self::dispatch( 'POST', '/layouts/fancy', [ 'content' => self::layout_markup() ] );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * Users who cannot publish patterns cannot create the stream layout.
	 */
	public function test_create_stream_is_closed_to_users_who_cannot_publish_patterns() {
		self::log_in_as( 'contributor' );

		$response = self::dispatch( 'POST', '/layouts/stream', [ 'content' => self::layout_markup() ] );

		$this->assertContains( $response->get_status(), [ 401, 403 ] );
		$this->assertSame( 0, (int) get_option( 'rolling_coverage_stream_layout_id', 0 ) );
	}

	/**
	 * The default layout keeps its original option name.
	 */
	public function test_option_name_keeps_the_default_option() {
		$this->assertSame( 'rolling_coverage_default_layout_id', Layout::option_name( 'default' ) );
	}

	/**
	 * A created built-in pattern is tagged with its slug.
	 */
	public function test_created_layout_carries_its_slug() {
		self::log_in_as( 'editor' );

		$id = self::dispatch( 'POST', '/layouts/stream', [ 'content' => self::layout_markup() ] )->get_data()['id'];

		$this->assertSame( 'stream', get_post_meta( $id, Layout::SLUG_META_KEY, true ) );
	}

	/**
	 * A lost option doesn't hide the pattern, and is repaired.
	 */
	public function test_layout_is_found_by_its_slug_when_the_option_is_lost() {
		self::log_in_as( 'editor' );
		$id = self::dispatch( 'POST', '/layouts/stream', [ 'content' => self::layout_markup() ] )->get_data()['id'];

		delete_option( Layout::option_name( 'stream' ) );
		$this->assertSame( $id, Layout::get_layout_id( 'stream' ) );
		$this->assertSame( $id, (int) get_option( Layout::option_name( 'stream' ) ) );

		update_option( Layout::option_name( 'stream' ), 999999 );
		$this->assertSame( $id, Layout::get_layout_id( 'stream' ) );
		$this->assertSame( $id, (int) get_option( Layout::option_name( 'stream' ) ) );
		$this->assertSame( 0, Layout::get_layout_id( 'default' ) );
	}

	/**
	 * Creating after the option is lost returns the existing pattern.
	 */
	public function test_create_returns_the_existing_layout_when_the_option_is_lost() {
		self::log_in_as( 'editor' );
		$id = self::dispatch( 'POST', '/layouts/stream', [ 'content' => self::layout_markup() ] )->get_data()['id'];
		delete_option( Layout::option_name( 'stream' ) );
		$count_before = (int) wp_count_posts( 'wp_block' )->publish;

		$second = self::dispatch( 'POST', '/layouts/stream', [ 'content' => self::layout_markup() ] );

		$this->assertSame( 200, $second->get_status() );
		$this->assertSame( $id, $second->get_data()['id'] );
		$this->assertSame( $count_before, (int) wp_count_posts( 'wp_block' )->publish );
	}

	/**
	 * A trashed tagged pattern is not returned.
	 */
	public function test_trashed_tagged_layout_is_not_found_by_its_slug() {
		self::log_in_as( 'editor' );
		$id = self::dispatch( 'POST', '/layouts/stream', [ 'content' => self::layout_markup() ] )->get_data()['id'];
		wp_trash_post( $id );
		delete_option( Layout::option_name( 'stream' ) );

		$this->assertSame( 0, Layout::get_layout_id( 'stream' ) );
	}

	/**
	 * Whether a built-in layout's creation lock is in the database.
	 *
	 * @param string $slug Built-in layout slug.
	 * @return bool
	 */
	private static function is_locked( string $slug ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return null !== $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Layout::lock_name( $slug ) ) );
	}

	/**
	 * A create while another holds the lock is refused without inserting.
	 */
	public function test_create_is_refused_while_another_holds_the_lock() {
		self::log_in_as( 'editor' );
		add_option( Layout::lock_name( 'stream' ), time(), '', false );
		$count_before = (int) wp_count_posts( 'wp_block' )->publish;

		$response = self::dispatch( 'POST', '/layouts/stream', [ 'content' => self::layout_markup() ] );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( $count_before, (int) wp_count_posts( 'wp_block' )->publish );
		$this->assertSame( 0, (int) get_option( Layout::option_name( 'stream' ), 0 ) );
		$this->assertTrue( self::is_locked( 'stream' ), 'The other request keeps its lock.' );
	}

	/**
	 * An abandoned lock doesn't block creation.
	 */
	public function test_create_takes_over_a_stale_lock() {
		self::log_in_as( 'editor' );
		add_option( Layout::lock_name( 'stream' ), time() - Layout::LOCK_TIMEOUT - 1, '', false );

		$response = self::dispatch( 'POST', '/layouts/stream', [ 'content' => self::layout_markup() ] );

		$this->assertSame( 201, $response->get_status() );
		$this->assertFalse( self::is_locked( 'stream' ) );
	}

	/**
	 * The lock is released after a successful create.
	 */
	public function test_create_releases_the_lock_after_success() {
		self::log_in_as( 'editor' );

		$response = self::dispatch( 'POST', '/layouts/default', [ 'content' => self::layout_markup() ] );

		$this->assertSame( 201, $response->get_status() );
		$this->assertFalse( self::is_locked( 'default' ) );
	}

	/**
	 * The lock is released when the insert fails.
	 */
	public function test_create_releases_the_lock_after_failure() {
		self::log_in_as( 'editor' );
		add_filter( 'wp_insert_post_empty_content', '__return_true' );

		$response = self::dispatch( 'POST', '/layouts/default', [ 'content' => self::layout_markup() ] );

		remove_filter( 'wp_insert_post_empty_content', '__return_true' );

		$this->assertTrue( $response->is_error() );
		$this->assertSame( 0, Layout::get_layout_id( 'default' ) );
		$this->assertFalse( self::is_locked( 'default' ) );
	}

	/**
	 * A layout another request created after this one first looked is found
	 * once the lock is held, even though this request's caches missed it.
	 */
	public function test_create_finds_a_layout_created_behind_the_cache() {
		global $wpdb;

		self::log_in_as( 'editor' );
		$this->assertSame( 0, Layout::get_layout_id( 'stream' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->posts,
			[
				'post_type'         => 'wp_block',
				'post_status'       => 'publish',
				'post_title'        => 'Rolling Coverage: Stream',
				'post_content'      => self::layout_markup(),
				'post_author'       => get_current_user_id(),
				'post_date'         => current_time( 'mysql' ),
				'post_date_gmt'     => current_time( 'mysql', true ),
				'post_modified'     => current_time( 'mysql' ),
				'post_modified_gmt' => current_time( 'mysql', true ),
			]
		);
		$id = (int) $wpdb->insert_id;
		$wpdb->insert(
			$wpdb->postmeta,
			[
				'post_id'    => $id,
				'meta_key'   => Layout::SLUG_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => 'stream', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			]
		);
		$wpdb->insert(
			$wpdb->options,
			[
				'option_name'  => Layout::option_name( 'stream' ),
				'option_value' => (string) $id,
				'autoload'     => 'off',
			]
		);
		$count_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'wp_block' AND post_status = 'publish'" );
		// phpcs:enable

		$response = self::dispatch( 'POST', '/layouts/stream', [ 'content' => self::layout_markup() ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $id, $response->get_data()['id'] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertSame( $count_before, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'wp_block' AND post_status = 'publish'" ) );
		$this->assertSame( $id, (int) get_option( Layout::option_name( 'stream' ) ) );
	}
}
