/**
 * WordPress dependencies
 */
import { registerBlockBindingsSource } from '@wordpress/blocks';
import { humanTimeDiff } from '@wordpress/date';
import { __, sprintf } from '@wordpress/i18n';

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
			query?: Record< string, string >
		) => { name?: string; modified_gmt?: string } | undefined;
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
 * How long ago an entry was last edited, as the summary card's "Updated" line
 * reads on the site (Entry_Bindings::updated_line()) and as the Coverage
 * Status block previews its own.
 *
 * @param {string} modifiedGmt The entry's last edit, in UTC.
 * @return {string} The line, or an empty string without a date.
 */
function updatedText( modifiedGmt: string | undefined ): string {
	if ( ! modifiedGmt ) {
		return '';
	}

	return sprintf(
		/* translators: %s: How long ago the liveblog's summary was last edited, e.g. "2 minutes ago". */
		__( 'Updated %s', 'newspack-rolling-coverage' ),
		humanTimeDiff( new Date( `${ modifiedGmt }Z` ) )
	);
}

/**
 * Mirrors Entry_Bindings::get_value() in the editor. Entry values are
 * resolved per entry on the server, so the editor leaves them empty; only the
 * coverage's name is read, from the coverage in context, the Full story
 * label shows the site's own, and the summary card's "Updated" line reads the
 * entry in context.
 */
registerBlockBindingsSource( {
	name: ENTRY_BINDINGS_SOURCE,
	usesContext: [ COVERAGE_ID_CONTEXT, 'postId', 'postType' ],
	getValues( { bindings, context, select }: BindingsArgs ) {
		const coverageId = Number( context?.[ COVERAGE_ID_CONTEXT ] ) || 0;

		return Object.fromEntries(
			Object.entries( bindings ).map( ( [ attribute, binding ] ) => {
				if ( binding?.args?.key === 'breakoutLabel' ) {
					return [ attribute, breakoutLabel() ];
				}

				if ( binding?.args?.key === 'summaryUpdated' ) {
					const postId = Number( context?.postId );
					const postType = context?.postType;
					const entry =
						postId && typeof postType === 'string'
							? select( 'core' ).getEntityRecord(
									'postType',
									postType,
									postId
								)
							: undefined;

					return [
						attribute,
						updatedText( entry?.modified_gmt ) ||
							__( 'Updated', 'newspack-rolling-coverage' ),
					];
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
