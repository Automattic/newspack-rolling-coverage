<?php
/**
 * Stand-in for Newspack Ads' placements registry.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Ads;

if ( ! class_exists( Placements::class ) ) {
	/**
	 * Holds the placements a test sets. With none set, the feed placement
	 * has no ad unit and renders nothing, as without Newspack Ads.
	 */
	class Placements {

		/**
		 * Placements by key, in the shape Newspack Ads stores them.
		 *
		 * @var array
		 */
		public static $placements = [];

		/**
		 * Register a placement; the stand-in keeps nothing.
		 *
		 * @param string $key    Placement key.
		 * @param array  $config Placement config.
		 */
		public static function register_placement( $key, $config ) {} // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable

		/**
		 * The placements a test set.
		 *
		 * @return array
		 */
		public static function get_placements() {
			return self::$placements;
		}

		/**
		 * Whether a placement may show its ad unit.
		 *
		 * @param string $key Placement key.
		 * @return bool
		 */
		public static function can_display_ad_unit( $key ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
			return true;
		}
	}
}
