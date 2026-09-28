/**
 * WordPress dependencies
 */
import { registerBlockType } from '@wordpress/blocks';
import { bell } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { getBlockCategory } from '../shared/category';
import { blockIcon } from '../shared/icon';
import metadata from './block.json';
import Edit from './edit';

registerBlockType( metadata.name, {
	...metadata,
	category: getBlockCategory(),
	icon: blockIcon( bell ),
	edit: Edit,
	save: () => null,
} );
