/**
 * WordPress dependencies
 */
import { registerBlockType } from '@wordpress/blocks';
import { archive } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import metadata from './block.json';
import Edit from './edit';
import './style.scss';

registerBlockType( metadata.name, {
	...metadata,
	icon: archive,
	edit: Edit,
	save: () => null,
} );
