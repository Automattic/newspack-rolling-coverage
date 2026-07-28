/**
 * Internal dependencies
 */
import type { FollowEditorConfig, OneSignalApi } from './types';

// Augment Window with the config objects injected by wp_localize_script, and
// third-party globals.
declare global {
	interface Window {
		newspackRollingCoverageFollow?: FollowEditorConfig;
		// OneSignal Web SDK's deferred-callback queue.
		OneSignalDeferred?: Array< ( os: OneSignalApi ) => void >;
	}
}

const config = window.newspackRollingCoverageFollow;

if ( ! config ) {
	throw new Error(
		'Coverage Follow block config not found. Ensure PHP localization is properly enqueued.'
	);
}

const {
	onesignalInstalled: ONESIGNAL_INSTALLED,
	onesignalV3Active: ONESIGNAL_V3_ACTIVE,
	onesignalConfigured: ONESIGNAL_CONFIGURED,
} = config;

export { ONESIGNAL_INSTALLED, ONESIGNAL_V3_ACTIVE, ONESIGNAL_CONFIGURED };
