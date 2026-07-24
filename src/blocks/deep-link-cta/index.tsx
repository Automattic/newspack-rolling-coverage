/**
 * WordPress dependencies
 */
import { registerBlockType } from '@wordpress/blocks';
import { info } from '@wordpress/icons';
import { InnerBlocks } from '@wordpress/block-editor';

/**
 * Internal dependencies
 */
import metadata from './block.json';
import Edit from './edit';

registerBlockType( metadata.name, {
	...metadata,
	icon: info,
	edit: Edit,
	save: () => <InnerBlocks.Content />,
} );
