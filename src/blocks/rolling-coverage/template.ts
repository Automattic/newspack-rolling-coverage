/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { ENTRY_BINDINGS_SOURCE } from '../shared/entry-bindings';
import { POST_DATE_ATTRIBUTES } from '../shared/post-date';
import type { TemplateItem, EntryEditedState } from './types';

const LOCKED = { remove: true, move: false };

/**
 * The Feed group stays at the layout's top level, and the pinned card and the
 * entry group at the Feed's, where the template is split by kind of entry.
 */
const LOCKED_IN_PLACE = { remove: true, move: true };

/**
 * Class of the layout's Feed group, mirroring
 * Rolling_Coverage_Block::FEED_CLASS.
 */
const FEED_CLASS = 'newspack-rolling-coverage-feed';

/**
 * The pin icon registered by Block_Icons::PIN.
 */
const PIN_ICON = 'newspack-rolling-coverage/pin-small';

/**
 * The pin icon and the pinned label in a row, shown only on pinned entries
 * (see Entry_Bindings::filter_pinned_group()).
 */
const PINNED_ROW: TemplateItem = [
	'core/group',
	{
		layout: {
			type: 'flex',
			flexWrap: 'nowrap',
			verticalAlignment: 'center',
		},
		style: { spacing: { blockGap: '0' } },
		metadata: { name: __( 'Pinned', 'newspack-rolling-coverage' ) },
	},
	[
		[
			'core/icon',
			{ icon: PIN_ICON, style: { dimensions: { width: '24px' } } },
		],
		[
			'core/paragraph',
			{
				// The Newspack Theme's class for its heading font, which it also gives the date.
				className: 'use-header-font',
				fontSize: 'small',
				style: { typography: { fontWeight: '700' } },
				metadata: {
					bindings: {
						content: {
							source: ENTRY_BINDINGS_SOURCE,
							args: { key: 'pinnedLabel' },
						},
					},
				},
			},
		],
	],
];

const SHARE_BACKGROUND =
	'var(--wp--preset--color--base-2, var(--newspack-theme-color-bg-light, #f0f0f0))';
const SHARE_TEXT =
	'var(--wp--preset--color--contrast, var(--newspack-theme-color-text-main, currentcolor))';
const READ_MORE_BACKGROUND =
	'var(--wp--preset--color--accent, var(--newspack-theme-color-primary))';
const READ_MORE_TEXT =
	'var(--wp--preset--color--accent-contrast, var(--wp--preset--color--base, var(--newspack-theme-color-against-primary)))';

/**
 * The share button: a 36px circle showing the link icon, both set by the
 * server (see Entry_Bindings::show_share_icon()). Its text and the entry's title
 * make the link's accessible name.
 */
const SHARE_BUTTONS: TemplateItem = [
	'core/buttons',
	{ metadata: { name: __( 'Share', 'newspack-rolling-coverage' ) } },
	[
		[
			'core/button',
			{
				text: __( 'Share', 'newspack-rolling-coverage' ),
				style: {
					border: { radius: '9999px' },
					color: {
						background: SHARE_BACKGROUND,
						text: SHARE_TEXT,
					},
				},
				metadata: {
					name: __( 'Share', 'newspack-rolling-coverage' ),
					bindings: {
						url: {
							source: ENTRY_BINDINGS_SOURCE,
							args: { key: 'shareUrl' },
						},
					},
				},
			},
		],
	],
];

/**
 * Spaces what follows the entry's content, such as "Read more", as the theme
 * spaces paragraphs: its block gap, or on a theme without one (the classic
 * theme), the preset matching its paragraph margin. Set on Post Content
 * because the classic theme redefines the block gap on Buttons.
 */
const CONTENT_GAP =
	'var(--wp--style--block-gap, var(--wp--preset--spacing--40))';

/**
 * Class of the group that shows a pinned entry as a card, mirroring
 * Rolling_Coverage_Block::PINNED_CARD_CLASS.
 */
const PINNED_CARD_CLASS = 'newspack-rolling-coverage-pinned-card';
const PINNED_CARD_BACKGROUND = 'var(--wp--custom--color--neutral-5, #f7f7f7)';
const PINNED_CARD_RADIUS =
	'var(--wp--custom--border--radius-large, var(--newspack-ui-border-radius-l, 8px))';

