/**
 * WordPress dependencies
 */
import { registerBlockBindingsSource } from '@wordpress/blocks';

const ENTRY_BINDINGS_SOURCE = 'newspack-rolling-coverage/entry';
const COVERAGE_ID_CONTEXT = 'newspack-rolling-coverage/coverageId';
const COVERAGE_TAXONOMY = 'rolling_coverage';

interface BindingsArgs {
	bindings: Record< string, { args?: { key?: string } } >;
	context?: Record< string, unknown >;
	select: ( store: string ) => {
		getEntityRecord: (
			kind: string,
			name: string,
			id: number
		) => { name?: string } | undefined;
	};
}

/**
 * Mirrors Entry_Bindings::get_value() in the editor. Entry values are
 * resolved per entry on the server, so the editor leaves them empty; only the
 * coverage's name is read, from the coverage in context.
 */
registerBlockBindingsSource( {
	name: ENTRY_BINDINGS_SOURCE,
	usesContext: [ COVERAGE_ID_CONTEXT ],
	getValues( { bindings, context, select }: BindingsArgs ) {
		const coverageId = Number( context?.[ COVERAGE_ID_CONTEXT ] ) || 0;

		return Object.fromEntries(
			Object.entries( bindings ).map( ( [ attribute, binding ] ) => {
				if ( binding?.args?.key !== 'coverageName' || ! coverageId ) {
					return [ attribute, '' ];
				}

				const coverage = select( 'core' ).getEntityRecord(
					'taxonomy',
					COVERAGE_TAXONOMY,
					coverageId
				);

				return [ attribute, coverage?.name ?? '' ];
			} )
		);
	},
} );

export { ENTRY_BINDINGS_SOURCE, COVERAGE_ID_CONTEXT };
