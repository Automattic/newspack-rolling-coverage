/**
 * External dependencies
 */
import { Tooltip } from '@wordpress/components';
import { dateI18n, getSettings } from '@wordpress/date';
import { __, sprintf } from '@wordpress/i18n';
import {
	Icon,
	pinSmall,
	info,
	wordpress as WordPressIconRaw,
} from '@wordpress/icons';

/**
 * Internal dependencies
 */
import type { Field, ViewState, Entry, AdminConfig } from '../types';
import { ChipLink } from '../shared/chip-link';
import { SlackIcon } from '../shared/icons/slack-icon';
import { TermChips } from '../shared/term-chips';
import { UserRow } from '../shared/user-row';
import StatusIndicator from 'newspack-components/dist/esm/status-indicator';
import {
	getEmbeddedTerms,
	getEntrySource,
	getStatusLabel,
	STATUS_ELEMENTS,
	POST_STATUS_INDICATORS,
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
						<span className="newspack-rolling-coverage-entry-title newspack-rolling-coverage-entry-title--pinned">
							<Icon
								className="newspack-rolling-coverage-entry-title__icon"
								icon={ pinSmall }
								size={ 24 }
							/>
							{ title }
						</span>
					);
				}
				return (
					<span className="newspack-rolling-coverage-entry-title">
						{ title }
					</span>
				);
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
				return (
					<UserRow
						label={ author.name }
						avatarUrls={ author.avatar_urls }
					/>
				);
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
						<span
							className="newspack-rolling-coverage-source-slack"
							title="Slack"
							aria-label="Slack"
						>
							<SlackIcon size={ 15 } />
						</span>
					);
				}
				return (
					<span title="WordPress" aria-label="WordPress">
						<Icon icon={ WordPressIconRaw } size={ 18 } />
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
			// All roles may see trash; the server scopes results to the user's own entries.
			elements: STATUS_ELEMENTS,
			filterBy: {
				operators: [ 'is', 'isNot' ],
			},
			render: ( { item } ) => {
				const archivedAt = item.archivedAt ?? 0;

				if ( ! archivedAt ) {
					return (
						<StatusIndicator
							status={ POST_STATUS_INDICATORS[ item.status ] }
						>
							{ getStatusLabel( item.status ) }
						</StatusIndicator>
					);
				}

				// A non-publish status keeps its own label; only a published
				// entry reads as "Archived". The icon flags the archive either way.
				const label =
					item.status === 'publish'
						? __( 'Archived', 'newspack-rolling-coverage' )
						: getStatusLabel( item.status );

				const archivedDate = dateI18n(
					getSettings().formats.datetime,
					new Date( archivedAt * 1000 )
				);

				return (
					<Tooltip
						className="newspack-rolling-coverage-archived-tooltip"
						text={ sprintf(
							// translators: %s: date the entry was archived.
							__(
								"Archived on %s. This entry can't be pinned, trashed, or given a new breakout post. Unarchive it to allow those actions.",
								'newspack-rolling-coverage'
							),
							archivedDate
						) }
					>
						<span className="newspack-rolling-coverage-status-archived">
							<StatusIndicator
								status={
									item.status === 'publish'
										? 'ended'
										: POST_STATUS_INDICATORS[ item.status ]
								}
							>
								{ label }
							</StatusIndicator>
							<Icon icon={ info } size={ 18 } />
						</span>
					</Tooltip>
				);
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
	sort: { field: 'date', direction: 'desc' },
	search: '',
	filters: [ { field: 'status', operator: 'isNot', value: 'trash' } ],
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