/**
 * Class of the group that shows an entry that isn't pinned, mirroring
 * Rolling_Coverage_Block::REGULAR_ENTRY_CLASS.
 */
const REGULAR_ENTRY_CLASS = 'newspack-rolling-coverage-regular-entry';

/**
 * The space between the blocks of an entry group or pinned card, mirroring
 * Rolling_Coverage_Block::DEFAULT_ENTRY_GAP.
 */
const DEFAULT_ENTRY_GAP = 'var:preset|spacing|20';

/**
 * What an entry shows: the date and title stacked with the share button
 * opposite, then content and "Read more" bound to the entry and locked
 * against removal. The pinned card's also carry the pinned row.
 *
 * @param {boolean} isPinned Whether the blocks are the pinned card's.
 * @return {TemplateItem[]} The entry's blocks.
 */
function entryBlocks( isPinned: boolean ): TemplateItem[] {
	const date: TemplateItem = [
		'core/post-date',
		{ ...POST_DATE_ATTRIBUTES, format: 'human-diff' },
	];
	const title: TemplateItem = [ 'core/post-title', { level: 4 } ];

	return [
		[
			'core/group',
			{
				layout: {
					type: 'flex',
					flexWrap: 'nowrap',
					justifyContent: 'space-between',
					verticalAlignment: 'top',
				},
				style: { spacing: { blockGap: 'var:preset|spacing|30' } },
				metadata: {
					name: __( 'Header', 'newspack-rolling-coverage' ),
				},
			},
			[
				[
					'core/group',
					{
						layout: { type: 'flex', orientation: 'vertical' },
						style: {
							spacing: { blockGap: 'var:preset|spacing|20' },
						},
						metadata: {
							name: __( 'Meta', 'newspack-rolling-coverage' ),
						},
					},
					isPinned ? [ PINNED_ROW, date, title ] : [ date, title ],
				],
				SHARE_BUTTONS,
			],
		],
		[
			'core/post-content',
			{
				style: {
					spacing: {
						padding: {
							top: '0',
							right: '0',
							bottom: '0',
							left: '0',
						},
						margin: { bottom: CONTENT_GAP },
					},
				},
			},
		],
		[
			'core/buttons',
			{
				lock: LOCKED,
				metadata: {
					name: __( 'Read more', 'newspack-rolling-coverage' ),
				},
			},
			[
				[
					'core/button',
					{
						lock: LOCKED,
						text: __( 'Read more', 'newspack-rolling-coverage' ),
						style: {
							color: {
								background: READ_MORE_BACKGROUND,
								text: READ_MORE_TEXT,
							},
						},
						metadata: {
							name: __(
								'Read more',
								'newspack-rolling-coverage'
							),
							bindings: {
								url: {
									source: ENTRY_BINDINGS_SOURCE,
									args: { key: 'breakoutUrl' },
								},
							},
						},
					},
				],
			],
		],
	];
}

/**
 * Default per-entry template: the pinned card, which a pinned entry shows,
 * the entry group, which every other entry shows, then the separator that
 * closes an entry.
 */
const ENTRY_TEMPLATE: TemplateItem[] = [
	[
		'core/group',
		{
			className: PINNED_CARD_CLASS,
			lock: LOCKED_IN_PLACE,
			style: {
				color: { background: PINNED_CARD_BACKGROUND },
				spacing: {
					padding: {
						top: 'var:preset|spacing|50',
						right: 'var:preset|spacing|50',
						bottom: 'var:preset|spacing|50',
						left: 'var:preset|spacing|50',
					},
					blockGap: DEFAULT_ENTRY_GAP,
				},
				border: { radius: PINNED_CARD_RADIUS },
			},
			metadata: {
				name: __( 'Pinned Card', 'newspack-rolling-coverage' ),
			},
		},
		entryBlocks( true ),
	],
	[
		'core/group',
		{
			className: REGULAR_ENTRY_CLASS,
			lock: LOCKED_IN_PLACE,
			style: { spacing: { blockGap: DEFAULT_ENTRY_GAP } },
			metadata: {
				name: __( 'Entry', 'newspack-rolling-coverage' ),
			},
		},
		entryBlocks( false ),
	],
	[ 'core/separator', { className: 'is-style-wide' } ],
];

