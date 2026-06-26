/**
 * External dependencies
 */
import {
	Button,
	TextControl,
	Card,
	CardHeader,
	CardBody,
	CardFooter,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalHStack as HStack,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { CredentialsTabProps, SlackSettingsInfo } from '../../../types';
import { SlackIcon } from '../../../shared/icons/slack-icon';

/**
 * Renders the Credentials tab. When Slack is already configured, shows the
 * "Connection Status" card with the connected workspace's identity details
 * (workspace name/ID, masked bot token, Slack bot user ID, and the WordPress
 * bot user — linked to its edit screen) and a Disconnect action. When not yet
 * configured, shows the "Connect to Slack" card with Bot User OAuth Token and
 * Signing Secret password fields and the Verify & Connect action.
 *
 * @param {Object}                   props                  - Component props.
 * @param {boolean}                  props.isConfigured     - Whether Slack is currently connected.
 * @param {string}                   props.botToken         - The bot user OAuth token input value.
 * @param {(v: string) => void}      props.setBotToken      - Bot token setter.
 * @param {string}                   props.signingSecret    - The signing secret input value.
 * @param {(v: string) => void}      props.setSigningSecret - Signing secret setter.
 * @param {boolean}                  props.isVerifying      - Whether the verify/connect request is in flight.
 * @param {boolean}                  props.isDisconnecting  - Whether the disconnect request is in flight.
 * @param {SlackSettingsInfo | null} props.workspaceInfo    - Fetched workspace settings, or null.
 * @param {string}                   props.editUserUrl      - Base admin URL for editing a WordPress user.
 * @param {() => void}               props.onVerify         - Verify & Connect handler.
 * @param {() => void}               props.onDisconnect     - Disconnect handler.
 */
function CredentialsTab( {
	isConfigured,
	botToken,
	setBotToken,
	signingSecret,
	setSigningSecret,
	isVerifying,
	isDisconnecting,
	workspaceInfo,
	editUserUrl,
	onVerify,
	onDisconnect,
}: CredentialsTabProps ) {
	if ( isConfigured ) {
		return (
			<Card className="newspack-rolling-coverage-slack-settings__card">
				<CardHeader>
					<HStack alignment="space-between" justify="space-between">
						<h2>
							{ __(
								'Connection Status',
								'newspack-rolling-coverage'
							) }
						</h2>
					</HStack>
				</CardHeader>
				<CardBody>
					<VStack spacing={ 4 }>
						<span className="newspack-rolling-coverage-slack-status-chip">
							<SlackIcon size={ 20 } />
							{ __( 'Connected', 'newspack-rolling-coverage' ) }
						</span>
						<div>
							<strong>
								{ __(
									'Workspace:',
									'newspack-rolling-coverage'
								) }
							</strong>{ ' ' }
							<span>
								{ workspaceInfo?.workspace_name ||
									__(
										'(unknown)',
										'newspack-rolling-coverage'
									) }
							</span>
						</div>
						<div>
							<strong>
								{ __(
									'Workspace ID:',
									'newspack-rolling-coverage'
								) }
							</strong>{ ' ' }
							<code>{ workspaceInfo?.workspace_id || '—' }</code>
						</div>
						<div>
							<strong>
								{ __(
									'Bot token:',
									'newspack-rolling-coverage'
								) }
							</strong>{ ' ' }
							<code>{ workspaceInfo?.masked_token || '—' }</code>
						</div>
						<div>
							<strong>
								{ __(
									'Slack bot user ID:',
									'newspack-rolling-coverage'
								) }
							</strong>{ ' ' }
							<code>
								{ workspaceInfo?.slack_bot_user_id || '—' }
							</code>
						</div>
						<div>
							<strong>
								{ __(
									'WordPress bot user:',
									'newspack-rolling-coverage'
								) }
							</strong>{ ' ' }
							{ workspaceInfo?.bot_user_id ? (
								<a
									href={ `${ editUserUrl }&user_id=${ workspaceInfo.bot_user_id }` }
									target="_blank"
									rel="noopener noreferrer"
								>
									{ `#${ workspaceInfo.bot_user_id }` }
								</a>
							) : (
								<span>—</span>
							) }
						</div>
					</VStack>
				</CardBody>
				<CardFooter>
					<HStack justify="space-between">
						<Button
							variant="primary"
							isDestructive
							onClick={ onDisconnect }
							isBusy={ isDisconnecting }
							disabled={ isDisconnecting }
						>
							{ __( 'Disconnect', 'newspack-rolling-coverage' ) }
						</Button>
					</HStack>
				</CardFooter>
			</Card>
		);
	}

	return (
		<Card className="newspack-rolling-coverage-slack-settings__card">
			<CardHeader>
				<HStack alignment="space-between" justify="space-between">
					<h2>
						{ __(
							'Connect to Slack',
							'newspack-rolling-coverage'
						) }
					</h2>
				</HStack>
			</CardHeader>
			<CardBody>
				<VStack spacing={ 4 }>
					<TextControl
						label={ __(
							'Bot User OAuth Token',
							'newspack-rolling-coverage'
						) }
						value={ botToken }
						onChange={ setBotToken }
						type="password"
						help={ __(
							'Starts with xoxb-',
							'newspack-rolling-coverage'
						) }
					/>
					<TextControl
						label={ __(
							'Signing Secret',
							'newspack-rolling-coverage'
						) }
						value={ signingSecret }
						onChange={ setSigningSecret }
						type="password"
						help={ __(
							'32-character hex string',
							'newspack-rolling-coverage'
						) }
					/>
				</VStack>
			</CardBody>
			<CardFooter>
				<HStack justify="space-between">
					<Button
						variant="primary"
						onClick={ onVerify }
						isBusy={ isVerifying }
						disabled={
							isVerifying || ! botToken || ! signingSecret
						}
					>
						{ __(
							'Verify & Connect',
							'newspack-rolling-coverage'
						) }
					</Button>
				</HStack>
			</CardFooter>
		</Card>
	);
}

export { CredentialsTab };
