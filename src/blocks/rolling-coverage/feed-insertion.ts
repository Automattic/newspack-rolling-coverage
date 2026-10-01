/**
 * WordPress dependencies
 */
import { addFilter } from '@wordpress/hooks';

/**
 * Internal dependencies
 */
import { ALL_ALLOWED_BLOCKS, RENDER_ONCE_BLOCKS } from './layout';
import { isFeedGroup } from './template';

/**
 * Limits the Feed group to the layout's block types, and keeps the blocks
 * that render once at the top of the coverage directly in the Feed, where
 * Rolling_Coverage_Block::layout_items() reads them. Anywhere deeper, the
 * site would render them in every entry.
 *
 * @param {boolean} canInsert          Whether the block can be inserted so far.
 * @param {Object}  blockType          The block type being inserted.
 * @param {string}  blockType.name     Its name.
 * @param {string}  rootClientId       The block it would be inserted into.
 * @param {Object}  selectors          Block editor selectors.
 * @param {Object}  selectors.getBlock Gets a block by client ID.
 * @return {boolean} Whether the block can be inserted.
 */
function canInsertIntoFeed(
	canInsert: boolean,
	blockType: { name: string },
	rootClientId: string | null,
	selectors: {
		getBlock: (
			clientId: string
		) => { name: string; attributes?: Record< string, unknown > } | null;
	}
): boolean {
	if ( ! canInsert ) {
		return canInsert;
	}

	const root = rootClientId ? selectors.getBlock( rootClientId ) : null;

	if ( root && isFeedGroup( root ) ) {
		return ALL_ALLOWED_BLOCKS.includes( blockType.name );
	}

	return ! RENDER_ONCE_BLOCKS.includes( blockType.name );
}

addFilter(
	'blockEditor.__unstableCanInsertBlockType',
	'newspack-rolling-coverage/feed-insertion',
	canInsertIntoFeed
);