/**
 * The follow button, rendered once at the top of the coverage: a core button
 * bound to the coverage's notification tag. It's a `<button>`, so the bound
 * value never shows as a link; it only carries the tag to the follow script.
 */
const FOLLOW_TEMPLATE: TemplateItem = [
	'core/buttons',
	{
		lock: LOCKED,
		metadata: { name: __( 'Follow', 'newspack-rolling-coverage' ) },
	},
	[
		[
			'core/button',
			{
				lock: LOCKED,
				tagName: 'button',
				text: __( 'Follow', 'newspack-rolling-coverage' ),
				metadata: {
					name: __( 'Follow', 'newspack-rolling-coverage' ),
					bindings: {
						url: {
							source: ENTRY_BINDINGS_SOURCE,
							args: { key: 'followTag' },
						},
					},
				},
			},
		],
	],
];

/**
 * Whether a block is the follow button's Buttons block (see FOLLOW_TEMPLATE),
 * mirroring Entry_Bindings::is_follow_buttons().
 *
 * @param {Object}   block             The block.
 * @param {string}   block.name        Block name.
 * @param {Object[]} block.innerBlocks Inner blocks.
 * @return {boolean} Whether it's the follow button.
 */
function isFollowButtons( block: {
	name: string;
	innerBlocks?: { attributes?: Record< string, unknown > }[];
} ): boolean {
	return (
		block.name === 'core/buttons' &&
		( block.innerBlocks ?? [] ).some( ( inner ) => {
			const metadata = inner.attributes?.metadata as
				| {
						bindings?: {
							url?: { source?: string; args?: { key?: string } };
						};
				  }
				| undefined;
			const url = metadata?.bindings?.url;
			return (
				url?.source === ENTRY_BINDINGS_SOURCE &&
				url?.args?.key === 'followTag'
			);
		} )
	);
}

/**
 * The Feed group holding the layout's items: everything the coverage shows,
 * spaced by its Block spacing.
 *
 * @param {Object[]} items         The items.
 * @param {string[]} allowedBlocks Block types the Feed accepts.
 * @return {Object} The Feed group.
 */
function feedTemplate(
	items: TemplateItem[],
	allowedBlocks: string[]
): TemplateItem {
	return [
		'core/group',
		{
			className: FEED_CLASS,
			lock: LOCKED_IN_PLACE,
			allowedBlocks,
			layout: {
				type: 'flex',
				orientation: 'vertical',
				justifyContent: 'stretch',
			},
			style: { spacing: { blockGap: 'var:preset|spacing|50' } },
			metadata: { name: __( 'Feed', 'newspack-rolling-coverage' ) },
		},
		items,
	];
}

/**
 * Whether a block is the layout's Feed group, mirroring
 * Rolling_Coverage_Block::feed_group().
 *
 * @param {Object} block            The block.
 * @param {string} block.name       Block name.
 * @param {Object} block.attributes Block attributes.
 * @return {boolean} Whether it's the Feed group.
 */
function isFeedGroup( block: {
	name: string;
	attributes?: Record< string, unknown >;
} ): boolean {
	const className = block.attributes?.className;

	return (
		block.name === 'core/group' &&
		typeof className === 'string' &&
		className.split( ' ' ).includes( FEED_CLASS )
	);
}

/**
 * The layout's Feed group, if it has one.
 *
 * @param {Object[]} blocks The layout's top-level blocks.
 * @return {Object|undefined} The Feed group.
 */
function feedGroupOf< T extends { name: string; [ key: string ]: unknown } >(
	blocks: T[]
): T | undefined {
	return blocks.find( ( block ) =>
		isFeedGroup(
			block as { name: string; attributes?: Record< string, unknown > }
		)
	);
}

/**
 * The layout's items: the blocks inside its Feed group, or for a layout
 * without one, its top-level blocks, mirroring
 * Rolling_Coverage_Block::layout_items().
 *
 * @param {Object[]} blocks The layout's top-level blocks.
 * @return {Object[]} The items.
 */
function feedItems< T extends { name: string; [ key: string ]: unknown } >(
	blocks: T[]
): T[] {
	const feed = feedGroupOf( blocks );

	return feed && Array.isArray( feed.innerBlocks )
		? ( feed.innerBlocks as T[] )
		: blocks;
}

