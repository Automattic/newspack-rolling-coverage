/**
 * Internal dependencies
 */
import type { AnalyticsConfig, BlockConfig } from './types';

// Augment Window with globals injected by wp_localize_script and external scripts.
declare global {
	interface Window {
		newspackRollingCoverageBlock?: BlockConfig;
		newspackRollingCoverageAnalytics?: AnalyticsConfig;
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
	entriesPreviewRestBase: ENTRIES_PREVIEW_REST_BASE,
	aiEndpoint: AI_ENDPOINT,
	aiAvailable: AI_AVAILABLE,
	newspackAdsAvailable: NEWSPACK_ADS_AVAILABLE,
	newspackAdsPlacementEnabled: NEWSPACK_ADS_PLACEMENT_ENABLED,
} = config;

export {
	COVERAGES_REST_BASE,
	STATUS_META_KEY,
	ENTRIES_PREVIEW_REST_BASE,
	AI_ENDPOINT,
	AI_AVAILABLE,
	NEWSPACK_ADS_AVAILABLE,
	NEWSPACK_ADS_PLACEMENT_ENABLED,
};
