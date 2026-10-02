<?php
/**
 * Stand-in for the parts of Newspack Ads the feed placement calls.
 *
 * @package Newspack_Rolling_Coverage
 */

if ( ! function_exists( 'newspack_ads_should_show_ads' ) ) {
	/**
	 * Whether ads may show on the current request.
	 *
	 * @return bool
	 */
	function newspack_ads_should_show_ads() {
		return true;
	}
}

require_once __DIR__ . '/newspack-ads/class-placements.php';
require_once __DIR__ . '/newspack-ads/class-providers.php';
