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
	ENTRY_TEMPLATE,
	compactEntryTemplate,
	ENTRY_ALLOWED_BLOCKS,
	FOLLOW_TEMPLATE,
	feedTemplate,
	latestTemplate,
	isFollowButtons,
	isLatestButtons,
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

/**
 * The legacy follow button block, still rendered once at the top of
 * coverages saved before the follow button became a core button.
 */
export const FOLLOW_BLOCK_NAME = 'newspack-rolling-coverage/coverage-follow';

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
 * Default inner-blocks template for the Rolling Coverage block: the Feed
 * group, holding the "Jump to Latest" button, in the colors the editor's
 * palette has for it, and the follow button at the top, then the per-entry
 * blocks.
 *
 * @return {TemplateItem[]} The template.
 */
export function innerTemplate(): TemplateItem[] {
	return [
		feedTemplate( [
			latestTemplate( paletteSlugs() ),
			FOLLOW_TEMPLATE,
			...ENTRY_TEMPLATE,
		] ),
	];
}

/**
 * The Compact layout's inner-blocks template: the same Feed group, buttons
 * and entry kinds as the default, with a tighter gap and a time-led entry.
 *
 * @return {TemplateItem[]} The template.
 */
export function compactInnerTemplate(): TemplateItem[] {
	const slugs = paletteSlugs();

	return [
		feedTemplate(
			[
				latestTemplate( slugs ),
				FOLLOW_TEMPLATE,
				...compactEntryTemplate( slugs ),
			],
			'var:preset|spacing|30'
		),
	];
}

/**
 * All block types allowed inside the Feed group.
 */
export const ALL_ALLOWED_BLOCKS = [
	...ENTRY_ALLOWED_BLOCKS,
	FOLLOW_BLOCK_NAME,
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
 * The per-entry preview blocks for a layout: the layout's blocks minus the
 * follow and Jump to Latest buttons, shaped per entry the way the site
 * renders each entry.
 *
 * @param {Object[]}       allBlocks      The layout's top-level blocks.
 * @param {EntryContext[]} entryContexts  The entries being previewed.
 * @param {number}         entriesPerPage Entries loaded per page.
 * @return {Object} The per-entry template blocks and a getter for one entry's preview blocks.
 */
export function useLayoutPreview(
	allBlocks: TemplateBlocks,
	entryContexts: EntryContext[],
	entriesPerPage: number
): {
	templateBlocks: TemplateBlocks;
	blocksForEntry: ( context: EntryContext ) => TemplateBlocks;
} {
	const templateBlocks = useMemo(
		() =>
			allBlocks.filter(
				( block ) =>
					block.name !== FOLLOW_BLOCK_NAME &&
					! isFollowButtons( block ) &&
					! isLatestButtons( block )
			),
		[ allBlocks ]
	);
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
		entryContexts.length < entriesPerPage
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

	return { templateBlocks, blocksForEntry };
}
