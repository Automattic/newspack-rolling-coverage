<?php
/**
 * The core terms route for coverages.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

use WP_Error;
use WP_REST_Request;
use WP_REST_Terms_Controller;

defined( 'ABSPATH' ) || exit;

/**
 * Core's terms controller, with creating a coverage limited to users who
 * manage coverages.
 */
class Coverage_REST_Controller extends WP_REST_Terms_Controller {

	/**
	 * Checks if a request has access to create a coverage.
	 *
	 * Core checks only assign_terms for a non-hierarchical taxonomy, which
	 * stays at edit_posts so Contributors can file entries into coverages.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return bool|WP_Error True if the request has access, otherwise false or a WP_Error.
	 */
	public function create_item_permissions_check( $request ) {
		$permitted = parent::create_item_permissions_check( $request );

		if ( true !== $permitted || current_user_can( 'manage_categories' ) ) {
			return $permitted;
		}

		return new WP_Error(
			'rest_cannot_create',
			__( 'Sorry, you are not allowed to create coverages.', 'newspack-rolling-coverage' ),
			[ 'status' => rest_authorization_required_code() ]
		);
	}
}
