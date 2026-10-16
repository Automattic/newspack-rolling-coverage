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
import type { Coverage, PublishMode } from '../types';
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
 * stored publish mode and last sync each time the drawer opens connected, and
 * exposes async handlers for connecting, disconnecting, and changing the
 * publish mode.
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
	const [ publishMode, setPublishMode ] = useState< PublishMode >( 'draft' );
	const [ lastSyncTs, setLastSyncTs ] = useState< string | null >( null );
	const [ isConnecting, setIsConnecting ] = useState( false );
	const [ isDisconnecting, setIsDisconnecting ] = useState( false );
	const [ isUpdatingPublishMode, setIsUpdatingPublishMode ] =
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
			setPublishMode( 'draft' );
			setLastSyncTs( null );
			setIsConnecting( false );
			setIsDisconnecting( false );
			setIsUpdatingPublishMode( false );
			setError( null );
		}
	}, [ isOpen ] );

	// When the drawer opens in connected mode, fetch the stored publish mode
	// and last sync. The dropdown stays locked until they arrive.
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
								'Failed to load the publish mode.',
								'newspack-rolling-coverage'
							)
					);
					return;
				}
				setPublishMode( result.publishMode ?? 'draft' );
				setLastSyncTs( result.lastSyncTs ?? '' );
			}
		);

		return () => {
			cancelled = true;
		};
	}, [ isOpen, channelId, restBase.slack ] );

	const handlePublishModeChange = useCallback(
		async ( next: PublishMode ) => {
			const session = sessionRef.current;
			const previous = publishMode;
			setPublishMode( next );
			setError( null );
			setIsUpdatingPublishMode( true );

			const result = await updateSlackChannelSettings(
				restBase.slack,
				channelId,
				next
			);

			if ( session !== sessionRef.current ) {
				return;
			}

			if ( ! result.success ) {
				// Put the dropdown back on failure.
				setPublishMode( previous );
				setError(
					result.error ||
						__(
							'Failed to update the publish mode.',
							'newspack-rolling-coverage'
						)
				);
			}

			setIsUpdatingPublishMode( false );
		},
		[ restBase.slack, channelId, publishMode ]
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
			publishMode
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
	}, [ coverage, channel, publishMode, restBase.slack, onSaved, onClose ] );

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
		publishMode,
		setPublishMode,
		lastSyncTs,
		isConnecting,
		isDisconnecting,
		isUpdatingPublishMode,
		error,
		handleConnect,
		handleDisconnect,
		handlePublishModeChange,
	};
}

export { useSlackConnection };
