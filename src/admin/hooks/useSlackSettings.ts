/**
 * External dependencies
 */
import { useState, useCallback, useEffect, useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type {
	ChannelMapping,
	PublishMode,
	SettingsNotice,
	SlackSettingsInfo,
} from '../types';
import { useAdminContext } from './useAdminContext';
import {
	disconnectSlack,
	getSlackSettings,
	listSlackChannels,
	unlinkSlackChannel,
	updateSlackChannelSettings,
	verifySlackCredentials,
} from '../utils/slack-api';
import { buildSlackManifest } from '../utils/slack-manifest';
import { registerSource } from '../utils/chat-source';
import { SlackAdapter } from '../utils/slack-adapter';

/**
 * Owns all state, API calls, and event handlers for the Slack settings admin
 * page. The SlackSettingsPage component consumes this hook's return value and
 * concerns itself only with rendering. All REST traffic goes through the
 * slack-api utility layer, which normalizes errors via handleApiError.
 *
 * @param {Object}   [options]            Hook options.
 * @param {Function} [options.onVerified] Runs once the credentials are saved, in place of reloading the page.
 * @return {Object} Slack settings state, derived flags, computed values, and handlers.
 */
function useSlackSettings( { onVerified }: { onVerified?: () => void } = {} ) {
	const config = useAdminContext();
	const { restBase, restBaseUrls, slack } = config;
	const namespace = restBase.slack;
	const restUrl = restBaseUrls.slack;

	const [ botToken, setBotToken ] = useState( '' );
	const [ signingSecret, setSigningSecret ] = useState( '' );
	const [ hasLoadedSettings, setHasLoadedSettings ] = useState( false );
	const [ channels, setChannels ] = useState< ChannelMapping[] >( [] );
	const [ hasLoadedChannels, setHasLoadedChannels ] = useState( false );
	const [ isVerifying, setIsVerifying ] = useState( false );
	const [ notice, setNotice ] = useState< SettingsNotice | null >( null );
	const [ workspaceInfo, setWorkspaceInfo ] =
		useState< SlackSettingsInfo | null >( null );

	useEffect( () => {
		// Register SlackAdapter as the first chat-source adapter.
		// Slack is the platform with a working implementation today; future
		// platforms (Telegram, WhatsApp) will register their own adapters here.
		const adapter = new SlackAdapter();
		registerSource( adapter );
		// No teardown needed — sources persist for the page lifetime.
	}, [] );

	// Re-fetches the channel map and updates state. Exposed so the channels
	// tab can refresh after mutations; the mount effect fetches inline with a
	// cancellation guard instead.
	const refreshChannels = useCallback( async () => {
		const result = await listSlackChannels( namespace );
		if ( result.success ) {
			setChannels( result.channels );
		} else {
			setNotice( {
				type: 'error',
				message:
					result.error ||
					__(
						'Failed to load channels.',
						'newspack-rolling-coverage'
					),
			} );
		}
		// On failure, leave the existing channels in place; the channels
		// tab surfaces an empty state when the list is empty.
	}, [ namespace ] );

	useEffect( () => {
		if ( ! slack.isConfigured ) {
			return;
		}

		let cancelled = false;

		listSlackChannels( namespace ).then( ( result ) => {
			if ( cancelled ) {
				return;
			}
			setHasLoadedChannels( true );
			if ( ! result.success ) {
				setNotice( {
					type: 'error',
					message:
						result.error ||
						__(
							'Failed to load channels.',
							'newspack-rolling-coverage'
						),
				} );
				return;
			}
			setChannels( result.channels );
		} );

		return () => {
			cancelled = true;
		};
	}, [ slack.isConfigured, namespace ] );

	// Fetch the workspace identity and the app's permissions once connected,
	// for the Connection Status drawer and the permission warnings.
	useEffect( () => {
		if ( ! slack.isConfigured ) {
			return;
		}

		let cancelled = false;

		getSlackSettings( namespace ).then( ( result ) => {
			if ( cancelled ) {
				return;
			}
			setHasLoadedSettings( true );
			if ( ! result.success || ! result.settings ) {
				setNotice( {
					type: 'error',
					message:
						result.error ||
						__(
							'Failed to load Slack settings.',
							'newspack-rolling-coverage'
						),
				} );
				return;
			}
			setWorkspaceInfo( result.settings );
		} );

		return () => {
			cancelled = true;
		};
	}, [ slack.isConfigured, namespace ] );

	const clearNotice = useCallback( () => {
		setNotice( null );
	}, [] );

	const handleUnlinkChannel = useCallback(
		async ( channelId: string ): Promise< boolean > => {
			setNotice( null );

			const result = await unlinkSlackChannel( namespace, channelId );

			if ( result.success ) {
				await refreshChannels();
				setNotice( {
					type: 'success',
					message: __(
						'Channel disconnected.',
						'newspack-rolling-coverage'
					),
				} );
				return true;
			}
			setNotice( {
				type: 'error',
				message:
					result.error ||
					__(
						'Failed to disconnect the channel.',
						'newspack-rolling-coverage'
					),
			} );
			return false;
		},
		[ namespace, refreshChannels ]
	);

	const handleVerify = useCallback( async () => {
		setIsVerifying( true );
		setNotice( null );

		const result = await verifySlackCredentials(
			namespace,
			botToken,
			signingSecret
		);

		if ( result.success ) {
			if ( onVerified ) {
				onVerified();
			} else {
				window.location.reload();
			}
		} else {
			setNotice( {
				type: 'error',
				message:
					result.error ||
					__( 'Connection failed.', 'newspack-rolling-coverage' ),
			} );
		}

		setIsVerifying( false );
	}, [ namespace, botToken, signingSecret, onVerified ] );

	const handleDisconnect = useCallback( async () => {
		const result = await disconnectSlack( namespace );

		if ( result.success ) {
			window.location.reload();
		} else {
			setNotice( {
				type: 'error',
				message:
					result.error ||
					__( 'Failed to disconnect.', 'newspack-rolling-coverage' ),
			} );
		}
	}, [ namespace ] );

	const handlePublishModeChange = useCallback(
		async ( channelId: string, publishMode: PublishMode ) => {
			setNotice( null );

			const result = await updateSlackChannelSettings(
				namespace,
				channelId,
				publishMode
			);

			if ( result.success ) {
				await refreshChannels();
			} else {
				setNotice( {
					type: 'error',
					message:
						result.error ||
						__(
							'Failed to update the publish mode.',
							'newspack-rolling-coverage'
						),
				} );
			}
		},
		[ namespace, refreshChannels ]
	);

	const manifestJson = useMemo(
		() => buildSlackManifest( restUrl ),
		[ restUrl ]
	);

	return {
		// State
		botToken,
		setBotToken,
		signingSecret,
		setSigningSecret,
		channels,
		hasLoadedChannels,
		hasLoadedSettings,
		isVerifying,
		workspaceInfo,
		notice,
		clearNotice,
		// Computed
		manifestJson,
		// Derived
		isConfigured: slack.isConfigured,
		// Handlers
		handleUnlinkChannel,
		handlePublishModeChange,
		handleVerify,
		handleDisconnect,
	};
}

export { useSlackSettings };
