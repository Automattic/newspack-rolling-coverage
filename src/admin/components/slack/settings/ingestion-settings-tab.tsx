/**
 * External dependencies
 */
import { TextControl } from '@wordpress/components';
import { Stack } from '@wordpress/ui';
import { __ } from '@wordpress/i18n';
import Grid from 'newspack-components/dist/esm/grid';
import Divider from 'newspack-components/dist/esm/divider';
import SectionHeader from 'newspack-components/dist/esm/section-header';

/**
 * Internal dependencies
 */
import type {
	IngestionSettingsTabProps,
	SlackSettingsInfo,
} from '../../../types';
import { BotUserSection } from './bot-user-section';

/**
 * Renders the Settings tab: the message ignore prefix and the WordPress bot
 * user that authors ingested entries. Saving happens from the page header.
 *
 * @param {Object}                   props                 - Component props.
 * @param {string}                   props.ignorePrefix    - The ignore-prefix setting value.
 * @param {(v: string) => void}      props.setIgnorePrefix - Ignore prefix setter.
 * @param {SlackSettingsInfo | null} props.workspaceInfo   - Fetched workspace settings, or null.
 * @param {string}                   props.editUserUrl     - Base admin URL for editing a WordPress user.
 */
function IngestionSettingsTab( {
	ignorePrefix,
	setIgnorePrefix,
	workspaceInfo,
	editUserUrl,
}: IngestionSettingsTabProps ) {
	return (
		<>
			<Grid columns={ 2 } gutter={ 32 } noMargin>
				<SectionHeader
					noMargin
					heading={ 2 }
					title={ __( 'Ingestion', 'newspack-rolling-coverage' ) }
					description={ __(
						'Choose which Slack messages become entries.',
						'newspack-rolling-coverage'
					) }
				/>
				<Stack direction="column" gap="xl">
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
				</Stack>
			</Grid>
			<Divider alignment="full-width" variant="tertiary" />
			<Grid columns={ 2 } gutter={ 32 } noMargin>
				<SectionHeader
					noMargin
					heading={ 2 }
					title={ __( 'Bot User', 'newspack-rolling-coverage' ) }
					description={ __(
						'This WordPress user is created automatically and is the author of every entry ingested from Slack.',
						'newspack-rolling-coverage'
					) }
				/>
				<BotUserSection
					botUser={ workspaceInfo?.bot_user }
					editUserUrl={ editUserUrl }
				/>
			</Grid>
		</>
	);
}

export { IngestionSettingsTab };
