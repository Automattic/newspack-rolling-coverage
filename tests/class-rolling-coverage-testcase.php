<?php
/**
 * Shared base test case for the Newspack Rolling Coverage test suite.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Breakout;
use Newspack_Rolling_Coverage\Post_Type;
use Newspack_Rolling_Coverage\Taxonomy;

/**
 * Provides fixtures for coverages and entries and a REST dispatch helper.
 *
 * The plugin keeps its state in posts, terms and options, all of which the
 * core test case rolls back, so no plugin-specific cleanup is needed here.
 */
abstract class Rolling_Coverage_TestCase extends WP_UnitTestCase {

	/**
	 * The `error_log` ini value to restore, or null when it was not changed.
	 *
	 * @var string|null
	 */
	private $previous_error_log = null;

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
	}

	/**
	 * Restore error logging if the test silenced it.
	 */
	public function tear_down() {
		if ( null !== $this->previous_error_log ) {
			ini_set( 'error_log', $this->previous_error_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Restoring the value changed by silence_error_log().
			$this->previous_error_log = null;
		}

		parent::tear_down();
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
