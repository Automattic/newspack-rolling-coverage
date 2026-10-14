<?php
/**
 * Stand-in for Newspack Ads' ad providers.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Ads;

if ( ! class_exists( Providers::class ) ) {
	/**
	 * Prints a marker in place of a provider's ad code, with the placement's
	 * own container id, as a provider prints it.
	 */
	class Providers {

		/**
		 * The default provider's ID.
		 *
		 * @return string
		 */
		public static function get_default_provider() {
			return 'gam';
		}

		/**
		 * Print the ad code for a placement.
		 *
		 * @param string $ad_unit_id     Ad unit ID.
		 * @param string $provider_id    Provider ID.
		 * @param string $placement_key  Placement key.
		 * @param string $hook_key       Placement hook key.
		 * @param array  $placement_data Placement data.
		 */
		public static function render_placement_ad_code( $ad_unit_id, $provider_id, $placement_key, $hook_key, $placement_data ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
			printf( '<div class="test-ad-code" id="%s"></div>', esc_attr( $placement_data['id'] ?? '' ) );
		}
	}
}
