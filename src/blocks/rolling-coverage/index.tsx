/**
 * WordPress dependencies
 */
import { registerBlockType } from '@wordpress/blocks';

/**
 * Internal dependencies
 */
import { getBlockCategory } from '../shared/category';
import { blockIcon } from '../shared/icon';
import { liveStreaming } from '../../shared/icons/live-streaming';
import metadata from './block.json';
import Edit from './edit';
import Save from './save';
import './entry-date-preview';
import './entry-gap-preview';
import './feed-insertion';
import './pattern-insertion';
import './share-preview';
import './editor.scss';
import type { RollingCoverageAttributes } from './types';

registerBlockType< RollingCoverageAttributes >( metadata.name, {
	...metadata,
	category: getBlockCategory(),
	icon: blockIcon( liveStreaming ),
	edit: Edit,
	save: Save,
} );
