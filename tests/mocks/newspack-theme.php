<?php
/**
 * Stand-ins for Newspack Theme functions, which are not loaded in the test
 * environment.
 *
 * @package Newspack_Rolling_Coverage
 */

if ( ! function_exists( 'newspack_fse_blocks_to_remove' ) ) {
	/**
	 * The list of blocks the theme unregisters in the editor, in the shape the
	 * theme localizes it.
	 *
	 * @return array
	 */
	function newspack_fse_blocks_to_remove() {
		return [
			'removeblocks' => 'core/query,core/post-featured-image,core/post-excerpt,core/post-content,core/post-date,core/post-author-name,core/avatar',
		];
	}
}
