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
import { isSummaryCard } from './template';
import type { EntryContext } from './types';

/**
 * The Summary an editable layout previews its summary card against, set by
 * the Rolling Coverage block around its inner blocks.
 */
const SummaryEntryContext = createContext< EntryContext | null >( null );

const NO_CONTEXT = {};

/**
 * Previews the summary card against the coverage's Summary, as the site
 * renders it, rather than the post the Rolling Coverage block sits in.
 */
const withSummaryEntryContext = createHigherOrderComponent(
	( BlockEdit ) =>
		( props: { name: string; attributes: Record< string, unknown > } ) => {
			const summary = useContext( SummaryEntryContext );

			if ( ! isSummaryCard( props ) ) {
				return <BlockEdit { ...props } />;
			}

			return (
				<BlockContextProvider value={ summary ?? NO_CONTEXT }>
					<BlockEdit { ...props } />
				</BlockContextProvider>
			);
		},
	'withSummaryEntryContext'
);

addFilter(
	'editor.BlockEdit',
	'newspack-rolling-coverage/summary-entry-context',
	withSummaryEntryContext
);

export default SummaryEntryContext;
