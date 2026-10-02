/**
 * WordPress dependencies
 */
import { getSettings } from '@wordpress/date';
import { escapeHTML } from '@wordpress/escape-html';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { ENTRY_BINDINGS_SOURCE } from '../shared/entry-bindings';
import { POST_DATE_ATTRIBUTES } from '../shared/post-date';
import type { TemplateItem } from './types';

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
 * Class of the paragraph that labels a pinned entry, mirroring
 * Entry_Bindings::PINNED_LABEL_CLASS.
 */
const PINNED_LABEL_CLASS = 'newspack-rolling-coverage-pinned-label';

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
				className: `use-header-font ${ PINNED_LABEL_CLASS }`,
				content: __( 'Pinned', 'newspack-rolling-coverage' ),
				fontSize: 'small',
				style: { typography: { fontWeight: '700' } },
			},
		],
	],
];

/**
 * Class of the group that shows a pinned entry as a card, mirroring
 * Rolling_Coverage_Block::PINNED_CARD_CLASS.
 */
const PINNED_CARD_CLASS = 'newspack-rolling-coverage-pinned-card';

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
 * Class of the paragraph that links to the entry's breakout post, mirroring
 * Entry_Bindings::READ_MORE_CLASS.
 */
const READ_MORE_CLASS = 'newspack-rolling-coverage-read-more';

/**
 * Class of the paragraph that links to the entry's share URL, mirroring
 * Entry_Bindings::SHARE_CLASS.
 */
const SHARE_CLASS = 'newspack-rolling-coverage-share';

const ACCENT =
	'var(--wp--preset--color--accent, var(--newspack-theme-color-primary))';
const ACCENT_CONTRAST =
	'var(--wp--preset--color--accent-contrast, var(--wp--preset--color--base, var(--newspack-theme-color-against-primary)))';
const CONTRAST =
	'var(--wp--preset--color--contrast, var(--newspack-theme-color-text-main, #111))';
const BORDER_COLOR =
	'var(--wp--preset--color--base-3, var(--newspack-theme-color-border, #ddd))';
const PINNED_BACKGROUND = 'var(--wp--custom--color--neutral-5, #f7f7f7)';

/**
 * The corner radius of the Stream layout's pinned card.
 */
const CARD_RADIUS = '0.5rem';

const CARD_PADDING = {
	top: 'var:preset|spacing|50',
	right: 'var:preset|spacing|50',
	bottom: 'var:preset|spacing|50',
	left: 'var:preset|spacing|50',
};

const NO_PADDING = {
	top: '0',
	right: '0',
	bottom: '0',
	left: '0',
};

/**
 * Paragraph content holding a placeholder link, so the editor shows the text
 * as a link. The site points it at the entry's link (see
 * Entry_Bindings::link_paragraph()).
 *
 * @param {string} text The link's text.
 * @return {string} The paragraph content.
 */
function placeholderLink( text: string ): string {
	return `<a href="#">${ escapeHTML( text ) }</a>`;
}

/**
 * The site's time format, for layouts that show the time an entry was posted.
 *
 * @return {string} A PHP date format.
 */
function siteTimeFormat(): string {
	return getSettings().formats.time || 'g:i a';
}

/**
 * The muted date color: the block theme's Contrast 3, else the classic
 * theme's Medium Gray, else the theme's own date color.
 *
 * @param {string[]} slugs The palette's color slugs.
 * @return {Object} The date's color attributes.
 */
function mutedDateColor( slugs: string[] ): { textColor?: string } {
	const slug = [ 'contrast-3', 'medium-gray' ].find( ( candidate ) =>
		slugs.includes( candidate )
	);

	return slug ? { textColor: slug } : {};
}

/**
 * A font size preset the theme defines: the preferred slug where the theme
 * has it, else its fallback. The Newspack Theme names its sizes Normal and
 * Huge where block themes have Medium and X-Large.
 *
 * @param {string[]} sizes     The theme's font size slugs.
 * @param {string}   preferred The preferred slug.
 * @param {string}   fallback  The slug to use where the theme lacks it.
 * @return {string} The slug.
 */
