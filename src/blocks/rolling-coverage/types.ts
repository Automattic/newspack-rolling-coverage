/**
 * TypeScript types for the Rolling Coverage block.
 */

/**
 * A coverage term, as shown in the editor's coverage combobox.
 */
interface CoverageOption {
	value: string;
	label: string;
	status: 'active' | 'paused' | 'archived' | string;
}

/**
 * Attributes saved on the Rolling Coverage block.
 */
interface RollingCoverageAttributes {
	coverageId: number;
	pollInterval: number;
	entriesPerPage: number;
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
	entriesPreviewRestBase: string;
}

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
 * REST response containing newly-published entries.
 */
interface PollResponse {
	html: string;
	since: string;
	count: number;
}

/**
 * REST response containing a page of older entries.
 */
interface PageResponse {
	html: string;
	before: string | null;
	hasMore: boolean;
	count: number;
}

/**
 * Shape of a single InnerBlocks template tuple: [ blockName, attributes,
 * innerBlocksTemplate ].
 */
type TemplateItem = [ string, Record< string, unknown >?, TemplateItem[]? ];

/**
 * A per-entry template's block instances, as read from the block-editor
 * store.
 */
type TemplateBlocks = object[];

export type {
	CoverageOption,
	RollingCoverageAttributes,
	ApplyNotice,
	EditProps,
	BlockConfig,
	EntryContext,
	PollResponse,
	PageResponse,
	TemplateItem,
	TemplateBlocks,
};
