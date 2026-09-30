/**
 * WordPress dependencies
 */
import { registerBlockType } from '@wordpress/blocks';
import { archive } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { getBlockCategory } from '../shared/category';
import { blockIcon } from '../shared/icon';
import metadata from './block.json';
import Edit from './edit';
import './style.scss';

registerBlockType( metadata.name, {
	...metadata,
	category: getBlockCategory(),
	icon: blockIcon( archive ),
	edit: Edit,
	save: () => null,
} );
