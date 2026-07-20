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
	SettingsNotice,
	SlackSettingsInfo,
} from '../types';
import { useAdminContext } from './useAdminContext';
import {
	disconnectSlack,
	getSlackSettings,
	listSlackChannels,
	saveSlackSettings,
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
 * @return {Object} Slack settings state, derived flags, computed values, and handlers.
 */
function useSlackSettings() {
	const config = useAdminContext();
	const { restBase, restBaseUrls, slack } = config;
	const namespace = restBase.slack;
	const restUrl = restBaseUrls.slack;
	const editUserUrl = config.adminUrls.editUser;

	const [ botToken, setBotToken ] = useState( '' );
	const [ signingSecret, setSigningSecret ] = useState( '' );
	const [ ignorePrefix, setIgnorePrefix ] = useState( '~~' );
	const [ channels, setChannels ] = useState< ChannelMapping[] >( [] );
	const [ isVerifying, setIsVerifying ] = useState( false );
	const [ isDisconnecting, setIsDisconnecting ] = useState( false );
	const [ isSavingSettings, setIsSavingSettings ] = useState( false );
	const [ disconnectingChannelId, setDisconnectingChannelId ] = useState<
		string | null
	>( null );
	const [ notice, setNotice ] = useState< SettingsNotice | null >( null );
	const [ workspaceInfo, setWorkspaceInfo ] =
		useState< SlackSettingsInfo | null >( null );
	const [ updatingAutopublishChannelId, setUpdatingAutopublishChannelId ] =
		useState< string | null >( null );

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

	// Fetch the workspace identity + masked token once connected, so the
	// Credentials tab can render the connection-status details.
	useEffect( () => {
		if ( ! slack.isConfigured ) {
			return;
		}

		let cancelled = false;

		getSlackSettings( namespace ).then( ( result ) => {
			if ( cancelled ) {
				return;
			}
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
		async ( channelId: string ) => {
			if (
				// eslint-disable-next-line no-alert
				! confirm(
					__(
						'Unlink this channel from its coverage? Ingestion from this channel will stop.',
						'newspack-rolling-coverage'
					)
				)
			) {
				return;
			}

			setDisconnectingChannelId( channelId );
			setNotice( null );

			const result = await unlinkSlackChannel( namespace, channelId );

			if ( result.success ) {
				await refreshChannels();
				setNotice( {
					type: 'success',
					message: __(
						'Channel unlinked.',
						'newspack-rolling-coverage'
					),
				} );
			} else {
				setNotice( {
					type: 'error',
					message:
						result.error ||
						__(
							'Failed to unlink channel.',
							'newspack-rolling-coverage'
						),
				} );
			}

			setDisconnectingChannelId( null );
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
			setNotice( {
				type: 'success',
				message: `${ __(
					'Connected to Slack workspace:',
					'newspack-rolling-coverage'
				) } ${ result.team || '' }`,
			} );
			window.location.reload();
		} else {
			setNotice( {
				type: 'error',
				message:
					result.error ||
					__( 'Connection failed.', 'newspack-rolling-coverage' ),
			} );
		}

		setIsVerifying( false );
	}, [ namespace, botToken, signingSecret ] );

	const handleDisconnect = useCallback( async () => {
		if (
			// eslint-disable-next-line no-alert
			! confirm(
				__(
					'Are you sure you want to disconnect Slack? All channel mappings will be removed.',
					'newspack-rolling-coverage'
				)
			)
		) {
			return;
		}

		setIsDisconnecting( true );

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

		setIsDisconnecting( false );
	}, [ namespace ] );

	const handleSaveSettings = useCallback( async () => {
		setIsSavingSettings( true );

		const result = await saveSlackSettings( namespace, ignorePrefix );

		if ( result.success ) {
			setNotice( {
				type: 'success',
				message: __( 'Settings saved.', 'newspack-rolling-coverage' ),
			} );
		} else {
			setNotice( {
				type: 'error',
				message:
					result.error ||
					__(
						'Failed to save settings.',
						'newspack-rolling-coverage'
					),
			} );
		}

		setIsSavingSettings( false );
	}, [ namespace, ignorePrefix ] );

	const handleAutopublishChange = useCallback(
		async ( channelId: string, autopublish: boolean ) => {
			setUpdatingAutopublishChannelId( channelId );
			setNotice( null );

			const result = await updateSlackChannelSettings(
				namespace,
				channelId,
				autopublish
			);

			if ( result.success ) {
				await refreshChannels();
			} else {
				setNotice( {
					type: 'error',
					message:
						result.error ||
						__(
							'Failed to update auto-publish setting.',
							'newspack-rolling-coverage'
						),
				} );
			}

			setUpdatingAutopublishChannelId( null );
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
		ignorePrefix,
		setIgnorePrefix,
		channels,
		isVerifying,
		isDisconnecting,
		isSavingSettings,
		disconnectingChannelId,
		updatingAutopublishChannelId,
		workspaceInfo,
		notice,
		clearNotice,
		// Computed
		manifestJson,
		editUserUrl,
		// Derived
		isConfigured: slack.isConfigured,
		// Handlers
		handleUnlinkChannel,
		handleAutopublishChange,
		handleVerify,
		handleDisconnect,
		handleSaveSettings,
	};
}

export { useSlackSettings };
