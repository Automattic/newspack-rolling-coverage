/**
 * WordPress dependencies
 */
import { store as blockEditorStore } from '@wordpress/block-editor';
import { select } from '@wordpress/data';
import { useCallback, useMemo } from '@wordpress/element';

/**
 * Internal dependencies
 */
import metadata from './block.json';
import {
	bulletinEntryTemplate,
	streamEntryTemplate,
	railEntryTemplate,
	clockEntryTemplate,
	marginEntryTemplate,
	minuteEntryTemplate,
	wireEntryTemplate,
	digestEntryTemplate,
	digestHeader,
	digestFooter,
	flashEntryTemplate,
	FLASH_FEED_STYLE,
	FLASH_FEED_LAYOUT,
	DIGEST_FEED_STYLE,
	allUpdatesLink,
	ENTRY_ALLOWED_BLOCKS,
	FOLLOW_BLOCK_NAME,
	STATUS_BLOCK_NAME,
	FOLLOW_TEMPLATE,
	feedTemplate,
	latestTemplate,
	layoutParts,
	withoutLatestButtons,
	withoutPinnedRow,
	withoutBreakoutLink,
	withLinkedTitle,
	withoutPinnedCard,
	withoutClosingSeparator,
	withShapedPinnedCard,
	withCenteredTitleRows,
	withoutPostTitle,
	hasPinnedCard,
	isPinnedCard,
	forEntryKind,
} from './template';
import type { EntryContext, TemplateBlocks, TemplateItem } from './types';

export const BLOCK_NAME = metadata.name;

export { FOLLOW_BLOCK_NAME };

/**
 * The slugs of every color in the editor's palette: the theme's, core's
 * default and the site's custom ones.
 *
 * @return {string[]} Color slugs.
 */
function paletteSlugs(): string[] {
	const settings = (
		select( blockEditorStore.name ) as unknown as {
			getSettings: () => {
				colors?: { slug: string }[];
				__experimentalFeatures?: {
					color?: { palette?: Record< string, { slug: string }[] > };
				};
			};
		}
	 ).getSettings();
	const origins = Object.values(
		settings.__experimentalFeatures?.color?.palette ?? {}
	);

	return [ ...origins.flat(), ...( settings.colors ?? [] ) ].map(
		( color ) => color.slug
	);
}

/**
 * The slugs of the theme's font sizes.
 *
 * @return {string[]} Font size slugs.
 */
function themeFontSizeSlugs(): string[] {
	const settings = (
		select( blockEditorStore.name ) as unknown as {
			getSettings: () => {
				__experimentalFeatures?: {
					typography?: {
						fontSizes?: { theme?: { slug: string }[] };
					};
				};
			};
		}
	 ).getSettings();

	return (
		settings.__experimentalFeatures?.typography?.fontSizes?.theme ?? []
	).map( ( size ) => size.slug );
}

/**
 * The Bulletin layout's inner-blocks template, the default: the Feed group,
 * holding the "Jump to Latest" button, in the colors the editor's palette
 * has for it, and the follow button at the top, then the per-entry blocks.
 *
 * @return {TemplateItem[]} The template.
 */
export function innerTemplate(): TemplateItem[] {
	return [
		feedTemplate( [
			latestTemplate( paletteSlugs() ),
			FOLLOW_TEMPLATE,
			...bulletinEntryTemplate( themeFontSizeSlugs() ),
		] ),
	];
}

/**
 * The Stream layout's inner-blocks template: the same Feed group and buttons
 * as the default, with a wider gap between untitled entries.
 *
 * @return {TemplateItem[]} The template.
 */
export function streamInnerTemplate(): TemplateItem[] {
	const slugs = paletteSlugs();

	return [
		feedTemplate(
			[
				latestTemplate( slugs ),
				FOLLOW_TEMPLATE,
				...streamEntryTemplate( slugs, themeFontSizeSlugs() ),
			],
			'var:preset|spacing|60'
		),
	];
}

/**
 * The Rail layout's inner-blocks template: the same Feed group and buttons
 * as the default, with each entry hanging off a timeline.
 *
 * @return {TemplateItem[]} The template.
 */
export function railInnerTemplate(): TemplateItem[] {
	return [
		feedTemplate( [
			latestTemplate( paletteSlugs() ),
			FOLLOW_TEMPLATE,
			...railEntryTemplate(),
		] ),
	];
}

