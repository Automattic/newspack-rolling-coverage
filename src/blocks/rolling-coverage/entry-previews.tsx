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
import PinnedEntryContext from './pinned-entry-context';

/**
 * The client ID of the block an editable layout renders its entry previews
 * after: the Feed's last per-entry block that Block Visibility shows in every
 * viewport. Kept apart from the previews, so editing the layout doesn't
 * render every block in it again.
 */
export const EntryPreviewsAnchorContext = createContext< string | null >(
	null
);

/**
 * The previews of the entries an editable layout shows beside its editable
 * one, set by the Rolling Coverage block around its inner blocks.
 */
export const EntryPreviewsContext = createContext< ReactNode >( null );

/**
 * The entry previews, without the anchor or pinned entry context: a preview
 * renders copies of the template's blocks, client IDs included, so it would
 * otherwise render the previews again inside itself and show its pinned
 * card against the lead pinned entry.
 */
function EntryPreviews() {
	const previews = useContext( EntryPreviewsContext );

	return (
		<EntryPreviewsAnchorContext.Provider value={ null }>
			<PinnedEntryContext.Provider value={ null }>
				{ previews }
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
		const anchorId = useContext( EntryPreviewsAnchorContext );

		return (
			<>
				<BlockListBlock { ...props } />
				{ anchorId === props.clientId && <EntryPreviews /> }
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
