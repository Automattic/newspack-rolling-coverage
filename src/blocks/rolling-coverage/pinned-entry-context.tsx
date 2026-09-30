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
import { isPinnedCard } from './template';
import type { EntryContext } from './types';

/**
 * The pinned entry an editable layout previews its pinned card against, set
 * by the Rolling Coverage block around its inner blocks.
 */
const PinnedEntryContext = createContext< EntryContext | null >( null );

const NO_CONTEXT = {};

/**
 * Previews the pinned card against the coverage's pinned entry, while the
 * blocks around it preview an entry that isn't pinned.
 */
const withPinnedEntryContext = createHigherOrderComponent(
	( BlockEdit ) =>
		( props: { name: string; attributes: Record< string, unknown > } ) => {
			const pinnedEntry = useContext( PinnedEntryContext );

			if ( ! isPinnedCard( props ) ) {
				return <BlockEdit { ...props } />;
			}

			return (
				<BlockContextProvider value={ pinnedEntry ?? NO_CONTEXT }>
					<BlockEdit { ...props } />
				</BlockContextProvider>
			);
		},
	'withPinnedEntryContext'
);

addFilter(
	'editor.BlockEdit',
	'newspack-rolling-coverage/pinned-entry-context',
	withPinnedEntryContext
);

export default PinnedEntryContext;
