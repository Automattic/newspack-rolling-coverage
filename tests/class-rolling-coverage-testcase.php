<?php
/**
 * Shared base test case for the Newspack Rolling Coverage test suite.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Breakout;
use Newspack_Rolling_Coverage\Check_Updates_Block;
use Newspack_Rolling_Coverage\Coverage_Follow_Block;
use Newspack_Rolling_Coverage\Coverage_Status_Block;
use Newspack_Rolling_Coverage\Lite_Feed;
use Newspack_Rolling_Coverage\Post_Type;
use Newspack_Rolling_Coverage\Push_Notifications;
use Newspack_Rolling_Coverage\Taxonomy;

/**
 * Provides fixtures for coverages and entries and a REST dispatch helper.
 *
 * The plugin keeps its state in posts, terms and options, all of which the
 * core test case rolls back. Its tear_down() resets the rest, which would
 * otherwise outlast the test: the ad unit a test gave the feed placement,
 * any lite feed it served, the blocks it registered, the entries it put
 * behind the gate and membership stand-ins and the error logging it
 * silenced.
 */
abstract class Rolling_Coverage_TestCase extends WP_UnitTestCase {

	/**
	 * The `error_log` ini value to restore, or null when it was not changed.
	 *
	 * @var string|null
	 */
	private $previous_error_log = null;

	/**
	 * Whether the test registered the Follow Coverage block itself.
	 *
	 * @var bool
	 */
	private $registered_follow_block = false;

	/**
	 * Whether the test registered the Coverage Status block itself.
	 *
	 * @var bool
	 */
	private $registered_status_block = false;

	/**
	 * Whether the test registered the Check for Updates block itself.
	 *
	 * @var bool
	 */
	private $registered_check_updates_block = false;

	/**
	 * Register the plugin's post and term meta again before every test.
	 *
	 * The core test case unregisters every meta key when a test ends, and the
	 * plugin only registers its keys once, on `init`. Without this, every test
	 * after the first would run without the meta defaults (a coverage with no
	 * stored status would stop reading as 'active') and without the `meta`
	 * fields in REST responses.
	 */
	public function set_up() {
		parent::set_up();
		Post_Type::register();
		Post_Type::register_meta();
		Taxonomy::register();
		Breakout::register_meta();
		Push_Notifications::register_meta();
	}

