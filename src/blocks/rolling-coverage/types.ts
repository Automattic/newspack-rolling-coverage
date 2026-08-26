/**
 * TypeScript types for the Rolling Coverage block.
 */

/**
 * Internal dependencies
 */
import type { EVENTS } from './analytics';

/**
 * A coverage term, as shown in the editor's coverage combobox.
 */
interface CoverageOption {
	value: string;
	label: string;
	status: string;
	canonicalUrl: string;
}

/**
 * Attributes saved on the Rolling Coverage block.
 */
interface RollingCoverageAttributes {
	coverageId: number;
	pollInterval: number;
	entriesPerPage: number;
	enableAds: boolean;
	adsInterval: number;
	[ key: string ]: unknown;
}

/**
 * Result of the editor's "Apply" status action, shown as a Notice.
 */
interface ApplyNotice {
	type: 'success' | 'error';
	message: string;
}

/**
 * Props for the Rolling Coverage block's Edit component.
 */
interface EditProps {
	clientId: string;
	attributes: RollingCoverageAttributes;
	setAttributes: ( attrs: Partial< RollingCoverageAttributes > ) => void;
}

/**
 * Config localised by wp_localize_script in Rolling_Coverage_Block::register_block().
 */
interface BlockConfig {
	coveragesRestBase: string;
	statusMetaKey: string;
	canonicalUrlMetaKey: string;
	entriesPreviewRestBase: string;
	aiEndpoint: string;
	aiAvailable: boolean;
	newspackAdsAvailable: boolean;
	newspackAdsPlacementEnabled: boolean;
}

/**
 * Frontend config localised by wp_localize_script in
 * Rolling_Coverage_Block::localize_frontend_config().
 */
interface FrontendConfig {
	readerTrackingEnabled: string;
	siteKitGa4Enabled: string;
}

/**
 * Arbitrary event parameters attached to a tracked event.
 */
type EventParams = Record< string, unknown >;

/**
 * Analytics event names emitted by the rolling coverage frontend, derived
 * from the EVENTS object in analytics.ts.
 */
type EventName = ( typeof EVENTS )[ keyof typeof EVENTS ];

/**
 * Block context for a single real entry, used by the editor's per-entry
 * template preview (see edit.tsx).
 */
interface EntryContext {
	postId: number;
	postType: string;
	queryId: number;
}

/**
 * A single entry in a poll response.
 */
interface PollEntry {
	id: number;
	html: string;
	type: 'insert' | 'update';
	adHtml: string | null;
	adSlot: AdSlot | null;
}

/**
 * REST response containing newly-published or edited entries.
 */
interface PollResponse {
	entries: PollEntry[];
	cursor: string;
	overflow: boolean;
	polledCount: number;
}

/**
 * A single GPT ad slot: container ID, ad unit path, sizes, targeting, and
 * the bounds/fixed-height data needed to size it against its real container.
 */
interface AdSlot {
	containerId: string;
	path: string;
	sizes: number[][];
	fluid: boolean;
	targeting: Record< string, string | string[] >;
	sizeMap: Record< string, number[][] >;
	boundsSelectors: string[];
	boundsBleed: number;
	fixedHeight: {
		active: boolean;
		useMaxHeight: boolean;
		maxHeight: number;
	};
}

/**
 * REST response containing a page of older entries.
 */
interface PageResponse {
	html: string;
	before: string | null;
	hasMore: boolean;
	count: number;
	adSlots: AdSlot[];
}

/**
 * A new entry paired with the ad slot that follows it, if any, and the
 * already-parsed element for that ad's wrapper markup.
 */
type PendingEntry = {
	el: HTMLElement;
	adSlot: AdSlot | null;
	adEl: HTMLElement | null;
};

/**
 * Shape of a single InnerBlocks template tuple: [ blockName, attributes,
 * innerBlocksTemplate ].
 */
type TemplateItem = [ string, Record< string, unknown >?, TemplateItem[]? ];

/**
 * A per-entry template's block instances, as read from the block-editor
 * store.
 */
type TemplateBlocks = { name: string; [ key: string ]: unknown }[];

export type {
	CoverageOption,
	RollingCoverageAttributes,
	ApplyNotice,
	EditProps,
	BlockConfig,
	FrontendConfig,
	EventParams,
	EventName,
	EntryContext,
	PollEntry,
	PollResponse,
	AdSlot,
	PageResponse,
	PendingEntry,
	TemplateItem,
	TemplateBlocks,
};