/**
 * Whether a block is a paragraph bound to the pinned label, mirroring
 * Entry_Bindings::is_pinned_label().
 *
 * @param {Object} block            The block.
 * @param {string} block.name       Block name.
 * @param {Object} block.attributes Block attributes.
 * @return {boolean} Whether it's the pinned label.
 */
function isPinnedLabel( block: {
	name: string;
	attributes?: Record< string, unknown >;
} ): boolean {
	const metadata = block.attributes?.metadata as
		| {
				bindings?: {
					content?: { source?: string; args?: { key?: string } };
				};
		  }
		| undefined;
	const content = metadata?.bindings?.content;

	return (
		block.name === 'core/paragraph' &&
		content?.source === ENTRY_BINDINGS_SOURCE &&
		content?.args?.key === 'pinnedLabel'
	);
}

/**
 * Whether a block is the pinned row: a group holding the pinned label and
 * nothing but icons besides, mirroring Entry_Bindings::is_pinned_row().
 *
 * @param {Object}   block             The block.
 * @param {string}   block.name        Block name.
 * @param {Object[]} block.innerBlocks Inner blocks.
 * @return {boolean} Whether it's the pinned row.
 */
function isPinnedRow( block: {
	name: string;
	innerBlocks?: unknown;
} ): boolean {
	const inner = Array.isArray( block.innerBlocks )
		? ( block.innerBlocks as { name: string }[] )
		: [];

	return (
		block.name === 'core/group' &&
		inner.some( isPinnedLabel ) &&
		inner.every(
			( child ) => isPinnedLabel( child ) || child.name === 'core/icon'
		)
	);
}

/**
 * The template without the pinned row and the pinned label, as an entry
 * that isn't pinned renders (see Entry_Bindings::filter_pinned_group()).
 *
 * @param {Object[]} blocks The template blocks.
 * @return {Object[]} The blocks an unpinned entry shows.
 */
function withoutPinnedRow<
	T extends { name: string; [ key: string ]: unknown },
>( blocks: T[] ): T[] {
	return blocks
		.filter(
			( block ) => ! isPinnedLabel( block ) && ! isPinnedRow( block )
		)
		.map( ( block ) =>
			Array.isArray( block.innerBlocks ) && block.innerBlocks.length
				? {
						...block,
						innerBlocks: withoutPinnedRow(
							block.innerBlocks as T[]
						),
					}
				: block
		);
}

/**
 * Whether a block is the "Read more" link to the entry's breakout post: a
 * button whose link is bound to it, or the legacy Breakout Post Link block.
 *
 * @param {Object} block            The block.
 * @param {string} block.name       Block name.
 * @param {Object} block.attributes Block attributes.
 * @return {boolean} Whether it's the breakout link.
 */
function isBreakoutLink( block: {
	name: string;
	attributes?: Record< string, unknown >;
} ): boolean {
	if ( block.name === 'newspack-rolling-coverage/breakout-post-link' ) {
		return true;
	}

	const metadata = block.attributes?.metadata as
		| {
				bindings?: {
					url?: { source?: string; args?: { key?: string } };
				};
		  }
		| undefined;
	const url = metadata?.bindings?.url;

	return (
		block.name === 'core/button' &&
		url?.source === ENTRY_BINDINGS_SOURCE &&
		url?.args?.key === 'breakoutUrl'
	);
}

/**
 * The template without the "Read more" link, as an entry without a published
 * breakout post renders. A Buttons block it leaves empty goes too, as
 * Rolling_Coverage_Block::drop_empty_entry_buttons() does on the front end,
 * and inside the pinned card any block it leaves empty, as
 * Rolling_Coverage_Block::without_breakout_link() does.
 *
 * @param {Object[]} blocks     The template blocks.
 * @param {boolean}  insideCard Whether the blocks are inside the pinned card.
 * @return {Object[]} The blocks an entry without a breakout shows.
 */
function withoutBreakoutLink<
	T extends { name: string; [ key: string ]: unknown },
>( blocks: T[], insideCard = false ): T[] {
	return blocks.flatMap( ( block ) => {
		if ( isBreakoutLink( block ) ) {
			return [];
		}

		if (
			! Array.isArray( block.innerBlocks ) ||
			! block.innerBlocks.length
		) {
			return [ block ];
		}

		const innerBlocks = withoutBreakoutLink(
			block.innerBlocks as T[],
			insideCard || isPinnedCard( block )
		);

		if (
			! innerBlocks.length &&
			( insideCard || block.name === 'core/buttons' )
		) {
			return [];
		}

		return [ { ...block, innerBlocks } ];
	} );
}

