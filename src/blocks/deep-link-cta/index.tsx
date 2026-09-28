/**
 * WordPress dependencies
 */
import { registerBlockType } from '@wordpress/blocks';
import { info } from '@wordpress/icons';
import { InnerBlocks } from '@wordpress/block-editor';

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
	icon: blockIcon( info ),
	edit: Edit,
	save: () => <InnerBlocks.Content />,
} );
