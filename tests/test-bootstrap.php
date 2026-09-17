<?php
/**
 * Smoke tests covering the plugin bootstrap.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Initializer;
use Newspack_Rolling_Coverage\Post_Type;
use Newspack_Rolling_Coverage\Taxonomy;

/**
 * Verify the plugin loads and registers its core objects.
 */
class Test_Bootstrap extends Rolling_Coverage_TestCase {

	/**
	 * The plugin's core constants are defined once it loads.
	 */
	public function test_plugin_constants_are_defined() {
		$this->assertTrue( defined( 'NEWSPACK_ROLLING_COVERAGE_VERSION' ), 'Version constant should be defined.' );
		$this->assertTrue( defined( 'NEWSPACK_ROLLING_COVERAGE_PLUGIN_FILE' ), 'Plugin file constant should be defined.' );
		$this->assertTrue( defined( 'NEWSPACK_ROLLING_COVERAGE_PLUGIN_DIR' ), 'Plugin dir constant should be defined.' );
	}

	/**
	 * The feature classes are autoloadable via Composer.
	 */
	public function test_feature_classes_exist() {
		$this->assertTrue( class_exists( Initializer::class ), 'Initializer class should autoload.' );
		$this->assertTrue( class_exists( Post_Type::class ), 'Post_Type class should autoload.' );
		$this->assertTrue( class_exists( Taxonomy::class ), 'Taxonomy class should autoload.' );
	}

	/**
	 * The custom post type is registered on the `init` hook.
	 */
	public function test_post_type_is_registered() {
		$this->assertTrue( post_type_exists( Post_Type::CPT_SLUG ), 'The rolling coverage entry post type should be registered.' );
	}

	/**
	 * The taxonomy is registered on the `init` hook.
	 */
	public function test_taxonomy_is_registered() {
		$this->assertTrue( taxonomy_exists( Taxonomy::TAXONOMY_SLUG ), 'The rolling coverage taxonomy should be registered.' );
	}

	/**
	 * Every feature registers its REST routes. A route missing here means a
	 * feature class dropped out of the initializer.
	 */
	public function test_feature_routes_are_registered() {
		$registered_routes = array_keys( rest_get_server()->get_routes( NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE ) );
		$route_prefix      = '/' . NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE;

		$expected_routes = [
			'/coverages/(?P<term_id>\\d+)/entries',
			'/coverages/(?P<term_id>\\d+)/entries-view',
			'/coverages/(?P<coverage_id>\\d+)/trash',
			'/coverages/(?P<coverage_id>\\d+)/restore',
			'/coverages/(?P<coverage_id>\\d+)',
			'/entries/(?P<entry_id>\\d+)/pin',
			'/entries/(?P<entry_id>\\d+)/restore',
			'/entries/(?P<entry_id>\\d+)/archive',
			'/entries/(?P<entry_id>\\d+)/breakout',
			'/entries/restore',
		];

		foreach ( $expected_routes as $expected_route ) {
			$this->assertContains( $route_prefix . $expected_route, $registered_routes, "The {$expected_route} route should be registered." );
		}
	}

	/**
	 * The Slack webhook routes accept unauthenticated requests, so they stay
	 * unregistered until the integration has credentials to verify them with.
	 */
	public function test_slack_webhook_routes_are_not_exposed_before_slack_is_configured() {
		$registered_routes = array_keys( rest_get_server()->get_routes( NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE ) );

		foreach ( [ 'events', 'commands', 'interactions' ] as $webhook ) {
			$this->assertNotContains( '/' . NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE . '/slack/' . $webhook, $registered_routes, "The {$webhook} webhook should not be registered." );
		}
	}
}