function themeFontSize(
	sizes: string[],
	preferred: string,
	fallback: string
): string {
	return ! sizes.includes( preferred ) && sizes.includes( fallback )
		? fallback
		: preferred;
}

/**
 * The entry's content, without the padding core gives Post Content.
 *
 * @param {string} [fontSize] The content's font size preset.
 * @return {TemplateItem} The Post Content block.
 */
function postContent( fontSize?: string ): TemplateItem {
	return [
		'core/post-content',
		{
			...( fontSize ? { fontSize } : {} ),
			style: { spacing: { padding: NO_PADDING } },
		},
	];
}

/**
 * The "Read more" link to the entry's breakout post, locked against removal.
 *
 * @param {Object} attributes Extra paragraph attributes.
 * @return {TemplateItem} The paragraph.
 */
function readMoreLink(
	attributes: Record< string, unknown > = {}
): TemplateItem {
	return [
		'core/paragraph',
		{
			className: `use-header-font ${ READ_MORE_CLASS }`,
			content: placeholderLink(
				__( 'Read more', 'newspack-rolling-coverage' )
			),
			fontSize: 'small',
			lock: LOCKED,
			metadata: {
				name: __( 'Read more', 'newspack-rolling-coverage' ),
			},
			...attributes,
		},
	];
}

/**
 * The "Share" link to the entry's share URL.
 *
 * @return {TemplateItem} The paragraph.
 */
function shareLink(): TemplateItem {
	return [
		'core/paragraph',
		{
			className: `use-header-font ${ SHARE_CLASS }`,
			content: placeholderLink(
				__( 'Share', 'newspack-rolling-coverage' )
			),
			fontSize: 'small',
			metadata: { name: __( 'Share', 'newspack-rolling-coverage' ) },
		},
	];
}

/**
 * A row of links, wrapping on narrow screens.
 *
 * @param {TemplateItem[]} links The links.
 * @return {TemplateItem} The row.
 */
function linksRow( links: TemplateItem[] ): TemplateItem {
	return [
		'core/group',
		{
			layout: { type: 'flex', verticalAlignment: 'center' },
			style: { spacing: { blockGap: 'var:preset|spacing|40' } },
			metadata: { name: __( 'Links', 'newspack-rolling-coverage' ) },
		},
		links,
	];
}

/**
 * What a Bulletin entry shows: a row with the time and "Share", or on the
 * pinned card the pinned row and the relative date, then a large title, the
 * content and "Read more".
 *
 * @param {boolean}  isPinned Whether the blocks are the pinned card's.
 * @param {string[]} sizes    The theme's font size slugs.
 * @return {TemplateItem[]} The entry's blocks.
 */
function bulletinEntryBlocks(
	isPinned: boolean,
	sizes: string[]
): TemplateItem[] {
	const meta: TemplateItem[] = isPinned
		? [
				PINNED_ROW,
				[
					'core/post-date',
					{
						...POST_DATE_ATTRIBUTES,
						format: 'human-diff',
						fontSize: 'small',
						style: { color: { text: ACCENT_CONTRAST } },
					},
				],
			]
		: [
				[
					'core/post-date',
					{
						...POST_DATE_ATTRIBUTES,
						format: siteTimeFormat(),
						fontSize: 'small',
					},
				],
				shareLink(),
			];

	return [
		[
			'core/group',
			{
				layout: {
					type: 'flex',
					flexWrap: 'wrap',
					verticalAlignment: 'center',
				},
				style: { spacing: { blockGap: 'var:preset|spacing|30' } },
				metadata: { name: __( 'Meta', 'newspack-rolling-coverage' ) },
			},
			meta,
		],
		[
			'core/post-title',
			{
				level: 3,
				fontSize: isPinned
					? themeFontSize( sizes, 'x-large', 'huge' )
					: 'large',
			},
		],
		postContent(),
		readMoreLink(),
	];
}

