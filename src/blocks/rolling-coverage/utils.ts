/**
 * REST helpers for the Rolling Coverage block.
 */

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import {
	serialize,
	createBlock,
	createBlocksFromInnerBlocksTemplate,
} from '@wordpress/blocks';
import { addQueryArgs, getQueryArg } from '@wordpress/url';

/**
 * Internal dependencies
 */
import type { CoverageOption, EntryContext, TemplateItem } from './types';
import {
	COVERAGES_REST_BASE,
	STATUS_META_KEY,
	CANONICAL_URL_META_KEY,
	ADS_DISABLED_META_KEY,
	ENTRIES_PREVIEW_REST_BASE,
	AI_ENDPOINT,
	LAYOUT_IDS,
	LAYOUTS_REST_BASE,
	ADMIN_URL,
	IS_BLOCK_THEME,
	CAN_EDIT_THEME_OPTIONS,
	LAYOUT_CATEGORY_ID,
} from './config';
import { BLOCK_NAME } from './layout';
import type { BuiltInLayoutSlug } from './layouts';

const layoutIds: Record< BuiltInLayoutSlug, number > = {
	default: Number( LAYOUT_IDS.default ) || 0,
	stream: Number( LAYOUT_IDS.stream ) || 0,
	rail: Number( LAYOUT_IDS.rail ) || 0,
	clock: Number( LAYOUT_IDS.clock ) || 0,
	margin: Number( LAYOUT_IDS.margin ) || 0,
	minute: Number( LAYOUT_IDS.minute ) || 0,
	byline: Number( LAYOUT_IDS.byline ) || 0,
	ticker: Number( LAYOUT_IDS.ticker ) || 0,
	split: Number( LAYOUT_IDS.split ) || 0,
	wire: Number( LAYOUT_IDS.wire ) || 0,
	digest: Number( LAYOUT_IDS.digest ) || 0,
	flash: Number( LAYOUT_IDS.flash ) || 0,
};
let layoutCategoryId = Number( LAYOUT_CATEGORY_ID ) || 0;
const pendingLayouts: Partial<
	Record< BuiltInLayoutSlug, Promise< number > >
> = {};

/**
 * Searches coverage terms by name.
 *
 * @param {string} search Search string.
 * @return {Promise<CoverageOption[]>} Matching coverages.
 */
async function searchCoverages( search: string ): Promise< CoverageOption[] > {
	try {
		const terms = await apiFetch< Array< Record< string, unknown > > >( {
			url: `${ COVERAGES_REST_BASE }?per_page=50&search=${ encodeURIComponent(
				search
			) }`,
		} );

		return terms
			.map( ( term ) => ( {
				value: String( term.id ),
				label: String( term.name ),
				status:
					( ( term.meta as Record< string, unknown > )?.[
						STATUS_META_KEY
					] as string ) || 'active',
				canonicalUrl:
					( ( term.meta as Record< string, unknown > )?.[
						CANONICAL_URL_META_KEY
					] as string ) || '',
				adsDisabled: Boolean(
					( term.meta as Record< string, unknown > )?.[
						ADS_DISABLED_META_KEY
					]
				),
			} ) )
			.filter( ( term ) => term.status !== 'trash' );
	} catch ( error ) {
		return [];
	}
}

/**
 * Fetches a single coverage term by ID.
 *
 * @param {number} id Coverage term ID.
 * @return {Promise<CoverageOption|null>} The coverage, or null if not found.
 */
async function getCoverage( id: number ): Promise< CoverageOption | null > {
	if ( ! id ) {
		return null;
	}

	try {
		const term = await apiFetch< Record< string, unknown > >( {
			url: `${ COVERAGES_REST_BASE }/${ id }`,
		} );

		return {
			value: String( term.id ),
			label: String( term.name ),
			status:
				( ( term.meta as Record< string, unknown > )?.[
					STATUS_META_KEY
				] as string ) || 'active',
			canonicalUrl:
				( ( term.meta as Record< string, unknown > )?.[
					CANONICAL_URL_META_KEY
				] as string ) || '',
			adsDisabled: Boolean(
				( term.meta as Record< string, unknown > )?.[
					ADS_DISABLED_META_KEY
				]
			),
		};
	} catch ( error ) {
		return null;
	}
}

/**
 * Updates a coverage term's canonical URL, used to build push-notification
 * links for its entries.
 *
 * @param {number} id  Coverage term ID.
 * @param {string} url New canonical URL value.
 * @return {Promise<boolean>} Whether the update succeeded.
 */
async function updateCoverageCanonicalUrl(
	id: number,
	url: string
): Promise< boolean > {
	try {
		await apiFetch( {
			url: `${ COVERAGES_REST_BASE }/${ id }`,
			method: 'POST',
			data: { meta: { [ CANONICAL_URL_META_KEY ]: url } },
		} );
		return true;
	} catch ( error ) {
		return false;
	}
}

/**
 * Fetches the IDs of a coverage's current published entries, newest first,
 * for the editor's per-entry template preview.
 *
 * @param {number}  coverageId Coverage term ID.
 * @param {number}  perPage    Maximum number of entries to fetch.
 * @param {boolean} latestOnly Whether the feed is capped, which ignores pinning.
 * @return {Promise<EntryContext[]>} Up to `perPage` entries, newest first.
 */
