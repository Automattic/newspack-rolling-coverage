<?php
/**
 * Tests for the site-wide status indicator labels.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Rolling_Coverage_Block;
use Newspack_Rolling_Coverage\Status_Labels;
use Newspack_Rolling_Coverage\Taxonomy;

/**
 * A site can set the status indicator's text for each status; a block's own
 * label still wins, and an empty site label falls back to the built-in one.
 */
class Test_Status_Labels extends Rolling_Coverage_TestCase {

	/**
	 * Act as an editor, who can manage the labels.
	 */
	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
	}

	/**
	 * Save labels through the REST route.
	 *
	 * @param array $labels Labels keyed by status.
	 * @return WP_REST_Response
	 */
	private static function save( array $labels ) {
		$request = new WP_REST_Request( 'POST', '/' . NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE . Status_Labels::REST_ROUTE );

		foreach ( $labels as $status => $label ) {
			$request->set_param( $status, $label );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Render a block with the status indicator on.
	 *
	 * @param int   $coverage_id Coverage term ID.
	 * @param array $labels      The block's own labels.
	 * @return string Rendered block.
	 */
	private static function render_indicator( int $coverage_id, array $labels = [] ): string {
		$attributes = [
			'coverageId'            => $coverage_id,
			'statusIndicatorShow'   => true,
			'statusIndicatorLabels' => $labels,
		];
		$feed       = '<!-- wp:group {"className":"newspack-rolling-coverage-feed"} --><div class="wp-block-group newspack-rolling-coverage-feed"></div><!-- /wp:group -->';
		$block      = parse_blocks( '<!-- wp:newspack-rolling-coverage/rolling-coverage ' . wp_json_encode( $attributes ) . ' -->' . $feed . '<!-- /wp:newspack-rolling-coverage/rolling-coverage -->' )[0];

		return Rolling_Coverage_Block::render_block( $attributes, '', new WP_Block( $block ) );
	}

	/**
	 * Saved labels are trimmed, stripped of markup and capped in length, and
	 * the response lists every status.
	 */
	public function test_saving_labels() {
		$response = self::save(
			[
				'active' => '  On <b>air</b> ',
				'paused' => str_repeat( 'a', Status_Labels::MAX_LENGTH + 10 ),
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			[
				'active'   => 'On air',
				'paused'   => str_repeat( 'a', Status_Labels::MAX_LENGTH ),
				'archived' => '',
			],
			$response->get_data()
		);
	}

	/**
	 * Clearing a label goes back to the built-in one, and clearing every label
	 * deletes the option.
	 */
	public function test_clearing_labels_returns_to_the_defaults() {
		self::save(
			[
				'active'   => 'On air',
				'archived' => 'Over',
			]
		);
		self::save( [ 'active' => '' ] );

		$this->assertSame( [ 'archived' => 'Over' ], get_option( Status_Labels::OPTION_KEY ), 'Only the label still set is stored.' );
		$this->assertSame( 'Live', Status_Labels::get_all()['active'] );

		self::save( [ 'archived' => '   ' ] );

		$this->assertFalse( get_option( Status_Labels::OPTION_KEY ), 'The option should be deleted once no label is set.' );
	}

	/**
	 * A status left out of a save keeps its label.
	 */
	public function test_partial_save_keeps_other_labels() {
		self::save( [ 'paused' => 'On hold' ] );
		self::save( [ 'active' => 'On air' ] );

		$this->assertSame(
			[
				'paused' => 'On hold',
				'active' => 'On air',
			],
			get_option( Status_Labels::OPTION_KEY )
		);
	}

	/**
	 * Contributors can't read or change the labels.
	 */
	public function test_contributors_cannot_manage_labels() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'contributor' ] ) );

		$this->assertSame( 403, self::save( [ 'active' => 'On air' ] )->get_status() );

		$request = new WP_REST_Request( 'GET', '/' . NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE . Status_Labels::REST_ROUTE );
		$this->assertSame( 403, rest_get_server()->dispatch( $request )->get_status() );
	}

	/**
	 * A stored value that isn't a list of text is ignored.
	 */
	public function test_malformed_option_falls_back_to_the_defaults() {
		update_option( Status_Labels::OPTION_KEY, 'On air' );
		$this->assertSame( Status_Labels::get_defaults(), Status_Labels::get_all() );

		update_option(
			Status_Labels::OPTION_KEY,
			[
				'active' => [ 'On air' ],
				'other'  => 'x',
			]
		);
		$this->assertSame( Status_Labels::get_defaults(), Status_Labels::get_all() );
	}

	/**
	 * A block without its own label shows the site's, and a block's own label
	 * wins over it.
	 */
	public function test_the_badge_uses_the_site_label_unless_the_block_sets_one() {
		$coverage_id = self::create_coverage( Taxonomy::STATUS_ARCHIVED );
		self::save( [ 'archived' => 'Over' ] );

		$this->assertStringContainsString( '>Over</span>', self::render_indicator( $coverage_id ), "The site's label." );
		$this->assertStringContainsString( '>Finished</span>', self::render_indicator( $coverage_id, [ 'archived' => 'Finished' ] ), "The block's label." );
		$this->assertStringContainsString( '>Over</span>', self::render_indicator( $coverage_id, [ 'archived' => ' ' ] ), "A blank block label falls back to the site's." );
	}
}
