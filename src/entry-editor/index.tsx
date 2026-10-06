/**
 * External dependencies
 */
import type { ComponentType, ReactNode } from 'react';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { registerPlugin } from '@wordpress/plugins';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import {
	PushNotificationsControl,
	useCanOptInToNotify,
} from '../admin/components/push-notifications-control';

// The editor's JS components carry inferred types that mark optional props
// as required.
const DocumentSettingPanel =
	PluginDocumentSettingPanel as unknown as ComponentType< {
		name: string;
		title: string;
		children: ReactNode;
	} >;

/**
 * The entry editor's Push Notifications panel.
 */
function PushNotificationsPanel() {
	const canOptIn = useCanOptInToNotify();

	if ( ! canOptIn ) {
		return null;
	}

	return (
		<DocumentSettingPanel
			name="push-notifications"
			title={ __( 'Push Notifications', 'newspack-rolling-coverage' ) }
		>
			<PushNotificationsControl />
		</DocumentSettingPanel>
	);
}

registerPlugin( 'newspack-rolling-coverage-push-notifications', {
	render: PushNotificationsPanel,
} );
