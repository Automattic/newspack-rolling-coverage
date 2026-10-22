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
	bylineEntryTemplate,
	wireEntryTemplate,
	digestEntryTemplate,
	coverageNameHeading,
	digestFooter,
	flashEntryTemplate,
	flashBar,
	FLASH_FEED_LAYOUT,
	ALERT_FEED_STYLE,
	tickerEntryTemplate,
	tickerHeader,
	TICKER_FEED_LAYOUT,
	RULED_FEED_CLASS,
	splitEntryTemplate,
	SPLIT_FEED_LAYOUT,
	SPLIT_FEED_STYLE,
	SPLIT_FEED_GAP,
	DIGEST_FEED_STYLE,
	allUpdatesLink,
	ENTRY_ALLOWED_BLOCKS,
	FOLLOW_BLOCK_NAME,
	CHECK_UPDATES_BLOCK_NAME,
	LOAD_MORE_BLOCK_NAME,
	loadMoreBlock,
	loadMoreTopUp,
	STATUS_BLOCK_NAME,
	UPDATE_TIMER_BLOCK_NAME,
	feedTemplate,
	layoutParts,
	withoutPinnedRow,
	fullStoryTakesPinned,
	withoutBreakoutLink,
	withLinkedTitle,
	withoutPinnedCard,
	withoutClosingSeparator,
	withShapedPinnedCard,
	withCenteredTitleRows,
	withoutPostTitle,
	withEntryLinkTitleText,
	withoutByline,
	withAuthorSettings,
	hasPinnedCard,
	isPinnedCard,
	isBreakoutEntry,
	pinnedStackGroups,
	forEntryKind,
	withoutFeaturedImage,
} from './template';
import type { EntryContext, TemplateBlocks, TemplateItem } from './types';

export const BLOCK_NAME = metadata.name;

export {
	FOLLOW_BLOCK_NAME,
	CHECK_UPDATES_BLOCK_NAME,
	LOAD_MORE_BLOCK_NAME,
	UPDATE_TIMER_BLOCK_NAME,
};

type PaletteColor = { slug: string };

/**
 * Every color in the editor's palette: the theme's, core's default and the
 * site's custom ones.
 *
 * @return {PaletteColor[]} Colors.
 */
function palette(): PaletteColor[] {
	const settings = (
		select( blockEditorStore.name ) as unknown as {
			getSettings: () => {
				colors?: PaletteColor[];
				__experimentalFeatures?: {
					color?: { palette?: Record< string, PaletteColor[] > };
				};
			};
		}
	 ).getSettings();
	const origins = Object.values(
		settings.__experimentalFeatures?.color?.palette ?? {}
	);

	return [ ...origins.flat(), ...( settings.colors ?? [] ) ];
}

/**
 * The slugs of every color in the editor's palette.
 *
 * @return {string[]} Color slugs.
 */