/**
 * The "Read more" blocks an entry without a published breakout post doesn't
 * show: each breakout link, and a Buttons block holding nothing else.
 *
 * @param {Object[]} blocks The blocks to look through.
 * @return {string[]} Their client IDs.
 */
function breakoutBlockIds(
	blocks: { name: string; [ key: string ]: unknown }[]
): string[] {
	return blocks.flatMap( ( block ) => {
		const innerBlocks = Array.isArray( block.innerBlocks )
			? ( block.innerBlocks as typeof blocks )
			: [];
		const holdsOnlyLinks =
			block.name === 'core/buttons' &&
			innerBlocks.length > 0 &&
			innerBlocks.every( isBreakoutLink );

		return [
			...( isBreakoutLink( block ) || holdsOnlyLinks
				? [ block.clientId as string ]
				: [] ),
			...breakoutBlockIds( innerBlocks ),
		];
	} );
}

/**
 * The template with the entry's title as a link, as an entry with a published
 * breakout post renders (see Entry_Bindings::link_title_to_breakout()).
 *
 * @param {Object[]} blocks The template blocks.
 * @return {Object[]} The blocks an entry with a breakout shows.
 */
function withLinkedTitle<
	T extends { name: string; [ key: string ]: unknown },
>( blocks: T[] ): T[] {
	return blocks.map( ( block ) => {
		if ( block.name === 'core/post-title' ) {
			return {
				...block,
				attributes: {
					...( block.attributes as Record< string, unknown > ),
					isLink: true,
				},
			};
		}

		return Array.isArray( block.innerBlocks ) && block.innerBlocks.length
			? {
					...block,
					innerBlocks: withLinkedTitle( block.innerBlocks as T[] ),
				}
			: block;
	} );
}

/**
 * Whether a block is the pinned card, mirroring
 * Rolling_Coverage_Block::is_pinned_card().
 *
 * @param {Object} block            The block.
 * @param {string} block.name       Block name.
 * @param {Object} block.attributes Block attributes.
 * @return {boolean} Whether it's the pinned card.
 */
function isPinnedCard( block: {
	name: string;
	attributes?: Record< string, unknown >;
} ): boolean {
	const className = block.attributes?.className;

	return (
		block.name === 'core/group' &&
		typeof className === 'string' &&
		className.split( ' ' ).includes( PINNED_CARD_CLASS )
	);
}

/**
 * Whether a block is the entry group, mirroring
 * Rolling_Coverage_Block::is_regular_entry().
 *
 * @param {Object} block            The block.
 * @param {string} block.name       Block name.
 * @param {Object} block.attributes Block attributes.
 * @return {boolean} Whether it's the entry group.
 */
function isRegularEntry( block: {
	name: string;
	attributes?: Record< string, unknown >;
} ): boolean {
	const className = block.attributes?.className;

	return (
		block.name === 'core/group' &&
		typeof className === 'string' &&
		className.split( ' ' ).includes( REGULAR_ENTRY_CLASS )
	);
}

/**
 * The template for one kind of entry, where it holds both the pinned card
 * and the entry group: a pinned entry shows the card alone, every other
 * entry the entry group alone (see
 * Rolling_Coverage_Block::shape_entry_template()). A template missing either
 * is returned as it is.
 *
 * @param {Object[]} blocks   The template's top-level blocks.
 * @param {boolean}  isPinned Whether the entry is pinned.
 * @return {Object[]} The blocks that kind of entry shows.
 */
function forEntryKind<
	T extends { name: string; attributes?: Record< string, unknown > },
>( blocks: T[], isPinned: boolean ): T[] {
	if ( ! blocks.some( isPinnedCard ) || ! blocks.some( isRegularEntry ) ) {
		return blocks;
	}

	return blocks.filter(
		( block ) => ! ( isPinned ? isRegularEntry : isPinnedCard )( block )
	);
}

/**
 * Whether blocks hold the pinned card, mirroring
 * Rolling_Coverage_Block::has_pinned_card().
 *
 * @param {Object[]} blocks The blocks.
 * @return {boolean} Whether the pinned card is among them.
 */
