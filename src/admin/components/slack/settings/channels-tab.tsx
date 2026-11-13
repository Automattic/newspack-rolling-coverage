/**
 * External dependencies
 */
import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { Modal } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { link } from '@wordpress/icons';
import type { View } from '@wordpress/dataviews';
import { filterSortAndPaginate } from '@wordpress/dataviews/wp';
import { EmptyState, Stack } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import type { ChannelsTabProps, ChannelRow, PublishMode } from '../../../types';
import { DataViewsWrapper } from '../../data-views-wrapper';
import { useAdminContext } from '../../../hooks/useAdminContext';
import { LoadingState } from '../../../shared/loading-state';
import {
	getChannelFields,
	defaultChannelView,
	orderChannelFields,
} from '../../../fields/channels';
import { ConfirmModal } from '../../confirm-modal';

const FIT_COLUMNS = [ 'disconnect' ];

/**
 * Renders the Channel Mappings tab: a DataViews list of the Slack channels
 * linked to coverages, with a Publish Mode dropdown and a Disconnect button
 * on each row.
 *
 * @param {ChannelsTabProps} props                Component props.
 * @param {Function}         props.onHeaderChange Receives the breadcrumb count and whether the empty state shows.
 */
function ChannelsTab( {
	channels,
	hasLoadedChannels,
	onUnlink,
	onPublishModeChange,
	onHeaderChange,
}: ChannelsTabProps ) {
	const [ view, setView ] = useState< View >( defaultChannelView );
	const [ updatingChannelIds, setUpdatingChannelIds ] = useState<
		Set< string >
	>( () => new Set() );
	const handlePublishModeChange = useCallback(
		async ( channelId: string, publishMode: PublishMode ) => {
			setUpdatingChannelIds( ( ids ) => new Set( ids ).add( channelId ) );
			await onPublishModeChange( channelId, publishMode );
			setUpdatingChannelIds( ( ids ) => {
				const next = new Set( ids );
				next.delete( channelId );
				return next;
			} );
		},
		[ onPublishModeChange ]
	);
	const containerRef = useRef< HTMLDivElement | null >( null );
	const emptyTitleRef = useRef< HTMLHeadingElement | null >( null );

	// The unlinked row's Disconnect button is gone once the list refreshes, so
	// focus has nowhere to return to; send it to the search field, or to the
	// empty state's heading when the last channel went.
	const handleUnlink = useCallback(
		async ( channelId: string ) => {
			if ( ! ( await onUnlink( channelId ) ) ) {
				return;
			}
			window.requestAnimationFrame( () => {
				const target =
					containerRef.current?.querySelector< HTMLElement >(
						'input[type="search"]'
					) ?? emptyTitleRef.current;
				target?.focus();
			} );
		},
		[ onUnlink ]
	);
	const handleChangeView = useCallback(
		( next: View ) =>
			setView( {
				...next,
				fields: orderChannelFields( next.fields ?? [] ),
			} ),
		[]
	);
	const [ channelToDisconnect, setChannelToDisconnect ] =
		useState< ChannelRow | null >( null );
	const { adminUrls } = useAdminContext();
	// The saving state rides on the rows, not the fields: rebuilding the
	// fields remounts every row's Publish Mode control and drops keyboard focus.
	const fields = useMemo(
		() =>
			getChannelFields(
				handlePublishModeChange,
				setChannelToDisconnect,
				adminUrls.coverages
			),
		[ handlePublishModeChange, adminUrls.coverages ]
	);

	const rows = useMemo< ChannelRow[] >(
		() =>
			channels.map( ( ch ) => ( {
				...ch,
				id: ch.channel_id,
				is_updating: updatingChannelIds.has( ch.channel_id ),
			} ) ),
		[ channels, updatingChannelIds ]
	);
	const { data, paginationInfo } = useMemo(
		() => filterSortAndPaginate( rows, view, fields ),
		[ rows, view, fields ]
	);

	useEffect( () => {
		onHeaderChange(
			hasLoadedChannels
				? {
						count: paginationInfo.totalItems,
						isEmpty: channels.length === 0,
					}
				: {}
		);
	}, [
		hasLoadedChannels,
		paginationInfo.totalItems,
		channels.length,
		onHeaderChange,
	] );

	if ( ! hasLoadedChannels ) {
		return (
			<LoadingState
				label={ __(
					'Fetching channels…',
					'newspack-rolling-coverage'
				) }
			/>
		);
	}

	return (
		<div ref={ containerRef }>
			{ channels.length === 0 ? (
				<Stack direction="column" align="center">
					<EmptyState.Root>
						<EmptyState.Icon icon={ link } />
						<EmptyState.Title ref={ emptyTitleRef } tabIndex={ -1 }>
							{ __(
								'No channels linked yet',
								'newspack-rolling-coverage'
							) }
						</EmptyState.Title>
						<EmptyState.Description>
							{ __(
								'Link a Slack channel to a liveblog from the All Liveblogs list.',
								'newspack-rolling-coverage'
							) }
						</EmptyState.Description>
					</EmptyState.Root>
				</Stack>
			) : (
				<DataViewsWrapper
					data={ data }
					fields={ fields }
					view={ view }
					onChangeView={ handleChangeView }
					actions={ [] }
					paginationInfo={ paginationInfo }
					isLoading={ false }
					fitColumns={ FIT_COLUMNS }
				/>
			) }
			{ channelToDisconnect && (
				<Modal
					title={ __(
						'Disconnect channel',
						'newspack-rolling-coverage'
					) }
					onRequestClose={ () => setChannelToDisconnect( null ) }
				>
					<ConfirmModal
						message={ __(
							'Disconnect this channel from its liveblog? Its messages will stop becoming entries.',
							'newspack-rolling-coverage'
						) }
						confirmLabel={ __(
							'Disconnect',
							'newspack-rolling-coverage'
						) }
						isDestructive
						onConfirm={ async () => {
							await handleUnlink(
								channelToDisconnect.channel_id
							);
						} }
						onClose={ () => setChannelToDisconnect( null ) }
					/>
				</Modal>
			) }
		</div>
	);
}

export { ChannelsTab };
