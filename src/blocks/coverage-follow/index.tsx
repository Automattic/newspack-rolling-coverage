/**
 * WordPress dependencies
 */
import { registerBlockType } from '@wordpress/blocks';
import { bell } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import metadata from './block.json';
import Edit from './edit';

registerBlockType( metadata.name, {
	...metadata,
	icon: bell,
	edit: Edit,
	save: () => null,
} );
