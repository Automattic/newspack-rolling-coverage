/**
 * External dependencies
 */
import type { ComponentType } from 'react';
import { PanelBody } from '@wordpress/components';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { getPlugin } from '@wordpress/plugins';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import {
	PushNotificationsControl,
	useCanOptInToNotify,
} from './push-notifications-control';

/**
 * Editor plugins whose document panels Quick Edit shows. The admin page
 * loads every plugin's editor scripts for its blocks, so the rest stay in
 * the full editor to keep Quick Edit light.
 */
const ALLOWED_PLUGINS = [ 'plugin-coauthors-document-setting' ];

const DocumentSettingPanelSlot = (
	PluginDocumentSettingPanel as unknown as { Slot: ComponentType }
 ).Slot;

/**
 * The panels below the Entry tab's summary: Push Notifications while the
 * entry can still opt in, and the allowed editor plugins' panels, such as
 * Co-Authors Plus's Authors.
 *
 * @param {Object}  props           Component props.
 * @param {boolean} props.canNotify Whether push notifications are set up.
 */
function QuickEditSettingsPanels( { canNotify }: { canNotify: boolean } ) {
	const canOptIn = useCanOptInToNotify();
	const plugins = ALLOWED_PLUGINS.map( ( name ) => getPlugin( name ) ).filter(
		( plugin ): plugin is NonNullable< typeof plugin > => Boolean( plugin )
	);

	return (
		<>
			{ canNotify && canOptIn && (
				<PanelBody
					title={ __(
						'Push Notifications',
						'newspack-rolling-coverage'
					) }
				>
					<PushNotificationsControl />
				</PanelBody>
			) }
			{ plugins.map( ( { name, render: Render } ) => (
				<Render key={ name } />
			) ) }
			<DocumentSettingPanelSlot />
		</>
	);
}

export { QuickEditSettingsPanels };
