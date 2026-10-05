/**
 * WordPress dependencies
 */
import { store as blockEditorStore } from '@wordpress/block-editor';
import { createBlock } from '@wordpress/blocks';
import { createHigherOrderComponent } from '@wordpress/compose';
import { store as coreStore } from '@wordpress/core-data';
import { useDispatch, useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { useEffect, useMemo, useRef } from '@wordpress/element';
import { addFilter } from '@wordpress/hooks';

/**
 * Internal dependencies
 */
import { BLOCK_NAME } from './layout';
import { builtInLayoutSlugFor, switchLayoutAttributes } from './layouts';
import { getLayoutCategoryId, isLayoutContent } from './utils';

const PATTERN_BLOCK_NAME = 'core/block';

type PatternRecord = {
	id: number;
	wp_pattern_category?: number[];
	content?: { raw?: string } | string;
};

/**
 * Turns a layout pattern reference into a Rolling Coverage block that uses
 * the layout, as picking the layout in the block does: core locks the blocks
 * inside a synced pattern reference, so the coverage couldn't be picked
 * there. Covers a layout inserted from the inserter, pasted, or already in a
 * story when it opens. Leaves the reference alone while editing a pattern,
 * and wherever the block can't replace it.
 *
 * @param {Object} props           Component props.
 * @param {string} props.clientId  The pattern reference's client ID.
 * @param {number} props.patternId The referenced pattern's ID.
 */
function LayoutPatternReplacer( {
	clientId,
	patternId,
}: {
	clientId: string;
	patternId: number;
} ) {
	const builtInSlug = builtInLayoutSlugFor( patternId );
	const replaced = useRef( false );

	const { canReplace, record } = useSelect(
		( select ) => {
			const editor = select( editorStore ) as unknown as {
				getCurrentPostType?: () => string | null;
			};
			const blockEditor = select( blockEditorStore ) as unknown as {
				getBlockRootClientId: ( id: string ) => string | null;
				getBlockParentsByBlockName: (
					id: string,
					name: string | string[]
				) => string[];
				getBlockEditingMode: ( id: string ) => string;
				canRemoveBlock: ( id: string ) => boolean;
				canInsertBlockType: (
					name: string,
					rootClientId: string | null
				) => boolean;
			};

			if ( ! builtInSlug && ! getLayoutCategoryId() ) {
				return { canReplace: false, record: null };
			}

			const replaceable =
				patternId > 0 &&
				editor.getCurrentPostType?.() !== 'wp_block' &&
				blockEditor.getBlockEditingMode( clientId ) !== 'disabled' &&
				blockEditor.getBlockParentsByBlockName( clientId, [
					PATTERN_BLOCK_NAME,
					BLOCK_NAME,
				] ).length === 0 &&
				blockEditor.canRemoveBlock( clientId ) &&
				blockEditor.canInsertBlockType(
					BLOCK_NAME,
					blockEditor.getBlockRootClientId( clientId )
				);

			if ( ! replaceable || builtInSlug ) {
				return { canReplace: replaceable, record: null };
			}

			const core = select( coreStore ) as unknown as {
				getEntityRecord: (
					kind: string,
					name: string,
					id: number
				) => PatternRecord | null | undefined;
			};

			return {
				canReplace: true,
				record:
					core.getEntityRecord( 'postType', 'wp_block', patternId ) ??
					null,
			};
		},
		[ clientId, patternId, builtInSlug ]
	);

	const isLayout = useMemo( () => {
		if ( builtInSlug ) {
			return true;
		}

		return (
			!! record &&
			( record.wp_pattern_category ?? [] ).includes(
				getLayoutCategoryId()
			) &&
			isLayoutContent( record.content )
		);
	}, [ builtInSlug, record ] );

	const { replaceBlock, __unstableMarkNextChangeAsNotPersistent } =
		useDispatch( blockEditorStore.name ) as unknown as {
			replaceBlock: ( id: string, block: unknown ) => void;
			__unstableMarkNextChangeAsNotPersistent: ( options?: {
				history?: string;
			} ) => void;
		};

	useEffect( () => {
		if ( ! canReplace || ! isLayout || replaced.current ) {
			return;
		}

		replaced.current = true;
		// Kept out of undo history: undo then never lands on the locked
		// reference, whether the layout was just inserted or the story opened
		// with it.
		__unstableMarkNextChangeAsNotPersistent( { history: 'ignore' } );
		replaceBlock(
			clientId,
			createBlock( BLOCK_NAME, {
				layoutId: patternId,
				...( builtInSlug
					? switchLayoutAttributes( builtInSlug, [], undefined )
					: {} ),
			} )
		);
	}, [
		canReplace,
		isLayout,
		clientId,
		patternId,
		builtInSlug,
		replaceBlock,
		__unstableMarkNextChangeAsNotPersistent,
	] );

	return null;
}

const withLayoutPatternInsertion = createHigherOrderComponent(
	( BlockEdit ) =>
		( props: {
			name: string;
			clientId: string;
			attributes: Record< string, unknown >;
		} ) => (
			<>
				<BlockEdit { ...props } />
				{ props.name === PATTERN_BLOCK_NAME && (
					<LayoutPatternReplacer
						clientId={ props.clientId }
						patternId={ Number( props.attributes?.ref ) || 0 }
					/>
				) }
			</>
		),
	'withLayoutPatternInsertion'
);

addFilter(
	'editor.BlockEdit',
	'newspack-rolling-coverage/pattern-insertion',
	withLayoutPatternInsertion
);