async function fetchEntryPreviewContexts(
	coverageId: number,
	perPage: number,
	latestOnly = false
): Promise< EntryContext[] > {
	if ( ! coverageId ) {
		return [];
	}

	try {
		const entries = await apiFetch<
			Array< {
				id: number;
				type: string;
				pinned?: boolean;
				hasBreakout?: boolean;
				hasTitle?: boolean;
				hidesByline?: boolean;
				fallbackTitle?: string;
			} >
		>( {
			url: `${ ENTRIES_PREVIEW_REST_BASE }/${ coverageId }/entries-preview?per_page=${ perPage }${
				latestOnly ? '&latest_only=1' : ''
			}`,
		} );

		return entries.map( ( entry ) => ( {
			postId: entry.id,
			postType: entry.type,
			queryId: 0,
			pinned: Boolean( entry.pinned ),
			hasBreakout: Boolean( entry.hasBreakout ),
			hasTitle: entry.hasTitle !== false,
			hidesByline: Boolean( entry.hidesByline ),
			fallbackTitle: entry.fallbackTitle ?? '',
		} ) );
	} catch ( error ) {
		return [];
	}
}

/**
 * Generates key takeaways for a coverage via the AI REST endpoint.
 *
 * Prompts are read from the server-side AI_Settings (manage_options);
 * the client does not send or override them.
 *
 * @param {number} coverageId Coverage term ID.
 * @return {Promise<{success: boolean, result?: string, error?: string}>} Result with generated text or error.
 */
async function generateKeyTakeaways(
	coverageId: number
): Promise< { success: boolean; result?: string; error?: string } > {
	try {
		const response = await apiFetch< { result: string } >( {
			url: `${ AI_ENDPOINT }/${ coverageId }/generate-key-takeaways`,
			method: 'POST',
		} );
		return { success: true, result: response.result };
	} catch ( error ) {
		const err = error as {
			message?: string;
			data?: { message?: string };
		};
		const message = err?.message ?? err?.data?.message ?? 'Unknown error';
		return { success: false, error: message };
	}
}

/**
 * The ID of a built-in layout's shared pattern, or 0 when there isn't one yet.
 *
 * @param {BuiltInLayoutSlug} slug The built-in layout's slug.
 * @return {number} The layout's pattern ID.
 */
function getLayoutId( slug: BuiltInLayoutSlug ): number {
	return layoutIds[ slug ];
}

/**
 * The layout pattern category's ID, or 0 until a built-in layout creates it.
 *
 * @return {number} The category's term ID.
 */
function getLayoutCategoryId(): number {
	return layoutCategoryId;
}

/**
 * Creates a built-in layout's shared pattern, or returns the existing one if
 * another story created it first. Concurrent calls for a layout share one
 * request.
 *
 * @param {BuiltInLayoutSlug} slug     The built-in layout's slug.
 * @param {Function}          template Returns the layout's inner blocks template.
 * @return {Promise<number>} The layout's pattern ID. Rejects on failure.
 */
function createLayout(
	slug: BuiltInLayoutSlug,
	template: () => TemplateItem[]
): Promise< number > {
	const pending = pendingLayouts[ slug ];

	if ( pending ) {
		return pending;
	}

	const content = serialize(
		createBlock(
			BLOCK_NAME,
			{},
			createBlocksFromInnerBlocksTemplate( template() )
		)
	);
	const request = apiFetch< { id: number; categoryId?: number } >( {
		url: `${ LAYOUTS_REST_BASE }/${ slug }`,
		method: 'POST',
		data: { content },
	} )
		.then( ( response ) => {
			if ( ! response?.id ) {
				throw new Error( 'Missing layout ID.' );
			}
			layoutIds[ slug ] = response.id;
			layoutCategoryId =
				Number( response.categoryId ) || layoutCategoryId;
			return response.id;
		} )
		.finally( () => {
			delete pendingLayouts[ slug ];
		} );

	pendingLayouts[ slug ] = request;

	return request;
}

const PREVIEW_COVERAGE_ARG = 'rolling_coverage_preview';

/**
 * The coverage the layout editor was opened from, read once on load because
 * the Site Editor rewrites its URL as it navigates.
 */
const PREVIEW_COVERAGE_ID =
	Number( getQueryArg( window.location.href, PREVIEW_COVERAGE_ARG ) ) || 0;

/**
 * The admin URL that edits a layout pattern: the Site Editor on block
 * themes for users who can open it, the post editor otherwise.
 *
 * @param {number} layoutId   The layout's pattern ID.
 * @param {number} coverageId The coverage whose entries the layout previews.
 * @return {string} The edit URL.
 */
function getLayoutEditUrl( layoutId: number, coverageId: number ): string {
	const preview = coverageId ? { [ PREVIEW_COVERAGE_ARG ]: coverageId } : {};

	if ( IS_BLOCK_THEME && CAN_EDIT_THEME_OPTIONS ) {
		return addQueryArgs( ADMIN_URL + 'site-editor.php', {
			p: '/wp_block/' + layoutId,
			canvas: 'edit',
			...preview,
		} );
	}

	return addQueryArgs( ADMIN_URL + 'post.php', {
		post: layoutId,
		action: 'edit',
		...preview,
	} );
}

export {
	searchCoverages,
	getCoverage,
	updateCoverageCanonicalUrl,
	fetchEntryPreviewContexts,
	generateKeyTakeaways,
	getLayoutId,
	getLayoutCategoryId,
	createLayout,
	getLayoutEditUrl,
	PREVIEW_COVERAGE_ID,
};
