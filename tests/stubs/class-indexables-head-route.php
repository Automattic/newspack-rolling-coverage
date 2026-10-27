<?php
/**
 * A stand-in for Yoast SEO's head route handler, for tests that run without
 * Yoast: it answers with a head for any URL, as Yoast does for one it has
 * indexed.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Yoast\WP\SEO\Routes;

use WP_REST_Request;
use WP_REST_Response;

/**
 * Stand-in for \Yoast\WP\SEO\Routes\Indexables_Head_Route.
 */
class Indexables_Head_Route {

	/**
	 * The head for the requested URL.
	 *
	 * @param WP_REST_Request $request Request with a `url` parameter.
	 * @return WP_REST_Response
	 */
	public function get_head( WP_REST_Request $request ) {
		return new WP_REST_Response( [ 'head' => '<title>' . esc_html( $request['url'] ) . '</title>' ] );
	}
}
