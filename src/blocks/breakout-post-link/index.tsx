/**
 * WordPress dependencies
 */
import { registerBlockType } from '@wordpress/blocks';
import { link } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import metadata from './block.json';
import Edit from './edit';

registerBlockType( metadata.name, {
	...metadata,
	icon: link,
	edit: Edit,
	save: () => null,
} );
