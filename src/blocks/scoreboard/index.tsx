/**
 * WordPress dependencies
 */
import { registerBlockType } from '@wordpress/blocks';
import { InnerBlocks } from '@wordpress/block-editor';
import { chartBar } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { getBlockCategory } from '../shared/category';
import { blockIcon } from '../shared/icon';
import metadata from './block.json';
import Edit from './edit';
import './bindings';
import type { ScoreboardAttributes } from './types';

registerBlockType< ScoreboardAttributes >( metadata, {
	category: getBlockCategory(),
	icon: blockIcon( chartBar ),
	edit: Edit,
	save: () => <InnerBlocks.Content />,
} );
