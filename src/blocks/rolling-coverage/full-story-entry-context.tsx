/**
 * WordPress dependencies
 */
import { BlockContextProvider } from '@wordpress/block-editor';
import { createHigherOrderComponent } from '@wordpress/compose';
import { createContext, useContext } from '@wordpress/element';
import { addFilter } from '@wordpress/hooks';

/**
 * Internal dependencies
 */
import { breakoutPreviewFor } from './breakout-previews';
import { isBreakoutEntry, isPinnedStack } from './template';
import type { EntryContext } from './types';

/**
 * The entry an editable layout previews its Full Story Entry group against,
 * set by the Rolling Coverage block around its inner blocks.
 */
const FullStoryEntryContext = createContext< EntryContext | null >( null );

/**
 * The pinned entry an editable layout previews the Full Story Entry group in
 * its Pinned group against.
 */
export const PinnedFullStoryEntryContext = createContext< EntryContext | null >(
	null
);

/**
 * Whether a block sits inside the Pinned group.
 */
const InsidePinnedStack = createContext( false );

/**
 * Whether a block sits inside a Full Story Entry group.
 */
const InsideFullStoryEntry = createContext( false );

/**
 * The blocks that show the breakout post rather than the entry inside the
 * group, as the site renders them (see Breakout_Card::render()).
 */
const BREAKOUT_POST_BLOCKS = [
	'core/post-title',
	'core/post-excerpt',
	'core/post-featured-image',
];

const NO_CONTEXT = {};

/**
 * Previews the Full Story Entry group against the entry the layout shows it
 * for, the one in the Pinned group against a pinned entry, and its Post Title, Post Excerpt and Post Featured Image against the
 * record standing in for that entry's breakout post (see
 * breakout-previews.ts). A post without a title leaves the entry's own, as
 * on the site. `queryId` keeps the stand-in read-only, as in a Query Loop.
 */
const withFullStoryEntryContext = createHigherOrderComponent(
	( BlockEdit ) =>
		( props: {
			name: string;
			attributes: Record< string, unknown >;
			context?: Record< string, unknown >;
		} ) => {
			const fullStoryEntry = useContext( FullStoryEntryContext );
			const pinnedFullStoryEntry = useContext(
				PinnedFullStoryEntryContext
			);
			const isInside = useContext( InsideFullStoryEntry );
			const isInsideStack = useContext( InsidePinnedStack );

			if ( isPinnedStack( props ) ) {
				return (
					<InsidePinnedStack.Provider value>
						<BlockEdit { ...props } />
					</InsidePinnedStack.Provider>
				);
			}

			if ( isBreakoutEntry( props ) ) {
				return (
					<InsideFullStoryEntry.Provider value>
						<BlockContextProvider
							value={
								( isInsideStack
									? pinnedFullStoryEntry
									: fullStoryEntry ) ?? NO_CONTEXT
							}
						>
							<BlockEdit { ...props } />
						</BlockContextProvider>
					</InsideFullStoryEntry.Provider>
				);
			}

			const preview =
				isInside && BREAKOUT_POST_BLOCKS.includes( props.name )
					? breakoutPreviewFor( props.context?.postId )
					: undefined;

			if (
				! preview ||
				( props.name === 'core/post-title' && ! preview.hasTitle )
			) {
				return <BlockEdit { ...props } />;
			}

			return (
				<BlockEdit
					{ ...props }
					context={ {
						...props.context,
						postId: preview.id,
						postType: 'post',
						queryId: 0,
					} }
				/>
			);
		},
	'withFullStoryEntryContext'
);

addFilter(
	'editor.BlockEdit',
	'newspack-rolling-coverage/full-story-entry-context',
	withFullStoryEntryContext
);

export default FullStoryEntryContext;
