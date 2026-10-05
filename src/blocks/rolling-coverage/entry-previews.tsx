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
import { isPinnedCard, isRegularEntry } from './template';

/**
 * The previews of the entries an editable layout shows beside its editable
 * one, set by the Rolling Coverage block around its inner blocks.
 */
const EntryPreviewsContext = createContext< {
	previews: ReactNode;
	followsCard: boolean;
} | null >( null );

/**
 * Renders the entry previews right after the entry group (or the pinned
 * card, in a template without one) inside the Feed, where the site renders
 * the coverage's entries, so they take the Feed's grid cells and come before
 * its footer. The previews and the entry's own blocks see no previews, or
 * every entry preview would render them again.
 */
const withEntryPreviews = createHigherOrderComponent(
	( BlockListBlock ) =>
		( props: { name: string; attributes: Record< string, unknown > } ) => {
			const entryPreviews = useContext( EntryPreviewsContext );
			const isAnchor =
				entryPreviews &&
				( entryPreviews.followsCard
					? isPinnedCard( props )
					: isRegularEntry( props ) );

			if ( ! isAnchor ) {
				return <BlockListBlock { ...props } />;
			}

			return (
				<EntryPreviewsContext.Provider value={ null }>
					<BlockListBlock { ...props } />
					{ entryPreviews.previews }
				</EntryPreviewsContext.Provider>
			);
		},
	'withEntryPreviews'
);

addFilter(
	'editor.BlockListBlock',
	'newspack-rolling-coverage/entry-previews',
	withEntryPreviews
);

export default EntryPreviewsContext;
