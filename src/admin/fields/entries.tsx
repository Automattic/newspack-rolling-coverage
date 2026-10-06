/**
 * External dependencies
 */
import { Tooltip } from '@wordpress/components';
import { dateI18n, getSettings } from '@wordpress/date';
import { __, sprintf } from '@wordpress/i18n';
import { Link, Stack, VisuallyHidden } from '@wordpress/ui';
import { Icon, pinSmall } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import type { Field, ViewState, Entry, AdminConfig } from '../types';
import { SlackIcon } from '../shared/icons/slack-icon';
import { UserRow } from '../shared/user-row';
import StatusIndicator from 'newspack-components/dist/esm/status-indicator';
import {
	getEntrySource,
	getStatusLabel,
	STATUS_ELEMENTS,
	POST_STATUS_INDICATORS,
	getRawTitle,
	getRawAuthor,
	getCategoryNames,
	getTermNames,
	summarizeTermNames,
	getTagNames,
	getBreakoutStatus,
	getArchivedStatus,
	ARCHIVED_ELEMENTS,
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
					item.summary ||
					__( '(no title)', 'newspack-rolling-coverage' );
				const isFromSlack = getEntrySource( item ) === SOURCE_SLACK;
				if ( ! item.pinned && ! isFromSlack ) {
					return title;
				}
				return (
					<Stack
						render={ <span /> }
						direction="row"
						align="flex-start"
						gap="sm"
					>
						{ item.pinned && (
							<Icon
								className="newspack-rolling-coverage-entry-title__icon"
								icon={ pinSmall }
								size={ 24 }
							/>
						) }
						{ isFromSlack && (
							<span
								className="newspack-rolling-coverage-entry-title__source"
								title={ __(
									'From Slack',
									'newspack-rolling-coverage'
								) }
							>
								<SlackIcon size={ 10 } />
								<VisuallyHidden render={ <span /> }>
									{ __(
										'From Slack',
										'newspack-rolling-coverage'
									) }
								</VisuallyHidden>
							</span>
						) }
						{ title }
					</Stack>
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
			// Filter only: the Slack marker sits beside the title instead.
			id: 'source',
			type: 'text',
			label: __( 'Source', 'newspack-rolling-coverage' ),
			enableHiding: false,
			enableSorting: false,
			getValue: ( { item } ) => getEntrySource( item ),
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
						<StatusIndicator
							className="newspack-rolling-coverage-status-archived"
							status={
								item.status === 'publish'
									? 'ended'
									: POST_STATUS_INDICATORS[ item.status ]
							}
						>
							{ label }
						</StatusIndicator>
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
			render: ( { item } ) =>
				summarizeTermNames( getTermNames( item, 'category' ) ),
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
			render: ( { item } ) =>
				summarizeTermNames( getTermNames( item, 'post_tag' ) ),
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
				const breakoutPostId = item.rolling_coverage_breakout_post_id;
				if (
					! item.rolling_coverage_breakout_status ||
					! breakoutPostId
				) {
					return <span>—</span>;
				}
				const breakoutStatus = item.rolling_coverage_breakout_status;
				return (
					<StatusIndicator
						status={ POST_STATUS_INDICATORS[ breakoutStatus ] }
					>
						<Link
							href={ `${ config.adminUrls.editEntry }&post=${ breakoutPostId }` }
							openInNewTab
						>
							{ getStatusLabel( breakoutStatus ) }
						</Link>
					</StatusIndicator>
				);
			},
			elements: BREAKOUT_ELEMENTS,
			filterBy: {
				operators: [ 'is', 'isNot' ],
			},
		},
		{
			id: 'archived',
			type: 'text',
			label: __( 'Archived', 'newspack-rolling-coverage' ),
			enableSorting: false,
			getValue: ( { item } ) => getArchivedStatus( item ),
			elements: ARCHIVED_ELEMENTS,
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
		'breakout',
		'categories',
		'tags',
		'modified',
	],
	titleField: 'title',
};

export { getEntryFields, defaultEntryView };
