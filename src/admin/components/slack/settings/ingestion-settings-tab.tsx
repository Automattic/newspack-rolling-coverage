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
import type {
	IngestionSettingsTabProps,
	SlackSettingsInfo,
} from '../../../types';
import { BotUserSection } from './bot-user-section';

/**
 * Renders the Ingestion Settings tab. Holds per-workspace ingestion options
 * that don't belong on the Credentials or Channel Mappings tabs. Today this is
 * the WordPress bot user details (linked to its edit screen) and the message
 * ignore prefix. When Slack is not yet connected, shows a prompt to connect
 * first.
 *
 * @param {Object}                   props                  - Component props.
 * @param {boolean}                  props.isConfigured     - Whether Slack is currently connected.
 * @param {string}                   props.ignorePrefix     - The ignore-prefix setting value.
 * @param {(v: string) => void}      props.setIgnorePrefix  - Ignore prefix setter.
 * @param {boolean}                  props.isSavingSettings - Whether the save-settings request is in flight.
 * @param {() => void}               props.onSaveSettings   - Save Settings handler.
 * @param {SlackSettingsInfo | null} props.workspaceInfo    - Fetched workspace settings, or null.
 * @param {string}                   props.editUserUrl      - Base admin URL for editing a WordPress user.
 */
function IngestionSettingsTab( {
	isConfigured,
	ignorePrefix,
	setIgnorePrefix,
	isSavingSettings,
	onSaveSettings,
	workspaceInfo,
	editUserUrl,
}: IngestionSettingsTabProps ) {
	if ( ! isConfigured ) {
		return (
			<Card className="newspack-rolling-coverage-slack-settings__card">
				<CardHeader>
					<HStack alignment="space-between" justify="space-between">
						<h2>
							{ __(
								'Global Settings',
								'newspack-rolling-coverage'
							) }
						</h2>
					</HStack>
				</CardHeader>
				<CardBody>
					<p>
						{ __(
							'Please connect to Slack first.',
							'newspack-rolling-coverage'
						) }
					</p>
				</CardBody>
			</Card>
		);
	}

	return (
		<Card className="newspack-rolling-coverage-slack-settings__card">
			<CardHeader>
				<HStack alignment="space-between" justify="space-between">
					<h2>
						{ __( 'Global Settings', 'newspack-rolling-coverage' ) }
					</h2>
				</HStack>
			</CardHeader>
			<CardBody>
				<VStack spacing={ 4 }>
					<BotUserSection
						botUser={ workspaceInfo?.bot_user }
						editUserUrl={ editUserUrl }
					/>
					<hr className="newspack-rolling-coverage-slack-settings__divider" />
					<div className="newspack-rolling-coverage-slack-settings__section">
						<h3 className="newspack-rolling-coverage-slack-settings__section-title">
							{ __(
								'Ingestion Settings',
								'newspack-rolling-coverage'
							) }
						</h3>
						<TextControl
							label={ __(
								'Ignore Prefix',
								'newspack-rolling-coverage'
							) }
							value={ ignorePrefix }
							onChange={ setIgnorePrefix }
							help={ __(
								'Messages starting with this prefix are ignored during ingestion.',
								'newspack-rolling-coverage'
							) }
						/>
					</div>
				</VStack>
			</CardBody>
			<CardFooter>
				<HStack justify="space-between">
					<Button
						variant="secondary"
						onClick={ onSaveSettings }
						isBusy={ isSavingSettings }
						disabled={ isSavingSettings }
					>
						{ __( 'Save Settings', 'newspack-rolling-coverage' ) }
					</Button>
				</HStack>
			</CardFooter>
		</Card>
	);
}

export { IngestionSettingsTab };