/**
 * The Bulletin layout's per-entry template, the default: a headline-led
 * entry closed by a separator, which the entry group's Block spacing spaces
 * like its other blocks. The pinned card is set in the accent color,
 * with its text, links and headings in the accent's contrast color.
 *
 * @param {string[]} sizes The theme's font size slugs.
 * @return {TemplateItem[]} The template.
 */
function bulletinEntryTemplate( sizes: string[] ): TemplateItem[] {
	return [
		[
			'core/group',
			{
				className: PINNED_CARD_CLASS,
				lock: LOCKED_IN_PLACE,
				style: {
					color: { background: ACCENT, text: ACCENT_CONTRAST },
					elements: {
						link: { color: { text: ACCENT_CONTRAST } },
						heading: { color: { text: ACCENT_CONTRAST } },
					},
					spacing: {
						padding: CARD_PADDING,
						blockGap: 'var:preset|spacing|30',
					},
				},
				metadata: {
					name: __( 'Pinned Entry', 'newspack-rolling-coverage' ),
				},
			},
			bulletinEntryBlocks( true, sizes ),
		],
		[
			'core/group',
			{
				className: REGULAR_ENTRY_CLASS,
				lock: LOCKED_IN_PLACE,
				style: { spacing: { blockGap: 'var:preset|spacing|30' } },
				metadata: {
					name: __( 'Entry', 'newspack-rolling-coverage' ),
				},
			},
			[
				...bulletinEntryBlocks( false, sizes ),
				[ 'core/separator', { className: 'is-style-wide' } ],
			],
		],
	];
}

/**
 * What a Stream entry shows: no title, the content set larger, then a row
 * with the relative date, "Read more" and "Share". The pinned card's also
 * carry the pinned row.
 *
 * @param {string[]} slugs    The palette's color slugs.
 * @param {string[]} sizes    The theme's font size slugs.
 * @param {boolean}  isPinned Whether the blocks are the pinned card's.
 * @return {TemplateItem[]} The entry's blocks.
 */
function streamEntryBlocks(
	slugs: string[],
	sizes: string[],
	isPinned: boolean
): TemplateItem[] {
	const blocks: TemplateItem[] = [
		postContent( themeFontSize( sizes, 'medium', 'normal' ) ),
		[
			'core/group',
			{
				layout: {
					type: 'flex',
					flexWrap: 'wrap',
					verticalAlignment: 'center',
				},
				style: { spacing: { blockGap: 'var:preset|spacing|30' } },
				metadata: {
					name: __( 'Footer', 'newspack-rolling-coverage' ),
				},
			},
			[
				[
					'core/post-date',
					{
						...POST_DATE_ATTRIBUTES,
						format: 'human-diff',
						fontSize: 'small',
						...mutedDateColor( slugs ),
					},
				],
				readMoreLink(),
				shareLink(),
			],
		],
	];

	return isPinned ? [ PINNED_ROW, ...blocks ] : blocks;
}

/**
 * The Stream layout's per-entry template: untitled entries spaced apart with
 * no separator, and the pinned entry in a bordered card.
 *
 * @param {string[]} slugs The palette's color slugs.
 * @param {string[]} sizes The theme's font size slugs.
 * @return {TemplateItem[]} The template.
 */
function streamEntryTemplate(
	slugs: string[],
	sizes: string[]
): TemplateItem[] {
	return [
		[
			'core/group',
			{
				className: PINNED_CARD_CLASS,
				lock: LOCKED_IN_PLACE,
				style: {
					spacing: {
						padding: CARD_PADDING,
						blockGap: 'var:preset|spacing|30',
					},
					border: {
						color: CONTRAST,
						style: 'solid',
						width: '1px',
						radius: CARD_RADIUS,
					},
				},
				metadata: {
					name: __( 'Pinned Entry', 'newspack-rolling-coverage' ),
				},
			},
			streamEntryBlocks( slugs, sizes, true ),
		],
		[
			'core/group',
			{
				className: REGULAR_ENTRY_CLASS,
				lock: LOCKED_IN_PLACE,
				style: { spacing: { blockGap: 'var:preset|spacing|30' } },
				metadata: {
					name: __( 'Entry', 'newspack-rolling-coverage' ),
				},
			},
			streamEntryBlocks( slugs, sizes, false ),
		],
	];
}

