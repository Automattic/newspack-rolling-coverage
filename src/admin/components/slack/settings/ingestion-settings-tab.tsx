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
import type { IngestionSettingsTabProps } from '../../../types';

/**
 * Renders the Ingestion Settings tab. Holds per-workspace ingestion options
 * that don't belong on the Credentials or Channel Mappings tabs. Today this is
 * the message ignore prefix. When Slack is not yet connected, shows a prompt
 * to connect first.
 *
 * @param {Object}              props                  - Component props.
 * @param {boolean}             props.isConfigured     - Whether Slack is currently connected.
 * @param {string}              props.ignorePrefix     - The ignore-prefix setting value.
 * @param {(v: string) => void} props.setIgnorePrefix  - Ignore prefix setter.
 * @param {boolean}             props.isSavingSettings - Whether the save-settings request is in flight.
 * @param {() => void}          props.onSaveSettings   - Save Settings handler.
 */
function IngestionSettingsTab( {
	isConfigured,
	ignorePrefix,
	setIgnorePrefix,
	isSavingSettings,
	onSaveSettings,
}: IngestionSettingsTabProps ) {
	return (
		<Card className="newspack-rolling-coverage-slack-settings__card">
			<CardHeader>
				<HStack alignment="space-between" justify="space-between">
					<h2>
						{ __(
							'Ingestion Settings',
							'newspack-rolling-coverage'
						) }
					</h2>
				</HStack>
			</CardHeader>
			<CardBody>
				{ ! isConfigured ? (
					<p>
						{ __(
							'Please connect to Slack first.',
							'newspack-rolling-coverage'
						) }
					</p>
				) : (
					<VStack spacing={ 4 }>
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
					</VStack>
				) }
			</CardBody>
			{ isConfigured && (
				<CardFooter>
					<HStack justify="space-between">
						<Button
							variant="secondary"
							onClick={ onSaveSettings }
							isBusy={ isSavingSettings }
							disabled={ isSavingSettings }
						>
							{ __(
								'Save Settings',
								'newspack-rolling-coverage'
							) }
						</Button>
					</HStack>
				</CardFooter>
			) }
		</Card>
	);
}

export { IngestionSettingsTab };
