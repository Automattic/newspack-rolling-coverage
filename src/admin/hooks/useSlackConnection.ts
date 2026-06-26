/**
 * External dependencies
 */
import { useState, useEffect, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { Liveblog } from '../types';
import { useAdminContext } from './useAdminContext';
import {
	connectSlackChannel,
	disconnectSlackChannel,
	getSlackChannelSettings,
	updateSlackChannelSettings,
} from '../utils/slack-api';

/**
 * Manages Slack channel connection state and operations for a liveblog term.
 *
 * Derives the current channel ID and name from the liveblog meta, fetches the
 * stored autopublish setting when connected, and exposes async handlers for
 * connecting, disconnecting, and toggling autopublish. Callers are notified
 * of successful mutations via the `onSaved` callback and may close the modal
 * via the `onClose` callback.
 *
 * @param {Liveblog | null} liveblog The liveblog term to manage, or null.
 * @param {() => void}      onSaved  Called after a successful connect/disconnect.
 * @param {() => void}      onClose  Called after a successful connect/disconnect to close the modal.
 *
 * @return {Object} Connection state, derived channel info, and async handlers.
 */
function useSlackConnection(
	liveblog: Liveblog | null,
	onSaved: () => void,
	onClose: () => void
) {
	const { restBase } = useAdminContext();
	const channelId = String(
		liveblog?.meta?.rolling_coverage_slack_channel_id ?? ''
	);
	const channelName = String(
		liveblog?.meta?.rolling_coverage_slack_channel_name ?? ''
	);

	const [ channel, setChannel ] = useState( '' );
	const [ autopublish, setAutopublish ] = useState( false );
	const [ isConnecting, setIsConnecting ] = useState( false );
	const [ isDisconnecting, setIsDisconnecting ] = useState( false );
	const [ isUpdatingAutopublish, setIsUpdatingAutopublish ] =
		useState( false );
	const [ error, setError ] = useState< string | null >( null );

	// When the modal opens in connected mode, fetch the current autopublish
	// state from the channel map so the toggle reflects the stored value.
	useEffect( () => {
		if ( ! channelId ) {
			return;
		}

		let cancelled = false;

		getSlackChannelSettings( restBase.slack, channelId ).then(
			( result ) => {
				if ( cancelled ) {
					return;
				}
				if ( ! result.success ) {
					setError(
						result.error ||
							__(
								'Failed to load auto-publish setting.',
								'newspack-rolling-coverage'
							)
					);
					return;
				}
				setAutopublish( Boolean( result.autopublish ) );
			}
		);

		return () => {
			cancelled = true;
		};
	}, [ channelId, restBase.slack ] );

	const handleAutopublishChange = useCallback(
		async ( next: boolean ) => {
			setAutopublish( next );
			setError( null );
			setIsUpdatingAutopublish( true );

			const result = await updateSlackChannelSettings(
				restBase.slack,
				channelId,
				next
			);

			if ( ! result.success ) {
				// Revert the toggle on failure.
				setAutopublish( ! next );
				setError(
					result.error ||
						__(
							'Failed to update auto-publish setting.',
							'newspack-rolling-coverage'
						)
				);
			}

			setIsUpdatingAutopublish( false );
		},
		[ restBase.slack, channelId ]
	);

	const handleConnect = useCallback( async () => {
		if ( ! liveblog || ! channel.trim() ) {
			return;
		}

		setIsConnecting( true );
		setError( null );

		const result = await connectSlackChannel(
			restBase.slack,
			liveblog.id,
			channel.trim(),
			autopublish
		);

		if ( result.success ) {
			onSaved();
			onClose();
		} else {
			// The server owns the wording; fall back to a generic message only
			// if no error was returned.
			setError(
				result.error ||
					__(
						'Could not connect to Slack.',
						'newspack-rolling-coverage'
					)
			);
		}

		setIsConnecting( false );
	}, [ liveblog, channel, autopublish, restBase.slack, onSaved, onClose ] );

	const handleDisconnect = useCallback( async () => {
		if ( ! liveblog ) {
			return;
		}

		setIsDisconnecting( true );
		setError( null );

		const result = await disconnectSlackChannel(
			restBase.slack,
			liveblog.id
		);

		if ( result.success ) {
			onSaved();
			onClose();
		} else {
			setError(
				result.error ||
					__( 'Failed to disconnect.', 'newspack-rolling-coverage' )
			);
		}

		setIsDisconnecting( false );
	}, [ liveblog, restBase.slack, onSaved, onClose ] );

	return {
		channelId,
		channelName,
		channel,
		setChannel,
		autopublish,
		setAutopublish,
		isConnecting,
		isDisconnecting,
		isUpdatingAutopublish,
		error,
		handleConnect,
		handleDisconnect,
		handleAutopublishChange,
	};
}

export { useSlackConnection };