/**
 * A Rail entry's row: the time, or on the pinned card the pin icon, in a
 * narrow column, then the entry beside a vertical rule. The entry is a
 * vertical flex group, so its spacing also applies on the Newspack Theme
 * (see Rolling_Coverage_Block::apply_entry_block_gap()). The pinned card's
 * rule is in the accent color and its entry sits on a tinted panel. With no
 * pinned label, the site announces the entry as pinned itself (see
 * Rolling_Coverage_Block::render_entry()).
 *
 * @param {boolean} isPinned Whether the row is the pinned card's.
 * @return {TemplateItem} The row.
 */
function railRow( isPinned: boolean ): TemplateItem {
	const marker: TemplateItem = isPinned
		? [
				'core/icon',
				{
					icon: PIN_ICON,
					align: 'right',
					style: {
						dimensions: { width: '24px' },
						color: { text: ACCENT },
					},
				},
			]
		: [
				'core/post-date',
				{
					...POST_DATE_ATTRIBUTES,
					format: siteTimeFormat(),
					fontSize: 'small',
					style: {
						typography: { textAlign: 'right', fontWeight: '700' },
					},
				},
			];
	const entry: TemplateItem[] = [
		[ 'core/post-title', { level: 4 } ],
		postContent(),
		linksRow( [ readMoreLink(), shareLink() ] ),
	];

	return [
		'core/columns',
		{
			isStackedOnMobile: false,
			style: {
				spacing: {
					blockGap: { left: '0' },
					margin: { top: '0', bottom: '0' },
				},
			},
			metadata: { name: __( 'Row', 'newspack-rolling-coverage' ) },
		},
		[
			[
				'core/column',
				{
					width: '5.5rem',
					style: {
						spacing: {
							padding: { right: 'var:preset|spacing|30' },
						},
					},
					metadata: {
						name: __( 'Time', 'newspack-rolling-coverage' ),
					},
				},
				[ marker ],
			],
			[
				'core/column',
				{
					style: {
						border: {
							left: {
								color: isPinned ? ACCENT : BORDER_COLOR,
								style: 'solid',
								width: '1px',
							},
						},
						spacing: {
							padding: { left: 'var:preset|spacing|40' },
						},
					},
					metadata: {
						name: __( 'Body', 'newspack-rolling-coverage' ),
					},
				},
				[
					[
						'core/group',
						{
							layout: {
								type: 'flex',
								orientation: 'vertical',
								justifyContent: 'stretch',
							},
							style: {
								...( isPinned
									? {
											color: {
												background: PINNED_BACKGROUND,
											},
										}
									: {} ),
								spacing: {
									...( isPinned
										? {
												padding: {
													top: 'var:preset|spacing|40',
													right: 'var:preset|spacing|40',
													bottom: 'var:preset|spacing|40',
													left: 'var:preset|spacing|40',
												},
											}
										: {} ),
									blockGap: DEFAULT_ENTRY_GAP,
								},
							},
							metadata: {
								name: __(
									'Entry',
									'newspack-rolling-coverage'
								),
							},
						},
						entry,
					],
				],
			],
		],
	];
}

/**
 * The Rail layout's per-entry template: a timeline with the time beside each
 * entry, and no separator.
 *
 * @return {TemplateItem[]} The template.
 */
