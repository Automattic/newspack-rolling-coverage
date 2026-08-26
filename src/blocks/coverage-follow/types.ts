/**
 * TypeScript types for the Coverage Follow block.
 */

/**
 * Config localised by wp_localize_script in Coverage_Follow_Block::register_block().
 */
interface FollowEditorConfig {
	onesignalInstalled: boolean;
	onesignalV3Active: boolean;
	onesignalConfigured: boolean;
}

/**
 * Minimal shape of the OneSignal Web SDK surface used by view.ts, passed
 * into each OneSignalDeferred callback.
 */
interface OneSignalApi {
	Notifications: {
		permission: boolean;
		isPushSupported: () => boolean;
		requestPermission: () => Promise< void >;
		addEventListener: (
			event: 'permissionChange',
			listener: ( permission: boolean ) => void
		) => void;
		removeEventListener: (
			event: 'permissionChange',
			listener: ( permission: boolean ) => void
		) => void;
	};
	User: {
		addTag: ( key: string, value: string ) => void;
		removeTag: ( key: string ) => void;
		getTags: () => Record< string, string >;
	};
}

export type { FollowEditorConfig, OneSignalApi };