function hasPinnedCard(
	blocks: {
		name: string;
		attributes?: Record< string, unknown >;
		innerBlocks?: unknown;
	}[]
): boolean {
	return blocks.some(
		( block ) =>
			isPinnedCard( block ) ||
			( Array.isArray( block.innerBlocks ) &&
				hasPinnedCard( block.innerBlocks ) )
	);
}

/**
 * The template with the pinned card's blocks in place of the card, as an
 * entry that isn't pinned renders (see
 * Rolling_Coverage_Block::shape_entry_template()).
 *
 * @param {Object[]} blocks The template blocks.
 * @return {Object[]} The blocks an unpinned entry shows.
 */
function withoutPinnedCard<
	T extends { name: string; [ key: string ]: unknown },
>( blocks: T[] ): T[] {
	return blocks.flatMap( ( block ) => {
		if ( ! Array.isArray( block.innerBlocks ) ) {
			return [ block ];
		}

		const innerBlocks = withoutPinnedCard( block.innerBlocks as T[] );

		return isPinnedCard( block )
			? innerBlocks
			: [ { ...block, innerBlocks } ];
	} );
}

/**
 * The template without the separator that closes it, as a pinned entry and
 * the last entry render.
 *
 * @param {Object[]} blocks The template blocks.
 * @return {Object[]} The blocks without the closing separator.
 */
function withoutClosingSeparator< T extends { name: string } >(
	blocks: T[]
): T[] {
	return blocks.at( -1 )?.name === 'core/separator'
		? blocks.slice( 0, -1 )
		: blocks;
}

/**
 * A block without its bottom margin, mirroring
 * Rolling_Coverage_Block::without_bottom_margin().
 *
 * @param {Object} block The block.
 * @return {Object} The block without its bottom margin.
 */
function withoutBottomMargin< T extends { [ key: string ]: unknown } >(
	block: T
): T {
	const attributes = ( block.attributes ?? {} ) as {
		style?: { spacing?: { margin?: Record< string, unknown > } };
	};
	const { bottom, ...margin } = attributes.style?.spacing?.margin ?? {};

	if ( bottom === undefined ) {
		return block;
	}

	return {
		...block,
		attributes: {
			...attributes,
			style: {
				...attributes.style,
				spacing: { ...attributes.style?.spacing, margin },
			},
		},
	};
}

/**
 * The template with the pinned card changed as a pinned entry renders it:
 * without "Read more", its last block keeps no space below it, and as the
 * last entry, the card keeps none either.
 *
 * @param {Object[]} blocks             The template blocks.
 * @param {Object}   options            How the entry renders.
 * @param {boolean}  options.closeUp    Whether the last block drops its bottom margin.
 * @param {boolean}  options.isLastCard Whether the card drops its own.
 * @return {Object[]} The blocks with the card changed.
 */
function withShapedPinnedCard<
	T extends { name: string; [ key: string ]: unknown },
>(
	blocks: T[],
	{ closeUp, isLastCard }: { closeUp: boolean; isLastCard: boolean }
): T[] {
	return blocks.map( ( block ) => {
		if ( ! Array.isArray( block.innerBlocks ) ) {
			return block;
		}

		const innerBlocks = block.innerBlocks as T[];

		if ( ! isPinnedCard( block ) ) {
			return {
				...block,
				innerBlocks: withShapedPinnedCard( innerBlocks, {
					closeUp,
					isLastCard,
				} ),
			};
		}

		const last = innerBlocks.at( -1 );
		const card = {
			...block,
			innerBlocks:
				closeUp && last
					? [
							...innerBlocks.slice( 0, -1 ),
							withoutBottomMargin( last ),
						]
					: innerBlocks,
		};

		return isLastCard ? withoutBottomMargin( card ) : card;
	} );
}

/**
 * Whether blocks hold a Post Title block.
 *
 * @param {Object[]} blocks The blocks.
 * @return {boolean} Whether a Post Title is among them.
 */
function holdsPostTitle(
	blocks: { name: string; innerBlocks?: unknown }[]
): boolean {
	return blocks.some(
		( block ) =>
			block.name === 'core/post-title' ||
			( Array.isArray( block.innerBlocks ) &&
				holdsPostTitle( block.innerBlocks ) )
	);
}