function railEntryTemplate(): TemplateItem[] {
	return [
		[
			'core/group',
			{
				className: PINNED_CARD_CLASS,
				lock: LOCKED_IN_PLACE,
				metadata: {
					name: __( 'Pinned Entry', 'newspack-rolling-coverage' ),
				},
			},
			[ railRow( true ) ],
		],
		[
			'core/group',
			{
				className: REGULAR_ENTRY_CLASS,
				lock: LOCKED_IN_PLACE,
				metadata: {
					name: __( 'Entry', 'newspack-rolling-coverage' ),
				},
			},
			[ railRow( false ) ],
		],
	];
}

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
 * The "Jump to Latest" button's default colors, as palette slugs: the theme's
 * Contrast and Base where its palette has both, as block themes do; otherwise
 * Dark Gray and White where it has both, as the Newspack Theme does; otherwise
 * Contrast and Base. Mirrors Rolling_Coverage_Block::latest_button_colors().
 *
 * @param {string[]} slugs The palette's color slugs.
 * @return {Object} The background and text color slugs.
 */
function latestColors( slugs: string[] ): {
	backgroundColor: string;
	textColor: string;
} {
	const hasContrastAndBase =
		slugs.includes( 'contrast' ) && slugs.includes( 'base' );

	if (
		! hasContrastAndBase &&
		slugs.includes( 'dark-gray' ) &&
		slugs.includes( 'white' )
	) {
		return { backgroundColor: 'dark-gray', textColor: 'white' };
	}

	return { backgroundColor: 'contrast', textColor: 'base' };
}

/**
 * The "Jump to Latest" button, rendered once above the feed: a core button
 * bound to the live feed's link, in the palette's colors (see latestColors())
 * with the theme's Elevation 1 shadow. The site fixes it to the top of the
 * viewport and shows it when new entries wait, or when the feed opens at a
 * shared entry (see Rolling_Coverage_Block::render_new_entries_control()).
 *
 * @param {string[]} slugs The palette's color slugs.
 * @return {TemplateItem} The button's template.
 */
function latestTemplate( slugs: string[] ): TemplateItem {
	return [
		'core/buttons',
		{
			lock: LOCKED_IN_PLACE,
			className: 'newspack-rolling-coverage-new-entries',
			layout: { type: 'flex', justifyContent: 'center' },
			metadata: {
				name: __( 'Jump to Latest', 'newspack-rolling-coverage' ),
			},
		},
		[
			[
				'core/button',
				{
					lock: LOCKED_IN_PLACE,
					text: __( 'Jump to Latest', 'newspack-rolling-coverage' ),
					...latestColors( slugs ),
					style: { shadow: 'var:preset|shadow|elevation-1' },
					metadata: {
						name: __(
							'Jump to Latest',
							'newspack-rolling-coverage'
						),
						bindings: {
							url: {
								source: ENTRY_BINDINGS_SOURCE,
								args: { key: 'latestUrl' },
							},
						},
					},
				},
			],
		],
	];
}

type ButtonsBlock = {
	name: string;
	innerBlocks?: { attributes?: Record< string, unknown > }[];
};

/**
 * Whether a block is a Buttons block holding a button whose link is bound to
 * one of the entry bindings source's values, mirroring
 * Entry_Bindings::is_buttons_bound_to().
 *
 * @param {Object} block The block.
 * @param {string} key   The bound value's key.
 * @return {boolean} Whether it holds a button bound to the value.
 */
function isButtonsBoundTo( block: ButtonsBlock, key: string ): boolean {
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
				url?.source === ENTRY_BINDINGS_SOURCE && url?.args?.key === key
			);
		} )
	);
}

/**
 * Whether a block is the follow button's Buttons block (see FOLLOW_TEMPLATE),
 * mirroring Entry_Bindings::is_follow_buttons().
 *
 * @param {Object}   block             The block.
 * @param {string}   block.name        Block name.
 * @param {Object[]} block.innerBlocks Inner blocks.
 * @return {boolean} Whether it's the follow button.
 */
function isFollowButtons( block: ButtonsBlock ): boolean {
	return isButtonsBoundTo( block, 'followTag' );
}

