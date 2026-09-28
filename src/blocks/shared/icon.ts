/**
 * WordPress dependencies
 */
import type { BlockConfiguration } from '@wordpress/blocks';

/**
 * External dependencies
 */
import colors from 'newspack-colors';

/**
 * Wraps a block icon in the foreground colour Newspack's blocks share.
 *
 * @param {JSX.Element} src The icon.
 * @return {Object} The block icon.
 */
function blockIcon( src: JSX.Element ): BlockConfiguration[ 'icon' ] {
	return { src, foreground: colors[ 'primary-400' ] };
}

export { blockIcon };
