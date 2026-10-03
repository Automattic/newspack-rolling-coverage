/**
 * WordPress dependencies
 */
import { addFilter } from '@wordpress/hooks';

/**
 * Internal dependencies
 */
import { ALL_ALLOWED_BLOCKS, FOLLOW_BLOCK_NAME } from './layout';
import { STATUS_BLOCK_NAME, feedPathOf, isFeedGroup } from './template';

/**
 * Limits the Feed group to the layout's block types, and keeps the legacy
 * follow button, which renders once at the top of the coverage, directly in
 * the Feed, where Rolling_Coverage_Block::layout_items() reads it. Anywhere
 * deeper, the site would render it in every entry. The groups wrapping the
 * Feed take no coverage status either: the site renders them outside the
 * coverage, so only the Feed can hold it.
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
		getBlock: ( clientId: string ) => {
			name: string;
			attributes?: Record< string, unknown >;
			innerBlocks?: unknown[];
		} | null;
	}
): boolean {
	if ( ! canInsert ) {
		return canInsert;
	}

	const root = rootClientId ? selectors.getBlock( rootClientId ) : null;

	if ( root && isFeedGroup( root ) ) {
		return ALL_ALLOWED_BLOCKS.includes( blockType.name );
	}

	if (
		root &&
		blockType.name === STATUS_BLOCK_NAME &&
		feedPathOf( [ root ] ).length > 1
	) {
		return false;
	}

	return blockType.name !== FOLLOW_BLOCK_NAME;
}

addFilter(
	'blockEditor.__unstableCanInsertBlockType',
	'newspack-rolling-coverage/feed-insertion',
	canInsertIntoFeed
);
