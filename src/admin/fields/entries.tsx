/**
 * External dependencies.
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import type { Field, ViewState, Entry } from '../types';
import { truncate, safeFormatUTCDate, getEmbeddedTerms } from '../utils/fields';
import { ChipLink } from '../shared/chip-link';
import { TermChips } from '../shared/term-chips';

/**
 * Field definitions for the entry DataViews table.
 * Configures columns: ID, title, created date, modified date, author, status,
 * categories, and tags.
 */
const entryFields: Field< Entry >[] = [
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
		elements: [
			{
				value: 'publish',
				label: __( 'Published', 'newspack-rolling-coverage' ),
			},
			{
				value: 'draft',
				label: __( 'Draft', 'newspack-rolling-coverage' ),
			},
			{
				value: 'pending',
				label: __( 'Pending', 'newspack-rolling-coverage' ),
			},
			{
				value: 'future',
				label: __( 'Scheduled', 'newspack-rolling-coverage' ),
			},
			{
				value: 'private',
				label: __( 'Private', 'newspack-rolling-coverage' ),
			},
		],
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
];

/**
 * Default view state for the entry list: table layout, sorted by date descending,
 * showing author, status, categories, tags, and modified columns.
 */
const defaultEntryView: ViewState = {
	type: 'table',
	perPage: 20,
	page: 1,
	sort: { field: 'date', direction: 'desc' },
	search: '',
	filters: [],
	fields: [ 'author', 'status', 'categories', 'tags', 'modified' ],
	titleField: 'title',
};

export { entryFields, defaultEntryView };
