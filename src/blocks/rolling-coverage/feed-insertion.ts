/**
 * WordPress dependencies
 */
import { addFilter } from '@wordpress/hooks';

/**
 * Internal dependencies
 */
import {
	ALL_ALLOWED_BLOCKS,
	BLOCK_NAME,
	CHECK_UPDATES_BLOCK_NAME,
	FOLLOW_BLOCK_NAME,
} from './layout';
import {
	STATUS_BLOCK_NAME,
	feedPathOf,
	isFeedGroup,
	isPinnedCard,
	isRegularEntry,
} from './template';

/**
 * Limits the Feed group to the layout's block types, and keeps the Follow
 * Coverage and Check for Updates blocks, which render once at the coverage
 * level, in the Feed
 * or in a coverage-level group inside it, such as a layout's footer, where
 * Rolling_Coverage_Block::layout_items() reads it. Inside an entry or the
 * pinned card, the site would render it in every entry or leave it out. The
 * groups wrapping the Feed take no coverage status either: the site renders
 * them outside the coverage, so only the Feed and its coverage-level groups
 * can hold it.
 *
 * @param {boolean} canInsert                            Whether the block can be inserted so far.
 * @param {Object}  blockType                            The block type being inserted.
 * @param {string}  blockType.name                       Its name.
 * @param {string}  rootClientId                         The block it would be inserted into.
 * @param {Object}  selectors                            Block editor selectors.
 * @param {Object}  selectors.getBlock                   Gets a block by client ID.
 * @param {Object}  selectors.getBlockParentsByBlockName Gets a block's ancestors of a type.
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
		getBlockParentsByBlockName: (
			clientId: string,
			blockName: string | string[],
			ascending?: boolean
		) => string[];
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

	if (
		! [ FOLLOW_BLOCK_NAME, CHECK_UPDATES_BLOCK_NAME ].includes(
			blockType.name
		) ||
		! rootClientId
	) {
		return true;
	}

	if ( root?.name === BLOCK_NAME ) {
		return false;
	}

	if (
		selectors.getBlockParentsByBlockName( rootClientId, BLOCK_NAME )
			.length === 0
	) {
		return true;
	}

	const chain = [
		root,
		...selectors
			.getBlockParentsByBlockName( rootClientId, 'core/group', true )
			.map( ( clientId ) => selectors.getBlock( clientId ) ),
	];

	for ( const block of chain ) {
		if ( ! block || isRegularEntry( block ) || isPinnedCard( block ) ) {
			return false;
		}

		if ( isFeedGroup( block ) ) {
			return true;
		}
	}

	return false;
}

addFilter(
	'blockEditor.__unstableCanInsertBlockType',
	'newspack-rolling-coverage/feed-insertion',
	canInsertIntoFeed
);