/**
 * The template as an entry without a title renders it: a row holding the
 * title centers its blocks, mirroring
 * Rolling_Coverage_Block::with_centered_title_rows().
 *
 * @param {Object[]} blocks The template blocks.
 * @return {Object[]} The blocks an entry without a title shows.
 */
function withCenteredTitleRows<
	T extends { name: string; [ key: string ]: unknown },
>( blocks: T[] ): T[] {
	return blocks.map( ( block ) => {
		if ( ! Array.isArray( block.innerBlocks ) ) {
			return block;
		}

		const innerBlocks = withCenteredTitleRows( block.innerBlocks as T[] );
		const attributes = ( block.attributes ?? {} ) as {
			layout?: { type?: string; orientation?: string };
		};
		const isTitleRow =
			block.name === 'core/group' &&
			attributes.layout?.type === 'flex' &&
			attributes.layout?.orientation !== 'vertical' &&
			holdsPostTitle( innerBlocks );

		return isTitleRow
			? {
					...block,
					innerBlocks,
					attributes: {
						...attributes,
						layout: {
							...attributes.layout,
							verticalAlignment: 'center',
						},
					},
				}
			: { ...block, innerBlocks };
	} );
}

/**
 * The template without its Post Title blocks, as an entry without a title
 * renders: core's Post Title block renders nothing for it, where its editor
 * preview would show a placeholder.
 *
 * @param {Object[]} blocks The template blocks.
 * @return {Object[]} The blocks without Post Title.
 */
function withoutPostTitle<
	T extends { name: string; [ key: string ]: unknown },
>( blocks: T[] ): T[] {
	return blocks
		.filter( ( block ) => block.name !== 'core/post-title' )
		.map( ( block ) =>
			Array.isArray( block.innerBlocks )
				? {
						...block,
						innerBlocks: withoutPostTitle(
							block.innerBlocks as T[]
						),
					}
				: block
		);
}

/**
 * Block types allowed inside the per-entry template.
 */
const ENTRY_ALLOWED_BLOCKS = [
	'core/post-title',
	'core/post-date',
	'core/post-content',
	'core/post-excerpt',
	'core/post-featured-image',
	'core/group',
	'core/columns',
	'core/column',
	'core/heading',
	'core/paragraph',
	'core/icon',
	'core/separator',
	'core/buttons',
	'core/button',
	'newspack-rolling-coverage/breakout-post-link',
	'newspack-rolling-coverage/share',
];

/**
 * Builds the className a state's block needs for editor.scss to show/hide it.
 *
 * @param {string} stateValue The state's value (see ENTRY_EDITED_STATES).
 * @return {string} The className for the block's template attrs.
 */
function stateBlockClassName( stateValue: string ): string {
	return `newspack-rolling-coverage-state-block newspack-rolling-coverage-state-block--${ stateValue }`;
}

/**
 * The block's editor states. "default" has no extra blocks. Extend by
 * adding an entry here plus a matching editor.scss rule.
 */
const ENTRY_EDITED_STATES: EntryEditedState[] = [
	{
		value: 'default',
		label: __( 'Default', 'newspack-rolling-coverage' ),
		blocks: [],
	},
	{
		value: 'archived',
		label: __( 'Archived', 'newspack-rolling-coverage' ),
		blocks: [
			[
				'newspack-rolling-coverage/coverage-archived-notice',
				{ className: stateBlockClassName( 'archived' ) },
			],
		],
	},
	{
		value: 'deep-link',
		label: __( 'Deep Link', 'newspack-rolling-coverage' ),
		blocks: [
			[
				'newspack-rolling-coverage/deep-link-cta',
				{ className: stateBlockClassName( 'deep-link' ) },
			],
		],
	},
];

export {
	ENTRY_TEMPLATE,
	ENTRY_ALLOWED_BLOCKS,
	ENTRY_EDITED_STATES,
	FOLLOW_TEMPLATE,
	feedTemplate,
	feedGroupOf,
	feedItems,
	isFollowButtons,
	withoutPinnedRow,
	withoutBreakoutLink,
	breakoutBlockIds,
	withLinkedTitle,
	hasPinnedCard,
	isPinnedCard,
	isRegularEntry,
	forEntryKind,
	withoutPinnedCard,
	withoutClosingSeparator,
	withShapedPinnedCard,
	withCenteredTitleRows,
	withoutPostTitle,
};