	/**
	 * Restore error logging if the test silenced it, take away any ad unit
	 * the test gave the feed placement, forget any lite feed the test served
	 * and the entries it gated, which would otherwise last for the rest of
	 * the run, as they last for the rest of a request.
	 */
	public function tear_down() {
		if ( class_exists( \Newspack_Ads\Placements::class ) ) {
			\Newspack_Ads\Placements::$placements = [];
		}

		foreach ( [ 'has_feed', 'keeps_feed_markup' ] as $lite_feed_state ) {
			$property = new ReflectionProperty( Lite_Feed::class, $lite_feed_state );
			$property->setAccessible( true );
			$property->setValue( null, false );
		}

		if ( $this->registered_follow_block ) {
			unregister_block_type( Coverage_Follow_Block::BLOCK_NAME );
			$this->registered_follow_block = false;
		}

		if ( $this->registered_status_block ) {
			unregister_block_type( Coverage_Status_Block::BLOCK_NAME );
			$this->registered_status_block = false;
		}

		if ( $this->registered_check_updates_block ) {
			unregister_block_type( Check_Updates_Block::BLOCK_NAME );
			$this->registered_check_updates_block = false;
		}

		if ( defined( '\\Newspack\\Content_Gate::IS_TEST_STUB' ) ) {
			\Newspack\Content_Gate::$withheld = [];
			\Newspack\Content_Gate::$teasers  = [];
		}

		if ( defined( 'NEWSPACK_ROLLING_COVERAGE_WC_MEMBERSHIPS_STUB' ) ) {
			$GLOBALS['newspack_rolling_coverage_restricted_posts'] = [];
			$GLOBALS['newspack_rolling_coverage_public_posts']     = [];
		}

		if ( null !== $this->previous_error_log ) {
			ini_set( 'error_log', $this->previous_error_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Restoring the value changed by silence_error_log().
			$this->previous_error_log = null;
		}

		parent::tear_down();
	}

	/**
	 * Give the feed placement an ad unit, as Newspack Ads would, for the rest
	 * of the test.
	 */
	protected static function enable_ad_placement() {
		require_once __DIR__ . '/mocks/newspack-ads.php';

		\Newspack_Ads\Placements::$placements = [
			'rolling_coverage_entry' => [
				'data' => [
					'enabled' => true,
					'ad_unit' => 'test-unit',
				],
			],
		];
	}

	/**
	 * Discard `error_log()` output for the rest of the test.
	 *
	 * The ingestion pipeline logs every message it skips or rejects. Tests
	 * that drive those paths on purpose call this so the expected lines stay
	 * out of the PHPUnit output.
	 */
	protected function silence_error_log() {
		if ( null === $this->previous_error_log ) {
			$this->previous_error_log = (string) ini_get( 'error_log' );
		}
		ini_set( 'error_log', '/dev/null' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Restored in tear_down().
	}

	/**
	 * Stand in an active, configured OneSignal.
	 */
	protected static function configure_onesignal(): void {
		require_once __DIR__ . '/mocks/onesignal.php';
		update_option(
			'OneSignalWPSetting',
			[
				'app_id'           => 'test-app-id',
				'app_rest_api_key' => 'test-rest-api-key',
			]
		);
	}

	/**
	 * Register the Follow Coverage block from its metadata for the rest of
	 * the test when the build isn't there, so its context reaches the render
	 * callback.
	 */
	protected function register_follow_block() {
		if ( WP_Block_Type_Registry::get_instance()->is_registered( Coverage_Follow_Block::BLOCK_NAME ) ) {
			return;
		}

		$metadata = wp_json_file_decode( NEWSPACK_ROLLING_COVERAGE_PLUGIN_DIR . 'src/blocks/coverage-follow/block.json', [ 'associative' => true ] );

		wp_register_script( 'newspack-rolling-coverage-follow-test-view', false, [], '1.0.0', true );

		register_block_type(
			Coverage_Follow_Block::BLOCK_NAME,
			array_merge(
				[
					'attributes'          => $metadata['attributes'],
					'supports'            => $metadata['supports'],
					'uses_context'        => $metadata['usesContext'],
					'view_script_handles' => [ 'newspack-rolling-coverage-follow-test-view' ],
				],
				Coverage_Follow_Block::block_type_args()
			)
		);
		$this->registered_follow_block = true;
	}

	/**
	 * Register the Coverage Status block from its metadata for the rest of
	 * the test when the build isn't there, so the coverage reaches its render
	 * callback.
	 */
	protected function register_status_block() {
		if ( WP_Block_Type_Registry::get_instance()->is_registered( Coverage_Status_Block::BLOCK_NAME ) ) {
			return;
		}

		$metadata = wp_json_file_decode( NEWSPACK_ROLLING_COVERAGE_PLUGIN_DIR . 'src/blocks/coverage-status/block.json', [ 'associative' => true ] );

		register_block_type(
			Coverage_Status_Block::BLOCK_NAME,
			[
				'attributes'      => $metadata['attributes'],
				'supports'        => $metadata['supports'],
				'uses_context'    => $metadata['usesContext'],
				'render_callback' => [ Coverage_Status_Block::class, 'render_block' ],
			]
		);
		$this->registered_status_block = true;
	}

	/**
	 * Register the Check for Updates block from its metadata for the rest of
	 * the test when the build isn't there, so the coverage reaches its render
	 * callback.
	 */
	protected function register_check_updates_block() {
		if ( WP_Block_Type_Registry::get_instance()->is_registered( Check_Updates_Block::BLOCK_NAME ) ) {
			return;
		}

		$metadata = wp_json_file_decode( NEWSPACK_ROLLING_COVERAGE_PLUGIN_DIR . 'src/blocks/check-updates/block.json', [ 'associative' => true ] );

		register_block_type(
			Check_Updates_Block::BLOCK_NAME,
			array_merge( Check_Updates_Block::block_type_args(), [ 'uses_context' => $metadata['usesContext'] ] )
		);
		$this->registered_check_updates_block = true;
	}

	/**
	 * Create a coverage term.
	 *
	 * @param string $status Coverage status. Left unset when empty, so the
	 *                       registered default ('active') applies.
	 * @param array  $args   Term factory arguments.
	 * @return int Coverage term ID.
	 */
	protected static function create_coverage( $status = '', array $args = [] ) {
		$coverage_id = self::factory()->term->create( array_merge( [ 'taxonomy' => Taxonomy::TAXONOMY_SLUG ], $args ) );

		if ( '' !== $status ) {
			update_term_meta( $coverage_id, Taxonomy::STATUS_META_KEY, $status );
		}

		return $coverage_id;
	}

	/**
	 * Load the stand-in for Newspack's Block_Visibility, which hides any block
	 * carrying a `zzHiddenFromPublic` attribute, and skip the test when the
	 * real one is loaded.
	 */
	protected function use_block_visibility_stub(): void {
		if ( ! class_exists( '\\Newspack\\Block_Visibility' ) ) {
			require_once __DIR__ . '/stubs/class-block-visibility.php';
		}

		if ( ! defined( '\\Newspack\\Block_Visibility::IS_TEST_STUB' ) ) {
			$this->markTestSkipped( 'Newspack is loaded; its visibility rules are tested there.' );
		}
	}

	/**
	 * Load the stand-in for Newspack's Content_Gate, which withholds the
	 * posts listed in its `$withheld`, and skip the test when the real one
	 * is loaded.
	 */
	protected function use_content_gate_stub(): void {
		if ( ! class_exists( '\\Newspack\\Content_Gate' ) ) {
			require_once __DIR__ . '/stubs/class-content-gate.php';
		}

		if ( ! defined( '\\Newspack\\Content_Gate::IS_TEST_STUB' ) ) {
			$this->markTestSkipped( 'Newspack is loaded; its content gate is tested there.' );
		}

		\Newspack\Content_Gate::$withheld = [];
		\Newspack\Content_Gate::$teasers  = [];
	}

	/**
	 * Put an entry behind a content gate, through the stand-in for Newspack's
	 * Content_Gate. Skips the test when the real one is loaded.
	 *
	 * @param int    $entry_id Entry post ID.
	 * @param string $teaser   What the gate shows of the entry; empty for a
	 *                         gate with no free preview.
	 */
	protected function gate_entry( int $entry_id, string $teaser = 'The teaser.' ): void {
		if ( ! defined( '\\Newspack\\Content_Gate::IS_TEST_STUB' ) ) {
			$this->use_content_gate_stub();
		}

		\Newspack\Content_Gate::$withheld[]           = $entry_id;
		\Newspack\Content_Gate::$teasers[ $entry_id ] = $teaser;
	}

	/**
	 * Load the stand-in for WooCommerce Memberships' restriction check,
	 * which restricts the posts listed in
	 * `$GLOBALS['newspack_rolling_coverage_restricted_posts']` unless they
	 * are also in `$GLOBALS['newspack_rolling_coverage_public_posts']`, and
	 * skip the test when the real plugin is loaded.
	 */
	protected function use_wc_memberships_stub(): void {
		if ( ! function_exists( 'wc_memberships_is_post_content_restricted' ) ) {
			require_once __DIR__ . '/stubs/wc-memberships-functions.php';
		}

		if ( ! defined( 'NEWSPACK_ROLLING_COVERAGE_WC_MEMBERSHIPS_STUB' ) ) {
			$this->markTestSkipped( 'WooCommerce Memberships is loaded; its restriction is tested there.' );
		}

		$GLOBALS['newspack_rolling_coverage_restricted_posts'] = [];
		$GLOBALS['newspack_rolling_coverage_public_posts']     = [];
	}

	/**
	 * A block Newspack hides from the public, as the Block_Visibility
	 * stand-in marks it.
	 *
	 * @param string $text The paragraph's text.
	 * @return string Serialized block.
	 */
	protected static function members_only_paragraph( string $text ): string {
		return '<!-- wp:paragraph {"zzHiddenFromPublic":true} --><p>' . $text . '</p><!-- /wp:paragraph -->';
	}

	/**
	 * Create an entry, optionally assigned to a coverage.
	 *
	 * @param int   $coverage_id Coverage term ID, or 0 for an unassigned entry.
	 * @param array $args        Post factory arguments.
	 * @return int Entry post ID.
	 */
	protected static function create_entry( $coverage_id = 0, array $args = [] ) {
		$entry_id = self::factory()->post->create(
			array_merge(
				[
					'post_type'   => Post_Type::CPT_SLUG,
					'post_status' => 'publish',
				],
				$args
			)
		);

		if ( $coverage_id ) {
			wp_set_object_terms( $entry_id, [ (int) $coverage_id ], Taxonomy::TAXONOMY_SLUG );
		}

		return $entry_id;
	}

	/**
	 * Create an entry whose publish and modified dates are both `$date`.
	 *
	 * @param int    $coverage_id Coverage term ID.
	 * @param string $date        GMT date.
	 * @param string $status      Post status.
	 * @param int    $author_id   Optional. Post author user ID.
	 * @return int Entry post ID.
	 */
	protected static function create_dated_entry( int $coverage_id, string $date, string $status = 'publish', int $author_id = 0 ): int {
		$args = [
			'post_status'   => $status,
			'post_content'  => 'Update at ' . $date,
			'post_date'     => $date,
			'post_date_gmt' => $date,
		];

		if ( $author_id > 0 ) {
			$args['post_author'] = $author_id;
		}

		return self::create_entry( $coverage_id, $args );
	}

	/**
	 * Log in as a freshly created user with the given role.
	 *
	 * @param string $role Role slug.
	 * @return int User ID.
	 */
	protected static function log_in_as( $role ) {
		$user_id = self::factory()->user->create( [ 'role' => $role ] );
		wp_set_current_user( $user_id );
		return $user_id;
	}

	/**
	 * Dispatch a request to one of the plugin's REST routes.
	 *
	 * Going through the REST server rather than calling the handler exercises
	 * the route's permission callback and argument validation as well.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Route path relative to the plugin namespace, with a leading slash.
	 * @param array  $params Request parameters.
	 * @return WP_REST_Response The response.
	 */
	protected static function dispatch( $method, $path, array $params = [] ) {
		$request = new WP_REST_Request( $method, '/' . NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE . $path );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $request );
	}
}
