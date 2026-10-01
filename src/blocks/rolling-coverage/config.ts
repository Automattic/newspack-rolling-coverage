/**
 * Internal dependencies
 */
import type { FrontendConfig, BlockConfig } from './types';

// Augment Window with globals injected by wp_localize_script and external scripts.
declare global {
	interface Window {
		newspackRollingCoverageBlock?: BlockConfig;
		newspackRollingCoverageFrontend?: FrontendConfig;
		dataLayer?: Record< string, unknown >[];
		gtag?: ( ...args: unknown[] ) => void;
		// eslint-disable-next-line @typescript-eslint/no-explicit-any
		googletag?: any;
	}
}

const config = window.newspackRollingCoverageBlock;

if ( ! config ) {
	throw new Error(
		'Rolling Coverage block config not found. Ensure PHP localization is properly enqueued.'
	);
}

const {
	coveragesRestBase: COVERAGES_REST_BASE,
	statusMetaKey: STATUS_META_KEY,
	canonicalUrlMetaKey: CANONICAL_URL_META_KEY,
	onesignalConfigured: ONESIGNAL_CONFIGURED,
	adsDisabledMetaKey: ADS_DISABLED_META_KEY,
	entriesPreviewRestBase: ENTRIES_PREVIEW_REST_BASE,
	aiEndpoint: AI_ENDPOINT,
	aiAvailable: AI_AVAILABLE,
	newspackAdsAvailable: NEWSPACK_ADS_AVAILABLE,
	newspackAdsPlacementEnabled: NEWSPACK_ADS_PLACEMENT_ENABLED,
	layoutIds: LAYOUT_IDS,
	layoutsRestBase: LAYOUTS_REST_BASE,
	adminUrl: ADMIN_URL,
	isBlockTheme: IS_BLOCK_THEME,
	canEditThemeOptions: CAN_EDIT_THEME_OPTIONS,
	layoutCategoryId: LAYOUT_CATEGORY_ID,
	entryPostType: ENTRY_POST_TYPE,
} = config;

export {
	COVERAGES_REST_BASE,
	STATUS_META_KEY,
	ADS_DISABLED_META_KEY,
	ENTRIES_PREVIEW_REST_BASE,
	AI_ENDPOINT,
	AI_AVAILABLE,
	NEWSPACK_ADS_AVAILABLE,
	NEWSPACK_ADS_PLACEMENT_ENABLED,
	CANONICAL_URL_META_KEY,
	ONESIGNAL_CONFIGURED,
	LAYOUT_IDS,
	LAYOUTS_REST_BASE,
	ADMIN_URL,
	IS_BLOCK_THEME,
	CAN_EDIT_THEME_OPTIONS,
	LAYOUT_CATEGORY_ID,
	ENTRY_POST_TYPE,
};
