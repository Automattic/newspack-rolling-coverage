/**
 * WordPress dependencies
 */
import { isUnmodifiedDefaultBlock, type Block } from '@wordpress/blocks';

/**
 * Which pattern the editor last decided for, and whether it has started that
 * pattern's layout.
 */
export type LayoutStart = {
	postId: number;
	started: boolean;
};

/**
 * The pattern being edited, as the decision reads it.
 */
export type LayoutStartPattern = {
	postId: number;
	isLoaded: boolean;
	isLayoutPattern: boolean;
	isEmpty: boolean;
};

/**
 * Whether a pattern's blocks leave it empty: no blocks, or only the empty
 * paragraph the editor adds when the canvas is clicked.
 *
 * @param {Block[]} blocks The pattern's top-level blocks.
 * @return {boolean} Whether the pattern is empty.
 */
export function isEmptyPattern( blocks: Block[] ): boolean {
	return (
		blocks.length === 0 ||
		( blocks.length === 1 && isUnmodifiedDefaultBlock( blocks[ 0 ] ) )
	);
}

/**
 * Whether to insert a Rolling Coverage block into the pattern being edited,
 * and what to remember for the next decision. A layout pattern that is empty
 * gets the block once. A layout pattern that already has content, or whose
 * block was inserted, never gets it again while it stays in the layout
 * category, so removing the block keeps it removed. Leaving the category
 * starts over, as does another pattern. Nothing is decided until the
 * pattern's record has loaded, since its content reads as empty before then.
 *
 * @param {LayoutStartPattern} pattern The pattern being edited.
 * @param {LayoutStart}        last    The previous decision.
 * @return {{insert: boolean, start: LayoutStart}} The decision.
 */
export function nextLayoutStart(
	pattern: LayoutStartPattern,
	last: LayoutStart
): { insert: boolean; start: LayoutStart } {
	if ( ! pattern.isLoaded ) {
		return { insert: false, start: last };
	}

	const started = last.postId === pattern.postId && last.started;

	if ( ! pattern.isLayoutPattern ) {
		return {
			insert: false,
			start: { postId: pattern.postId, started: false },
		};
	}

	return {
		insert: ! started && pattern.isEmpty,
		start: { postId: pattern.postId, started: true },
	};
}
