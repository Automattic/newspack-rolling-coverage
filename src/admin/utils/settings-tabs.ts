/**
 * External dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { AdminConfig, AdminTab } from '../types';

const SETTINGS_TABS: AdminTab[] = [
	{ name: 'general', title: __( 'General', 'newspack-rolling-coverage' ) },
	{ name: 'slack', title: __( 'Slack', 'newspack-rolling-coverage' ) },
	{ name: 'ai', title: __( 'AI', 'newspack-rolling-coverage' ) },
];

/**
 * The Settings tabs the current user can open: General needs
 * `edit_others_posts`, Slack and AI need `manage_options`, as their REST
 * routes do.
 *
 * @param config Admin config with the user's capabilities.
 * @return Tabs in display order.
 */
function getSettingsTabs( config: AdminConfig ): AdminTab[] {
	return SETTINGS_TABS.filter( ( { name } ) =>
		name === 'general'
			? config.capabilities.canManageSettings
			: config.capabilities.canManageOptions
	);
}

export { getSettingsTabs };
