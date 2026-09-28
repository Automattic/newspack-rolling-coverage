/**
 * External dependencies
 */
import { Button, TextControl } from '@wordpress/components';
import { Stack } from '@wordpress/ui';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { useRef } from '@wordpress/element';
import type { MouseEvent } from 'react';
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
import { useAdminContext } from '../../../hooks/useAdminContext';

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
	const { supportsHandoff } = useAdminContext();

	// With newspack-plugin active, a handoff gives the profile screen a
	// banner that brings the admin back here; without it, the link is plain.
	const isHandingOff = useRef( false );
	const handleEditUser = ( event: MouseEvent< HTMLAnchorElement > ) => {
		const isModifiedClick =
			event.metaKey ||
			event.ctrlKey ||
			event.shiftKey ||
			event.altKey ||
			event.button !== 0;
		if ( ! supportsHandoff || isModifiedClick ) {
			return;
		}
		event.preventDefault();
		if ( isHandingOff.current ) {
			return;
		}
		isHandingOff.current = true;
		const destinationUrl = event.currentTarget.href;
		apiFetch< { HandoffLink: string } >( {
			path: '/newspack/v1/handoff',
			method: 'POST',
			data: {
				destinationUrl,
				handoffReturnUrl: window.location.href,
				bannerText: __(
					'Return to the Slack connection after editing the bot user.',
					'newspack-rolling-coverage'
				),
				bannerButtonText: __(
					'Back to Slack Connection',
					'newspack-rolling-coverage'
				),
			},
		} )
			.then( ( response ) => {
				window.location.href = response.HandoffLink;
			} )
			.catch( () => {
				window.location.href = destinationUrl;
			} );
	};

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
				<Stack direction="column" gap="xl" align="flex-start">
					<SectionHeader
						noMargin
						heading={ 2 }
						title={ __( 'Bot User', 'newspack-rolling-coverage' ) }
						description={ __(
							'This WordPress user is created automatically and is the author of every entry ingested from Slack. Change its display name or avatar from its WordPress profile.',
							'newspack-rolling-coverage'
						) }
					/>
					{ workspaceInfo?.bot_user && (
						<Button
							variant="secondary"
							href={ `${ editUserUrl }?user_id=${ workspaceInfo.bot_user.id }` }
							onClick={ handleEditUser }
						>
							{ __( 'Edit User', 'newspack-rolling-coverage' ) }
						</Button>
					) }
				</Stack>
				<BotUserSection botUser={ workspaceInfo?.bot_user } />
			</Grid>
		</>
	);
}

export { IngestionSettingsTab };
