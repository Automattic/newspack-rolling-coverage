/**
 * WordPress dependencies
 */
import { registerBlockType } from '@wordpress/blocks';
import { share } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import metadata from './block.json';
import Edit from './edit';

registerBlockType( metadata.name, {
	...metadata,
	icon: share,
	edit: Edit,
	save: () => null,
} );
