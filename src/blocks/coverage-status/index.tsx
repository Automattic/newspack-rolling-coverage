/**
 * WordPress dependencies
 */
import { registerBlockType } from '@wordpress/blocks';
import { published } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { getBlockCategory } from '../shared/category';
import { blockIcon } from '../shared/icon';
import metadata from './block.json';
import Edit from './edit';
import type { CoverageStatusAttributes } from './types';

registerBlockType< CoverageStatusAttributes >( metadata, {
	category: getBlockCategory(),
	icon: blockIcon( published ),
	edit: Edit,
	save: () => null,
} );
