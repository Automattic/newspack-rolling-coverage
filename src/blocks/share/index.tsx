/**
 * WordPress dependencies
 */
import { registerBlockType } from '@wordpress/blocks';
import { share } from '@wordpress/icons';

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
	icon: blockIcon( share ),
	edit: Edit,
	save: () => null,
} );
