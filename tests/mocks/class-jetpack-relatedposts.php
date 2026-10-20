<?php
/**
 * Stand-in for Jetpack's Related Posts module.
 *
 * @package Newspack_Rolling_Coverage
 */

if ( ! class_exists( 'Jetpack_RelatedPosts' ) ) {
	/**
	 * How the module places its list on a classic theme, as of Jetpack 16.1:
	 * on a single post's page it adds a placeholder to the end of every
	 * `the_content` the request runs, whichever post it is for, and its
	 * script fills the first placeholder on the page.
	 */
	class Jetpack_RelatedPosts {

		/**
		 * The module's instance.
		 *
		 * @var Jetpack_RelatedPosts|null
		 */
		private static $instance = null;

		/**
		 * The module's instance, created on first use.
		 *
		 * @return Jetpack_RelatedPosts
		 */
		public static function init() {
			if ( ! self::$instance ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Start placing the list on a single post's page, as the module does
		 * on `wp`.
		 */
		public function action_frontend_init() {
			if ( is_single() ) {
				add_filter( 'the_content', [ $this, 'filter_add_target_to_dom' ], 40 );
			}
		}

		/**
		 * Add the placeholder the module's script fills.
		 *
		 * @param string $content Content.
		 * @return string
		 */
		public function filter_add_target_to_dom( $content ) {
			return $content . "\n<div id='jp-relatedposts' class='jp-relatedposts' ></div>";
		}
	}
}
