<?php
/**
 * Tests for the site-wide name readers see for entries.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Entry_Name;
use Newspack_Rolling_Coverage\Rolling_Coverage_Block;
use Newspack_Rolling_Coverage\Share_Block;

/**
 * A site can call entries something else, e.g. "updates", in every label
 * readers see; without a name the labels keep their own wording.
 */
class Test_Entry_Name extends Rolling_Coverage_TestCase {

	/**
	 * Whether this test registered the Rolling Coverage block.
	 *
	 * @var bool
	 */
	private $registered_feed = false;

	/**
	 * Act as an editor, who can manage the name.
	 */
	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
	}

	/**
	 * Forget the Rolling Coverage block if the test registered it.
	 */
	public function tear_down() {
		if ( $this->registered_feed ) {
			unregister_block_type( Rolling_Coverage_Block::BLOCK_NAME );
			$this->registered_feed = false;
		}

		parent::tear_down();
	}

	/**
	 * Save the name through the REST route.
	 *
	 * @param array $params Request parameters.
	 * @return WP_REST_Response
	 */
	private static function save( array $params ) {
		$request = new WP_REST_Request( 'POST', '/' . NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE . Entry_Name::REST_ROUTE );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Read the name through the REST route.
	 *
	 * @return WP_REST_Response
	 */
	private static function read() {
		return rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/' . NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE . Entry_Name::REST_ROUTE ) );
	}

	/**
	 * Store a name directly.
	 *
	 * @param string $singular Singular.
	 * @param string $plural   Plural.
	 */
	private static function set_name( string $singular, string $plural ): void {
		update_option(
			Entry_Name::OPTION_KEY,
			[
				'singular' => $singular,
				'plural'   => $plural,
			]
		);
	}

	/**
	 * A feed of one coverage with a plain entry template. Registers the block
	 * when the build isn't there.
	 *
	 * @param int $coverage_id Coverage term ID.
	 * @return string Rendered HTML.
	 */
	private function render_feed( int $coverage_id ): string {
		if ( ! WP_Block_Type_Registry::get_instance()->is_registered( Rolling_Coverage_Block::BLOCK_NAME ) ) {
			register_block_type( Rolling_Coverage_Block::BLOCK_NAME, Rolling_Coverage_Block::block_type_args() );
			$this->registered_feed = true;
		}

		return do_blocks(
			'<!-- wp:newspack-rolling-coverage/rolling-coverage ' . wp_json_encode( [ 'coverageId' => $coverage_id ] ) . ' -->'
			. '<!-- wp:group {"className":"newspack-rolling-coverage-feed"} --><div class="wp-block-group newspack-rolling-coverage-feed">'
			. '<!-- wp:group {"className":"newspack-rolling-coverage-regular-entry"} --><div class="wp-block-group newspack-rolling-coverage-regular-entry"><!-- wp:post-title /--></div><!-- /wp:group -->'
			. '</div><!-- /wp:group -->'
			. '<!-- /wp:newspack-rolling-coverage/rolling-coverage -->'
		);
	}

	/**
	 * Call one of a class's private static methods.
	 *
	 * @param string $class_name Class name.
	 * @param string $method     Method name.
	 * @return mixed
	 */
	private static function call_private( string $class_name, string $method ) {
		$reflection = new ReflectionMethod( $class_name, $method );
		$reflection->setAccessible( true );

		return $reflection->invoke( null );
	}

	/**
	 * Without a saved name the route reports none and the labels use their
	 * own wording.
	 */
	public function test_no_name_keeps_the_built_in_wording() {
		$this->assertSame(
			[
				'singular' => '',
				'plural'   => '',
			],
			self::read()->get_data()
		);
		$this->assertSame( '', Entry_Name::word( 1 ) );
		$this->assertSame( '', Entry_Name::for_script() );
		$this->assertSame( '3 Newer Entries', Rolling_Coverage_Block::newer_entries_label( 3 ) );
		$this->assertSame( 'Share this entry', self::call_private( Share_Block::class, 'aria_label' ) );
	}

	/**
	 * Saved words are trimmed, stripped of markup, capped in characters and
	 * read back.
	 */
	public function test_saving_the_name() {
		$response = self::save(
			[
				'singular' => '  live <b>update</b> ',
				'plural'   => str_repeat( 'é', Entry_Name::MAX_LENGTH + 5 ),
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			[
				'singular' => 'live update',
				'plural'   => str_repeat( 'é', Entry_Name::MAX_LENGTH ),
			],
			$response->get_data()
		);
		$this->assertSame( $response->get_data(), self::read()->get_data() );
	}

	/**
	 * One word without the other is refused, leaving the saved name alone.
	 *
	 * @dataProvider incomplete_names
	 *
	 * @param array $params Request parameters.
	 */
	public function test_one_word_without_the_other_is_refused( array $params ) {
		self::set_name( 'update', 'updates' );

		$response = self::save( $params );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rolling_coverage_entry_name_incomplete', $response->get_data()['code'] );
		$this->assertSame( 'update', Entry_Name::word( 1 ) );
	}

	/**
	 * Requests that set only one word.
	 *
	 * @return array[]
	 */
	public static function incomplete_names(): array {
		return [
			'singular only'       => [ [ 'singular' => 'post' ] ],
			'plural only'         => [ [ 'plural' => 'posts' ] ],
			'empty plural'        => [
				[
					'singular' => 'post',
					'plural'   => '',
				],
			],
			'empty singular only' => [ [ 'singular' => '' ] ],
			'plural only spaces'  => [
				[
					'singular' => 'post',
					'plural'   => '   ',
				],
			],
		];
	}

	/**
	 * Clearing both words deletes the option.
	 */
	public function test_clearing_both_words_returns_to_the_built_in_wording() {
		self::set_name( 'update', 'updates' );

		$response = self::save(
			[
				'singular' => '',
				'plural'   => ' ',
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( get_option( Entry_Name::OPTION_KEY ) );
		$this->assertSame( '', Entry_Name::word( 2 ) );
	}

	/**
	 * A save without either word keeps the saved name.
	 */
	public function test_save_without_words_keeps_the_name() {
		self::set_name( 'update', 'updates' );

		$this->assertSame( 'updates', self::save( [] )->get_data()['plural'] );
	}

	/**
	 * A stored name missing a word counts as no name.
	 */
	public function test_half_stored_name_counts_as_none() {
		update_option( Entry_Name::OPTION_KEY, [ 'singular' => 'update' ] );

		$this->assertSame( '', Entry_Name::word( 1 ) );
	}

	/**
	 * Contributors can't read or change the name.
	 */
	public function test_contributors_cannot_manage_the_name() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'contributor' ] ) );

		$this->assertSame(
			403,
			self::save(
				[
					'singular' => 'update',
					'plural'   => 'updates',
				]
			)->get_status()
		);
		$this->assertSame( 403, self::read()->get_status() );
	}

	/**
	 * The words read as typed mid-sentence; English button labels capitalize
	 * each word.
	 */
	public function test_button_labels_use_title_case_in_english() {
		self::set_name( 'live update', 'live updates' );

		$this->assertSame( 'live update', Entry_Name::word( 1 ) );
		$this->assertSame( 'live updates', Entry_Name::word( 0 ) );
		$this->assertSame( 'Live Updates', Entry_Name::title_word( 3 ) );
		$this->assertSame( '1 Newer Live Update', Rolling_Coverage_Block::newer_entries_label( 1 ) );
		$this->assertSame( '3 Newer Live Updates', Rolling_Coverage_Block::newer_entries_label( 3 ) );
		$this->assertSame( '50+ Newer Live Updates', Rolling_Coverage_Block::newer_entries_label( 60 ) );
		$this->assertSame(
			[
				'singular'      => 'live update',
				'plural'        => 'live updates',
				'singularTitle' => 'Live Update',
				'pluralTitle'   => 'Live Updates',
			],
			json_decode( Entry_Name::for_script(), true )
		);
	}

	/**
	 * Title case leaves a word with capitals of its own as typed.
	 */
	public function test_words_with_their_own_capitals_stay_as_typed() {
		$this->assertSame( 'iPhone Alert', Entry_Name::title_case( 'iPhone alert' ) );
	}

	/**
	 * The editor starts a layout's link to the coverage page with the site's
	 * plural, and with its own wording when the site sets no complete name.
	 */
	public function test_plural_for_the_all_updates_link_is_empty_without_a_complete_name() {
		$this->assertSame( '', Entry_Name::word( 2 ) );

		self::set_name( 'dispatch', 'dispatches' );
		$this->assertSame( 'dispatches', Entry_Name::word( 2 ) );

		self::set_name( 'update', '' );
		$this->assertSame( '', Entry_Name::word( 2 ) );
	}

	/**
	 * Other languages keep the words as typed in button labels.
	 */
	public function test_other_languages_keep_the_words_as_typed() {
		self::set_name( 'mise à jour', 'mises à jour' );
		$locale = static fn() => 'fr_FR';
		add_filter( 'determine_locale', $locale );

		$this->assertSame( 'mises à jour', Entry_Name::title_word( 2 ) );

		remove_filter( 'determine_locale', $locale );
	}

	/**
	 * The feed carries the name for the view script, and an empty feed says
	 * it has none of them yet.
	 */
	public function test_feed_uses_the_name() {
		$coverage_id = self::create_coverage();

		$html = $this->render_feed( $coverage_id );

		$this->assertStringNotContainsString( 'data-entry-name=', $html );
		$this->assertStringContainsString( 'No entries yet.', $html );

		self::set_name( 'update', 'updates' );
		$html = $this->render_feed( $coverage_id );

		$this->assertStringContainsString( 'No updates yet.', $html );
		$this->assertSame( 1, preg_match( '/data-entry-name="([^"]*)"/', $html, $match ) );
		$this->assertSame( 'updates', json_decode( html_entity_decode( $match[1] ), true )['plural'] );
	}

	/**
	 * The archived entry notice allows markup, so a name stored with markup
	 * is shown as text, not rendered.
	 */
	public function test_archived_notice_escapes_the_name() {
		self::set_name( '<a href="https://example.test">x</a>', 'updates' );

		$notice = self::call_private( Rolling_Coverage_Block::class, 'archived_entry_notice_text' );

		$this->assertStringNotContainsString( '<a ', wp_kses_post( $notice ) );
		$this->assertStringContainsString( '&lt;a href=', $notice );
	}

	/**
	 * The share button and an archived entry's notice name the entry as the
	 * site does.
	 */
	public function test_share_label_and_archived_notice_use_the_name() {
		self::set_name( 'update', 'updates' );

		$this->assertSame( 'Share this update', self::call_private( Share_Block::class, 'aria_label' ) );
		$this->assertSame(
			'This update is now out of date compared to newer updates, but is preserved as it originally appeared.',
			self::call_private( Rolling_Coverage_Block::class, 'archived_entry_notice_text' )
		);
	}
}
