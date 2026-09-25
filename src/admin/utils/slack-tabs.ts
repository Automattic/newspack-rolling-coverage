/**
 * External dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { AdminTab } from '../types';

const SLACK_TABS: AdminTab[] = [
	{
		name: 'channels',
		title: __( 'Channel Mappings', 'newspack-rolling-coverage' ),
	},
	{ name: 'monitor', title: __( 'Monitor', 'newspack-rolling-coverage' ) },
	{ name: 'settings', title: __( 'Settings', 'newspack-rolling-coverage' ) },
];

export { SLACK_TABS };
