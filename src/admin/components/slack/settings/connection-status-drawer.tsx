/**
 * External dependencies
 */
import { Button } from '@wordpress/components';
import { Badge, Stack } from '@wordpress/ui';
import { __ } from '@wordpress/i18n';
import { Drawer } from 'newspack-components/dist/esm/drawer';

/**
 * Internal dependencies
 */
import type {
	ConnectionStatusDrawerProps,
	SlackSettingsInfo,
} from '../../../types';
import { DetailRow } from '../../detail-row';
import { CopyManifestButton } from './copy-manifest-button';

/**
 * Shows the connected Slack workspace's identity details (workspace name/ID,
 * masked bot token, Slack bot user ID) in a drawer opened from the page
 * header, with the app manifest for reinstalling and the Disconnect action.
 *
 * @param {Object}                   props               - Component props.
 * @param {boolean}                  props.isOpen        - Whether the drawer is open.
 * @param {() => void}               props.onClose       - Closes the drawer.
 * @param {SlackSettingsInfo | null} props.workspaceInfo - Fetched workspace settings, or null.
 * @param {string}                   props.manifestJson  - The Slack app manifest JSON string.
 * @param {() => void}               props.onDisconnect  - Asks to disconnect Slack.
 */
function ConnectionStatusDrawer( {
	isOpen,
	onClose,
	workspaceInfo,
	manifestJson,
	onDisconnect,
}: ConnectionStatusDrawerProps ) {
	return (
		<Drawer.Root isOpen={ isOpen } onRequestClose={ onClose }>
			<Drawer.Header>
				<Drawer.Title>
					{ __( 'Connection Status', 'newspack-rolling-coverage' ) }
				</Drawer.Title>
				<Drawer.CloseIcon />
			</Drawer.Header>
			<Drawer.Content>
				<Stack direction="column" gap="lg">
					<DetailRow
						label={ __( 'Status', 'newspack-rolling-coverage' ) }
					>
						<Badge intent="stable">
							{ __( 'Connected', 'newspack-rolling-coverage' ) }
						</Badge>
					</DetailRow>
					<DetailRow
						label={ __( 'Workspace', 'newspack-rolling-coverage' ) }
					>
						<span>
							{ workspaceInfo?.workspace_name ||
								__( '(unknown)', 'newspack-rolling-coverage' ) }
						</span>
					</DetailRow>
					<DetailRow
						label={ __(
							'Workspace ID',
							'newspack-rolling-coverage'
						) }
					>
						<code>{ workspaceInfo?.workspace_id || '—' }</code>
					</DetailRow>
					<DetailRow
						label={ __( 'Bot token', 'newspack-rolling-coverage' ) }
					>
						<code>{ workspaceInfo?.masked_token || '—' }</code>
					</DetailRow>
					<DetailRow
						label={ __(
							'Slack bot user ID',
							'newspack-rolling-coverage'
						) }
					>
						<code>{ workspaceInfo?.slack_bot_user_id || '—' }</code>
					</DetailRow>
				</Stack>
			</Drawer.Content>
			<Drawer.Footer>
				<CopyManifestButton
					manifestJson={ manifestJson }
					feedback="inline"
				/>
				<Button
					variant="secondary"
					isDestructive
					onClick={ onDisconnect }
				>
					{ __( 'Disconnect', 'newspack-rolling-coverage' ) }
				</Button>
			</Drawer.Footer>
		</Drawer.Root>
	);
}

export { ConnectionStatusDrawer };
