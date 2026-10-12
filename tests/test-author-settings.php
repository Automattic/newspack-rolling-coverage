<?php
/**
 * Tests for the block's Author name and Author avatar settings.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Rolling_Coverage_Block;
use Newspack_Rolling_Coverage\Slack_Config;

/**
 * Author name on Hide drops the author's name from every entry, Author
 * avatar on Hide the avatar, each on its own, and the Slack bot's entries drop both. A
 * group left empty goes with them, so it takes no gap in its row, and the
 * stored config carries the result to polls and load more.
 */
class Test_Author_Settings extends Rolling_Coverage_TestCase {

	/**
	 * A links row led by the author's avatar and name in a group of their own,
	 * as Rail, Clock and Split have it.
	 */
	const LINKS_MARKUP = '<!-- wp:group {"metadata":{"name":"Links"},"layout":{"type":"flex"}} --><div class="wp-block-group">'
		. '<!-- wp:group {"metadata":{"name":"Author"},"layout":{"type":"flex","flexWrap":"nowrap"}} --><div class="wp-block-group">'
		. '<!-- wp:avatar {"size":24} /--><!-- wp:post-author-name /-->'
		. '</div><!-- /wp:group -->'
		. '<!-- wp:paragraph --><p>Share</p><!-- /wp:paragraph -->'
		. '</div><!-- /wp:group -->';

	/**
	 * Byline's row: the avatar in a column of its own beside the name.
	 */
	const ROW_MARKUP = '<!-- wp:columns {"isStackedOnMobile":false} --><div class="wp-block-columns">'
		. '<!-- wp:column {"width":"40px"} --><div class="wp-block-column"><!-- wp:avatar {"size":40} /--></div><!-- /wp:column -->'
		. '<!-- wp:column --><div class="wp-block-column"><!-- wp:post-author-name /--><!-- wp:post-date /--></div><!-- /wp:column -->'
		. '</div><!-- /wp:columns -->';

	/**
	 * Render the block holding a layout.
	 *
	 * @param array  $attributes Block attributes.
	 * @param string $markup     The block's inner blocks, as the editor saves them.
	 * @return string
	 */
	private static function render_block( array $attributes, string $markup ): string {
		$block = parse_blocks( '<!-- wp:newspack-rolling-coverage/rolling-coverage ' . wp_json_encode( $attributes ) . ' -->' . $markup . '<!-- /wp:newspack-rolling-coverage/rolling-coverage -->' )[0];

		return Rolling_Coverage_Block::render_block( $attributes, '', new WP_Block( $block ) );
	}

	/**
	 * A coverage with one entry by a named reporter.
	 *
	 * @return int Coverage term ID.
	 */
	private static function create_signed_coverage(): int {
		$coverage_id = self::create_coverage();
		self::create_entry( $coverage_id, [ 'post_author' => self::factory()->user->create( [ 'display_name' => 'Jane Reporter' ] ) ] );

		return $coverage_id;
	}

	/**
	 * How many groups the HTML holds, and how many of them are empty.
	 *
	 * @param string $html Rendered HTML.
	 * @return int[] The count of groups, then of empty ones.
	 */
	private static function groups( string $html ): array {
		return [
			preg_match_all( '/<div class="wp-block-group[ "]/', $html ),
			preg_match_all( '/<div class="wp-block-group[ "][^>]*>\s*<\/div>/', $html ),
		];
	}

	/**
	 * How many columns a rendered row has.
	 *
	 * @param string $html Rendered HTML.
	 * @return int
	 */
	private static function count_columns( string $html ): int {
		return preg_match_all( '/class="wp-block-column[ "]/', $html );
	}

	/**
	 * Author name on Hide drops only the name: the author group keeps the
	 * avatar, with no empty group left in the row.
	 */
	public function test_hiding_the_name_keeps_the_avatar() {
		$html = self::render_block(
			[
				'coverageId' => self::create_signed_coverage(),
				'showAuthor' => false,
			],
			self::LINKS_MARKUP
		);

		$this->assertStringNotContainsString( 'wp-block-post-author-name', $html );
		$this->assertStringContainsString( 'wp-block-avatar', $html );
		$this->assertSame( [ 2, 0 ], self::groups( $html ), 'The author group still holds the avatar.' );
	}

