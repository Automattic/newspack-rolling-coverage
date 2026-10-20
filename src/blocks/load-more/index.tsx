/**
 * WordPress dependencies
 */
import { registerBlockType } from '@wordpress/blocks';
import { InnerBlocks } from '@wordpress/block-editor';
import { arrowDown } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { getBlockCategory } from '../shared/category';
import { blockIcon } from '../shared/icon';
import metadata from './block.json';
import Edit from './edit';

registerBlockType( metadata, {
	category: getBlockCategory(),
	icon: blockIcon( arrowDown ),
	edit: Edit,
	save: () => <InnerBlocks.Content />,
} );