/**
 * Whether a block is the "Jump to Latest" button's Buttons block (see
 * latestTemplate()), mirroring Entry_Bindings::is_latest_buttons().
 *
 * @param {Object}   block             The block.
 * @param {string}   block.name        Block name.
 * @param {Object[]} block.innerBlocks Inner blocks.
 * @return {boolean} Whether it's the "Jump to Latest" button.
 */
function isLatestButtons( block: ButtonsBlock ): boolean {
	return isButtonsBoundTo( block, 'latestUrl' );
}

/**
 * The Feed group holding the layout's items: everything the coverage shows,
 * spaced by its Block spacing.
 *
 * @param {Object[]} items The items.
 * @param {string}   gap   The space between the items, as a spacing preset.
 * @return {Object} The Feed group.
 */
function feedTemplate(
	items: TemplateItem[],
	gap = 'var:preset|spacing|50'
): TemplateItem {
	return [
		'core/group',
		{
			className: FEED_CLASS,
			lock: LOCKED_IN_PLACE,
			layout: {
				type: 'flex',
				orientation: 'vertical',
				justifyContent: 'stretch',
			},
			style: { spacing: { blockGap: gap } },
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
 * Whether a block is the paragraph labeling a pinned entry, mirroring
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
	const className = block.attributes?.className;

	return (
		block.name === 'core/paragraph' &&
		typeof className === 'string' &&
		className.split( ' ' ).includes( PINNED_LABEL_CLASS )
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
 * Whether a block is the paragraph that links to the entry's breakout post,
 * mirroring Entry_Bindings::is_read_more_paragraph().
 *
 * @param {Object} block            The block.
 * @param {string} block.name       Block name.
 * @param {Object} block.attributes Block attributes.
 * @return {boolean} Whether it's the "Read more" paragraph.
 */
function isReadMoreParagraph( block: {
	name: string;
	attributes?: Record< string, unknown >;
} ): boolean {
	const className = block.attributes?.className;

	return (
		block.name === 'core/paragraph' &&
		typeof className === 'string' &&
		className.split( ' ' ).includes( READ_MORE_CLASS )
	);
}

/**
 * Whether a block is the "Read more" link to the entry's breakout post: a
 * button whose link is bound to it, the "Read more" paragraph, or the legacy
 * Breakout Post Link block.
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
	if (
		block.name === 'newspack-rolling-coverage/breakout-post-link' ||
		isReadMoreParagraph( block )
	) {
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
 * The template without the separator that closes it or ends the entry
 * group, as a pinned entry and the last entry render (see
 * Rolling_Coverage_Block::without_closing_separator()).
 *
 * @param {Object[]} blocks The template blocks.
 * @return {Object[]} The blocks without the closing separator.
 */
function withoutClosingSeparator<
	T extends {
		name: string;
		attributes?: Record< string, unknown >;
		innerBlocks?: unknown;
	},
>( blocks: T[] ): T[] {
	const last = blocks.at( -1 );

	if ( last?.name === 'core/separator' ) {
		return blocks.slice( 0, -1 );
	}

	const innerBlocks = Array.isArray( last?.innerBlocks )
		? ( last.innerBlocks as { name: string }[] )
		: [];

	if (
		last &&
		isRegularEntry( last ) &&
		innerBlocks.at( -1 )?.name === 'core/separator'
	) {
		return [
			...blocks.slice( 0, -1 ),
			{ ...last, innerBlocks: innerBlocks.slice( 0, -1 ) },
		];
	}

	return blocks;
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

export {
	bulletinEntryTemplate,
	streamEntryTemplate,
	railEntryTemplate,
	ENTRY_ALLOWED_BLOCKS,
	FOLLOW_TEMPLATE,
	feedTemplate,
	feedGroupOf,
	isFeedGroup,
	feedItems,
	latestTemplate,
	isFollowButtons,
	isLatestButtons,
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
