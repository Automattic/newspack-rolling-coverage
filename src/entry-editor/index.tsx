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
import { BackToCoverage } from './back-to-coverage';
import {
	PushNotificationsControl,
	useCanOptInToNotify,
} from './push-notifications-control';

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

	if (
		! canOptIn ||
		! window.newspackRollingCoverageEntryEditor?.pushNotifications
	) {
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

function EntryEditor() {
	const data = window.newspackRollingCoverageEntryEditor;

	return (
		<>
			{ data && <BackToCoverage { ...data } /> }
			<PushNotificationsPanel />
		</>
	);
}

registerPlugin( 'newspack-rolling-coverage-entry-editor', {
	render: EntryEditor,
} );
