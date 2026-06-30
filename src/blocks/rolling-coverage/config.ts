/**
 * Internal dependencies
 */
import type { BlockConfig } from './types';

// Augment Window with the config object injected by wp_localize_script.
declare global {
	interface Window {
		newspackRollingCoverageBlock?: BlockConfig;
	}
}

const config = window.newspackRollingCoverageBlock;

if ( ! config ) {
	throw new Error(
		'Rolling Coverage block config not found. Ensure PHP localization is properly enqueued.'
	);
}

const {
	liveblogsRestBase: LIVEBLOGS_REST_BASE,
	statusMetaKey: STATUS_META_KEY,
	entriesPreviewRestBase: ENTRIES_PREVIEW_REST_BASE,
} = config;

export { LIVEBLOGS_REST_BASE, STATUS_META_KEY, ENTRIES_PREVIEW_REST_BASE };
