<?php
/**
 * Stand-in for the Password Protected plugin.
 *
 * @package Newspack_Rolling_Coverage
 */

if ( ! class_exists( 'Password_Protected' ) ) {
	/**
	 * The plugin's REST gate as of version 2.7.4: with protection on, a REST
	 * request from a visitor who hasn't entered the site password is refused
	 * unless the "Allow REST API" setting is on. The ways past it that need a
	 * login, the site password or a user account, are left out.
	 */
	class Password_Protected {

		/**
		 * Hook the REST gate in, as the plugin's constructor does.
		 */
		public function __construct() {
			add_filter( 'rest_authentication_errors', [ $this, 'only_allow_logged_in_rest_access' ] );
		}

		/**
		 * Whether password protection applies to the current request.
		 *
		 * @return bool
		 */
		public function is_active() {
			return (bool) apply_filters( 'password_protected_is_active', (bool) get_option( 'password_protected_status' ) );
		}

		/**
		 * Refuse REST requests while protection applies.
		 *
		 * @param WP_Error|null|true $access Result of earlier authentication checks.
		 * @return WP_Error|null|true
		 */
		public function only_allow_logged_in_rest_access( $access ) {
			if ( $this->is_active() && ! get_option( 'password_protected_rest' ) ) {
				return new WP_Error( 'rest_cannot_access', 'Only authenticated users can access the REST API.', [ 'status' => rest_authorization_required_code() ] );
			}

			return $access;
		}
	}
}
