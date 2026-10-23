/**
 * WordPress dependencies
 */
import { createBlock, serialize, type Block } from '@wordpress/blocks';
import { store as coreStore } from '@wordpress/core-data';
import { useDispatch, useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { useEffect, useRef } from '@wordpress/element';
import { registerPlugin } from '@wordpress/plugins';

/**
 * Internal dependencies
 */
import { BLOCK_NAME } from './layout';
import { isLayoutPatternPost } from './layouts';
import {
	isEmptyPattern,
	nextLayoutStart,
	type LayoutStart,
} from './layout-start';

/**
 * Starts an empty layout pattern with a Rolling Coverage block, which opens
 * as the layout pattern's editable layout, as if the publisher had added it.
 * The block goes in through the post's edits rather than the block editor,
 * so it survives the block editor loading the post's blocks after it.
 */
function LayoutPatternStart() {
	const pattern = useSelect( ( select ) => {
		const editor = select( editorStore ) as unknown as {
			getCurrentPostType: () => string | null;
			getCurrentPostId: () => number | string | null;
			getEditedPostAttribute: ( attribute: string ) => unknown;
			getEditorBlocks: () => Block[];
		};
		const core = select( coreStore ) as unknown as {
			getEntityRecord: (
				kind: string,
				name: string,
				id: number
			) => unknown;
		};
		const postType = editor.getCurrentPostType();
		const postId = Number( editor.getCurrentPostId() ) || 0;

		// Until the pattern loads, its content reads as empty.
		if (
			postType !== 'wp_block' ||
			! postId ||
			! core.getEntityRecord( 'postType', postType, postId )
		) {
			return null;
		}

		return {
			postId,
			isLayoutPattern: isLayoutPatternPost(
				postType,
				postId,
				editor.getEditedPostAttribute( 'wp_pattern_category' ) as
					number[] | undefined
			),
			isEmpty: isEmptyPattern( editor.getEditorBlocks() ),
		};
	}, [] );
	const last = useRef< LayoutStart >( { postId: 0, started: false } );
	const { editPost } = useDispatch( editorStore ) as unknown as {
		editPost: (
			edits: Record< string, unknown >,
			options?: { undoIgnore?: boolean }
		) => void;
	};

	const postId = pattern?.postId ?? 0;
	const isLayoutPattern = pattern?.isLayoutPattern ?? false;
	const isEmpty = pattern?.isEmpty ?? false;

	useEffect( () => {
		if ( ! postId ) {
			return;
		}

		const { insert, start } = nextLayoutStart(
			{ postId, isLayoutPattern, isEmpty },
			last.current
		);
		last.current = start;

		if ( ! insert ) {
			return;
		}

		// Kept out of undo history, as the layout replacing a pattern
		// reference is, so undo never empties the pattern back out.
		editPost(
			{
				blocks: [ createBlock( BLOCK_NAME ) ],
				content: ( { blocks = [] }: { blocks?: Block[] } ) =>
					serialize( blocks ),
			},
			{ undoIgnore: true }
		);
	}, [ postId, isLayoutPattern, isEmpty, editPost ] );

	return null;
}

registerPlugin( 'newspack-rolling-coverage-layout-pattern-start', {
	render: LayoutPatternStart,
} );
