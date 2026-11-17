/**
 * WordPress dependencies
 */
import { registerBlockBindingsSource } from '@wordpress/blocks';
import { dateI18n, getSettings } from '@wordpress/date';
import { __, _x, sprintf } from '@wordpress/i18n';

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
 * When an entry was last edited, as the summary card's "Updated" line reads,
 * mirroring Entry_Bindings::updated_text(): the time in the site's time
 * format, with the date when it wasn't today.
 *
 * @param {string} modifiedGmt The entry's last edit, in UTC.
 * @return {string} The line, or an empty string without a date.
 */
function updatedText( modifiedGmt: string | undefined ): string {
	if ( ! modifiedGmt ) {
		return '';
	}

	const modified = `${ modifiedGmt }Z`;

	const { formats } = getSettings();
	let time = dateI18n( formats.time, modified, undefined );

	if (
		dateI18n( 'Y-m-d', modified, undefined ) !==
		dateI18n( 'Y-m-d', new Date(), undefined )
	) {
		time = sprintf(
			/* translators: 1: a date in the site's date format, 2: a time in the site's time format. */
			_x( '%1$s, %2$s', 'date and time', 'newspack-rolling-coverage' ),
			dateI18n( formats.date, modified, undefined ),
			time
		);
	}

	return sprintf(
		/* translators: %s: when the liveblog's summary was last edited, such as "3:42 pm". */
		__( 'Updated %s', 'newspack-rolling-coverage' ),
		time
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
