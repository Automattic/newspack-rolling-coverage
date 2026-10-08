/**
 * WordPress dependencies
 */
import { registerBlockType } from '@wordpress/blocks';
import { time } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { getBlockCategory } from '../shared/category';
import { blockIcon } from '../shared/icon';
import metadata from './block.json';
import Edit from './edit';
import type { UpdateTimerAttributes } from './types';

registerBlockType< UpdateTimerAttributes >( metadata, {
	category: getBlockCategory(),
	icon: blockIcon( time ),
	edit: Edit,
	save: () => null,
} );
