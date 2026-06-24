/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import type { Field, ViewState, Entry, AdminConfig } from '../types';
import { truncate, safeFormatUTCDate, getEmbeddedTerms } from '../utils/fields';
import { ChipLink } from '../shared/chip-link';
import { TermChips } from '../shared/term-chips';

const POST_STATUS_LABELS: Record< string, string > = {
	publish: __( 'Published', 'newspack-rolling-coverage' ),
	draft: __( 'Draft', 'newspack-rolling-coverage' ),
	pending: __( 'Pending', 'newspack-rolling-coverage' ),
	future: __( 'Scheduled', 'newspack-rolling-coverage' ),
	private: __( 'Private', 'newspack-rolling-coverage' ),
};

const STATUS_ELEMENTS = Object.entries( POST_STATUS_LABELS ).map(
	( [ value, label ] ) => ( { value, label } )
);

/**
 * Returns the display label for a post status.
 *
 * @param {string} status Post status slug.
 * @return {string} Translated label, or the raw status if unrecognised.
 */
function getStatusLabel( status: string ): string {
	return POST_STATUS_LABELS[ status ] || status;
}

/**
 * Field definitions for the entry DataViews table.
 * Configures columns: ID, title, created date, modified date, author, status,
 * categories, tags, and breakout.
 *
 * @param {AdminConfig} config Admin config containing edit URLs.
 * @return {Field<Entry>[]} Field definitions for the entry DataViews table.
 */
function getEntryFields( config: AdminConfig ): Field< Entry >[] {
	return [
		{
			id: 'id',
			type: 'text',
			label: __( 'Post ID', 'newspack-rolling-coverage' ),
			enableSorting: true,
			getValue: ( { item } ) => String( item.id ),
		},
		{
			id: 'title',
			type: 'text',
			label: __( 'Title', 'newspack-rolling-coverage' ),
			enableHiding: false,
			enableSorting: true,
			enableGlobalSearch: true,
			getValue: ( { item } ) =>
				truncate(
					item.title?.rendered ||
						__( '(no title)', 'newspack-rolling-coverage' ),
					20
				),
		},
		{
			id: 'date',
			type: 'datetime',
			label: __( 'Created', 'newspack-rolling-coverage' ),
			enableSorting: true,
		},
		{
			id: 'modified',
			type: 'datetime',
			label: __( 'Modified', 'newspack-rolling-coverage' ),
			enableSorting: true,
			getValue: ( { item } ) => safeFormatUTCDate( item.modified ),
		},
		{
			id: 'author',
			type: 'text',
			label: __( 'Author', 'newspack-rolling-coverage' ),
			enableSorting: true,
			enableGlobalSearch: true,
			getValue: ( { item } ) => {
				const author = item._embedded?.author?.[ 0 ];
				return author?.name || '—';
			},
			render: ( { item } ) => {
				const author = item._embedded?.author?.[ 0 ];
				if ( ! author ) {
					return <span>—</span>;
				}
				return <ChipLink href={ author.link } label={ author.name } />;
			},
		},
		{
			id: 'status',
			type: 'text',
			label: __( 'Status', 'newspack-rolling-coverage' ),
			getValue: ( { item } ) => item.status,
			elements: STATUS_ELEMENTS,
			filterBy: {
				operators: [ 'is', 'isNot' ],
			},
		},
		{
			id: 'categories',
			type: 'text',
			label: __( 'Categories', 'newspack-rolling-coverage' ),
			enableSorting: false,
			render: ( { item } ) => {
				const allTerms = getEmbeddedTerms( item );
				return (
					<TermChips
						terms={ allTerms.filter(
							( t ) => t.taxonomy === 'category'
						) }
					/>
				);
			},
		},
		{
			id: 'tags',
			type: 'text',
			label: __( 'Tags', 'newspack-rolling-coverage' ),
			enableSorting: false,
			render: ( { item } ) => {
				const allTerms = getEmbeddedTerms( item );
				return (
					<TermChips
						terms={ allTerms.filter(
							( t ) => t.taxonomy === 'post_tag'
						) }
					/>
				);
			},
		},
		{
			id: 'breakout',
			type: 'text',
			label: __( 'Breakout', 'newspack-rolling-coverage' ),
			enableSorting: false,
			getValue: ( { item } ) =>
				item.rolling_coverage_breakout_status
					? getStatusLabel( item.rolling_coverage_breakout_status )
					: '—',
			render: ( { item } ) => {
				const breakoutPostId =
					item.meta?.rolling_coverage_breakout_post_id;
				if (
					! item.rolling_coverage_breakout_status ||
					! breakoutPostId
				) {
					return <span>—</span>;
				}
				const label = getStatusLabel(
					item.rolling_coverage_breakout_status
				);
				return (
					<ChipLink
						href={ `${ config.adminUrls.editEntry }&post=${ breakoutPostId }` }
						label={ label }
						variant={ item.rolling_coverage_breakout_status }
					/>
				);
			},
		},
	];
}

/**
 * Default view state for the entry list: table layout, sorted by date descending,
 * showing author, status, categories, tags, modified, and breakout columns.
 */
const defaultEntryView: ViewState = {
	type: 'table',
	perPage: 20,
	page: 1,
	sort: { field: 'date', direction: 'desc' },
	search: '',
	filters: [],
	fields: [
		'author',
		'status',
		'categories',
		'tags',
		'modified',
		'breakout',
	],
	titleField: 'title',
};

export { getEntryFields, defaultEntryView };
