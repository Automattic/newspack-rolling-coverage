/**
 * WordPress dependencies
 */
import { store as blockEditorStore } from '@wordpress/block-editor';
import { createBlock } from '@wordpress/blocks';
import { createHigherOrderComponent } from '@wordpress/compose';
import { store as coreStore } from '@wordpress/core-data';
import { useDispatch, useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { useEffect, useMemo } from '@wordpress/element';
import { addFilter } from '@wordpress/hooks';

/**
 * Internal dependencies
 */
import { patternInnerBlocks } from './components/layout-picker-modal';
import { BLOCK_NAME } from './layout';
import { builtInLayoutSlugFor, switchLayoutAttributes } from './layouts';
import { getLayoutCategoryId } from './utils';

const PATTERN_BLOCK_NAME = 'core/block';

type PatternRecord = {
	id: number;
	wp_pattern_category?: number[];
	content?: { raw?: string } | string;
};

const replacedClientIds = new Set< string >();

/**
 * Turns a layout pattern inserted from the inserter's Patterns tab into a
 * Rolling Coverage block that uses the layout, as picking the layout in the
 * block does. Core locks the blocks inside a synced pattern reference, and
 * the Rolling Coverage block there would write its coverage into the shared
 * layout. Leaves the reference alone while editing a pattern, and wherever
 * the block can't replace it.
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

			if ( ! replaceable || builtInSlug || ! getLayoutCategoryId() ) {
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
			patternInnerBlocks( record ) !== null
		);
	}, [ builtInSlug, record ] );

	const { replaceBlock, __unstableMarkNextChangeAsNotPersistent } =
		useDispatch( blockEditorStore.name ) as unknown as {
			replaceBlock: ( id: string, block: unknown ) => void;
			__unstableMarkNextChangeAsNotPersistent: () => void;
		};

	useEffect( () => {
		if ( ! canReplace || ! isLayout || replacedClientIds.has( clientId ) ) {
			return;
		}

		replacedClientIds.add( clientId );
		// Merges the replacement into the insertion's undo level, so one undo
		// removes the layout instead of restoring the locked reference.
		__unstableMarkNextChangeAsNotPersistent();
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
