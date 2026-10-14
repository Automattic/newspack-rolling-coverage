/**
 * WordPress dependencies
 */
import { registerBlockBindingsSource } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';

const ENTRY_BINDINGS_SOURCE = 'newspack-rolling-coverage/entry';
const COVERAGE_ID_CONTEXT = 'newspack-rolling-coverage/coverageId';
const COVERAGE_TAXONOMY = 'rolling_coverage';
// Edit context needs manage_categories, which Authors and Contributors lack.
const VIEW_CONTEXT = { context: 'view' };

interface BindingsArgs {
	bindings: Record< string, { args?: { key?: string } } >;
	context?: Record< string, unknown >;
	select: ( store: string ) => {
		getEntityRecord: (
			kind: string,
			name: string,
			id: number,
			query: Record< string, string >
		) => { name?: string } | undefined;
	};
}

/**
 * The site's Full story label, which the editor shows for every breakout
 * post, from the Rolling Coverage block's config where this bundle has it.
 *
 * @return {string} The label.
 */
function breakoutLabel(): string {
	return (
		window.newspackRollingCoverageBlock?.breakoutLabel ||
		__( 'Full story', 'newspack-rolling-coverage' )
	);
}

/**
 * Mirrors Entry_Bindings::get_value() in the editor. Entry values are
 * resolved per entry on the server, so the editor leaves them empty; only the
 * coverage's name is read, from the coverage in context, and the Full story
 * label shows the site's own.
 */
registerBlockBindingsSource( {
	name: ENTRY_BINDINGS_SOURCE,
	usesContext: [ COVERAGE_ID_CONTEXT ],
	getValues( { bindings, context, select }: BindingsArgs ) {
		const coverageId = Number( context?.[ COVERAGE_ID_CONTEXT ] ) || 0;

		return Object.fromEntries(
			Object.entries( bindings ).map( ( [ attribute, binding ] ) => {
				if ( binding?.args?.key === 'breakoutLabel' ) {
					return [ attribute, breakoutLabel() ];
				}

				if ( binding?.args?.key !== 'coverageName' || ! coverageId ) {
					return [ attribute, '' ];
				}

				const coverage = select( 'core' ).getEntityRecord(
					'taxonomy',
					COVERAGE_TAXONOMY,
					coverageId,
					VIEW_CONTEXT
				);

				return [ attribute, coverage?.name ?? '' ];
			} )
		);
	},
} );

export { ENTRY_BINDINGS_SOURCE, COVERAGE_ID_CONTEXT };