function paletteSlugs(): string[] {
	return palette().map( ( color ) => color.slug );
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
 * The Bulletin layout's inner-blocks template, the default: the Feed group
 * holding the per-entry blocks.
 *
 * @return {TemplateItem[]} The template.
 */
export function innerTemplate(): TemplateItem[] {
	return [
		feedTemplate( [
			...bulletinEntryTemplate( paletteSlugs() ),
			loadMoreBlock(),
		] ),
	];
}

/**
 * The Stream layout's inner-blocks template: the same Feed group as the
 * default.
 *
 * @return {TemplateItem[]} The template.
 */
export function streamInnerTemplate(): TemplateItem[] {
	return [
		feedTemplate( [
			...streamEntryTemplate( paletteSlugs(), themeFontSizeSlugs() ),
			loadMoreBlock( {
				layout: { type: 'flex', justifyContent: 'center' },
			} ),
		] ),
	];
}

/**
 * The Rail layout's inner-blocks template: the same Feed group as the
 * default, a little tighter, with each entry hanging off a timeline.
 *
 * @return {TemplateItem[]} The template.
 */
export function railInnerTemplate(): TemplateItem[] {
	return [
		feedTemplate(
			[
				...railEntryTemplate( paletteSlugs() ),
				loadMoreBlock( loadMoreTopUp( '40' ) ),
			],
			'var:preset|spacing|40'
		),
	];
}

/**
 * The Clock layout's inner-blocks template: the same Feed group as the
 * default, with each entry headed by the time it was posted.
 *
 * @return {TemplateItem[]} The template.
 */
export function clockInnerTemplate(): TemplateItem[] {
	return [
		feedTemplate( [
			...clockEntryTemplate( paletteSlugs(), themeFontSizeSlugs() ),
			loadMoreBlock(),
		] ),
	];
}

/**
 * The Margin layout's inner-blocks template: the same Feed group as the
 * default, with each entry split into a margin and its content.
 *
 * @return {TemplateItem[]} The template.
 */
export function marginInnerTemplate(): TemplateItem[] {
	return [
		feedTemplate( [
			...marginEntryTemplate( paletteSlugs() ),
			loadMoreBlock(),
		] ),
	];
}

/**
 * The Minute layout's inner-blocks template: the same Feed group as the
 * default, closer together, with each entry reduced to its content.
 *
 * @return {TemplateItem[]} The template.
 */
export function minuteInnerTemplate(): TemplateItem[] {
	return [
		feedTemplate(
			[
				...minuteEntryTemplate( paletteSlugs() ),
				loadMoreBlock( loadMoreTopUp( '30' ) ),
			],
			'var:preset|spacing|30'
		),
	];
}

/**
 * The Byline layout's inner-blocks template: the same Feed group as the
 * default, with each entry signed by its author.
 *
 * @return {TemplateItem[]} The template.
 */
export function bylineInnerTemplate(): TemplateItem[] {
	return [
		feedTemplate( [
			...bylineEntryTemplate( paletteSlugs() ),
			loadMoreBlock(),
		] ),
	];
}

/**
 * The Ticker layout's inner-blocks template: the coverage's status and name
 * beside the latest entries' headlines, on one line that scrolls when they
 * don't fit, then a link to the coverage page, with a rule in every gap
 * between them.
 *
 * @return {TemplateItem[]} The template.
 */
export function tickerInnerTemplate(): TemplateItem[] {
	return [
		feedTemplate(
			[
				tickerHeader( themeFontSizeSlugs() ),
				...tickerEntryTemplate( paletteSlugs() ),
				allUpdatesLink(),
			],
			'var:preset|spacing|40',
			{},
			TICKER_FEED_LAYOUT,
			{ className: RULED_FEED_CLASS, align: 'wide' }
		),
	];
}

/**
 * The Split layout's inner-blocks template: the full feed at wide width,
 * the pinned entry's summary in a column beside the entries.
 *
 * @return {TemplateItem[]} The template.
 */
export function splitInnerTemplate(): TemplateItem[] {
	return [
		feedTemplate(
			[ ...splitEntryTemplate( paletteSlugs() ), loadMoreBlock() ],
			SPLIT_FEED_GAP,
			SPLIT_FEED_STYLE,
			SPLIT_FEED_LAYOUT,
			{ align: 'wide' }
		),
	];
}

/**
 * The Wire layout's inner-blocks template: a narrow list of the latest
 * entries, ending in a link to the coverage page.
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
			'var:preset|spacing|30'
		),
	];
}

/**
 * The Digest layout's inner-blocks template: a bordered box with the coverage
 * name, the latest entries against their times, and a footer holding the link
 * to the coverage page.
 *
 * @return {TemplateItem[]} The template.
 */
export function digestInnerTemplate(): TemplateItem[] {
	return [
		feedTemplate(
			[
				coverageNameHeading(),
				...digestEntryTemplate( paletteSlugs(), themeFontSizeSlugs() ),
				digestFooter(),
			],
			'var:preset|spacing|30',
			DIGEST_FEED_STYLE
		),
	];
}

/**
 * The Flash layout's inner-blocks template: a full-width bar on the site's
 * accent color holding, at the theme's wide width, the coverage's status,
 * the newest entry's time and text, then a link to the coverage page on the
 * right, side by side at every width. The Live badge takes the theme's page
 * background and text colors, so it follows the theme's style variations.
 *
 * @return {TemplateItem[]} The template.
 */
export function flashInnerTemplate(): TemplateItem[] {
	return [
		flashBar(
			feedTemplate(
				[
					[
						STATUS_BLOCK_NAME,
						{ backgroundColors: { active: 'base' } },
					],
					...flashEntryTemplate(),
					allUpdatesLink(),
				],
				'var:preset|spacing|30',
				{},
				FLASH_FEED_LAYOUT,
				{ align: 'wide' }
			)
		),
	];
}

/**
 * The Alert layout's inner-blocks template: a box on the pinned entry's
 * background holding the coverage's status, its name on one line, cut short
 * to fit, and a link to the coverage page, side by side at every width. It
 * shows no entries. The Live badge takes the theme's accent color and the
 * text color made for it, so it follows the theme's style variations.
 *
 * @return {TemplateItem[]} The template.
 */
export function alertInnerTemplate(): TemplateItem[] {
	return [
		feedTemplate(
			[
				[
					STATUS_BLOCK_NAME,
					{ backgroundColors: { active: 'accent' } },
				],
				coverageNameHeading( 'small', {
					className: 'newspack-rolling-coverage-name',
					style: { layout: { selfStretch: 'fill' } },
				} ),
				allUpdatesLink(),
			],
			'var:preset|spacing|30',
			ALERT_FEED_STYLE,
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
	CHECK_UPDATES_BLOCK_NAME,
	LOAD_MORE_BLOCK_NAME,
	STATUS_BLOCK_NAME,
	UPDATE_TIMER_BLOCK_NAME,
];

/**
 * The template variants an entry can render with (see previewTemplateFor()).
 * The Full Story variants exist only for a template with a Full Story Entry
 * group, and the pinned ones only when that group takes pinned entries too
 * (see fullStoryTakesPinned()).
 */
type PreviewTemplates = {
	pinned: TemplateBlocks;
	unpinned: TemplateBlocks;
	pinnedWithoutBreakout: TemplateBlocks;
	unpinnedWithoutBreakout: TemplateBlocks;
	fullStory?: TemplateBlocks;
	fullStoryWithoutImage?: TemplateBlocks;
	pinnedFullStory?: TemplateBlocks;
	pinnedFullStoryWithoutImage?: TemplateBlocks;
};

/**
 * Whether an entry shows its breakout post through a template's Full Story
 * Entry group, where the template has one: an unpinned entry with a
 * published breakout post, or a pinned one too when the group takes pinned
 * entries (see Rolling_Coverage_Block::is_full_story()).
 *
 * @param {EntryContext} context       The entry.
 * @param {boolean}      [takesPinned] Whether the template's Full Story Entry
 *                                     takes pinned entries (see
 *                                     fullStoryTakesPinned()).
 * @return {boolean} Whether the entry is a Full Story entry.
 */
export function isFullStoryContext(
	context: EntryContext,
	takesPinned = false
): boolean {
	return ( ! context.pinned || takesPinned ) && Boolean( context.breakout );
}

/**
 * Picks the template variant an entry renders with on the front end: the
 * Full Story Entry group for an entry with a published breakout that isn't
 * pinned, or is pinned where the group takes pinned entries, with the pinned
 * row only when pinned and without its featured image when the post has
 * none; otherwise the pinned row only when pinned, and "Read more" and a
 * linked title only with a published breakout, though a title that links to
 * its entry links either way.
 *
 * @param {Object}       templates                             Template variants.
 * @param {Object}       templates.pinned                      Full template, title linked.
 * @param {Object}       templates.unpinned                    Without the pinned row, title linked.
 * @param {Object}       templates.pinnedWithoutBreakout       Without "Read more".
 * @param {Object}       templates.unpinnedWithoutBreakout     Without either.
 * @param {Object}       templates.fullStory                   The Full Story Entry group, without the pinned row.
 * @param {Object}       templates.fullStoryWithoutImage       The same, without featured images.
 * @param {Object}       templates.pinnedFullStory             The Full Story Entry group, pinned row kept.
 * @param {Object}       templates.pinnedFullStoryWithoutImage The same, without featured images.
 * @param {EntryContext} context                               The entry.
 * @return {TemplateBlocks} The blocks to preview the entry with.
 */
export function previewTemplateFor(
	templates: PreviewTemplates,
	context: EntryContext
): TemplateBlocks {
	const [ fullStory, fullStoryWithoutImage ] = context.pinned
		? [ templates.pinnedFullStory, templates.pinnedFullStoryWithoutImage ]
		: [ templates.fullStory, templates.fullStoryWithoutImage ];

	if ( fullStory && fullStoryWithoutImage && context.breakout ) {
		return context.breakout.imageId ? fullStory : fullStoryWithoutImage;
	}

	if ( context.hasBreakout ) {
		return context.pinned ? templates.pinned : templates.unpinned;
	}

	return context.pinned
		? templates.pinnedWithoutBreakout
		: templates.unpinnedWithoutBreakout;
}

/**
 * Applies a change to every template variant of a set.
 *
 * @param {Object}   templates Template variants, as previewTemplateFor() takes them.
 * @param {Function} change    Returns a variant changed.
 * @return {Object} The changed variants.
 */
function mapTemplates(
	templates: PreviewTemplates,
	change: ( blocks: TemplateBlocks ) => TemplateBlocks
): PreviewTemplates {
	return Object.fromEntries(
		Object.entries( templates ).map( ( [ key, blocks ] ) => [
			key,
			blocks && change( blocks ),
		] )
	) as PreviewTemplates;
}

/**
 * Every template variant of a set without the author's avatar and name, as
 * an entry the Slack bot wrote renders.
 *
 * @param {Object} templates Template variants, as previewTemplateFor() takes them.
 * @return {Object} The same variants without the byline.
 */
function withoutBylines( templates: PreviewTemplates ): PreviewTemplates {
	return mapTemplates( templates, withoutByline );
}

/**
 * Whether an entry previews as untitled: an entry without a title, or for a
 * Full Story entry, one whose breakout post has none either, as the site
 * centers its title rows (see Rolling_Coverage_Block::render_entry()).
 *
 * @param {EntryContext} context      The entry.
 * @param {boolean}      hasFullStory Whether the template has a Full Story Entry group.
 * @param {boolean}      takesPinned  Whether that group takes pinned entries.
 * @return {boolean} Whether it previews as untitled.
 */
function isUntitled(
	context: EntryContext,
	hasFullStory: boolean,
	takesPinned: boolean
): boolean {
	if ( hasFullStory && isFullStoryContext( context, takesPinned ) ) {
		return context.breakout?.title === '' && context.hasTitle === false;
	}

	return context.hasTitle === false;
}

/**
 * The preview blocks for a layout: the coverage-level blocks above and below
 * the entries (see layoutParts()), and the per-entry blocks, shaped per entry
 * the way the site renders each entry.
 *
 * @param {Object[]}       allBlocks           The layout's top-level blocks.
 * @param {EntryContext[]} entryContexts       The entries being previewed.
 * @param {number}         entriesPerPage      Entries loaded per page.
 * @param {boolean}        isLastPage          Whether no more entries load after those previewed, as in a capped feed.
 * @param {Object}         settings            The block's Author name and Author avatar settings.
 * @param {boolean}        settings.showAuthor Whether entries show their author's name.
 * @param {boolean}        settings.showAvatar Whether entries show their author's avatar.
 * @return {Object} The header, footer and per-entry template blocks, and a getter for one entry's preview blocks.
 */
export function useLayoutPreview(
	allBlocks: TemplateBlocks,
	entryContexts: EntryContext[],
	entriesPerPage: number,
	isLastPage = false,
	{
		showAuthor = true,
		showAvatar = true,
	}: { showAuthor?: boolean; showAvatar?: boolean } = {}
): {
	headerBlocks: TemplateBlocks;
	footerBlocks: TemplateBlocks;
	templateBlocks: TemplateBlocks;
	blocksForEntry: ( context: EntryContext ) => TemplateBlocks;
} {
	const { headerBlocks, templateBlocks, footerBlocks } = useMemo( () => {
		const { header, template, footer } = layoutParts( allBlocks );

		return {
			headerBlocks: header,
			templateBlocks: template,
			footerBlocks: footer,
		};
	}, [ allBlocks ] );
	const hasFullStory = [
		...templateBlocks,
		...pinnedStackGroups( templateBlocks ),
	].some( isBreakoutEntry );
	const takesPinned = fullStoryTakesPinned( templateBlocks );
	const hasBotEntry = entryContexts.some(
		( context ) => context.hidesByline
	);
	const previewTemplates = useMemo( () => {
		const entryBlocks = withAuthorSettings(
			templateBlocks,
			showAuthor,
			showAvatar
		);
		const pinnedBlocks = forEntryKind( entryBlocks, true );
		const hasCard = hasPinnedCard( pinnedBlocks );
		const pinned = hasCard
			? withoutClosingSeparator( pinnedBlocks )
			: pinnedBlocks;
		const unpinned = withoutPinnedCard(
			withoutPinnedRow( forEntryKind( entryBlocks, false ) )
		);
		const fullStoryBlocks = hasFullStory
			? withoutPinnedCard( forEntryKind( entryBlocks, false, true ) )
			: undefined;
		const fullStory =
			fullStoryBlocks &&
			withLinkedTitle( withoutPinnedRow( fullStoryBlocks ) );
		const pinnedFullStory =
			hasFullStory && takesPinned
				? withLinkedTitle(
						withoutPinnedCard(
							forEntryKind( entryBlocks, true, true )
						)
					)
				: undefined;

		const asUntitled = ( blocks: TemplateBlocks ) =>
			withoutPostTitle( withCenteredTitleRows( blocks ) );
		const titled: PreviewTemplates = {
			pinned: withLinkedTitle( pinned ),
			unpinned: withLinkedTitle( unpinned ),
			pinnedWithoutBreakout: withShapedPinnedCard(
				withLinkedTitle( withoutBreakoutLink( pinned ), true ),
				{ closeUp: true, isLastCard: false }
			),
			unpinnedWithoutBreakout: withLinkedTitle(
				withoutBreakoutLink( unpinned ),
				true
			),
			...( fullStory
				? {
						fullStory,
						fullStoryWithoutImage:
							withoutFeaturedImage( fullStory ),
					}
				: {} ),
			...( pinnedFullStory
				? {
						pinnedFullStory,
						pinnedFullStoryWithoutImage:
							withoutFeaturedImage( pinnedFullStory ),
					}
				: {} ),
		};
		const untitled = mapTemplates( titled, asUntitled );

		return {
			hasCard,
			titled,
			untitled,
			titledWithoutByline: hasBotEntry
				? withoutBylines( titled )
				: undefined,
			untitledWithoutByline: hasBotEntry
				? withoutBylines( untitled )
				: undefined,
		};
	}, [
		templateBlocks,
		hasFullStory,
		takesPinned,
		hasBotEntry,
		showAuthor,
		showAvatar,
	] );

	// The last entry drops its separator once no more entries would load
	// (see Rolling_Coverage_Block::shape_entry_template()).
	const lastContext =
		isLastPage || entryContexts.length < entriesPerPage
			? entryContexts.at( -1 )
			: undefined;
	const lastPreviewBlocks = useMemo( () => {
		if ( ! lastContext ) {
			return undefined;
		}

		const blocks = previewTemplateFor(
			isUntitled( lastContext, hasFullStory, takesPinned )
				? previewTemplates.untitled
				: previewTemplates.titled,
			lastContext
		);
		const closing = blocks.at( -1 );
		const showsCard =
			lastContext.pinned &&
			previewTemplates.hasCard &&
			! (
				hasFullStory && isFullStoryContext( lastContext, takesPinned )
			);
		let shaped = blocks;

		if ( ! showsCard ) {
			shaped = withoutClosingSeparator( blocks );
		} else if ( closing && isPinnedCard( closing ) ) {
			shaped = withShapedPinnedCard( blocks, {
				closeUp: false,
				isLastCard: true,
			} );
		}

		return lastContext.hidesByline ? withoutByline( shaped ) : shaped;
	}, [ previewTemplates, lastContext, hasFullStory, takesPinned ] );

	// Untitled entries' blocks carry their own text, so they're kept per entry
	// to hand the preview the same blocks on every render.
	const untitledBlocks = useMemo(
		() => new WeakMap< EntryContext, TemplateBlocks >(),
		[ lastPreviewBlocks, previewTemplates ]
	);

	const blocksForEntry = useCallback(
		( context: EntryContext ) => {
			const untitled = isUntitled( context, hasFullStory, takesPinned );
			const kept = untitled ? untitledBlocks.get( context ) : undefined;

			if ( kept ) {
				return kept;
			}

			let blocks: TemplateBlocks;

			if ( context === lastContext && lastPreviewBlocks ) {
				blocks = lastPreviewBlocks;
			} else {
				const templates = untitled
					? previewTemplates.untitled
					: previewTemplates.titled;
				const bylineless = untitled
					? previewTemplates.untitledWithoutByline
					: previewTemplates.titledWithoutByline;

				blocks = previewTemplateFor(
					( context.hidesByline && bylineless ) || templates,
					context
				);
			}

			if ( ! untitled ) {
				return blocks;
			}

			const filled = withEntryLinkTitleText(
				blocks,
				context.fallbackTitle ?? ''
			);
			untitledBlocks.set( context, filled );

			return filled;
		},
		[
			lastContext,
			lastPreviewBlocks,
			previewTemplates,
			untitledBlocks,
			hasFullStory,
			takesPinned,
		]
	);

	return { headerBlocks, footerBlocks, templateBlocks, blocksForEntry };
}