/**
 * The Clock layout's inner-blocks template: the same Feed group and buttons
 * as the default, with each entry headed by the time it was posted.
 *
 * @return {TemplateItem[]} The template.
 */
export function clockInnerTemplate(): TemplateItem[] {
	const slugs = paletteSlugs();

	return [
		feedTemplate( [
			latestTemplate( slugs ),
			FOLLOW_TEMPLATE,
			...clockEntryTemplate( slugs, themeFontSizeSlugs() ),
		] ),
	];
}

/**
 * The Margin layout's inner-blocks template: the same Feed group and buttons
 * as the default, with each entry split into a margin and its content.
 *
 * @return {TemplateItem[]} The template.
 */
export function marginInnerTemplate(): TemplateItem[] {
	return [
		feedTemplate( [
			latestTemplate( paletteSlugs() ),
			FOLLOW_TEMPLATE,
			...marginEntryTemplate(),
		] ),
	];
}

/**
 * The Minute layout's inner-blocks template: the same Feed group and buttons
 * as the default, closer together, with each entry reduced to its content.
 *
 * @return {TemplateItem[]} The template.
 */
export function minuteInnerTemplate(): TemplateItem[] {
	return [
		feedTemplate(
			[
				latestTemplate( paletteSlugs() ),
				FOLLOW_TEMPLATE,
				...minuteEntryTemplate(),
			],
			'var:preset|spacing|30'
		),
	];
}

/**
 * The Wire layout's inner-blocks template: a narrow list of the latest
 * entries, with no buttons, ending in a link to the coverage page.
 *
 * @return {TemplateItem[]} The template.
 */
export function wireInnerTemplate(): TemplateItem[] {
	return [
		feedTemplate(
			[
				...wireEntryTemplate( paletteSlugs(), themeFontSizeSlugs() ),
				allUpdatesLink(),
			],
			'var:preset|spacing|40'
		),
	];
}

/**
 * The Digest layout's inner-blocks template: a bordered box with the coverage
 * name, the latest entries against their times, and a footer holding the link
 * to the coverage page beside the Follow button.
 *
 * @return {TemplateItem[]} The template.
 */
export function digestInnerTemplate(): TemplateItem[] {
	return [
		feedTemplate(
			[
				digestHeader(),
				...digestEntryTemplate( paletteSlugs(), themeFontSizeSlugs() ),
				digestFooter(),
			],
			'var:preset|spacing|40',
			DIGEST_FEED_STYLE
		),
	];
}

/**
 * The Flash layout's inner-blocks template: a full-width strip on the site's
 * accent color holding the coverage's status, the newest entry's time and
 * text, then a link to the coverage page.
 *
 * @return {TemplateItem[]} The template.
 */
export function flashInnerTemplate(): TemplateItem[] {
	return [
		feedTemplate(
			[
				[ STATUS_BLOCK_NAME, {} ],
				...flashEntryTemplate( themeFontSizeSlugs() ),
				allUpdatesLink(),
			],
			'var:preset|spacing|40',
			FLASH_FEED_STYLE,
			FLASH_FEED_LAYOUT
		),
	];
}

/**
 * All block types allowed inside the Feed group.
 */
export const ALL_ALLOWED_BLOCKS = [
	...ENTRY_ALLOWED_BLOCKS,
	FOLLOW_BLOCK_NAME,
	STATUS_BLOCK_NAME,
];

/**
 * Picks the template variant an entry renders with on the front end: the
 * pinned row only when pinned; "Read more" and a linked title only with a
 * published breakout.
 *
 * @param {Object}       templates                         Template variants.
 * @param {Object}       templates.pinned                  Full template, title linked.
 * @param {Object}       templates.unpinned                Without the pinned row, title linked.
 * @param {Object}       templates.pinnedWithoutBreakout   Without "Read more".
 * @param {Object}       templates.unpinnedWithoutBreakout Without either.
 * @param {EntryContext} context                           The entry.
 * @return {TemplateBlocks} The blocks to preview the entry with.
 */
export function previewTemplateFor(
	templates: {
		pinned: TemplateBlocks;
		unpinned: TemplateBlocks;
		pinnedWithoutBreakout: TemplateBlocks;
		unpinnedWithoutBreakout: TemplateBlocks;
	},
	context: EntryContext
): TemplateBlocks {
	if ( context.hasBreakout ) {
		return context.pinned ? templates.pinned : templates.unpinned;
	}

	return context.pinned
		? templates.pinnedWithoutBreakout
		: templates.unpinnedWithoutBreakout;
}