	/**
	 * With both on Hide, the author group goes, leaving the rest of the links
	 * row and no empty group.
	 */
	public function test_hiding_the_name_and_the_avatar_drops_their_group() {
		$coverage_id = self::create_signed_coverage();

		$this->assertSame( [ 2, 0 ], self::groups( self::render_block( [ 'coverageId' => $coverage_id ], self::LINKS_MARKUP ) ) );

		$html = self::render_block(
			[
				'coverageId' => $coverage_id,
				'showAuthor' => false,
				'showAvatar' => false,
			],
			self::LINKS_MARKUP
		);

		$this->assertStringNotContainsString( 'wp-block-avatar', $html );
		$this->assertStringNotContainsString( 'wp-block-post-author-name', $html );
		$this->assertSame( [ 1, 0 ], self::groups( $html ), 'Only the links row should be left.' );
		$this->assertStringContainsString( 'Share', $html );
	}

	/**
	 * Byline with the name hidden keeps its avatar column and the date.
	 */
	public function test_hiding_the_name_keeps_bylines_avatar_column_and_date() {
		$row = self::render_block(
			[
				'coverageId' => self::create_signed_coverage(),
				'showAuthor' => false,
			],
			self::ROW_MARKUP
		);

		$this->assertStringNotContainsString( 'wp-block-post-author-name', $row );
		$this->assertStringContainsString( 'wp-block-avatar', $row );
		$this->assertStringContainsString( 'wp-block-post-date', $row );
		$this->assertSame( 2, self::count_columns( $row ) );
	}

	/**
	 * Author avatar on Hide drops only the avatar, and Byline's avatar column with it.
	 */
	public function test_hiding_the_avatar_keeps_the_name() {
		$coverage_id = self::create_signed_coverage();
		$attributes  = [
			'coverageId' => $coverage_id,
			'showAvatar' => false,
		];

		$html = self::render_block( $attributes, self::LINKS_MARKUP );

		$this->assertStringNotContainsString( 'wp-block-avatar', $html );
		$this->assertStringContainsString( 'wp-block-post-author-name', $html );
		$this->assertSame( [ 2, 0 ], self::groups( $html ), 'The author group still holds the name.' );

		$this->assertSame( 2, self::count_columns( self::render_block( [ 'coverageId' => $coverage_id ], self::ROW_MARKUP ) ) );

		$row = self::render_block( $attributes, self::ROW_MARKUP );

		$this->assertSame( 1, self::count_columns( $row ), 'The column that held only the avatar should go.' );
		$this->assertStringContainsString( 'Jane Reporter', $row );
	}

	/**
	 * The Slack bot's entry drops its avatar and name with no empty group left
	 * in the row, while Byline keeps the avatar's column so the text lines up.
	 */
	public function test_a_slack_bot_entry_leaves_no_empty_group() {
		$entry_id = self::create_entry( self::create_coverage(), [ 'post_author' => Slack_Config::get_or_create_bot_user_id() ] );

		$html = Rolling_Coverage_Block::render_entry( get_post( $entry_id ), parse_blocks( self::LINKS_MARKUP ) );

		$this->assertStringNotContainsString( 'wp-block-avatar', $html );
		$this->assertStringNotContainsString( 'wp-block-post-author-name', $html );
		$this->assertSame( [ 1, 0 ], self::groups( $html ) );

		$row = Rolling_Coverage_Block::render_entry( get_post( $entry_id ), parse_blocks( self::ROW_MARKUP ) );

		$this->assertSame( 2, self::count_columns( $row ) );
	}

	/**
	 * A group the layout left empty is the layout's own, so hiding the name
	 * and the avatar keeps it.
	 */
	public function test_hiding_the_byline_keeps_a_group_the_layout_left_empty() {
		$markup = self::LINKS_MARKUP . '<!-- wp:group {"className":"spacer"} --><div class="wp-block-group spacer"></div><!-- /wp:group -->';

		$html = self::render_block(
			[
				'coverageId' => self::create_signed_coverage(),
				'showAuthor' => false,
				'showAvatar' => false,
			],
			$markup
		);

		$this->assertStringNotContainsString( 'wp-block-post-author-name', $html );
		$this->assertStringContainsString( 'wp-block-group spacer', $html, 'The empty spacer should stay.' );
	}

