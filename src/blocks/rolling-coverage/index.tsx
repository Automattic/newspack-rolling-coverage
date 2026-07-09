/**
 * WordPress dependencies
 */
import { registerBlockType } from '@wordpress/blocks';
import { megaphone } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import metadata from './block.json';
import Edit from './edit';
import Save from './save';
import './editor.scss';
import type { RollingCoverageAttributes } from './types';

registerBlockType< RollingCoverageAttributes >( metadata.name, {
	...metadata,
	icon: megaphone,
	edit: Edit,
	save: Save,
} );
