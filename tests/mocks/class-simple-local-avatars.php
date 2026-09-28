<?php
/**
 * Stand-in for the Simple Local Avatars plugin.
 *
 * @package Newspack_Rolling_Coverage
 */

if ( ! class_exists( 'Simple_Local_Avatars' ) ) {
	/**
	 * Resolves uploaded avatars from a list the test sets, the one question
	 * the bot avatar filter asks the real plugin.
	 */
	class Simple_Local_Avatars {

		/**
		 * Avatar URLs by user ID.
		 *
		 * @var array<int, string>
		 */
		public $uploaded = [];

		/**
		 * Return the uploaded avatar URL for a user, or an empty string.
		 *
		 * @param int|string $id_or_email User ID.
		 * @param int        $size        Avatar size in pixels.
		 * @return string Avatar URL, or '' when none is uploaded.
		 */
		public function get_simple_local_avatar_url( $id_or_email, $size ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
			return $this->uploaded[ (int) $id_or_email ] ?? '';
		}
	}
}
