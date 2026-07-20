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
class Test_Bootstrap extends WP_UnitTestCase {

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
}
