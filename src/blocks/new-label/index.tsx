/**
 * WordPress dependencies
 */
import { registerBlockType } from '@wordpress/blocks';
import { tag } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { getBlockCategory } from '../shared/category';
import { blockIcon } from '../shared/icon';
import metadata from './block.json';
import Edit from './edit';
import './editor.scss';

registerBlockType( metadata, {
	category: getBlockCategory(),
	icon: blockIcon( tag ),
	edit: Edit,
	save: () => null,
} );
