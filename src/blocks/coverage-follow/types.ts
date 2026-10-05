/**
 * TypeScript types for the Coverage Follow block.
 */

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

declare global {
	interface Window {
		newspackCoverageFollowBlock?: CoverageFollowConfig;
		OneSignalDeferred?: Array< ( os: OneSignalApi ) => void >;
	}
}

/**
 * Attributes saved on the Follow Coverage block.
 */
type CoverageFollowAttributes = {
	coverageId: number;
};

/**
 * Editor config localized by Coverage_Follow_Block.
 */
interface CoverageFollowConfig {
	onesignalConfigured: boolean;
	sourceEntryField: string;
	statusMetaKey: string;
	taxonomySlug: string;
}

export type { OneSignalApi, CoverageFollowAttributes, CoverageFollowConfig };
