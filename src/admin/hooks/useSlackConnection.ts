/**
 * External dependencies
 */
import {
	useState,
	useEffect,
	useLayoutEffect,
	useCallback,
	useRef,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { Coverage } from '../types';
import { useAdminContext } from './useAdminContext';
import {
	connectSlackChannel,
	disconnectSlackChannel,
	getSlackChannelSettings,
	updateSlackChannelSettings,
} from '../utils/slack-api';

/**
 * Manages Slack channel connection state and operations for a coverage term.
 *
 * Derives the current channel ID and name from the coverage meta, fetches the
 * stored autopublish setting and last sync each time the drawer opens connected, and exposes
 * async handlers for connecting, disconnecting, and toggling autopublish.
 * Callers are notified of successful mutations via the `onSaved` callback and
 * may close the drawer via the `onClose` callback.
 *
 * @param {boolean}         isOpen   Whether the drawer is open.
 * @param {Coverage | null} coverage The coverage term to manage, or null.
 * @param {() => void}      onSaved  Called after a successful connect/disconnect.
 * @param {() => void}      onClose  Called after a successful connect/disconnect to close the drawer.
 *
 * @return {Object} Connection state, derived channel info, and async handlers.
 */
function useSlackConnection(
	isOpen: boolean,
	coverage: Coverage | null,
	onSaved: () => void,
	onClose: () => void
) {
	const { restBase } = useAdminContext();
	const channelId = String(
		coverage?.meta?.rolling_coverage_slack_channel_id ?? ''
	);
	const channelName = String(
		coverage?.meta?.rolling_coverage_slack_channel_name ?? ''
	);

	const [ channel, setChannel ] = useState( '' );
	const [ autopublish, setAutopublish ] = useState( false );
	const [ lastSyncTs, setLastSyncTs ] = useState< string | null >( null );
	const [ isConnecting, setIsConnecting ] = useState( false );
	const [ isDisconnecting, setIsDisconnecting ] = useState( false );
	const [ isUpdatingAutopublish, setIsUpdatingAutopublish ] =
		useState( false );
	const [ error, setError ] = useState< string | null >( null );

	// Each opening is its own session. A request still in flight from an
	// earlier session must not write into, or close, the drawer as it is now,
	// which may be showing another coverage.
	const sessionRef = useRef( 0 );

	useLayoutEffect( () => {
		if ( isOpen ) {
			sessionRef.current++;
			setChannel( '' );
			setAutopublish( false );
			setLastSyncTs( null );
			setIsConnecting( false );
			setIsDisconnecting( false );
			setIsUpdatingAutopublish( false );
			setError( null );
		}
	}, [ isOpen ] );

	// When the drawer opens in connected mode, fetch the stored autopublish
	// state and last sync. The toggle stays locked until they arrive.
	useEffect( () => {
		if ( ! isOpen || ! channelId ) {
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
				setLastSyncTs( result.lastSyncTs ?? '' );
			}
		);

		return () => {
			cancelled = true;
		};
	}, [ isOpen, channelId, restBase.slack ] );

	const handleAutopublishChange = useCallback(
		async ( next: boolean ) => {
			const session = sessionRef.current;
			setAutopublish( next );
			setError( null );
			setIsUpdatingAutopublish( true );

			const result = await updateSlackChannelSettings(
				restBase.slack,
				channelId,
				next
			);

			if ( session !== sessionRef.current ) {
				return;
			}

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
		if ( ! coverage || ! channel.trim() ) {
			return;
		}

		const session = sessionRef.current;
		setIsConnecting( true );
		setError( null );

		const result = await connectSlackChannel(
			restBase.slack,
			coverage.id,
			channel.trim(),
			autopublish
		);

		if ( session !== sessionRef.current ) {
			if ( result.success ) {
				onSaved();
			}
			return;
		}

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
	}, [ coverage, channel, autopublish, restBase.slack, onSaved, onClose ] );

	const handleDisconnect = useCallback( async () => {
		if ( ! coverage ) {
			return;
		}

		const session = sessionRef.current;
		setIsDisconnecting( true );
		setError( null );

		const result = await disconnectSlackChannel(
			restBase.slack,
			coverage.id
		);

		if ( session !== sessionRef.current ) {
			if ( result.success ) {
				onSaved();
			}
			return;
		}

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
	}, [ coverage, restBase.slack, onSaved, onClose ] );

	return {
		channelId,
		channelName,
		channel,
		setChannel,
		autopublish,
		setAutopublish,
		lastSyncTs,
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
