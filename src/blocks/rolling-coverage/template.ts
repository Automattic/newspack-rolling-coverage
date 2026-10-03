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
import { FOLLOW_BUTTONS_TEMPLATE } from '../shared/follow-buttons';
import { POST_DATE_ATTRIBUTES } from '../shared/post-date';
import type { TemplateItem } from './types';

const LOCKED = { remove: true, move: false };

/**
 * The Feed group and the groups wrapping it stay in place, and the pinned
 * card and the entry group at the Feed's top level, where the template is
 * split by kind of entry.
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
 *
 * @param {string} [color] The row's text color.
 * @return {TemplateItem} The row.
 */
function pinnedRow( color?: string ): TemplateItem {
	return [
		'core/group',
		{
			layout: {
				type: 'flex',
				flexWrap: 'nowrap',
				verticalAlignment: 'center',
			},
			style: {
				...( color ? { color: { text: color } } : {} ),
				spacing: { blockGap: '0' },
			},
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
}

const PINNED_ROW = pinnedRow();

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

/**
 * Class of the paragraph that links to the coverage page's full feed,
 * mirroring Entry_Bindings::ALL_UPDATES_CLASS.
 */
const ALL_UPDATES_CLASS = 'newspack-rolling-coverage-all-updates';

/**
 * The Follow Coverage block, which sits once among the layout's coverage-level
 * blocks.
 */
const FOLLOW_BLOCK_NAME = 'newspack-rolling-coverage/coverage-follow';

/**
 * The Coverage Status block, which shows the coverage's status once when it
 * sits among the layout's coverage-level blocks.
 */
const STATUS_BLOCK_NAME = 'newspack-rolling-coverage/coverage-status';

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
 * The "See all updates" link to the coverage page, shown once by a capped
 * feed.
 *
 * @param {Object} attributes Extra paragraph settings, such as its alignment.
 * @return {TemplateItem} The paragraph.
 */
function allUpdatesLink(
	attributes: Record< string, unknown > = {}
): TemplateItem {
	return [
		'core/paragraph',
		{
			className: `use-header-font ${ ALL_UPDATES_CLASS }`,
			content: placeholderLink(
				__( 'See all updates', 'newspack-rolling-coverage' )
			),
			fontSize: 'small',
			metadata: {
				name: __( 'See all updates', 'newspack-rolling-coverage' ),
			},
			...attributes,
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
						blockGap: 'var:preset|spacing|20',
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
				style: { spacing: { blockGap: 'var:preset|spacing|20' } },
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
	return rowEntryTemplate( railRow );
}

/**
 * Blocks stacked in a vertical flex group, so their Block spacing also
 * applies on the Newspack Theme (see
 * Rolling_Coverage_Block::apply_entry_block_gap()).
 *
 * @param {string}         name   The group's name in the List View.
 * @param {TemplateItem[]} blocks The blocks.
 * @return {TemplateItem} The group.
 */
function stack( name: string, blocks: TemplateItem[] ): TemplateItem {
	return [
		'core/group',
		{
			layout: {
				type: 'flex',
				orientation: 'vertical',
				justifyContent: 'stretch',
			},
			style: { spacing: { blockGap: DEFAULT_ENTRY_GAP } },
			metadata: { name },
		},
		blocks,
	];
}

/**
 * An entry's columns, ruled off from the entry above by a top border. The
 * space below the rule matches the Feed's Block spacing above it, and the
 * columns stack on narrow screens.
 *
 * @param {Object}         border       The top border.
 * @param {string}         border.color Its color.
 * @param {string}         border.width Its width.
 * @param {string}         stackedGap   The space between the stacked columns.
 * @param {TemplateItem[]} columns      The columns.
 * @return {TemplateItem} The row.
 */
function ruledRow(
	border: { color: string; width: string },
	stackedGap: string,
	columns: TemplateItem[]
): TemplateItem {
	return [
		'core/columns',
		{
			style: {
				border: { top: { ...border, style: 'solid' } },
				spacing: {
					blockGap: {
						top: stackedGap,
						left: 'var:preset|spacing|40',
					},
					padding: { top: 'var:preset|spacing|50' },
					margin: { top: '0', bottom: '0' },
				},
			},
			metadata: { name: __( 'Row', 'newspack-rolling-coverage' ) },
		},
		columns,
	];
}

/**
 * A Clock entry's row: the time set large in a narrow column with the
 * relative date below it, or on the pinned card the pinned row in the
 * accent color, then the title, content and links beside it.
 *
 * @param {string[]} slugs    The palette's color slugs.
 * @param {string[]} sizes    The theme's font size slugs.
 * @param {boolean}  isPinned Whether the row is the pinned card's.
 * @return {TemplateItem} The row.
 */
function clockRow(
	slugs: string[],
	sizes: string[],
	isPinned: boolean
): TemplateItem {
	const time: TemplateItem = isPinned
		? pinnedRow( ACCENT )
		: stack( __( 'Dates', 'newspack-rolling-coverage' ), [
				[
					'core/post-date',
					{
						...POST_DATE_ATTRIBUTES,
						format: siteTimeFormat(),
						fontSize: themeFontSize( sizes, 'x-large', 'large' ),
						style: {
							color: { text: CONTRAST },
							typography: { fontWeight: '300' },
						},
					},
				],
				[
					'core/post-date',
					{
						...POST_DATE_ATTRIBUTES,
						format: 'human-diff',
						fontSize: 'small',
						...mutedDateColor( slugs ),
						style: { typography: { fontWeight: '600' } },
					},
				],
			] );

	return ruledRow( { color: BORDER_COLOR, width: '1px' }, DEFAULT_ENTRY_GAP, [
		[
			'core/column',
			{
				width: '9.5rem',
				metadata: { name: __( 'Time', 'newspack-rolling-coverage' ) },
			},
			[ time ],
		],
		[
			'core/column',
			{ metadata: { name: __( 'Body', 'newspack-rolling-coverage' ) } },
			[
				stack( __( 'Entry', 'newspack-rolling-coverage' ), [
					[ 'core/post-title', { level: 4 } ],
					postContent(),
					linksRow( [ readMoreLink(), shareLink() ] ),
				] ),
			],
		],
	] );
}

/**
 * A Margin entry's row: the time, or on the pinned card the pinned row,
 * the title and the links in a margin column, and the content in the wider
 * column beside it. The pinned card is ruled off with a heavier rule.
 *
 * @param {boolean} isPinned Whether the row is the pinned card's.
 * @return {TemplateItem} The row.
 */
function marginRow( isPinned: boolean ): TemplateItem {
	const marker: TemplateItem = isPinned
		? PINNED_ROW
		: [
				'core/post-date',
				{
					...POST_DATE_ATTRIBUTES,
					format: siteTimeFormat(),
					fontSize: 'small',
				},
			];

	return ruledRow(
		isPinned
			? { color: CONTRAST, width: '3px' }
			: { color: BORDER_COLOR, width: '1px' },
		'var:preset|spacing|30',
		[
			[
				'core/column',
				{
					width: '33.33%',
					metadata: {
						name: __( 'Side', 'newspack-rolling-coverage' ),
					},
				},
				[
					stack( __( 'Summary', 'newspack-rolling-coverage' ), [
						marker,
						[ 'core/post-title', { level: 4 } ],
						linksRow( [ readMoreLink(), shareLink() ] ),
					] ),
				],
			],
			[
				'core/column',
				{
					width: '66.66%',
					metadata: {
						name: __( 'Body', 'newspack-rolling-coverage' ),
					},
				},
				[ postContent() ],
			],
		]
	);
}

/**
 * A per-entry template whose pinned card and entry group each hold one row.
 *
 * @param {Function} row Returns the row, given whether it's the pinned card's.
 * @return {TemplateItem[]} The template.
 */
function rowEntryTemplate(
	row: ( isPinned: boolean ) => TemplateItem
): TemplateItem[] {
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
			[ row( true ) ],
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
			[ row( false ) ],
		],
	];
}

/**
 * The Clock layout's per-entry template: each entry headed by the time it
 * was posted, set large, and ruled off from the one above.
 *
 * @param {string[]} slugs The palette's color slugs.
 * @param {string[]} sizes The theme's font size slugs.
 * @return {TemplateItem[]} The template.
 */
function clockEntryTemplate(
	slugs: string[],
	sizes: string[]
): TemplateItem[] {
	return rowEntryTemplate( ( isPinned ) =>
		clockRow( slugs, sizes, isPinned )
	);
}

/**
 * The Margin layout's per-entry template: a broadsheet split, each entry
 * ruled off from the one above.
 *
 * @return {TemplateItem[]} The template.
 */
function marginEntryTemplate(): TemplateItem[] {
	return rowEntryTemplate( marginRow );
}

/**
 * The Minute layout's per-entry template: each entry is only its content,
 * ruled off from the one above. The pinned card is shaded.
 *
 * @return {TemplateItem[]} The template.
 */
function minuteEntryTemplate(): TemplateItem[] {
	const flex = {
		type: 'flex',
		orientation: 'vertical',
		justifyContent: 'stretch',
	};

	return [
		[
			'core/group',
			{
				className: PINNED_CARD_CLASS,
				lock: LOCKED_IN_PLACE,
				layout: flex,
				style: {
					color: { background: PINNED_BACKGROUND },
					spacing: {
						blockGap: DEFAULT_ENTRY_GAP,
						padding: {
							top: 'var:preset|spacing|40',
							right: 'var:preset|spacing|40',
							bottom: 'var:preset|spacing|40',
							left: 'var:preset|spacing|40',
						},
					},
				},
				metadata: {
					name: __( 'Pinned Entry', 'newspack-rolling-coverage' ),
				},
			},
			[ pinnedRow( ACCENT ), postContent() ],
		],
		[
			'core/group',
			{
				className: REGULAR_ENTRY_CLASS,
				lock: LOCKED_IN_PLACE,
				layout: flex,
				style: {
					border: {
						top: {
							color: BORDER_COLOR,
							width: '1px',
							style: 'solid',
						},
					},
					spacing: {
						blockGap: DEFAULT_ENTRY_GAP,
						padding: { top: 'var:preset|spacing|30' },
					},
				},
				metadata: {
					name: __( 'Entry', 'newspack-rolling-coverage' ),
				},
			},
			[ postContent() ],
		],
	];
}

/**
 * A Byline entry's row: the author's avatar in a narrow column that stays
 * beside the entry at every width, then the byline, title, content and
 * links. The pinned entry differs only by the pinned label after its time.
 *
 * @param {string[]} slugs    The palette's color slugs.
 * @param {boolean}  isPinned Whether the row is the pinned card's.
 * @return {TemplateItem} The row.
 */
function bylineRow( slugs: string[], isPinned: boolean ): TemplateItem {
	const entry: TemplateItem[] = [
		[
			'core/group',
			{
				layout: {
					type: 'flex',
					flexWrap: 'wrap',
					verticalAlignment: 'center',
				},
				style: { spacing: { blockGap: 'var:preset|spacing|20' } },
				metadata: { name: __( 'Byline', 'newspack-rolling-coverage' ) },
			},
			[
				[
					'core/post-author-name',
					{
						className: 'use-header-font',
						fontSize: 'small',
						style: { typography: { fontWeight: '700' } },
					},
				],
				[
					'core/post-date',
					{
						...POST_DATE_ATTRIBUTES,
						format: siteTimeFormat(),
						fontSize: 'small',
						...mutedDateColor( slugs ),
					},
				],
				...( isPinned ? [ pinnedRow( ACCENT ) ] : [] ),
			],
		],
		[ 'core/post-title', { level: 4 } ],
		postContent(),
		linksRow( [ readMoreLink(), shareLink() ] ),
	];

	return [
		'core/columns',
		{
			isStackedOnMobile: false,
			style: {
				border: {
					top: { color: BORDER_COLOR, width: '1px', style: 'solid' },
				},
				spacing: {
					blockGap: { left: 'var:preset|spacing|30' },
					padding: { top: 'var:preset|spacing|50' },
					margin: { top: '0', bottom: '0' },
				},
			},
			metadata: { name: __( 'Row', 'newspack-rolling-coverage' ) },
		},
		[
			[
				'core/column',
				{
					width: '40px',
					metadata: {
						name: __( 'Avatar', 'newspack-rolling-coverage' ),
					},
				},
				[
					[
						'core/avatar',
						{ size: 40, style: { border: { radius: '50%' } } },
					],
				],
			],
			[
				'core/column',
				{
					metadata: {
						name: __( 'Body', 'newspack-rolling-coverage' ),
					},
				},
				[ stack( __( 'Entry', 'newspack-rolling-coverage' ), entry ) ],
			],
		],
	];
}

/**
 * The Byline layout's per-entry template: each entry signed with its
 * author's avatar and name, and ruled off from the one above.
 *
 * @param {string[]} slugs The palette's color slugs.
 * @return {TemplateItem[]} The template.
 */
function bylineEntryTemplate( slugs: string[] ): TemplateItem[] {
	return rowEntryTemplate( ( isPinned ) => bylineRow( slugs, isPinned ) );
}

/**
 * The Wire layout's per-entry template: the time, headline and a short
 * excerpt, ruled off from the entry above. The pinned card matches the
 * regular entry, since a capped feed ignores pinning.
 *
 * @param {string[]} slugs The palette's color slugs.
 * @param {string[]} sizes The theme's font size slugs.
 * @return {TemplateItem[]} The template.
 */
function wireEntryTemplate( slugs: string[], sizes: string[] ): TemplateItem[] {
	const entry = ( className: string, name: string ): TemplateItem => [
		'core/group',
		{
			className,
			lock: LOCKED_IN_PLACE,
			layout: {
				type: 'flex',
				orientation: 'vertical',
				justifyContent: 'stretch',
			},
			style: {
				border: {
					top: {
						color: BORDER_COLOR,
						width: '1px',
						style: 'solid',
					},
				},
				spacing: {
					blockGap: 'var:preset|spacing|10',
					padding: { top: 'var:preset|spacing|30' },
				},
			},
			metadata: { name },
		},
		[
			[
				'core/post-date',
				{
					...POST_DATE_ATTRIBUTES,
					format: siteTimeFormat(),
					fontSize: 'small',
					style: { typography: { fontWeight: '700' } },
				},
			],
			[
				'core/post-title',
				{
					level: 4,
					fontSize: themeFontSize( sizes, 'medium', 'normal' ),
				},
			],
			[
				'core/post-excerpt',
				{
					excerptLength: 15,
					moreText: '',
					fontSize: 'small',
					...mutedDateColor( slugs ),
				},
			],
		],
	];

	return [
		entry(
			PINNED_CARD_CLASS,
			__( 'Pinned Entry', 'newspack-rolling-coverage' )
		),
		entry(
			REGULAR_ENTRY_CLASS,
			__( 'Entry', 'newspack-rolling-coverage' )
		),
	];
}

const DIGEST_FEED_STYLE = {
	border: { color: CONTRAST, width: '1px', style: 'solid' },
	spacing: { padding: 'var:preset|spacing|50' },
};

/**
 * The Digest layout's header: the coverage's name as a heading, bound so it
 * follows the coverage.
 *
 * @return {TemplateItem} The heading.
 */
function digestHeader(): TemplateItem {
	return [
		'core/heading',
		{
			level: 3,
			fontSize: 'large',
			content: __( 'Live Coverage', 'newspack-rolling-coverage' ),
			metadata: {
				name: __( 'Coverage Name', 'newspack-rolling-coverage' ),
				bindings: {
					content: {
						source: ENTRY_BINDINGS_SOURCE,
						args: { key: 'coverageName' },
					},
				},
			},
		},
	];
}

/**
 * The Digest layout's footer: the link to the coverage page beside the Follow
 * button, ruled off from the entries.
 *
 * @return {TemplateItem} The group.
 */
function digestFooter(): TemplateItem {
	return [
		'core/group',
		{
			layout: {
				type: 'flex',
				flexWrap: 'wrap',
				justifyContent: 'space-between',
				verticalAlignment: 'center',
			},
			style: {
				border: {
					top: {
						color: BORDER_COLOR,
						width: '1px',
						style: 'solid',
					},
				},
				spacing: { padding: { top: 'var:preset|spacing|40' } },
			},
			metadata: { name: __( 'Footer', 'newspack-rolling-coverage' ) },
		},
		[ allUpdatesLink(), FOLLOW_TEMPLATE ],
	];
}

/**
 * A Digest entry's row: the time in a narrow column, then the headline over a
 * short excerpt.
 *
 * @param {string[]} slugs The palette's color slugs.
 * @param {string[]} sizes The theme's font size slugs.
 * @return {TemplateItem} The row.
 */
function digestRow( slugs: string[], sizes: string[] ): TemplateItem {
	return [
		'core/columns',
		{
			isStackedOnMobile: false,
			style: {
				border: {
					top: {
						color: BORDER_COLOR,
						width: '1px',
						style: 'solid',
					},
				},
				spacing: {
					blockGap: { left: 'var:preset|spacing|30' },
					padding: { top: 'var:preset|spacing|30' },
					margin: { top: '0', bottom: '0' },
				},
			},
			metadata: {
				name: __( 'Row', 'newspack-rolling-coverage' ),
			},
		},
		[
			[
				'core/column',
				{ width: '5.5rem' },
				[
					[
						'core/post-date',
						{
							...POST_DATE_ATTRIBUTES,
							format: siteTimeFormat(),
							fontSize: 'small',
							style: { typography: { fontWeight: '700' } },
						},
					],
				],
			],
			[
				'core/column',
				{},
				[
					stack( __( 'Details', 'newspack-rolling-coverage' ), [
						[
							'core/post-title',
							{
								level: 4,
								fontSize: themeFontSize(
									sizes,
									'medium',
									'normal'
								),
							},
						],
						[
							'core/post-excerpt',
							{
								excerptLength: 20,
								moreText: '',
								fontSize: 'small',
								...mutedDateColor( slugs ),
							},
						],
					] ),
				],
			],
		],
	];
}

const FLASH_BAR_STYLE = {
	color: { background: ACCENT, text: ACCENT_CONTRAST },
	elements: { link: { color: { text: ACCENT_CONTRAST } } },
	spacing: {
		padding: {
			top: 'var:preset|spacing|30',
			bottom: 'var:preset|spacing|30',
			left: 'var:preset|spacing|50',
			right: 'var:preset|spacing|50',
		},
	},
};

const FLASH_FEED_LAYOUT = {
	type: 'flex',
	orientation: 'horizontal',
	flexWrap: 'wrap',
	justifyContent: 'left',
	verticalAlignment: 'center',
};

/**
 * The Flash layout's bar: a group on the site's accent color spanning the
 * block, its content laid out at the theme's widths so a wide Feed lines up
 * with the site's wide content.
 *
 * @param {TemplateItem} feed The Feed group.
 * @return {TemplateItem} The bar.
 */
function flashBar( feed: TemplateItem ): TemplateItem {
	return [
		'core/group',
		{
			lock: LOCKED_IN_PLACE,
			layout: { type: 'constrained' },
			style: FLASH_BAR_STYLE,
			metadata: { name: __( 'Bar', 'newspack-rolling-coverage' ) },
		},
		[ feed ],
	];
}

/**
 * The Flash layout's per-entry template: the time and the entry's text on one
 * row. The pinned card matches the regular entry, since a capped feed ignores
 * pinning.
 *
 * @return {TemplateItem[]} The template.
 */
function flashEntryTemplate(): TemplateItem[] {
	const entry = ( className: string, name: string ): TemplateItem => [
		'core/group',
		{
			className,
			lock: LOCKED_IN_PLACE,
			layout: {
				type: 'flex',
				flexWrap: 'wrap',
				verticalAlignment: 'center',
			},
			style: { spacing: { blockGap: 'var:preset|spacing|30' } },
			metadata: { name },
		},
		[
			[
				'core/post-date',
				{
					...POST_DATE_ATTRIBUTES,
					format: siteTimeFormat(),
					fontSize: 'small',
					style: { typography: { fontWeight: '700' } },
				},
			],
			[
				'core/post-excerpt',
				{
					excerptLength: 20,
					moreText: '',
					fontSize: 'small',
				},
			],
		],
	];

	return [
		entry(
			PINNED_CARD_CLASS,
			__( 'Pinned Entry', 'newspack-rolling-coverage' )
		),
		entry(
			REGULAR_ENTRY_CLASS,
			__( 'Entry', 'newspack-rolling-coverage' )
		),
	];
}

/**
 * The Digest layout's per-entry template: the time and, beside it, the
 * headline over a short excerpt, ruled off from the entry above. The pinned
 * card matches the regular entry, since a capped feed ignores pinning.
 *
 * @param {string[]} slugs The palette's color slugs.
 * @param {string[]} sizes The theme's font size slugs.
 * @return {TemplateItem[]} The template.
 */
function digestEntryTemplate(
	slugs: string[],
	sizes: string[]
): TemplateItem[] {
	return rowEntryTemplate( () => digestRow( slugs, sizes ) );
}

/**
 * The follow button, rendered once wherever the layout places it: the Follow
 * Coverage block, which holds the core button bound to the coverage's
 * notification tag.
 */
const FOLLOW_TEMPLATE: TemplateItem = [
	FOLLOW_BLOCK_NAME,
	{ lock: LOCKED },
	[ FOLLOW_BUTTONS_TEMPLATE ],
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
 * Whether a block is a heading bound to the coverage's name, mirroring
 * Entry_Bindings::is_coverage_name_heading().
 *
 * @param {Object} block            The block.
 * @param {string} block.name       Block name.
 * @param {Object} block.attributes Block attributes.
 * @return {boolean} Whether it's the coverage name heading.
 */
function isCoverageNameHeading( block: {
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
		block.name === 'core/heading' &&
		content?.source === ENTRY_BINDINGS_SOURCE &&
		content?.args?.key === 'coverageName'
	);
}

/**
 * Whether a block is the paragraph linking to the coverage page's full feed,
 * mirroring Entry_Bindings::is_all_updates_paragraph().
 *
 * @param {Object} block            The block.
 * @param {string} block.name       Block name.
 * @param {Object} block.attributes Block attributes.
 * @return {boolean} Whether it's the "See all updates" paragraph.
 */
function isAllUpdatesParagraph( block: {
	name: string;
	attributes?: Record< string, unknown >;
} ): boolean {
	const className = block.attributes?.className;

	return (
		block.name === 'core/paragraph' &&
		typeof className === 'string' &&
		className.split( ' ' ).includes( ALL_UPDATES_CLASS )
	);
}

/**
 * Whether a block belongs to the coverage rather than to each entry, so it
 * renders once: the Follow Coverage block, the "Jump to Latest" button,
 * the Coverage Status block, a heading bound to the coverage's name,
 * the "See all updates" paragraph, or a block holding one at any depth,
 * mirroring
 * Entry_Bindings::is_coverage_item(). The pinned card and the entry group
 * always belong to each entry, whatever they hold.
 *
 * @param {Object} block      The block.
 * @param {string} block.name Block name.
 * @return {boolean} Whether it's a coverage-level block.
 */
function isCoverageItem( block: {
	name: string;
	[ key: string ]: unknown;
} ): boolean {
	const typed = block as ButtonsBlock & {
		attributes?: Record< string, unknown >;
	};

	if ( isPinnedCard( typed ) || isRegularEntry( typed ) ) {
		return false;
	}

	return (
		typed.name === FOLLOW_BLOCK_NAME ||
		block.name === STATUS_BLOCK_NAME ||
		isLatestButtons( typed ) ||
		isCoverageNameHeading( typed ) ||
		isAllUpdatesParagraph( typed ) ||
		( Array.isArray( block.innerBlocks ) &&
			( block.innerBlocks as { name: string }[] ).some( isCoverageItem ) )
	);
}

/**
 * The layout's items split by where they render, mirroring
 * Rolling_Coverage_Block::layout_parts(): the coverage-level items before the
 * first per-entry item go above the entries, the per-entry items make the
 * entry template, and the coverage-level items after it go below the
 * entries. "Jump to Latest" renders in its own place, so it's in neither
 * list.
 *
 * @param {Object[]} items The layout's items.
 * @return {Object} The header, template and footer blocks.
 */
function layoutParts< T extends { name: string; [ key: string ]: unknown } >(
	items: T[]
): { header: T[]; template: T[]; footer: T[] } {
	return items.reduce(
		( parts, item ) => {
			if ( ! isCoverageItem( item ) ) {
				parts.template.push( item );
			} else if ( ! isLatestButtons( item as ButtonsBlock ) ) {
				( parts.template.length ? parts.footer : parts.header ).push(
					item
				);
			}

			return parts;
		},
		{ header: [] as T[], template: [] as T[], footer: [] as T[] }
	);
}

/**
 * The blocks without those matching a test, at any depth, and without the
 * groups that leaves empty, as the site renders coverage-level blocks (see
 * Rolling_Coverage_Block::render_coverage_blocks()).
 *
 * @param {Object[]} blocks    The blocks.
 * @param {Function} isDropped Whether a block is left out.
 * @return {Object[]} The blocks left.
 */
function withoutBlocks< T extends { name: string; [ key: string ]: unknown } >(
	blocks: T[],
	isDropped: ( block: T ) => boolean
): T[] {
	return blocks.flatMap( ( block ) => {
		if ( isDropped( block ) ) {
			return [];
		}

		if (
			! Array.isArray( block.innerBlocks ) ||
			! block.innerBlocks.length
		) {
			return [ block ];
		}

		const innerBlocks = withoutBlocks(
			block.innerBlocks as T[],
			isDropped
		);

		return block.name === 'core/group' && ! innerBlocks.length
			? []
			: [ { ...block, innerBlocks } ];
	} );
}

/**
 * The blocks without their follow buttons, at any depth, as the site renders
 * them where the coverage can't be followed.
 *
 * @param {Object[]} blocks The blocks.
 * @return {Object[]} The blocks without follow buttons.
 */
function withoutFollowButtons<
	T extends { name: string; [ key: string ]: unknown },
>( blocks: T[] ): T[] {
	return withoutBlocks(
		blocks,
		( block ) => block.name === FOLLOW_BLOCK_NAME
	);
}

/**
 * Blocks without the "Jump to Latest" button, at any depth, as everywhere
 * but its own control renders them (see
 * Rolling_Coverage_Block::render_coverage_blocks()).
 *
 * @param {Object[]} blocks Blocks.
 * @return {Object[]} The blocks without it.
 */
function withoutLatestButtons<
	T extends { name: string; [ key: string ]: unknown },
>( blocks: T[] ): T[] {
	return withoutBlocks( blocks, ( block ) =>
		isLatestButtons( block as ButtonsBlock )
	);
}

/**
 * The client IDs of the follow buttons among the blocks, at any depth.
 *
 * @param {Object[]} blocks The blocks.
 * @return {string[]} Client IDs.
 */
function followBlockIds(
	blocks: { name: string; [ key: string ]: unknown }[]
): string[] {
	return blocks.flatMap( ( block ) =>
		block.name === FOLLOW_BLOCK_NAME
			? [ block.clientId as string ]
			: followBlockIds(
					Array.isArray( block.innerBlocks )
						? ( block.innerBlocks as typeof blocks )
						: []
				)
	);
}

/**
 * The blocks without the "See all updates" paragraph, at any depth, as the
 * site renders them where the link has nothing to show.
 *
 * @param {Object[]} blocks The blocks.
 * @return {Object[]} The blocks without the paragraph.
 */
function withoutAllUpdatesParagraph<
	T extends { name: string; [ key: string ]: unknown },
>( blocks: T[] ): T[] {
	return withoutBlocks( blocks, isAllUpdatesParagraph );
}

/**
 * The client IDs of the "See all updates" paragraphs among the blocks, at
 * any depth.
 *
 * @param {Object[]} blocks The blocks.
 * @return {string[]} Client IDs.
 */
function allUpdatesBlockIds(
	blocks: { name: string; [ key: string ]: unknown }[]
): string[] {
	return blocks.flatMap( ( block ) =>
		isAllUpdatesParagraph( block )
			? [ block.clientId as string ]
			: allUpdatesBlockIds(
					Array.isArray( block.innerBlocks )
						? ( block.innerBlocks as typeof blocks )
						: []
				)
	);
}

/**
 * The client IDs of the coverage-level groups whose inner blocks are all
 * hidden, at any depth, as the site leaves such a group out.
 *
 * @param {Object[]} blocks    The layout's items.
 * @param {string[]} hiddenIds The client IDs of the hidden blocks.
 * @return {string[]} Client IDs.
 */
function emptiedGroupIds(
	blocks: { name: string; [ key: string ]: unknown }[],
	hiddenIds: string[]
): string[] {
	const hidden = new Set( hiddenIds );
	const emptied: string[] = [];
	const isHidden = ( block: {
		name: string;
		[ key: string ]: unknown;
	} ): boolean => {
		if ( hidden.has( block.clientId as string ) ) {
			return true;
		}

		const inner = Array.isArray( block.innerBlocks )
			? ( block.innerBlocks as typeof blocks )
			: [];
		const innerHidden = inner.map( isHidden );

		if (
			block.name === 'core/group' &&
			inner.length > 0 &&
			innerHidden.every( Boolean )
		) {
			emptied.push( block.clientId as string );
			return true;
		}

		return false;
	};

	blocks.filter( isCoverageItem ).forEach( isHidden );

	return emptied;
}

/**
 * The Feed group holding the layout's items: everything the coverage shows,
 * spaced by its Block spacing.
 *
 * @param {Object[]} items      The items.
 * @param {string}   gap        The space between the items, as a spacing preset.
 * @param {Object}   style      Extra style settings, such as a border or padding.
 * @param {Object}   layout     The group's layout, a vertical stack by default.
 * @param {Object}   attributes Extra group settings, such as its alignment.
 * @return {Object} The Feed group.
 */
function feedTemplate(
	items: TemplateItem[],
	gap = 'var:preset|spacing|50',
	style: Record< string, unknown > = {},
	layout: Record< string, unknown > = {
		type: 'flex',
		orientation: 'vertical',
		justifyContent: 'stretch',
		// A wrapping column sizes each item's height at its fit-content width, so text that wraps narrower leaves space below it.
		flexWrap: 'nowrap',
	},
	attributes: Record< string, unknown > = {}
): TemplateItem {
	return [
		'core/group',
		{
			...attributes,
			className: FEED_CLASS,
			lock: LOCKED_IN_PLACE,
			layout,
			style: {
				...style,
				spacing: { ...( style.spacing ?? {} ), blockGap: gap },
			},
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
 * The groups leading to the layout's Feed group, from the outermost wrapper
 * group down to the Feed itself, which may sit at the layout's top level or
 * inside plain groups, mirroring Rolling_Coverage_Block::feed_path().
 *
 * @param {Object[]} blocks The layout's top-level blocks.
 * @return {Object[]} The groups, the Feed last, or none for a layout without one.
 */
function feedPathOf< T extends { name: string; [ key: string ]: unknown } >(
	blocks: T[]
): T[] {
	for ( const block of blocks ) {
		if ( block.name !== 'core/group' ) {
			continue;
		}

		if (
			isFeedGroup(
				block as {
					name: string;
					attributes?: Record< string, unknown >;
				}
			)
		) {
			return [ block ];
		}

		const path = Array.isArray( block.innerBlocks )
			? feedPathOf( block.innerBlocks as T[] )
			: [];

		if ( path.length > 0 ) {
			return [ block, ...path ];
		}
	}

	return [];
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
	return feedPathOf( blocks ).pop();
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
	'core/post-author-name',
	'core/avatar',
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
	clockEntryTemplate,
	marginEntryTemplate,
	minuteEntryTemplate,
	bylineEntryTemplate,
	wireEntryTemplate,
	digestEntryTemplate,
	digestHeader,
	digestFooter,
	flashEntryTemplate,
	flashBar,
	FLASH_FEED_LAYOUT,
	DIGEST_FEED_STYLE,
	ENTRY_ALLOWED_BLOCKS,
	ALL_UPDATES_CLASS,
	FOLLOW_BLOCK_NAME,
	STATUS_BLOCK_NAME,
	FOLLOW_TEMPLATE,
	feedTemplate,
	feedGroupOf,
	feedPathOf,
	isFeedGroup,
	feedItems,
	latestTemplate,
	isLatestButtons,
	isCoverageNameHeading,
	isAllUpdatesParagraph,
	isCoverageItem,
	layoutParts,
	withoutFollowButtons,
	withoutLatestButtons,
	followBlockIds,
	allUpdatesLink,
	allUpdatesBlockIds,
	withoutAllUpdatesParagraph,
	emptiedGroupIds,
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
