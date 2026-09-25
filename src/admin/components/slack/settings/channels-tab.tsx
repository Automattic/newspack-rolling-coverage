/**
 * External dependencies
 */
import { useCallback, useMemo, useState } from '@wordpress/element';
import { Modal } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import type { View } from '@wordpress/dataviews';
import { filterSortAndPaginate } from '@wordpress/dataviews/wp';
import { EmptyState } from 'newspack-components/dist/esm/empty-state';

/**
 * Internal dependencies
 */
import type { ChannelsTabProps, ChannelRow } from '../../../types';
import { DataViewsWrapper } from '../../data-views-wrapper';
import { LoadingState } from '../../../shared/loading-state';
import { SlackIcon } from '../../../shared/icons/slack-icon';
import {
	getChannelFields,
	defaultChannelView,
	orderChannelFields,
} from '../../../fields/channels';
import { ConfirmModal } from '../../confirm-modal';

/**
 * Renders the Channel Mappings tab: a DataViews list of the Slack channels
 * linked to coverages, with an auto-publish toggle and a Disconnect button
 * on each row.
 *
 * @param {ChannelsTabProps} props Component props.
 */
function ChannelsTab( {
	channels,
	hasLoadedChannels,
	onUnlink,
	onAutopublishChange,
}: ChannelsTabProps ) {
	const [ view, setView ] = useState< View >( defaultChannelView );
	const [ updatingChannelId, setUpdatingChannelId ] = useState<
		string | null
	>( null );
	const handleAutopublishChange = useCallback(
		async ( channelId: string, autopublish: boolean ) => {
			setUpdatingChannelId( channelId );
			await onAutopublishChange( channelId, autopublish );
			setUpdatingChannelId( null );
		},
		[ onAutopublishChange ]
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
	const fields = useMemo(
		() =>
			getChannelFields(
				handleAutopublishChange,
				updatingChannelId,
				setChannelToDisconnect
			),
		[ handleAutopublishChange, updatingChannelId ]
	);

	const rows = useMemo< ChannelRow[] >(
		() => channels.map( ( ch ) => ( { ...ch, id: ch.channel_id } ) ),
		[ channels ]
	);
	const { data, paginationInfo } = useMemo(
		() => filterSortAndPaginate( rows, view, fields ),
		[ rows, view, fields ]
	);

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

	if ( channels.length === 0 ) {
		return (
			<EmptyState.Root className="newspack-rolling-coverage-slack-empty-state">
				<EmptyState.Header
					icon={ <SlackIcon size={ 36 } /> }
					title={ __(
						'No channels linked yet',
						'newspack-rolling-coverage'
					) }
					description={ __(
						'Link a Slack channel to a coverage from the All Coverages list.',
						'newspack-rolling-coverage'
					) }
				/>
			</EmptyState.Root>
		);
	}

	return (
		<>
			<DataViewsWrapper
				data={ data }
				fields={ fields }
				view={ view }
				onChangeView={ handleChangeView }
				actions={ [] }
				paginationInfo={ paginationInfo }
				isLoading={ false }
			/>
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
							'Unlink this channel from its coverage? Ingestion from this channel will stop.',
							'newspack-rolling-coverage'
						) }
						confirmLabel={ __(
							'Disconnect',
							'newspack-rolling-coverage'
						) }
						isDestructive
						onConfirm={ () =>
							onUnlink( channelToDisconnect.channel_id )
						}
						onClose={ () => setChannelToDisconnect( null ) }
					/>
				</Modal>
			) }
		</>
	);
}

export { ChannelsTab };