/**
 * The preview blocks for a layout: the coverage-level blocks above and below
 * the entries (see layoutParts()), and the per-entry blocks, shaped per entry
 * the way the site renders each entry.
 *
 * @param {Object[]}       allBlocks      The layout's top-level blocks.
 * @param {EntryContext[]} entryContexts  The entries being previewed.
 * @param {number}         entriesPerPage Entries loaded per page.
 * @param {boolean}        isCapped       Whether the feed shows only its latest entries, so the last one previewed is the last.
 * @return {Object} The header, footer and per-entry template blocks, and a getter for one entry's preview blocks.
 */
export function useLayoutPreview(
	allBlocks: TemplateBlocks,
	entryContexts: EntryContext[],
	entriesPerPage: number,
	isCapped = false
): {
	headerBlocks: TemplateBlocks;
	footerBlocks: TemplateBlocks;
	templateBlocks: TemplateBlocks;
	blocksForEntry: ( context: EntryContext ) => TemplateBlocks;
} {
	const { headerBlocks, templateBlocks, footerBlocks } = useMemo( () => {
		const { header, template, footer } = layoutParts( allBlocks );

		return {
			headerBlocks: withoutLatestButtons( header ),
			templateBlocks: template,
			footerBlocks: withoutLatestButtons( footer ),
		};
	}, [ allBlocks ] );
	const previewTemplates = useMemo( () => {
		const pinnedBlocks = forEntryKind( templateBlocks, true );
		const hasCard = hasPinnedCard( pinnedBlocks );
		const pinned = hasCard
			? withoutClosingSeparator( pinnedBlocks )
			: pinnedBlocks;
		const unpinned = withoutPinnedCard(
			withoutPinnedRow( forEntryKind( templateBlocks, false ) )
		);

		const asUntitled = ( blocks: TemplateBlocks ) =>
			withoutPostTitle( withCenteredTitleRows( blocks ) );
		const titled = {
			pinned: withLinkedTitle( pinned ),
			unpinned: withLinkedTitle( unpinned ),
			pinnedWithoutBreakout: withShapedPinnedCard(
				withoutBreakoutLink( pinned ),
				{ closeUp: true, isLastCard: false }
			),
			unpinnedWithoutBreakout: withoutBreakoutLink( unpinned ),
		};

		return {
			hasCard,
			titled,
			untitled: {
				pinned: asUntitled( titled.pinned ),
				unpinned: asUntitled( titled.unpinned ),
				pinnedWithoutBreakout: asUntitled(
					titled.pinnedWithoutBreakout
				),
				unpinnedWithoutBreakout: asUntitled(
					titled.unpinnedWithoutBreakout
				),
			},
		};
	}, [ templateBlocks ] );

	// The last entry drops its separator once no more entries would load
	// (see Rolling_Coverage_Block::shape_entry_template()).
	const lastContext =
		isCapped || entryContexts.length < entriesPerPage
			? entryContexts.at( -1 )
			: undefined;
	const lastPreviewBlocks = useMemo( () => {
		if ( ! lastContext ) {
			return undefined;
		}

		const blocks = previewTemplateFor(
			lastContext.hasTitle === false
				? previewTemplates.untitled
				: previewTemplates.titled,
			lastContext
		);

		if ( ! lastContext.pinned || ! previewTemplates.hasCard ) {
			return withoutClosingSeparator( blocks );
		}

		const closing = blocks.at( -1 );

		return closing && isPinnedCard( closing )
			? withShapedPinnedCard( blocks, {
					closeUp: false,
					isLastCard: true,
				} )
			: blocks;
	}, [ previewTemplates, lastContext ] );

	const blocksForEntry = useCallback(
		( context: EntryContext ) =>
			context === lastContext && lastPreviewBlocks
				? lastPreviewBlocks
				: previewTemplateFor(
						context.hasTitle === false
							? previewTemplates.untitled
							: previewTemplates.titled,
						context
					),
		[ lastContext, lastPreviewBlocks, previewTemplates ]
	);

	return { headerBlocks, footerBlocks, templateBlocks, blocksForEntry };
}
