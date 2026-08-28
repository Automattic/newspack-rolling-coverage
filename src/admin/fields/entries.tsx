/**
 * External dependencies
 */
import { __ } from '@wordpress/i18n';
import { Icon, pin, wordpress as WordPressIconRaw } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import type { Field, ViewState, Entry, AdminConfig } from '../types';
import { ChipLink } from '../shared/chip-link';
import { SlackIcon } from '../shared/icons/slack-icon';
import { TermChips } from '../shared/term-chips';
import {
	truncate,
	getEmbeddedTerms,
	getEntrySource,
	getStatusLabel,
	STATUS_ELEMENTS,
	getRawTitle,
	getRawAuthor,
	getCategoryNames,
	getTagNames,
	getBreakoutStatus,
	SOURCE_SLACK,
	SOURCE_WORDPRESS,
} from '../utils/fields';

const BREAKOUT_ELEMENTS = [
	{ value: 'publish', label: __( 'Published', 'newspack-rolling-coverage' ) },
	{ value: 'draft', label: __( 'Draft', 'newspack-rolling-coverage' ) },
	{ value: 'pending', label: __( 'Pending', 'newspack-rolling-coverage' ) },
	{ value: 'future', label: __( 'Scheduled', 'newspack-rolling-coverage' ) },
	{ value: 'private', label: __( 'Private', 'newspack-rolling-coverage' ) },
	{ value: 'trash', label: __( 'Trashed', 'newspack-rolling-coverage' ) },
	{ value: 'none', label: __( 'None', 'newspack-rolling-coverage' ) },
];

/**
 * Field definitions for the entry DataViews table.
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
			enableSorting: false,
			getValue: ( { item } ) => String( item.id ),
			filterBy: {
				operators: [ 'is' ],
			},
		},
		{
			id: 'title',
			type: 'text',
			label: __( 'Title', 'newspack-rolling-coverage' ),
			enableHiding: false,
			enableGlobalSearch: true,
			enableSorting: false,
			getValue: ( { item } ) => getRawTitle( item ),
			render: ( { item } ) => {
				const title =
					item.title?.rendered ||
					__( '(no title)', 'newspack-rolling-coverage' );
				if ( item.pinned ) {
					return (
						<span
							style={ {
								display: 'inline-flex',
								alignItems: 'center',
								gap: 4,
							} }
						>
							<Icon icon={ pin } size={ 14 } />
							{ truncate( title, 20 ) }
						</span>
					);
				}
				return truncate( title, 20 );
			},
			filterBy: {
				operators: [ 'contains' ],
			},
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
		},
		{
			id: 'author',
			type: 'text',
			label: __( 'Author', 'newspack-rolling-coverage' ),
			enableGlobalSearch: true,
			enableSorting: false,
			getValue: ( { item } ) => getRawAuthor( item ),
			render: ( { item } ) => {
				const author = item._embedded?.author?.[ 0 ];
				if ( ! author ) {
					return <span>—</span>;
				}
				return <ChipLink href={ author.link } label={ author.name } />;
			},
			filterBy: {
				operators: [ 'contains' ],
			},
		},
		{
			id: 'source',
			type: 'text',
			label: __( 'Source', 'newspack-rolling-coverage' ),
			enableSorting: false,
			getValue: ( { item } ) => getEntrySource( item ),
			render: ( { item } ) => {
				if ( getEntrySource( item ) === SOURCE_SLACK ) {
					return (
						<span title="Slack" aria-label="Slack">
							<SlackIcon size={ 16 } />
						</span>
					);
				}
				return (
					<span title="WordPress" aria-label="WordPress">
						<Icon icon={ WordPressIconRaw } size={ 16 } />
					</span>
				);
			},
			elements: [
				{
					value: SOURCE_SLACK,
					label: __( 'Slack', 'newspack-rolling-coverage' ),
				},
				{
					value: SOURCE_WORDPRESS,
					label: __( 'WordPress', 'newspack-rolling-coverage' ),
				},
			],
			filterBy: {
				operators: [ 'is', 'isNot' ],
			},
		},
		{
			id: 'status',
			type: 'text',
			label: __( 'Status', 'newspack-rolling-coverage' ),
			enableSorting: false,
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
			getValue: ( { item } ) => getCategoryNames( item ),
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
			filterBy: {
				operators: [ 'contains' ],
			},
		},
		{
			id: 'tags',
			type: 'text',
			label: __( 'Tags', 'newspack-rolling-coverage' ),
			enableSorting: false,
			getValue: ( { item } ) => getTagNames( item ),
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
			filterBy: {
				operators: [ 'contains' ],
			},
		},
		{
			id: 'breakout',
			type: 'text',
			label: __( 'Breakout', 'newspack-rolling-coverage' ),
			enableSorting: false,
			getValue: ( { item } ) => getBreakoutStatus( item ),
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
			elements: BREAKOUT_ELEMENTS,
			filterBy: {
				operators: [ 'is', 'isNot' ],
			},
		},
	];
}

/**
 * Default view state for the entry list.
 */
const defaultEntryView: ViewState = {
	type: 'table',
	perPage: 20,
	page: 1,
	search: '',
	filters: [],
	fields: [
		'author',
		'status',
		'source',
		'breakout',
		'categories',
		'tags',
		'modified',
	],
	titleField: 'title',
};

export { getEntryFields, defaultEntryView };
