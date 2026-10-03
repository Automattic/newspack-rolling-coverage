/**
 * WordPress dependencies
 */
import { registerBlockType } from '@wordpress/blocks';
import { InnerBlocks } from '@wordpress/block-editor';
import { buttons } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { getBlockCategory } from '../shared/category';
import { blockIcon } from '../shared/icon';
import metadata from './block.json';
import Edit from './edit';
import type { CoverageFollowAttributes } from './types';

registerBlockType< CoverageFollowAttributes >( metadata, {
	category: getBlockCategory(),
	icon: blockIcon( buttons ),
	edit: Edit,
	save: () => <InnerBlocks.Content />,
} );