	/**
	 * With Avatar Display off, the Slack bot's entry drops its name too, and
	 * the group that held the avatar and name goes.
	 */
	public function test_a_slack_bot_entry_with_avatars_off_leaves_no_empty_group() {
		update_option( 'show_avatars', 0 );
		$coverage_id = self::create_coverage();
		self::create_entry( $coverage_id, [ 'post_author' => Slack_Config::get_or_create_bot_user_id() ] );

		$html = self::render_block( [ 'coverageId' => $coverage_id ], self::LINKS_MARKUP );

		$this->assertStringNotContainsString( 'wp-block-post-author-name', $html );
		$this->assertSame( [ 1, 0 ], self::groups( $html ) );
	}

	/**
	 * Load more follows Avatar Display as it is when the entries load, so
	 * turning avatars off drops the avatar column from a page stored with it.
	 */
	public function test_loaded_entries_drop_the_avatar_column_when_avatars_are_off() {
		$coverage_id = self::create_coverage();
		$author_id   = self::factory()->user->create( [ 'display_name' => 'Jane Reporter' ] );
		self::create_entry(
			$coverage_id,
			[
				'post_author' => $author_id,
				'post_date'   => '2026-01-01 10:00:00',
			]
		);
		self::create_entry(
			$coverage_id,
			[
				'post_author' => $author_id,
				'post_date'   => '2026-01-01 11:00:00',
			]
		);

		$html = self::render_block(
			[
				'coverageId'     => $coverage_id,
				'entriesPerPage' => 1,
			],
			self::ROW_MARKUP
		);

		$this->assertSame( 2, self::count_columns( $html ) );

		preg_match( '/data-template-key="([^"]+)"/', $html, $matches );
		update_option( 'show_avatars', 0 );

		$request = new WP_REST_Request( 'GET' );
		$request->set_param( 'term_id', $coverage_id );
		$request->set_param( 'template_key', $matches[1] );
		$request->set_param( 'per_page', 1 );
		$request->set_param( 'before', '2026-01-01 11:00:00' );

		$older = Rolling_Coverage_Block::get_entries( $request )->get_data()['html'];

		$this->assertStringContainsString( 'Jane Reporter', $older, 'The older entry should load.' );
		$this->assertSame( 1, self::count_columns( $older ), 'The column that held only the avatar should go.' );
	}

	/**
	 * Entries a poll or load more brings in follow the block's settings.
	 *
	 * @dataProvider data_settings
	 *
	 * @param array $settings     The block's Author name and Author avatar settings.
	 * @param bool  $shows_name   Whether the entry should show its author's name.
	 * @param bool  $shows_avatar Whether the entry should show its author's avatar.
	 */
	public function test_polled_entries_follow_the_settings( array $settings, bool $shows_name, bool $shows_avatar ) {
		$coverage_id = self::create_coverage();
		$author_id   = self::factory()->user->create( [ 'display_name' => 'Jane Reporter' ] );
		self::create_entry(
			$coverage_id,
			[
				'post_author' => $author_id,
				'post_date'   => '2026-01-01 10:00:00',
			]
		);
		self::create_entry(
			$coverage_id,
			[
				'post_author' => $author_id,
				'post_date'   => '2026-01-01 11:00:00',
			]
		);

		$html = self::render_block(
			array_merge(
				[
					'coverageId'     => $coverage_id,
					'entriesPerPage' => 1,
				],
				$settings
			),
			self::LINKS_MARKUP
		);

		preg_match( '/data-template-key="([^"]+)"/', $html, $matches );

		$request = new WP_REST_Request( 'GET' );
		$request->set_param( 'term_id', $coverage_id );
		$request->set_param( 'template_key', $matches[1] );
		$request->set_param( 'per_page', 1 );
		$request->set_param( 'before', '2026-01-01 11:00:00' );

		$older = Rolling_Coverage_Block::get_entries( $request )->get_data()['html'];

		$this->assertStringContainsString( 'data-entry-id', $older, 'The older entry should load.' );
		$this->assertSame( $shows_name, false !== strpos( $older, 'wp-block-post-author-name' ) );
		$this->assertSame( $shows_avatar, false !== strpos( $older, 'wp-block-avatar' ) );
		$this->assertSame( 0, self::groups( $older )[1], 'No group should be left empty.' );
	}

	/**
	 * Settings that hide the name, the avatar or both, and what stays.
	 *
	 * @return array[]
	 */
	public function data_settings(): array {
		return [
			'name hidden'   => [ [ 'showAuthor' => false ], false, true ],
			'avatar hidden' => [ [ 'showAvatar' => false ], true, false ],
			'both hidden'   => [
				[
					'showAuthor' => false,
					'showAvatar' => false,
				],
				false,
				false,
			],
		];
	}
}
