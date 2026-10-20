/**
 * WordPress dependencies
 */
import { createHigherOrderComponent } from '@wordpress/compose';
import { createContext, useContext } from '@wordpress/element';
import type { ReactNode } from 'react';
import { addFilter } from '@wordpress/hooks';

/**
 * Internal dependencies
 */
import FullStoryEntryContext, {
	PinnedFullStoryEntryContext,
} from './full-story-entry-context';
import PinnedEntryContext from './pinned-entry-context';

/**
 * Where an editable layout renders its entry previews: after the anchor, the
 * Feed's last per-entry block that Block Visibility shows in every viewport,
 * and whether the anchor and the previews share an entries element, as the
 * site renders a ruled row's entries (see `isRuledRow()`). Kept apart from
 * the previews, so editing the layout doesn't render every block in it
 * again.
 */
export const EntryPreviewsAnchorContext = createContext< {
	clientId: string;
	wrapsEntries: boolean;
	pinnedClientId?: string | null;
} | null >( null );

/**
 * The previews of the entries an editable layout shows beside its editable
 * one, set by the Rolling Coverage block around its inner blocks.
 */
export const EntryPreviewsContext = createContext< ReactNode >( null );

/**
 * The previews of the pinned entries an editable layout's Pinned group shows
 * beside its editable ones, rendered after the group's last block (the
 * anchor's `pinnedClientId`), inside it.
 */
export const PinnedPreviewsContext = createContext< ReactNode >( null );

/**
 * The entry previews, without the anchor, pinned entry or Full Story entry
 * context: a preview renders copies of the template's blocks, client IDs
 * included, so it would otherwise render the previews again inside itself
 * and show its pinned card and Full Story Entry group against the entries
 * the editable layout shows them for.
 */
function EntryPreviews( { pinned = false }: { pinned?: boolean } ) {
	const entries = useContext( EntryPreviewsContext );
	const pinnedEntries = useContext( PinnedPreviewsContext );

	return (
		<EntryPreviewsAnchorContext.Provider value={ null }>
			<PinnedEntryContext.Provider value={ null }>
				<FullStoryEntryContext.Provider value={ null }>
					<PinnedFullStoryEntryContext.Provider value={ null }>
						{ pinned ? pinnedEntries : entries }
					</PinnedFullStoryEntryContext.Provider>
				</FullStoryEntryContext.Provider>
			</PinnedEntryContext.Provider>
		</EntryPreviewsAnchorContext.Provider>
	);
}

/**
 * Renders the entry previews right after the anchor block, where the site
 * renders the coverage's entries, so they take the Feed's grid cells and
 * come before its footer.
 */
const withEntryPreviews = createHigherOrderComponent(
	( BlockListBlock ) => ( props: { clientId: string } ) => {
		const anchor = useContext( EntryPreviewsAnchorContext );

		if (
			anchor?.pinnedClientId &&
			anchor.pinnedClientId === props.clientId
		) {
			return (
				<>
					<BlockListBlock { ...props } />
					<EntryPreviews pinned />
				</>
			);
		}

		if ( anchor?.clientId !== props.clientId ) {
			return <BlockListBlock { ...props } />;
		}

		if ( anchor.wrapsEntries ) {
			return (
				<div className="newspack-rolling-coverage-entries">
					<BlockListBlock { ...props } />
					<EntryPreviews />
				</div>
			);
		}

		return (
			<>
				<BlockListBlock { ...props } />
				<EntryPreviews />
			</>
		);
	},
	'withEntryPreviews'
);

addFilter(
	'editor.BlockListBlock',
	'newspack-rolling-coverage/entry-previews',
	withEntryPreviews
);
