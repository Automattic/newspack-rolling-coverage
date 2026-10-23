/**
 * WordPress dependencies
 */
import { getSettings } from '@wordpress/date';
import { escapeHTML } from '@wordpress/escape-html';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { ENTRY_PLURAL } from './config';
import { ENTRY_BINDINGS_SOURCE } from '../shared/entry-bindings';
import { POST_DATE_ATTRIBUTES } from '../shared/post-date';
import { blockGapCss } from './spacing';
import { loadMoreButtonsTemplate } from '../shared/load-more-buttons';
import { mutedTextColor } from '../shared/muted-color';
import { themeFontSize } from '../shared/theme-font-size';
import type { TemplateItem } from './types';

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
 * Class of a Feed group drawing a rule centered in every gap between its
 * items, doubling its Block spacing to make room (see style.scss).
 */
const RULED_FEED_CLASS = 'newspack-rolling-coverage-ruled';

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
 * Class of the paragraph that marks an entry as new once it arrives,
 * mirroring Entry_Bindings::NEW_LABEL_CLASS.
 */
const NEW_LABEL_CLASS = 'newspack-rolling-coverage-new-label';

/**
 * The label that marks an entry as new once it arrives. The site renders it
 * hidden and the view script reveals it, so a layout places it where the
 * mark should show.
 *
 * @param {Object}  [options]        How the label shows.
 * @param {string}  [options.color]  The label's text color.
 * @param {boolean} [options.hidden] Whether the label is for screen readers
 *                                   only, for a layout whose entries already
 *                                   look new. Default false.
 * @return {TemplateItem} The label.
 */
function newLabel( {
	color = READABLE_ACCENT,
	hidden = false,
}: { color?: string; hidden?: boolean } = {} ): TemplateItem {
	return [
		'core/paragraph',
		{
			// The Newspack Theme's class for its heading font, which it also gives the date.
			className: `use-header-font ${ NEW_LABEL_CLASS }${
				hidden ? ' screen-reader-text' : ''
			}`,
			content: __( 'New', 'newspack-rolling-coverage' ),
			fontSize: 'small',
			style: {
				typography: { fontWeight: '700' },
				...( color ? { color: { text: color } } : {} ),
			},
		},
	];
}

/**
 * The pin icon and the pinned label in a row, shown only on pinned entries
 * (see Entry_Bindings::filter_pinned_group()).
 *
 * @param {Object}  [options]          How the row shows.
 * @param {string}  [options.color]    The row's text color.
 * @param {boolean} [options.icon]     Whether the row starts with the pin
 *                                     icon. Default true.
 * @param {boolean} [options.hidden]   Whether the row is for screen readers
 *                                     only, for a layout whose pinned card
 *                                     already looks pinned. Default false.
 * @param {boolean} [options.iconOnly] Whether the pin shows alone, with the
 *                                     label for screen readers only.
 *                                     Default false.
 * @return {TemplateItem} The row.
 */
function pinnedRow( {
	color,
	icon = true,
	hidden = false,
	iconOnly = false,
}: {
	color?: string;
	icon?: boolean;
	hidden?: boolean;
	iconOnly?: boolean;
} = {} ): TemplateItem {
	return [
		'core/group',
		{
			...( hidden ? { className: 'screen-reader-text' } : {} ),
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
			...( icon
				? [
						[
							'core/icon',
							{
								icon: PIN_ICON,
								style: { dimensions: { width: '24px' } },
							},
						] as TemplateItem,
					]
				: [] ),
			[
				'core/paragraph',
				{
					// The Newspack Theme's class for its heading font, which it also gives the date.
					className: `use-header-font ${ PINNED_LABEL_CLASS }${
						iconOnly ? ' screen-reader-text' : ''
					}`,
					content: __( 'Pinned', 'newspack-rolling-coverage' ),
					fontSize: 'small',
					style: { typography: { fontWeight: '700' } },
				},
			],
		],
	];
}

/**
 * Whether a block is the paragraph that marks an entry as new, mirroring
 * Entry_Bindings::is_new_label().
 *
 * @param {Object} block            The block.
 * @param {string} block.name       Block name.
 * @param {Object} block.attributes Block attributes.
 * @return {boolean} Whether it's the New label.
 */
function isNewLabel( block: {
	name: string;
	attributes?: Record< string, unknown >;
} ): boolean {
	const className = block.attributes?.className;

	return (
		block.name === 'core/paragraph' &&
		typeof className === 'string' &&
		className.split( ' ' ).includes( NEW_LABEL_CLASS )
	);
}

/**
 * The template without the New label, as the entries that aren't the newest
 * preview, since only the newest entry shows as new.
 *
 * @param {Object[]} blocks The template blocks.
 * @return {Object[]} The blocks without the label.
 */
function withoutNewLabel<
	T extends { name: string; [ key: string ]: unknown },
>( blocks: T[] ): T[] {
	return blocks
		.filter( ( block ) => ! isNewLabel( block ) )
		.map( ( block ) =>
			Array.isArray( block.innerBlocks ) && block.innerBlocks.length
				? {
						...block,
						innerBlocks: withoutNewLabel(
							block.innerBlocks as T[]
						),
					}
				: block
		);
}

/**
 * Client IDs of the New label, at any depth.
 *
 * @param {Object[]} blocks The blocks.
 * @return {string[]} Client IDs.
 */
function newLabelBlockIds(
	blocks: { name: string; [ key: string ]: unknown }[]
): string[] {
	return blocks.flatMap( ( block ) => {
		if ( isNewLabel( block ) ) {
			return [ block.clientId as string ];
		}

		return Array.isArray( block.innerBlocks )
			? newLabelBlockIds( block.innerBlocks as typeof blocks )
			: [];
	} );
}

/**
 * Client IDs of the New labels to hide in the editable groups, so only the
 * group previewing the newest sample shows one.
 *
 * @param {Object[]} entryGroupBlocks         The editable entry groups, including those in the Pinned stack.
 * @param {Object[]} pinnedGroups             The groups inside the Pinned stack.
 * @param {Object}   contexts                 The sample each kind of group previews.
 * @param {*}        contexts.card            The Pinned card's.
 * @param {*}        contexts.entry           The Entry's.
 * @param {*}        contexts.fullStory       The Full Story Entry's.
 * @param {*}        contexts.pinnedFullStory The Pinned group's Full Story Entry's.
 * @param {Object}   newest                   The newest sample.
 * @return {string[]} Client IDs.
 */
function hiddenNewLabelIds(
	entryGroupBlocks: {
		name: string;
		attributes?: Record< string, unknown >;
		[ key: string ]: unknown;
	}[],
	pinnedGroups: unknown[],
	contexts: {
		card?: unknown;
		entry?: unknown;
		fullStory?: unknown;
		pinnedFullStory?: unknown;
	},
	newest: unknown
): string[] {
	return newLabelBlockIds(
		entryGroupBlocks.filter( ( block ) => {
			if ( isPinnedCard( block ) ) {
				return contexts.card !== newest;
			}

			if ( isRegularEntry( block ) ) {
				return contexts.entry !== newest;
			}

			if ( isBreakoutEntry( block ) ) {
				return (
					( pinnedGroups.includes( block )
						? contexts.pinnedFullStory
						: contexts.fullStory ) !== newest
				);
			}

			return false;
		} )
	);
}

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
 * Class of the group that shows an entry whose breakout post is published,
 * mirroring Rolling_Coverage_Block::BREAKOUT_ENTRY_CLASS.
 */
const BREAKOUT_ENTRY_CLASS = 'newspack-rolling-coverage-breakout-entry';

/**
 * Class of the box in the Full Story Entry group that its title's link
 * covers whole, mirroring Breakout_Card::BOX_CLASS.
 */
const BREAKOUT_BOX_CLASS = 'newspack-rolling-coverage-breakout-box';

/**
 * Class of the group that holds a layout's pinned entries one under another,
 * the pinned card for each pinned entry and the Full Story Entry group inside
 * it for each pinned Full Story entry, mirroring
 * Rolling_Coverage_Block::PINNED_STACK_CLASS.
 */
const PINNED_STACK_CLASS = 'newspack-rolling-coverage-pinned-stack';

/**
 * Which part of a per-entry template a row builds: the pinned card, the entry
 * group or the Full Story Entry group.
 */
type EntryKind = 'pinned' | 'entry' | 'fullStory';

/**
 * The space between the blocks of an entry group or pinned card, mirroring
 * Rolling_Coverage_Block::DEFAULT_ENTRY_GAP.
 */
const DEFAULT_ENTRY_GAP = 'var:preset|spacing|20';

/**
 * The space between an entry's time, headline and excerpt in the compact
 * Wire and Digest layouts, tighter than any spacing preset.
 */
const TIGHT_GAP = '0.25rem';

/**
 * Class of the group holding a byline's author name and date, whose
 * stylesheet rule aligns the two on their baseline.
 */
const BYLINE_TEXT_CLASS = 'newspack-rolling-coverage-byline-text';

/**
 * Class of the paragraph that links to the entry's breakout post, mirroring
 * Entry_Bindings::READ_MORE_CLASS.
 */
const READ_MORE_CLASS = 'newspack-rolling-coverage-read-more';

/**
 * Class of Rail's body column, whose rule style.scss carries up through the
 * gap to the entry above.
 */
const TIMELINE_CLASS = 'newspack-rolling-coverage-timeline';

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
 * Class of a title that links to its entry on the coverage page when the
 * entry has no published breakout post, and shows the entry's opening words
 * when it has no title, mirroring Entry_Bindings::ENTRY_LINK_CLASS.
 */
const ENTRY_LINK_CLASS = 'newspack-rolling-coverage-entry-link';

/**
 * The Follow Coverage block, which renders with a layout's coverage-level
 * blocks; the editor keeps it out of entries.
 */
const FOLLOW_BLOCK_NAME = 'newspack-rolling-coverage/coverage-follow';

/**
 * The Check for Updates block, which renders with a layout's coverage-level
 * blocks and makes the feed check for new entries only when readers ask.
 */
const CHECK_UPDATES_BLOCK_NAME = 'newspack-rolling-coverage/check-updates';
const LOAD_MORE_BLOCK_NAME = 'newspack-rolling-coverage/load-more';

/**
 * The Coverage Status block, which shows the coverage's status once when it
 * sits among the layout's coverage-level blocks.
 */
const STATUS_BLOCK_NAME = 'newspack-rolling-coverage/coverage-status';

/**
 * The Update Timer block, which counts down to the feed's next check once
 * when it sits among the layout's coverage-level blocks.
 */
const UPDATE_TIMER_BLOCK_NAME = 'newspack-rolling-coverage/update-timer';

const ACCENT =
	'var(--wp--preset--color--accent, var(--newspack-theme-color-primary))';
const READABLE_ACCENT =
	'var(--newspack-rolling-coverage-accent, var(--wp--preset--color--accent, var(--newspack-theme-color-primary)))';
const ACCENT_CONTRAST =
	'var(--wp--preset--color--accent-contrast, var(--wp--preset--color--base, var(--newspack-theme-color-against-primary)))';
const CONTRAST =
	'var(--wp--preset--color--contrast, var(--newspack-theme-color-text-main, #111))';
const BORDER_COLOR =
	'var(--wp--preset--color--base-3, var(--newspack-theme-color-border, #ddd))';
const PINNED_BACKGROUND =
	'var(--wp--preset--color--base-2, var(--newspack-theme-color-bg-light, #f7f7f7))';

/**
 * A quiet "Read more" button's background: the light gray the theme gives
 * secondary surfaces. Its text takes the theme's text color.
 */
const MUTED_BUTTON_BACKGROUND =
	'var(--wp--preset--color--base-2, var(--newspack-theme-color-bg-light, #f0f0f0))';

/**
 * A light tint of the readable accent, defined in style.scss.
 */
const ACCENT_TINT = 'var(--newspack-rolling-coverage-accent-tint)';

/**
 * The corner radius of the Stream layout's pinned card.
 */
const CARD_RADIUS = '0.5rem';

/**
 * The block theme's medium corner radius, at its value where the theme
 * doesn't define it.
 */
const RADIUS_MEDIUM = 'var(--wp--custom--border--radius-medium, 0.375rem)';

/**
 * The Full Story Entry's card in Byline: rounded, spaced from the
 * byline and links around it, with the arrow beside its label, room around
 * its image, cropped to 16:9 so its height is known before it loads, and a
 * small summary in the entry text's color.
 */
const BYLINE_CARD = {
	asEntries: true,
	excerptSize: 'small',
	imageMargin: 'var:preset|spacing|20',
	imageRatio: '16/9',
	boxMargin: 'var:preset|spacing|20',
	boxRadius: RADIUS_MEDIUM,
	labelArrow: true,
};

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
 * The blocks that go when a featured image they held was all they held.
 */
const EMPTIED_CONTAINERS = [ 'core/group', 'core/column', 'core/columns' ];

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
	const slug = mutedTextColor( slugs );

	return slug ? { textColor: slug } : {};
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
 * The entry author's name. Bold where it leads its row; regular weight
 * beside the links, so it never outweighs the entry's time.
 *
 * @param {string} fontWeight The name's font weight.
 * @return {TemplateItem} The Post Author Name block.
 */
function authorName( fontWeight: '400' | '700' ): TemplateItem {
	return [
		'core/post-author-name',
		{
			className: 'use-header-font',
			fontSize: 'small',
			style: { typography: { fontWeight } },
		},
	];
}

/**
 * The entry author's avatar and name, side by side, to lead a links row. They
 * share a group so the row's wider gap falls between the author and the links.
 *
 * @return {TemplateItem} The group.
 */
function authorGroup(): TemplateItem {
	return [
		'core/group',
		{
			layout: {
				type: 'flex',
				flexWrap: 'nowrap',
				verticalAlignment: 'center',
			},
			style: { spacing: { blockGap: 'var:preset|spacing|20' } },
			metadata: { name: __( 'Author', 'newspack-rolling-coverage' ) },
		},
		[
			[
				'core/avatar',
				{ size: 24, style: { border: { radius: '50%' } } },
			],
			authorName( '400' ),
		],
	];
}

/**
 * The text a new layout's link to the coverage page starts with, in the
 * site's own plural for entries when it sets one.
 *
 * @return {string} The link text.
 */
function allUpdatesText(): string {
	if ( ! ENTRY_PLURAL ) {
		return __( 'See all entries', 'newspack-rolling-coverage' );
	}

	return sprintf(
		/* translators: %s: the site's name for several coverage entries, as it reads mid-sentence, e.g. "updates". */
		__( 'See all %s', 'newspack-rolling-coverage' ),
		ENTRY_PLURAL
	);
}

/**
 * The link to the coverage page, shown once by a capped feed.
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
			content: placeholderLink( allUpdatesText() ),
			fontSize: 'small',
			metadata: { name: allUpdatesText() },
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
 * The Full story label, which shows the label of the entry's breakout post
 * (see Entry_Bindings::filter_breakout_label()).
 *
 * @param {string} [fontSize] The label's font size preset.
 * @return {TemplateItem} The paragraph.
 */
function fullStoryLabel( fontSize = 'small' ): TemplateItem {
	return [
		'core/paragraph',
		{
			className: 'use-header-font',
			content: __( 'Full story', 'newspack-rolling-coverage' ),
			fontSize,
			style: {
				color: { text: READABLE_ACCENT },
				typography: { fontWeight: '700' },
			},
			metadata: {
				name: __( 'Full Story Label', 'newspack-rolling-coverage' ),
				bindings: {
					content: {
						source: ENTRY_BINDINGS_SOURCE,
						args: { key: 'breakoutLabel' },
					},
				},
			},
		},
	];
}

/**
 * An arrow pointing the way to the post. It is decorative: the card's title
 * link already names the post for screen readers.
 *
 * @return {TemplateItem} The Icon block.
 */
function fullStoryArrow(): TemplateItem {
	return [
		'core/icon',
		{
			icon: 'core/arrow-right',
			style: {
				dimensions: { width: '24px' },
				color: { text: READABLE_ACCENT },
			},
		},
	];
}

/**
 * A box's last row: the entry's links, such as the author and Share, with
 * the arrow at the row's far end.
 *
 * @param {TemplateItem} links The links row.
 * @return {TemplateItem} The row.
 */
function fullStoryFooter( links: TemplateItem ): TemplateItem {
	return [
		'core/group',
		{
			layout: {
				type: 'flex',
				flexWrap: 'nowrap',
				justifyContent: 'space-between',
				verticalAlignment: 'center',
			},
			metadata: { name: __( 'Footer', 'newspack-rolling-coverage' ) },
		},
		[ links, fullStoryArrow() ],
	];
}

/**
 * A "Read more" button linking to the breakout post, in the accent color
 * with the text color the theme pairs with it, as Bulletin's pinned card, or
 * quiet in gray where the entry sits on the page's own background.
 *
 * @param {Object}  [options]              How the button looks.
 * @param {boolean} [options.muted]        Whether it's the quiet gray button.
 *                                         Default false.
 * @param {Object}  [options.space]        The space above and below it, on
 *                                         top of its group's gap.
 * @param {string}  [options.space.top]    The space above it.
 * @param {string}  [options.space.bottom] The space below it.
 * @return {TemplateItem} The Buttons block.
 */
function fullStoryReadMore( {
	muted = false,
	space,
}: {
	muted?: boolean;
	space?: { top?: string; bottom?: string };
} = {} ): TemplateItem {
	return [
		'core/buttons',
		{
			...( space ? { style: { spacing: { margin: space } } } : {} ),
			metadata: { name: __( 'Read More', 'newspack-rolling-coverage' ) },
		},
		[
			[
				'core/button',
				{
					text: __( 'Read more', 'newspack-rolling-coverage' ),
					style: {
						color: muted
							? {
									background: MUTED_BUTTON_BACKGROUND,
									text: CONTRAST,
								}
							: { background: ACCENT, text: ACCENT_CONTRAST },
					},
					metadata: {
						name: __( 'Read More', 'newspack-rolling-coverage' ),
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
	];
}

/**
 * The breakout post's featured image: cropped to fill a narrow column beside
 * the text, or across the full width of its column, at its own proportions
 * unless an aspect ratio crops it. It links to the post (see
 * Breakout_Card::render_featured_image()), since core positions the image
 * above the card's stretched title link.
 *
 * @param {Object}  [options]             How the image sits.
 * @param {boolean} [options.besideText]  Whether the image sits beside the
 *                                        text. Default false.
 * @param {string}  [options.margin]      The space above and below it, on top
 *                                        of the box's gap.
 * @param {string}  [options.aspectRatio] The ratio a full-width image is
 *                                        cropped to.
 * @return {TemplateItem} The Post Featured Image block.
 */
function fullStoryImage( {
	besideText = false,
	margin,
	aspectRatio,
}: {
	besideText?: boolean;
	margin?: string;
	aspectRatio?: string;
} = {} ): TemplateItem {
	let look: Record< string, string > = { sizeSlug: 'large' };

	if ( besideText ) {
		look = { aspectRatio: '4/3', scale: 'cover', sizeSlug: 'medium_large' };
	} else if ( aspectRatio ) {
		look = { aspectRatio, scale: 'cover', sizeSlug: 'large' };
	}

	return [
		'core/post-featured-image',
		{
			isLink: true,
			...look,
			...( margin
				? {
						style: {
							spacing: {
								margin: { top: margin, bottom: margin },
							},
						},
					}
				: {} ),
		},
	];
}

/**
 * The breakout post's title, which the site links to the post.
 *
 * @param {number} level      The heading level.
 * @param {string} [fontSize] The title's font size preset.
 * @return {TemplateItem} The Post Title block.
 */
function fullStoryTitle( level: number, fontSize?: string ): TemplateItem {
	return [
		'core/post-title',
		{ level, ...( fontSize ? { fontSize } : {} ) },
	];
}

/**
 * The breakout post's summary, small and muted, or set like the layout's
 * entry text.
 *
 * @param {string[]} slugs               The palette's color slugs.
 * @param {Object}   [options]           How the summary is set.
 * @param {boolean}  [options.asEntries] Whether it takes the entry text's
 *                                       color, and its size unless `fontSize`
 *                                       is given, instead. Default false.
 * @param {string}   [options.fontSize]  The entry text's font size preset,
 *                                       where the layout sets one.
 * @return {TemplateItem} The Post Excerpt block.
 */
function fullStoryExcerpt(
	slugs: string[],
	{
		asEntries = false,
		fontSize,
	}: { asEntries?: boolean; fontSize?: string } = {}
): TemplateItem {
	const entryLook = fontSize ? { fontSize } : {};
	const look = asEntries
		? entryLook
		: { fontSize: 'small', ...mutedDateColor( slugs ) };

	return [
		'core/post-excerpt',
		{
			excerptLength: 25,
			moreText: '',
			showMoreOnNewLine: false,
			...look,
		},
	];
}

type BoxMargin = string | { top?: string; bottom?: string };

/**
 * The box a Full Story Entry shows the breakout post in, by default outlined
 * and tinted in the readable accent. The post's title links the whole box
 * (see style.scss), so it needs a Post Title.
 *
 * @param {TemplateItem[]} blocks               The box's blocks.
 * @param {Object}         [options]            How the box looks.
 * @param {string}         [options.gap]        The space between its blocks.
 * @param {string}         [options.inset]      Its padding on every side.
 * @param {string}         [options.radius]     Its corner radius.
 * @param {boolean}        [options.outlined]   Whether it has the accent
 *                                              border. Default true.
 * @param {string}         [options.background] Its background color. Default
 *                                              the accent tint.
 * @param {string|Object}  [options.margin]     The space above and below it,
 *                                              or `{ top, bottom }`.
 * @return {TemplateItem} The group.
 */
function fullStoryBox(
	blocks: TemplateItem[],
	{
		gap = DEFAULT_ENTRY_GAP,
		inset = 'var:preset|spacing|40',
		radius,
		outlined = true,
		background = ACCENT_TINT,
		margin,
	}: {
		gap?: string;
		inset?: string;
		radius?: string;
		outlined?: boolean;
		background?: string;
		margin?: BoxMargin;
	} = {}
): TemplateItem {
	return [
		'core/group',
		{
			className: BREAKOUT_BOX_CLASS,
			layout: {
				type: 'flex',
				orientation: 'vertical',
				justifyContent: 'stretch',
			},
			style: {
				...( outlined || radius
					? {
							border: {
								...( outlined
									? {
											color: READABLE_ACCENT,
											style: 'solid',
											width: '1px',
										}
									: {} ),
								...( radius ? { radius } : {} ),
							},
						}
					: {} ),
				color: { background },
				spacing: {
					padding: {
						top: inset,
						right: inset,
						bottom: inset,
						left: inset,
					},
					...( margin
						? {
								margin:
									typeof margin === 'string'
										? { top: margin, bottom: margin }
										: margin,
							}
						: {} ),
					blockGap: gap,
				},
			},
			metadata: { name: __( 'Card', 'newspack-rolling-coverage' ) },
		},
		blocks,
	];
}

/**
 * The box of a layout that sets each entry in columns: the label, the title,
 * the image across the column and the summary, then any footer rows.
 *
 * @param {string[]}       slugs                 The palette's color slugs.
 * @param {Object}         [options]             How the box is built.
 * @param {boolean}        [options.hasImage]    Whether the box shows the
 *                                               featured image. Default true.
 * @param {TemplateItem[]} [options.footer]      Rows after the summary, such
 *                                               as the author and Share.
 * @param {boolean}        [options.asEntries]   Whether the summary is set
 *                                               like the entry text rather
 *                                               than small and muted.
 * @param {string}         [options.imageMargin] The space above and below the
 *                                               image, on top of the box's gap.
 * @param {string}         [options.imageRatio]  The aspect ratio the image is
 *                                               cropped to.
 * @param {string}         [options.excerptSize] The summary's font size preset,
 *                                               where it takes the entry
 *                                               text's color but not its size.
 * @param {string|Object}  [options.boxMargin]   The space above and below the
 *                                               box, or `{ top, bottom }`.
 * @param {string}         [options.boxRadius]   The box's corner radius.
 * @param {boolean}        [options.labelArrow]  Whether the arrow follows the
 *                                               label.
 * @param {string}         [options.titleSize]   The title's font size preset.
 * @param {string}         [options.boxGap]      The space between the box's
 *                                               blocks.
 * @param {string}         [options.boxInset]    The box's padding on every
 *                                               side.
 * @return {TemplateItem} The group.
 */
function columnFullStoryBox(
	slugs: string[],
	{
		hasImage = true,
		footer = [],
		asEntries = false,
		imageMargin,
		imageRatio,
		excerptSize,
		boxMargin,
		boxRadius,
		labelArrow = false,
		titleSize,
		boxGap,
		boxInset,
	}: {
		hasImage?: boolean;
		footer?: TemplateItem[];
		asEntries?: boolean;
		imageMargin?: string;
		imageRatio?: string;
		excerptSize?: string;
		boxMargin?: BoxMargin;
		boxRadius?: string;
		labelArrow?: boolean;
		titleSize?: string;
		boxGap?: string;
		boxInset?: string;
	} = {}
): TemplateItem {
	return fullStoryBox(
		[
			labelArrow ? fullStoryLabelRow() : fullStoryLabel(),
			fullStoryTitle( 4, titleSize ),
			...( hasImage
				? [
						fullStoryImage( {
							margin: imageMargin,
							aspectRatio: imageRatio,
						} ),
					]
				: [] ),
			fullStoryExcerpt( slugs, { asEntries, fontSize: excerptSize } ),
			...footer,
		],
		{
			margin: boxMargin,
			radius: boxRadius,
			...( boxGap ? { gap: boxGap } : {} ),
			...( boxInset ? { inset: boxInset } : {} ),
		}
	);
}

/**
 * Clock's Full Story Entry box, which Rail shares: rounded, with room around
 * the image, a small summary in the entry text's color, and the entry's links row
 * inside it with the arrow at its far end.
 *
 * @param {string[]}     slugs The palette's color slugs.
 * @param {TemplateItem} links The entry's links row.
 * @return {TemplateItem} The group.
 */
function clockFullStoryBox(
	slugs: string[],
	links: TemplateItem
): TemplateItem {
	return columnFullStoryBox( slugs, {
		footer: [ fullStoryFooter( links ) ],
		asEntries: true,
		excerptSize: 'small',
		imageMargin: 'var:preset|spacing|20',
		boxRadius: RADIUS_MEDIUM,
	} );
}

/**
 * The Full story label with the arrow beside it.
 *
 * @return {TemplateItem} The row.
 */
function fullStoryLabelRow(): TemplateItem {
	return [
		'core/group',
		{
			layout: {
				type: 'flex',
				flexWrap: 'nowrap',
				verticalAlignment: 'center',
			},
			style: { spacing: { blockGap: 'var:preset|spacing|20' } },
			metadata: { name: __( 'Label Row', 'newspack-rolling-coverage' ) },
		},
		[ fullStoryLabel(), fullStoryArrow() ],
	];
}

/**
 * The Full Story Entry group, which shows an entry whose breakout post is
 * published in place of the entry group, or in place of the pinned card
 * inside a layout's Pinned group.
 *
 * @param {TemplateItem[]} blocks       The group's blocks.
 * @param {Object}         [attributes] Extra group settings, such as its style.
 * @param {string}         [name]       The group's name in the editor.
 * @return {TemplateItem} The group.
 */
function fullStoryGroup(
	blocks: TemplateItem[],
	attributes: Record< string, unknown > = {},
	name: string = __( 'Full Story Entry', 'newspack-rolling-coverage' )
): TemplateItem {
	return [
		'core/group',
		{
			className: BREAKOUT_ENTRY_CLASS,
			lock: LOCKED_IN_PLACE,
			...attributes,
			metadata: { name },
		},
		blocks,
	];
}

/**
 * A Bulletin entry's top row: its details, such as the time and "Share",
 * and on a pinned entry the pin, alone at the row's far end.
 *
 * @param {TemplateItem[]} details    The row's details.
 * @param {boolean}        [withPin]  Whether the row ends with the pin.
 * @param {string}         [pinColor] The pin's color, if not the text's.
 * @return {TemplateItem} The row.
 */
function bulletinMetaRow(
	details: TemplateItem[],
	withPin = false,
	pinColor?: string
): TemplateItem {
	const detailsGroup: TemplateItem = [
		'core/group',
		{
			layout: {
				type: 'flex',
				flexWrap: 'wrap',
				verticalAlignment: 'center',
			},
			style: { spacing: { blockGap: 'var:preset|spacing|30' } },
			metadata: { name: __( 'Details', 'newspack-rolling-coverage' ) },
		},
		details,
	];

	if ( ! withPin ) {
		return [
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
			details,
		];
	}

	return [
		'core/group',
		{
			layout: {
				type: 'flex',
				flexWrap: 'nowrap',
				justifyContent: 'space-between',
				verticalAlignment: 'center',
			},
			style: { spacing: { blockGap: 'var:preset|spacing|30' } },
			metadata: { name: __( 'Meta', 'newspack-rolling-coverage' ) },
		},
		[ detailsGroup, pinnedRow( { iconOnly: true, color: pinColor } ) ],
	];
}

/**
 * What a Bulletin entry shows: a row with the time and "Share", or on the
 * pinned card the pin, with the Pinned label for screen readers only, the
 * relative date and "Share", then a large title and the content.
 *
 * @param {boolean} isPinned Whether the blocks are the pinned card's.
 * @return {TemplateItem[]} The entry's blocks.
 */
function bulletinEntryBlocks( isPinned: boolean ): TemplateItem[] {
	const meta: TemplateItem[] = isPinned
		? [
				[
					'core/post-date',
					{
						...POST_DATE_ATTRIBUTES,
						format: 'human-diff',
						fontSize: 'small',
						style: { color: { text: ACCENT_CONTRAST } },
					},
				],
				shareLink(),
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
		bulletinMetaRow( meta, isPinned ),
		[ 'core/post-title', { level: 3, fontSize: 'large' } ],
		postContent(),
	];
}

/**
 * A Bulletin Full Story Entry's box, on a light gray panel with no border: a
 * row of the pin, shown only for a pinned entry, with the Pinned label for
 * screen readers only, the label, the time and "Share", then the post's
 * title, at the entries' title size, its summary and a "Read more" button,
 * with its image to their right, stacked on phones.
 *
 * @param {string[]} slugs The palette's color slugs.
 * @return {TemplateItem} The group.
 */
function bulletinFullStoryBox( slugs: string[] ): TemplateItem {
	return fullStoryBox(
		[
			bulletinMetaRow(
				[
					fullStoryLabel(),
					[
						'core/post-date',
						{
							...POST_DATE_ATTRIBUTES,
							format: siteTimeFormat(),
							fontSize: 'small',
						},
					],
					shareLink(),
				],
				true,
				READABLE_ACCENT
			),
			[
				'core/columns',
				{
					isStackedOnMobile: true,
					style: {
						spacing: {
							blockGap: {
								top: 'var:preset|spacing|30',
								left: 'var:preset|spacing|50',
							},
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
						{
							metadata: {
								name: __( 'Text', 'newspack-rolling-coverage' ),
							},
						},
						[
							stack(
								__( 'Text', 'newspack-rolling-coverage' ),
								[
									fullStoryTitle( 3, 'large' ),
									fullStoryExcerpt( slugs, {
										asEntries: true,
									} ),
									fullStoryReadMore(),
								],
								'var:preset|spacing|30'
							),
						],
					],
					[
						'core/column',
						{
							width: '11rem',
							metadata: {
								name: __(
									'Image',
									'newspack-rolling-coverage'
								),
							},
						},
						[ fullStoryImage( { besideText: true } ) ],
					],
				],
			],
		],
		{
			gap: 'var:preset|spacing|30',
			inset: 'var:preset|spacing|50',
			outlined: false,
			background: PINNED_BACKGROUND,
		}
	);
}

/**
 * The Bulletin layout's per-entry template, the default: a headline-led
 * entry closed by a separator, which the entry group's Block spacing spaces
 * like its other blocks. The pinned card is set in the accent color,
 * with its text, links and headings in the accent's contrast color. An
 * entry whose breakout post is published, pinned or not, shows the post in
 * a box, with no separator, like the pinned card.
 *
 * @param {string[]} slugs The palette's color slugs.
 * @return {TemplateItem[]} The template.
 */
function bulletinEntryTemplate( slugs: string[] ): TemplateItem[] {
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
			bulletinEntryBlocks( true ),
		],
		[
			'core/group',
			{
				className: REGULAR_ENTRY_CLASS,
				lock: LOCKED_IN_PLACE,
				style: { spacing: { blockGap: 'var:preset|spacing|50' } },
				metadata: {
					name: __( 'Entry', 'newspack-rolling-coverage' ),
				},
			},
			[
				stack(
					__( 'Text', 'newspack-rolling-coverage' ),
					bulletinEntryBlocks( false ),
					'var:preset|spacing|30'
				),
				[ 'core/separator', { className: 'is-style-wide' } ],
			],
		],
		fullStoryGroup( [ bulletinFullStoryBox( slugs ) ], {
			style: { spacing: { blockGap: 'var:preset|spacing|30' } },
		} ),
	];
}

/**
 * A Stream entry's byline: the author's avatar, name and the relative date.
 *
 * @param {string[]} slugs The palette's color slugs.
 * @return {TemplateItem} The group.
 */
function streamByline( slugs: string[] ): TemplateItem {
	return [
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
				'core/avatar',
				{ size: 24, style: { border: { radius: '50%' } } },
			],
			[
				'core/group',
				{
					className: BYLINE_TEXT_CLASS,
					layout: { type: 'flex', flexWrap: 'nowrap' },
					style: {
						spacing: { blockGap: 'var:preset|spacing|20' },
					},
					metadata: {
						name: __(
							'Author + Date',
							'newspack-rolling-coverage'
						),
					},
				},
				[
					authorName( '700' ),
					[
						'core/post-date',
						{
							...POST_DATE_ATTRIBUTES,
							format: 'human-diff',
							fontSize: 'small',
							...mutedDateColor( slugs ),
						},
					],
				],
			],
		],
	];
}

/**
 * The Pinned label that marks a pinned Stream entry, for screen readers
 * only: a pinned Stream entry shows no pin, as its card or box already sets
 * it apart.
 *
 * @return {TemplateItem} The pinned row.
 */
function streamPin(): TemplateItem {
	return pinnedRow( { icon: false, hidden: true } );
}

/**
 * A Stream entry's top row: the byline, then what marks the entry at the
 * row's far end.
 *
 * @param {TemplateItem}   byline The byline.
 * @param {TemplateItem[]} end    The blocks at the row's far end.
 * @param {boolean}        wrap   Whether the end drops below the byline
 *                                when the row runs out of room.
 * @return {TemplateItem} The row.
 */
function streamMetaRow(
	byline: TemplateItem,
	end: TemplateItem[],
	wrap: boolean
): TemplateItem {
	return [
		'core/group',
		{
			layout: {
				type: 'flex',
				flexWrap: wrap ? 'wrap' : 'nowrap',
				justifyContent: 'space-between',
				verticalAlignment: 'center',
			},
			style: { spacing: { blockGap: 'var:preset|spacing|20' } },
			metadata: { name: __( 'Meta', 'newspack-rolling-coverage' ) },
		},
		[
			byline,
			end.length > 1
				? [
						'core/group',
						{
							layout: {
								type: 'flex',
								flexWrap: 'nowrap',
								verticalAlignment: 'center',
							},
							style: {
								spacing: { blockGap: 'var:preset|spacing|20' },
							},
							metadata: {
								name: __(
									'Marks',
									'newspack-rolling-coverage'
								),
							},
						},
						end,
					]
				: end[ 0 ],
		],
	];
}

/**
 * A Stream entry's footer, holding "Share".
 *
 * @return {TemplateItem} The group.
 */
function streamFooter(): TemplateItem {
	return [
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
		[ shareLink() ],
	];
}

/**
 * What a Stream entry shows: no title, the byline above the content, which
 * is set larger, then "Share".
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
	return [
		isPinned
			? streamMetaRow( streamByline( slugs ), [ streamPin() ], false )
			: streamByline( slugs ),
		postContent( themeFontSize( sizes, 'medium', 'normal' ) ),
		streamFooter(),
	];
}

/**
 * The Stream layout's per-entry template: untitled entries spaced apart with
 * no separator, and the pinned entry in a bordered card. An entry whose
 * breakout post is published shows the post in a rounded box: the entry's
 * byline with the label and an arrow at the far end of its row, the post's image above
 * its title and summary, set like the entries' text, then "Share".
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
		fullStoryGroup(
			[
				fullStoryBox(
					[
						streamMetaRow(
							streamByline( slugs ),
							[ streamPin(), fullStoryLabel(), fullStoryArrow() ],
							true
						),
						fullStoryImage( { margin: 'var:preset|spacing|30' } ),
						fullStoryTitle( 3, 'large' ),
						fullStoryExcerpt( slugs, {
							asEntries: true,
							fontSize: themeFontSize(
								sizes,
								'medium',
								'normal'
							),
						} ),
						streamFooter(),
					],
					{
						gap: 'var:preset|spacing|20',
						inset: 'var:preset|spacing|50',
						radius: CARD_RADIUS,
					}
				),
			],
			{ style: { spacing: { blockGap: 'var:preset|spacing|20' } } }
		),
	];
}

/**
 * The pin above the time of Rail's pinned card and Full Story Entry, at the
 * column's right edge, in the accent color, with the Pinned label for screen
 * readers only. It renders only for a pinned entry, and its presence makes
 * the Full Story Entry take pinned entries (see
 * Entry_Bindings::has_pinned_row()).
 *
 * @return {TemplateItem} The pinned row.
 */
function railPin(): TemplateItem {
	const [ name, attributes, blocks ] = pinnedRow( {
		iconOnly: true,
		color: READABLE_ACCENT,
	} ) as [ string, Record< string, unknown >, TemplateItem[] ];

	const style = ( attributes.style ?? {} ) as Record< string, unknown >;

	return [
		name,
		{
			...attributes,
			layout: {
				...( attributes.layout as Record< string, unknown > ),
				justifyContent: 'right',
			},
			style: {
				...style,
				spacing: {
					...( style.spacing as Record< string, unknown > ),
					margin: { bottom: 'var:preset|spacing|20' },
				},
			},
		},
		blocks,
	];
}

/**
 * A Rail entry's row: the time, under the pin on the pinned card, in a
 * narrow column, then the entry beside a vertical rule, which style.scss
 * runs up through the Feed's gap to the entry above, in this entry's color: the content, or the
 * breakout post's box for a Full Story Entry, then the author's avatar and
 * name beside Share, with an arrow at that row's far end in the box. The
 * entry is a vertical flex group, so its spacing also applies on the
 * Newspack Theme (see Rolling_Coverage_Block::apply_entry_block_gap()).
 * The pinned card's rule is in the accent color and its entry sits on a
 * panel in the accent color, with its text in the accent's contrast color,
 * like Bulletin's pinned card. A Full Story Entry's box is Clock's
 * (clockFullStoryBox()). With no pinned label, the site announces the entry as
 * pinned itself (see Rolling_Coverage_Block::render_entry()).
 *
 * @param {string[]}  slugs The palette's color slugs.
 * @param {EntryKind} kind  Which part of the template the row is.
 * @return {TemplateItem} The row.
 */
function railRow( slugs: string[], kind: EntryKind ): TemplateItem {
	const isPinned = kind === 'pinned';
	const date: TemplateItem = [
		'core/post-date',
		{
			...POST_DATE_ATTRIBUTES,
			format: siteTimeFormat(),
			fontSize: 'small',
			style: {
				typography: { textAlign: 'right', fontWeight: '700' },
				...( kind === 'entry'
					? {}
					: { spacing: { margin: { top: '0' } } } ),
			},
		},
	];
	const markers: TemplateItem[] =
		kind === 'entry' ? [ date ] : [ railPin(), date ];
	const links = linksRow( [ authorGroup(), shareLink() ] );
	const entry: TemplateItem[] =
		kind === 'fullStory'
			? [ clockFullStoryBox( slugs, links ) ]
			: [ [ 'core/post-title', { level: 4 } ], postContent(), links ];

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
					width: '9.5rem',
					style: {
						spacing: {
							padding: { right: 'var:preset|spacing|40' },
						},
					},
					metadata: {
						name: __( 'Time', 'newspack-rolling-coverage' ),
					},
				},
				markers,
			],
			[
				'core/column',
				{
					className: TIMELINE_CLASS,
					style: {
						border: {
							left: {
								color: isPinned
									? READABLE_ACCENT
									: BORDER_COLOR,
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
												background: ACCENT,
												text: ACCENT_CONTRAST,
											},
											border: { radius: RADIUS_MEDIUM },
											elements: {
												link: {
													color: {
														text: ACCENT_CONTRAST,
													},
												},
												heading: {
													color: {
														text: ACCENT_CONTRAST,
													},
												},
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
 * @param {string[]} slugs The palette's color slugs.
 * @return {TemplateItem[]} The template.
 */
function railEntryTemplate( slugs: string[] ): TemplateItem[] {
	return rowEntryTemplate( ( kind ) => railRow( slugs, kind ), true );
}

/**
 * Blocks stacked in a vertical flex group, so their Block spacing also
 * applies on the Newspack Theme (see
 * Rolling_Coverage_Block::apply_entry_block_gap()).
 *
 * @param {string}         name   The group's name in the List View.
 * @param {TemplateItem[]} blocks The blocks.
 * @param {string}         [gap]  The space between the blocks.
 * @return {TemplateItem} The group.
 */
function stack(
	name: string,
	blocks: TemplateItem[],
	gap = DEFAULT_ENTRY_GAP
): TemplateItem {
	return [
		'core/group',
		{
			layout: {
				type: 'flex',
				orientation: 'vertical',
				justifyContent: 'stretch',
			},
			style: { spacing: { blockGap: gap } },
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
 * @param {string}         [columnGap]  The space between the columns side by
 *                                      side.
 * @return {TemplateItem} The row.
 */
function ruledRow(
	border: { color: string; width: string },
	stackedGap: string,
	columns: TemplateItem[],
	columnGap = 'var:preset|spacing|40'
): TemplateItem {
	return [
		'core/columns',
		{
			style: {
				border: { top: { ...border, style: 'solid' } },
				spacing: {
					blockGap: {
						top: stackedGap,
						left: columnGap,
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
 * relative date below it, under the pinned row in the accent color on the
 * pinned card and the Full Story Entry (which so takes pinned entries too),
 * then the title and content, or the breakout post's box for a Full Story
 * Entry, and the author's avatar and name with Share beside it, with an
 * arrow at that row's far end in the box.
 *
 * @param {string[]}  slugs The palette's color slugs.
 * @param {string[]}  sizes The theme's font size slugs.
 * @param {EntryKind} kind  Which part of the template the row is.
 * @return {TemplateItem} The row.
 */
function clockRow(
	slugs: string[],
	sizes: string[],
	kind: EntryKind
): TemplateItem {
	const time: TemplateItem = stack(
		__( 'Dates', 'newspack-rolling-coverage' ),
		[
			...( kind === 'entry'
				? []
				: [ pinnedRow( { color: READABLE_ACCENT } ) ] ),
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
		]
	);
	const links = linksRow( [ authorGroup(), shareLink() ] );
	const entry: TemplateItem[] =
		kind === 'fullStory'
			? [ clockFullStoryBox( slugs, links ) ]
			: [ [ 'core/post-title', { level: 4 } ], postContent(), links ];

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
			[ stack( __( 'Entry', 'newspack-rolling-coverage' ), entry ) ],
		],
	] );
}

/**
 * A Margin entry's row: the time, under the pinned label on the pinned
 * card, the title, the author's avatar and name, then Share, stacked in a margin
 * column, and the content in the wider column beside it. The pinned card is
 * ruled off with a heavier rule. A Full Story Entry is ruled off as heavily
 * in the accent color, with the time and the breakout post's title in the
 * margin, and the Full story label, small like the Pinned label, its image,
 * cropped to 16:9, its summary and a Read more button in the content's place.
 * It takes pinned entries too, with the Pinned label above the time.
 *
 * @param {string[]}  slugs The palette's color slugs.
 * @param {EntryKind} kind  Which part of the template the row is.
 * @return {TemplateItem} The row.
 */
function marginRow( slugs: string[], kind: EntryKind ): TemplateItem {
	const isPinned = kind === 'pinned';
	const isFullStory = kind === 'fullStory';
	const date: TemplateItem = [
		'core/post-date',
		{
			...POST_DATE_ATTRIBUTES,
			format: siteTimeFormat(),
			fontSize: 'small',
		},
	];
	const title: TemplateItem = [
		'core/post-title',
		{ level: 4, fontSize: 'medium' },
	];
	let summary: TemplateItem[] = [ date, title ];
	let rule = { color: BORDER_COLOR, width: '1px' };

	if ( isPinned ) {
		summary = [ pinnedRow( { icon: false } ), date, title ];
		rule = { color: CONTRAST, width: '3px' };
	} else if ( isFullStory ) {
		summary = [ pinnedRow( { icon: false } ), date, title ];
		rule = { color: READABLE_ACCENT, width: '3px' };
	}

	return ruledRow(
		rule,
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
						...summary,
						authorGroup(),
						shareLink(),
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
				[
					isFullStory
						? stack(
								__( 'Text', 'newspack-rolling-coverage' ),
								[
									fullStoryLabel(),
									fullStoryImage( { aspectRatio: '16/9' } ),
									fullStoryExcerpt( slugs, {
										asEntries: true,
									} ),
									fullStoryReadMore( { muted: true } ),
								],
								'var:preset|spacing|30'
							)
						: postContent(),
				],
			],
		],
		'var:preset|spacing|50'
	);
}

/**
 * A per-entry template whose pinned card and entry group each hold one row,
 * and its Full Story Entry group too when it has one.
 *
 * @param {Function} row            Returns the row for a part of the template.
 * @param {boolean}  [hasFullStory] Whether the template has a Full Story
 *                                  Entry group.
 * @return {TemplateItem[]} The template.
 */
function rowEntryTemplate(
	row: ( kind: EntryKind ) => TemplateItem,
	hasFullStory = false
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
			[ row( 'pinned' ) ],
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
			[ row( 'entry' ) ],
		],
		...( hasFullStory ? [ fullStoryGroup( [ row( 'fullStory' ) ] ) ] : [] ),
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
	return rowEntryTemplate( ( kind ) => clockRow( slugs, sizes, kind ), true );
}

/**
 * The Margin layout's per-entry template: a broadsheet split, each entry
 * ruled off from the one above.
 *
 * @param {string[]} slugs The palette's color slugs.
 * @return {TemplateItem[]} The template.
 */
function marginEntryTemplate( slugs: string[] ): TemplateItem[] {
	return rowEntryTemplate( ( kind ) => marginRow( slugs, kind ), true );
}

/**
 * The Minute layout's per-entry template: each entry is only its content,
 * ruled off from the one above, and so is a Full Story Entry. The pinned card is set in the accent color,
 * like Bulletin's, with its text in the accent's contrast color. An entry
 * whose breakout post is published shows the post's title and summary on a
 * gray card, with the pin before its label when pinned and an arrow at the
 * far end below the summary.
 *
 * @param {string[]} slugs The palette's color slugs.
 * @return {TemplateItem[]} The template.
 */
function minuteEntryTemplate( slugs: string[] ): TemplateItem[] {
	const flex = {
		type: 'flex',
		orientation: 'vertical',
		justifyContent: 'stretch',
	};
	const entryStyle = {
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
	};

	return [
		[
			'core/group',
			{
				className: PINNED_CARD_CLASS,
				lock: LOCKED_IN_PLACE,
				layout: flex,
				style: {
					color: { background: ACCENT, text: ACCENT_CONTRAST },
					elements: {
						link: { color: { text: ACCENT_CONTRAST } },
						heading: { color: { text: ACCENT_CONTRAST } },
					},
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
			[ pinnedRow(), postContent() ],
		],
		[
			'core/group',
			{
				className: REGULAR_ENTRY_CLASS,
				lock: LOCKED_IN_PLACE,
				layout: flex,
				style: entryStyle,
				metadata: {
					name: __( 'Entry', 'newspack-rolling-coverage' ),
				},
			},
			[ postContent() ],
		],
		fullStoryGroup(
			[
				fullStoryBox(
					[
						[
							'core/group',
							{
								layout: {
									type: 'flex',
									flexWrap: 'nowrap',
									verticalAlignment: 'center',
								},
								style: { spacing: { blockGap: '0' } },
								metadata: {
									name: __(
										'Meta',
										'newspack-rolling-coverage'
									),
								},
							},
							[
								pinnedRow( {
									iconOnly: true,
									color: READABLE_ACCENT,
								} ),
								fullStoryLabel(),
							],
						],
						fullStoryTitle( 4 ),
						fullStoryExcerpt( slugs, { asEntries: true } ),
						[
							'core/group',
							{
								layout: {
									type: 'flex',
									justifyContent: 'right',
								},
								metadata: {
									name: __(
										'Arrow',
										'newspack-rolling-coverage'
									),
								},
							},
							[ fullStoryArrow() ],
						],
					],
					{ outlined: false, background: PINNED_BACKGROUND }
				),
			],
			{ layout: flex, style: entryStyle }
		),
	];
}

/**
 * A Byline entry's row: the author's avatar in a narrow column that stays
 * beside the entry at every width, then the byline, title, content and
 * links. The pinned entry differs only by the pinned label at the end of its
 * byline row, and a Full Story Entry by the breakout post's box in place of
 * the title and content; it has the pinned label too, so it takes pinned
 * entries.
 *
 * @param {string[]}  slugs The palette's color slugs.
 * @param {EntryKind} kind  Which part of the template the row is.
 * @return {TemplateItem} The row.
 */
function bylineRow( slugs: string[], kind: EntryKind ): TemplateItem {
	const entry: TemplateItem[] = [
		[
			'core/group',
			{
				layout: {
					type: 'flex',
					flexWrap: 'wrap',
					verticalAlignment: 'center',
					justifyContent: 'space-between',
				},
				style: { spacing: { blockGap: 'var:preset|spacing|20' } },
				metadata: { name: __( 'Byline', 'newspack-rolling-coverage' ) },
			},
			[
				[
					'core/group',
					{
						className: BYLINE_TEXT_CLASS,
						layout: { type: 'flex', flexWrap: 'nowrap' },
						style: {
							spacing: { blockGap: 'var:preset|spacing|20' },
						},
						metadata: {
							name: __(
								'Author + Date',
								'newspack-rolling-coverage'
							),
						},
					},
					[
						authorName( '700' ),
						[
							'core/post-date',
							{
								...POST_DATE_ATTRIBUTES,
								format: siteTimeFormat(),
								fontSize: 'small',
								...mutedDateColor( slugs ),
							},
						],
					],
				],
				...( kind === 'entry'
					? []
					: [ pinnedRow( { color: READABLE_ACCENT } ) ] ),
			],
		],
		...( kind === 'fullStory'
			? [ columnFullStoryBox( slugs, BYLINE_CARD ) ]
			: [
					[ 'core/post-title', { level: 4 } ] as TemplateItem,
					postContent(),
				] ),
		linksRow( [ shareLink() ] ),
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
	return rowEntryTemplate( ( kind ) => bylineRow( slugs, kind ), true );
}

/**
 * The Wire layout's per-entry template: the time, headline and a short
 * excerpt, ruled off from the entry above. The pinned card matches the
 * regular entry, since a capped feed ignores pinning. A Full Story Entry
 * shows the time above Byline's card, without the image, its
 * title at the entries' size and its blocks spaced like an entry's.
 *
 * @param {string[]} slugs The palette's color slugs.
 * @param {string[]} sizes The theme's font size slugs.
 * @return {TemplateItem[]} The template.
 */
function wireEntryTemplate( slugs: string[], sizes: string[] ): TemplateItem[] {
	const titleSize = themeFontSize( sizes, 'medium', 'normal' );
	const group = {
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
				blockGap: TIGHT_GAP,
				padding: { top: 'var:preset|spacing|30' },
			},
		},
	};
	const date: TemplateItem = [
		'core/post-date',
		{
			...POST_DATE_ATTRIBUTES,
			format: siteTimeFormat(),
			fontSize: 'small',
			style: { typography: { fontWeight: '700' } },
		},
	];
	const entry = ( className: string, name: string ): TemplateItem => [
		'core/group',
		{
			className,
			lock: LOCKED_IN_PLACE,
			...group,
			metadata: { name },
		},
		[
			date,
			[ 'core/post-title', { level: 4, fontSize: titleSize } ],
			[
				'core/post-excerpt',
				{
					excerptLength: 15,
					moreText: '',
					showMoreOnNewLine: false,
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
		fullStoryGroup(
			[
				date,
				columnFullStoryBox( slugs, {
					...BYLINE_CARD,
					hasImage: false,
					titleSize,
					boxGap: TIGHT_GAP,
					boxInset: 'var:preset|spacing|30',
					boxMargin: undefined,
				} ),
			],
			group
		),
	];
}

const DIGEST_FEED_STYLE = {
	border: { color: CONTRAST, width: '1px', style: 'solid' },
	spacing: { padding: 'var:preset|spacing|50' },
};

/**
 * The coverage's name as a heading, bound so it follows the coverage, as
 * the Digest layout's header, in the Ticker layout's and in the Alert box.
 *
 * @param {string} fontSize   The heading's font size preset.
 * @param {Object} attributes More attributes for the heading.
 * @return {TemplateItem} The heading.
 */
function coverageNameHeading(
	fontSize = 'large',
	attributes: Record< string, unknown > = {}
): TemplateItem {
	return [
		'core/heading',
		{
			...attributes,
			level: 3,
			fontSize,
			content: __( 'Live coverage', 'newspack-rolling-coverage' ),
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
 * The Digest layout's footer: the link to the coverage page, ruled off from
 * the entries.
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
		[ allUpdatesLink() ],
	];
}

/**
 * A Digest entry's row: the time in a narrow column, then the headline over a
 * short excerpt, or for a Full Story Entry Wire's card.
 *
 * @param {string[]}  slugs The palette's color slugs.
 * @param {string[]}  sizes The theme's font size slugs.
 * @param {EntryKind} kind  Which part of the template the row is.
 * @return {TemplateItem} The row.
 */
function digestRow(
	slugs: string[],
	sizes: string[],
	kind: EntryKind
): TemplateItem {
	const titleSize = themeFontSize( sizes, 'medium', 'normal' );

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
					kind === 'fullStory'
						? columnFullStoryBox( slugs, {
								...BYLINE_CARD,
								hasImage: false,
								titleSize,
								boxGap: TIGHT_GAP,
								boxInset: 'var:preset|spacing|30',
								boxMargin: undefined,
							} )
						: stack(
								__( 'Details', 'newspack-rolling-coverage' ),
								[
									[
										'core/post-title',
										{ level: 4, fontSize: titleSize },
									],
									[
										'core/post-excerpt',
										{
											excerptLength: 20,
											moreText: '',
											showMoreOnNewLine: false,
											fontSize: 'small',
											...mutedDateColor( slugs ),
										},
									],
								],
								TIGHT_GAP
							),
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
			left: 'var:preset|spacing|30',
			right: 'var:preset|spacing|30',
		},
	},
};

const ALERT_FEED_STYLE = {
	color: { background: PINNED_BACKGROUND },
	spacing: { padding: 'var:preset|spacing|30' },
};

const FLASH_FEED_LAYOUT = {
	type: 'flex',
	orientation: 'horizontal',
	flexWrap: 'nowrap',
	justifyContent: 'left',
	verticalAlignment: 'center',
};

/**
 * The Flash layout's bar: a full-width group on the site's accent color, its
 * content laid out at the theme's widths so a wide Feed lines up with the
 * site's wide content.
 *
 * @param {TemplateItem} feed The Feed group.
 * @return {TemplateItem} The bar.
 */
function flashBar( feed: TemplateItem ): TemplateItem {
	return [
		'core/group',
		{
			lock: LOCKED_IN_PLACE,
			align: 'full',
			layout: { type: 'constrained' },
			style: FLASH_BAR_STYLE,
			metadata: { name: __( 'Bar', 'newspack-rolling-coverage' ) },
		},
		[ feed ],
	];
}

/**
 * The Flash layout's per-entry template: the time and the entry's text on one
 * line, the text cut short to fit. Phones hide the time. The pinned card
 * matches the regular entry, since a capped feed ignores pinning. A Full
 * Story Entry shows the time, the Full story label in capitals and in the
 * bar's text color, the post's title and its summary, cut short to fit.
 *
 * @return {TemplateItem[]} The template.
 */
function flashEntryTemplate(): TemplateItem[] {
	const group = {
		layout: {
			type: 'flex',
			flexWrap: 'nowrap',
			verticalAlignment: 'center',
		},
		style: { spacing: { blockGap: 'var:preset|spacing|30' } },
	};
	const date: TemplateItem = [
		'core/post-date',
		{
			...POST_DATE_ATTRIBUTES,
			format: siteTimeFormat(),
			fontSize: 'small',
			style: { typography: { fontWeight: '700' } },
			metadata: {
				...POST_DATE_ATTRIBUTES.metadata,
				blockVisibility: { viewport: { mobile: false } },
			},
		},
	];
	const excerpt: TemplateItem = [
		'core/post-excerpt',
		{
			excerptLength: 100,
			moreText: '',
			showMoreOnNewLine: false,
			fontSize: 'small',
		},
	];
	const [ labelName, labelAttributes ] = fullStoryLabel() as [
		string,
		Record< string, unknown >,
	];
	const entry = ( className: string, name: string ): TemplateItem => [
		'core/group',
		{
			className,
			lock: LOCKED_IN_PLACE,
			...group,
			metadata: { name },
		},
		[ date, excerpt ],
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
		fullStoryGroup(
			[
				date,
				[
					labelName,
					{
						...labelAttributes,
						style: {
							typography: {
								fontWeight: '700',
								textTransform: 'uppercase',
							},
						},
					},
				],
				[
					'core/post-title',
					{
						level: 0,
						fontSize: 'small',
						style: { typography: { fontWeight: '700' } },
					},
				],
				excerpt,
			],
			group
		),
	];
}

/**
 * The Ticker layout's Feed layout: a wrapping row. The ruled Feed's
 * stylesheet keeps the entries on one line that scrolls, beside the header
 * or under it in a narrow Feed, and puts the footer on a line of its own.
 */
const TICKER_FEED_LAYOUT = {
	type: 'flex',
	orientation: 'horizontal',
	flexWrap: 'wrap',
	justifyContent: 'left',
	verticalAlignment: 'stretch',
};

/**
 * The Ticker layout's header: the coverage's status over its name.
 *
 * @param {string[]} sizes The theme's font size slugs.
 * @return {TemplateItem} The group.
 */
function tickerHeader( sizes: string[] ): TemplateItem {
	return [
		'core/group',
		{
			layout: {
				type: 'flex',
				orientation: 'vertical',
				justifyContent: 'left',
			},
			style: { spacing: { blockGap: '0.25em' } },
			metadata: { name: __( 'Header', 'newspack-rolling-coverage' ) },
		},
		[
			[ STATUS_BLOCK_NAME, {} ],
			coverageNameHeading( themeFontSize( sizes, 'medium', 'normal' ) ),
		],
	];
}

/**
 * The Ticker layout's per-entry template: the relative time over the
 * headline, which links to the entry. An entry without a title shows its
 * opening words as the headline (see
 * Entry_Bindings::untitled_fallback_title()). The pinned card matches the
 * regular entry, since a capped feed ignores pinning.
 *
 * @param {string[]} slugs The palette's color slugs.
 * @return {TemplateItem[]} The template.
 */
function tickerEntryTemplate( slugs: string[] ): TemplateItem[] {
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
			style: { spacing: { blockGap: '0.25em' } },
			metadata: { name },
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
			[
				'core/post-title',
				{
					level: 4,
					isLink: true,
					fontSize: 'small',
					className: ENTRY_LINK_CLASS,
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
 * How many columns the Split layout's grid has on desktop: the pinned
 * entries in the first, the entries across the other two.
 */
const SPLIT_COLUMNS = 3;

/**
 * How many rows the Split layout's Pinned group spans, one per entry beside it,
 * enough for a full page (Rolling_Coverage_Block::PER_PAGE_MAX). Infinite
 * scroll appends further pages and each ad takes a row, so a long feed can
 * pass the span: updates past it continue in their column and the Pinned
 * group's column ends there.
 */
const SPLIT_SUMMARY_ROWS = 100;

/**
 * The Split layout's Feed layout: a grid of three columns.
 */
const SPLIT_FEED_LAYOUT = {
	type: 'grid',
	columnCount: SPLIT_COLUMNS,
};

/**
 * The Split layout's Feed style: one column on tablets and phones.
 */
const SPLIT_FEED_STYLE = {
	'@tablet': { layout: { columnCount: 1 } },
	'@mobile': { layout: { columnCount: 1 } },
};

/**
 * The Split layout's Feed spacing: space between the columns and none
 * between the rows, since the Pinned group spans many rows that would each add
 * it. The entries space themselves with their padding.
 */
const SPLIT_FEED_GAP = { top: '0', left: 'var:preset|spacing|50' };

/**
 * Where a Split block sits on tablets and phones, where the grid has one
 * column.
 */
const SPLIT_STACKED_PLACEMENT = { layout: { columnStart: 1, columnSpan: 1 } };

/**
 * A Split full story's blocks, in the entries' columns and in the Pinned
 * group alike: the label, the title, the image cropped to 16:9 so its height
 * is known before it loads, the summary set like the entry text, a quiet
 * "Read more" button with more room below it than above, and the author and
 * Share. No box: the label and the button set it apart.
 *
 * @param {string[]} slugs The palette's color slugs.
 * @return {TemplateItem[]} The blocks.
 */
function splitFullStoryBlocks( slugs: string[] ): TemplateItem[] {
	return [
		fullStoryLabel(),
		fullStoryTitle( 4 ),
		fullStoryImage( {
			aspectRatio: '16/9',
			margin: 'var:preset|spacing|20',
		} ),
		fullStoryExcerpt( slugs, { asEntries: true } ),
		fullStoryReadMore( {
			muted: true,
			space: {
				top: 'var:preset|spacing|20',
				bottom: 'var:preset|spacing|30',
			},
		} ),
		linksRow( [ authorGroup(), shareLink() ] ),
	];
}

/**
 * A Split entry's group: placed in the grid's other two columns, ruled off
 * from the entry above, the time it was posted beside the title, content,
 * and the author's avatar and name with the links, or above them on phones.
 * A Full Story Entry shows the breakout post beside its time instead (see
 * splitFullStoryBlocks()). Pinned entries show in the Pinned group instead
 * (see splitPinnedStack()).
 *
 * @param {string[]} slugs       The palette's color slugs.
 * @param {boolean}  isFullStory Whether the group is the Full Story Entry group.
 * @return {TemplateItem} The group.
 */
function splitEntryGroup(
	slugs: string[],
	isFullStory: boolean
): TemplateItem {
	const attributes = {
		lock: LOCKED_IN_PLACE,
		style: {
			layout: { columnStart: 2, columnSpan: 2 },
			'@tablet': SPLIT_STACKED_PLACEMENT,
			'@mobile': SPLIT_STACKED_PLACEMENT,
			border: {
				top: {
					color: BORDER_COLOR,
					width: '1px',
					style: 'solid',
				},
			},
			spacing: {
				padding: {
					top: 'var:preset|spacing|40',
					bottom: 'var:preset|spacing|40',
				},
			},
		},
	};
	const row: TemplateItem = [
		'core/columns',
		{
			style: {
				spacing: {
					blockGap: {
						top: 'var:preset|spacing|20',
						left: 'var:preset|spacing|30',
					},
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
				{
					width: '7rem',
					metadata: {
						name: __( 'Time', 'newspack-rolling-coverage' ),
					},
				},
				[
					[
						'core/post-date',
						{
							...POST_DATE_ATTRIBUTES,
							format: siteTimeFormat(),
							fontSize: 'small',
							style: {
								typography: { fontWeight: '700' },
							},
						},
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
				[
					stack(
						__( 'Entry', 'newspack-rolling-coverage' ),
						isFullStory
							? splitFullStoryBlocks( slugs )
							: [
									[ 'core/post-title', { level: 4 } ],
									postContent(),
									linksRow( [ authorGroup(), shareLink() ] ),
								]
					),
				],
			],
		],
	];

	if ( isFullStory ) {
		return fullStoryGroup( [ row ], attributes );
	}

	return [
		'core/group',
		{
			className: REGULAR_ENTRY_CLASS,
			...attributes,
			metadata: {
				name: __( 'Entry', 'newspack-rolling-coverage' ),
			},
		},
		[ row ],
	];
}

/**
 * A pinned entry's look in Split's Pinned group: headed by the same heavy
 * rule as the column, with the same space above and below its content, so
 * the rule between two pinned entries sits halfway. Nothing shows it's
 * pinned, as the column says it; the pinned row is there for screen readers.
 */
const SPLIT_PINNED_STYLE = {
	border: { top: { color: CONTRAST, width: '3px', style: 'solid' } },
	spacing: {
		blockGap: DEFAULT_ENTRY_GAP,
		padding: {
			top: 'var:preset|spacing|40',
			bottom: 'var:preset|spacing|40',
		},
	},
};

/**
 * Split's Pinned group: the column beside the entries that holds every
 * pinned entry, one under another, as the pinned card or, for a pinned Full
 * Story entry, the Full Story Entry group inside it (see
 * Rolling_Coverage_Block::for_entry_kind()). It spans the feed's full length
 * and sticks as the reader scrolls where the theme supports it, by its foot
 * once it's taller than the viewport (see view.ts); above the entries on
 * tablets and phones.
 *
 * @param {string[]} slugs The palette's color slugs.
 * @return {TemplateItem} The group.
 */
function splitPinnedStack( slugs: string[] ): TemplateItem {
	const vertical = {
		type: 'flex',
		orientation: 'vertical',
		justifyContent: 'stretch',
	};
	const stacked = {
		layout: { ...SPLIT_STACKED_PLACEMENT.layout, rowSpan: 1 },
	};

	return [
		'core/group',
		{
			className: PINNED_STACK_CLASS,
			lock: LOCKED_IN_PLACE,
			// The site renders only the groups it holds, once per pinned entry.
			allowedBlocks: [],
			layout: vertical,
			style: {
				layout: {
					columnStart: 1,
					columnSpan: 1,
					rowSpan: SPLIT_SUMMARY_ROWS,
				},
				'@tablet': stacked,
				'@mobile': stacked,
				spacing: { blockGap: '0' },
				position: { type: 'sticky', top: '0px' },
			},
			metadata: { name: __( 'Pinned', 'newspack-rolling-coverage' ) },
		},
		[
			[
				'core/group',
				{
					className: PINNED_CARD_CLASS,
					lock: LOCKED_IN_PLACE,
					layout: vertical,
					style: SPLIT_PINNED_STYLE,
					metadata: {
						name: __( 'Pinned Entry', 'newspack-rolling-coverage' ),
					},
				},
				[
					pinnedRow( { icon: false, hidden: true } ),
					[ 'core/post-title', { level: 4 } ],
					postContent(),
					linksRow( [ authorGroup(), shareLink() ] ),
				],
			],
			fullStoryGroup(
				[
					pinnedRow( { icon: false, hidden: true } ),
					...splitFullStoryBlocks( slugs ),
				],
				{ layout: vertical, style: SPLIT_PINNED_STYLE },
				__( 'Pinned Full Story', 'newspack-rolling-coverage' )
			),
		],
	];
}

/**
 * The Split layout's per-entry template: the Pinned group in the grid's
 * first column (see splitPinnedStack()), and each entry spanning the other
 * two (see splitEntryGroup()). The site places each entry's article where its
 * group says, and the Pinned group around the pinned entries' articles (see
 * Rolling_Coverage_Block::place_in_grid()).
 *
 * @param {string[]} slugs The palette's color slugs.
 * @return {TemplateItem[]} The template.
 */
function splitEntryTemplate( slugs: string[] ): TemplateItem[] {
	return [
		splitPinnedStack( slugs ),
		splitEntryGroup( slugs, false ),
		splitEntryGroup( slugs, true ),
	];
}

/**
 * Whether a group sets where it sits in a grid, on desktop or at any
 * viewport, mirroring Rolling_Coverage_Block::grid_placement().
 *
 * @param {Object} block            The group.
 * @param {Object} block.attributes Its attributes.
 * @return {boolean} Whether it sets a placement.
 */
function hasGridPlacement( block?: {
	attributes?: Record< string, unknown >;
} ): boolean {
	const style = block?.attributes?.style as
		Record< string, unknown > | undefined;
	const setsPlacement = ( styles: unknown ) => {
		const layout = ( styles as { layout?: Record< string, unknown > } )
			?.layout;

		return [ 'columnStart', 'columnSpan', 'rowStart', 'rowSpan' ].some(
			( key ) => Boolean( layout?.[ key ] )
		);
	};

	return (
		!! style &&
		( setsPlacement( style ) ||
			Object.keys( style ).some(
				( key ) =>
					key.startsWith( '@' ) && setsPlacement( style[ key ] )
			) )
	);
}

/**
 * A group's top border settings: its top side's, or those of all its sides
 * when they're linked, the color preset among them, mirroring
 * Rolling_Coverage_Block::top_border().
 *
 * @param {Object} block            The group.
 * @param {Object} block.attributes Its attributes.
 * @return {Object} Top border settings, as a group's style holds them.
 */
function topBorder( block?: {
	attributes?: Record< string, unknown >;
} ): Record< string, unknown > {
	const border = {
		...( (
			block?.attributes?.style as
				{ border?: Record< string, unknown > } | undefined
		 )?.border ?? {} ),
	};
	const top = { ...( border.top as Record< string, unknown > | undefined ) };
	const preset = block?.attributes?.borderColor;

	if ( border.color === undefined && typeof preset === 'string' && preset ) {
		border.color = `var:preset|color|${ preset }`;
	}

	[ 'color', 'width', 'style' ].forEach( ( property ) => {
		const value = border[ property ];

		if (
			top[ property ] === undefined &&
			typeof value === 'string' &&
			value
		) {
			top[ property ] = value;
		}
	} );

	return top;
}

/**
 * An entry's preview blocks with the pinned card's top border on the entry
 * group or the Full Story Entry group, as the entry heading the entries
 * beside the card renders, so the two rules line up (see
 * Rolling_Coverage_Block::column_rules()). Unchanged unless a grid Feed
 * places the card beside the entry group and the card has a top border.
 *
 * @param {Object[]} blocks     The entry's preview blocks.
 * @param {Object[]} template   The layout's per-entry blocks.
 * @param {Object}   feedLayout The Feed group's layout attribute.
 * @return {Object[]} The blocks.
 */
function withColumnRule< T extends { name: string; [ key: string ]: unknown } >(
	blocks: T[],
	template: { name: string; attributes?: Record< string, unknown > }[],
	feedLayout: Record< string, unknown > | undefined
): T[] {
	const pinnedGroup = template.find( isPinnedStack );
	const card = pinnedGroup
		? pinnedStackGroups( template ).find( isPinnedCard )
		: template.find( isPinnedCard );
	const top = topBorder( card );

	if (
		feedLayout?.type !== 'grid' ||
		! hasGridPlacement( pinnedGroup ?? card ) ||
		! template.some( isRegularEntry ) ||
		Object.keys( top ).length === 0
	) {
		return blocks;
	}

	return blocks.map( ( block ) => {
		const attributes = ( block.attributes ?? {} ) as {
			className?: string;
			style?: { border?: Record< string, unknown > };
		};

		if (
			! isRegularEntry( { name: block.name, attributes } ) &&
			! isBreakoutEntry( { name: block.name, attributes } )
		) {
			return block;
		}

		return {
			...block,
			attributes: {
				...attributes,
				style: {
					...attributes.style,
					border: { ...attributes.style?.border, top },
				},
			},
		};
	} );
}

/**
 * The grid cell an entry's preview takes in a grid Feed, mirroring the
 * article the site places (see Rolling_Coverage_Block::place_in_grid()):
 * the pinned card's desktop placement for the first pinned entry, the Full
 * Story Entry group's for a Full Story entry when it sets one, else the
 * entry group's, which takes the full row while no pinned entry shows if
 * the pinned card carries a placement of its own. None outside a grid Feed,
 * or where the group sets no placement.
 *
 * @param {Object[]} template      The layout's per-entry blocks.
 * @param {Object}   feedLayout    The Feed group's layout attribute.
 * @param {boolean}  isLeadPin     Whether the entry is the first pinned entry previewed.
 * @param {boolean}  showsPin      Whether any pinned entry is previewed.
 * @param {boolean}  [isFullStory] Whether the entry shows through the Full Story Entry group.
 * @return {Object|undefined} The preview container's inline style.
 */
function entryPreviewPlacement(
	template: { name: string; attributes?: Record< string, unknown > }[],
	feedLayout: Record< string, unknown > | undefined,
	isLeadPin: boolean,
	showsPin: boolean,
	isFullStory = false
): Record< string, string > | undefined {
	if ( feedLayout?.type !== 'grid' ) {
		return undefined;
	}

	const placementOf = ( block?: {
		attributes?: Record< string, unknown >;
	} ) =>
		(
			block?.attributes?.style as
				{ layout?: Record< string, string | number > } | undefined
		 )?.layout;
	const cardBlock =
		template.find( isPinnedStack ) ?? template.find( isPinnedCard );
	const card = placementOf( cardBlock );
	const entry = placementOf( template.find( isRegularEntry ) );
	const breakout = placementOf( template.find( isBreakoutEntry ) );
	let placement = entry;

	if ( isLeadPin && card ) {
		placement = card;
	} else if ( isFullStory && breakout ) {
		placement = breakout;
	}

	if ( ! placement ) {
		return undefined;
	}

	if ( placement !== card && hasGridPlacement( cardBlock ) && ! showsPin ) {
		return { gridColumn: '1 / -1' };
	}

	const track = ( start?: string | number, span?: string | number ) => {
		if ( start && span ) {
			return `${ start } / span ${ span }`;
		}

		return start ? `${ start }` : span && `span ${ span }`;
	};
	const gridColumn = track( placement.columnStart, placement.columnSpan );
	const gridRow = track( placement.rowStart, placement.rowSpan );

	return gridColumn || gridRow
		? {
				...( gridColumn ? { gridColumn } : {} ),
				...( gridRow ? { gridRow } : {} ),
			}
		: undefined;
}

/**
 * The Digest layout's per-entry template: the time and, beside it, the
 * headline over a short excerpt, ruled off from the entry above. The pinned
 * card matches the regular entry, since a capped feed ignores pinning. A
 * Full Story Entry shows Wire's card beside the time.
 *
 * @param {string[]} slugs The palette's color slugs.
 * @param {string[]} sizes The theme's font size slugs.
 * @return {TemplateItem[]} The template.
 */
function digestEntryTemplate(
	slugs: string[],
	sizes: string[]
): TemplateItem[] {
	return rowEntryTemplate(
		( kind ) => digestRow( slugs, sizes, kind ),
		true
	);
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
 * @return {boolean} Whether it's the all-updates paragraph.
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
 * renders once: the Follow Coverage block, the Coverage Status block, the
 * Update Timer block, a heading bound to the coverage's name, the
 * all-updates paragraph, or a block holding one at any depth, mirroring
 * Entry_Bindings::is_coverage_item(). The Pinned group, the pinned card, the
 * entry group and the Full Story Entry group always belong to each entry,
 * whatever they hold.
 *
 * @param {Object} block      The block.
 * @param {string} block.name Block name.
 * @return {boolean} Whether it's a coverage-level block.
 */
function isCoverageItem( block: {
	name: string;
	[ key: string ]: unknown;
} ): boolean {
	const typed = block as {
		name: string;
		attributes?: Record< string, unknown >;
	};

	if (
		isPinnedStack( typed ) ||
		isPinnedCard( typed ) ||
		isRegularEntry( typed ) ||
		isBreakoutEntry( typed )
	) {
		return false;
	}

	return (
		typed.name === FOLLOW_BLOCK_NAME ||
		typed.name === CHECK_UPDATES_BLOCK_NAME ||
		typed.name === LOAD_MORE_BLOCK_NAME ||
		block.name === STATUS_BLOCK_NAME ||
		typed.name === UPDATE_TIMER_BLOCK_NAME ||
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
 * entries.
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
			} else {
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
 * The blocks without their Check for Updates blocks, at any depth, as the
 * site renders them where the feed checks on its own.
 *
 * @param {Object[]} blocks The blocks.
 * @return {Object[]} The blocks without Check for Updates blocks.
 */
function withoutCheckUpdatesButtons<
	T extends { name: string; [ key: string ]: unknown },
>( blocks: T[] ): T[] {
	return withoutBlocks(
		blocks,
		( block ) => block.name === CHECK_UPDATES_BLOCK_NAME
	);
}

/**
 * The blocks without their Load More blocks, at any depth, as the site
 * renders them where the feed doesn't load older entries with a button.
 *
 * @param {Object[]} blocks The blocks.
 * @return {Object[]} The blocks without Load More blocks.
 */
function withoutLoadMoreButtons<
	T extends { name: string; [ key: string ]: unknown },
>( blocks: T[] ): T[] {
	return withoutBlocks(
		blocks,
		( block ) => block.name === LOAD_MORE_BLOCK_NAME
	);
}

/**
 * The client IDs of the blocks of a type among the blocks, at any depth.
 *
 * @param {Object[]} blocks The blocks.
 * @param {string}   name   Block type name.
 * @return {string[]} Client IDs.
 */
function blockIdsOfType(
	blocks: { name: string; [ key: string ]: unknown }[],
	name: string
): string[] {
	return blocks.flatMap( ( block ) =>
		block.name === name
			? [ block.clientId as string ]
			: blockIdsOfType(
					Array.isArray( block.innerBlocks )
						? ( block.innerBlocks as typeof blocks )
						: [],
					name
				)
	);
}

/**
 * The client IDs of the blocks the Author name and Author avatar settings
 * leave out of an entry template (see withAuthorSettings()), at any depth,
 * so an editable layout hides them as the site does.
 *
 * @param {Object[]} blocks     The template blocks.
 * @param {boolean}  showAuthor The block's Author name setting.
 * @param {boolean}  showAvatar The block's Author avatar setting.
 * @return {string[]} Client IDs.
 */
function hiddenAuthorBlockIds(
	blocks: { name: string; [ key: string ]: unknown }[],
	showAuthor: boolean,
	showAvatar: boolean
): string[] {
	const idsOf = ( items: typeof blocks ): string[] =>
		items.flatMap( ( block ) => [
			block.clientId as string,
			...idsOf(
				Array.isArray( block.innerBlocks )
					? ( block.innerBlocks as typeof blocks )
					: []
			),
		] );
	const kept = new Set(
		idsOf( withAuthorSettings( blocks, showAuthor, showAvatar ) )
	);

	return idsOf( blocks ).filter( ( id ) => ! kept.has( id ) );
}

/**
 * Whether the blocks hold a block of a type, at any depth.
 *
 * @param {Object[]} blocks The blocks.
 * @param {string}   name   Block type name.
 * @return {boolean} Whether one of them is, or holds, that type.
 */
function holdsBlockType(
	blocks: { name: string; [ key: string ]: unknown }[],
	name: string
): boolean {
	return blocks.some(
		( block ) =>
			block.name === name ||
			holdsBlockType(
				Array.isArray( block.innerBlocks )
					? ( block.innerBlocks as typeof blocks )
					: [],
				name
			)
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
 * The blocks without the all-updates paragraph, at any depth, as the
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
 * The blocks with each all-updates paragraph reading the text, at any
 * depth, as the site renders a shared layout with the block's own link text
 * (Entry_Bindings::link_all_updates()).
 *
 * @param {Object[]} blocks The blocks.
 * @param {string}   text   The link text.
 * @return {Object[]} The blocks.
 */
function withAllUpdatesText<
	T extends { name: string; [ key: string ]: unknown },
>( blocks: T[], text: string ): T[] {
	return blocks.map( ( block ) => {
		if ( isAllUpdatesParagraph( block ) ) {
			return {
				...block,
				attributes: {
					...( block.attributes as Record< string, unknown > ),
					content: placeholderLink( text ),
				},
			};
		}

		return Array.isArray( block.innerBlocks ) && block.innerBlocks.length
			? {
					...block,
					innerBlocks: withAllUpdatesText(
						block.innerBlocks as T[],
						text
					),
				}
			: block;
	} );
}

/**
 * The text of the first all-updates paragraph among the blocks, at any
 * depth.
 *
 * @param {Object[]} blocks The blocks.
 * @return {string|null} The text, or null without the paragraph.
 */
function allUpdatesTextOf(
	blocks: { name: string; [ key: string ]: unknown }[]
): string | null {
	for ( const block of blocks ) {
		if ( isAllUpdatesParagraph( block ) ) {
			const content = ( block.attributes as Record< string, unknown > )
				?.content;

			return (
				new DOMParser().parseFromString(
					String( content ?? '' ),
					'text/html'
				).body.textContent ?? ''
			).trim();
		}

		const text = allUpdatesTextOf(
			Array.isArray( block.innerBlocks )
				? ( block.innerBlocks as typeof blocks )
				: []
		);

		if ( text !== null ) {
			return text;
		}
	}

	return null;
}

/**
 * The client IDs of the all-updates paragraphs among the blocks, at
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
 * The Load More block, which shows the feed's Load more button where the
 * layout places it.
 *
 * @param {Object} buttons Settings for its Buttons block, such as its
 *                         justification or margin.
 * @return {Array} The template item.
 */
function loadMoreBlock(
	buttons: Record< string, unknown > = {}
): TemplateItem {
	return [ LOAD_MORE_BLOCK_NAME, {}, [ loadMoreButtonsTemplate( buttons ) ] ];
}

/**
 * Settings for a Load More block's Buttons block that leave a full
 * spacing-50 above the button in a Feed whose Block spacing is smaller.
 *
 * @param {string} gap The Feed's Block spacing preset slug, such as `30`.
 * @return {Object} The settings.
 */
function loadMoreTopUp( gap: string ): Record< string, unknown > {
	return {
		style: {
			spacing: {
				margin: {
					top: `calc(var(--wp--preset--spacing--50) - var(--wp--preset--spacing--${ gap }))`,
				},
			},
		},
	};
}

/**
 * The Feed group holding the layout's items: everything the coverage shows,
 * spaced by its Block spacing.
 *
 * @param {Object[]}      items      The items.
 * @param {string|Object} gap        The space between the items, as a spacing
 *                                   preset, or between the rows (`top`) and the
 *                                   columns (`left`).
 * @param {Object}        style      Extra style settings, such as a border or padding.
 * @param {Object}        layout     The group's layout, a vertical stack by default.
 * @param {Object}        attributes Extra group settings, such as its alignment.
 * @return {Object} The Feed group.
 */
function feedTemplate(
	items: TemplateItem[],
	gap: string | { top: string; left: string } = 'var:preset|spacing|50',
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
			className: [ FEED_CLASS, attributes.className ]
				.filter( Boolean )
				.join( ' ' ),
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
 * Whether blocks hold the pinned row, at any depth, mirroring
 * Entry_Bindings::has_pinned_row().
 *
 * @param {Object[]} blocks The blocks.
 * @return {boolean} Whether the pinned row is among them.
 */
function holdsPinnedRow(
	blocks: { name: string; innerBlocks?: unknown }[]
): boolean {
	return blocks.some(
		( block ) =>
			isPinnedRow( block ) ||
			( Array.isArray( block.innerBlocks ) &&
				holdsPinnedRow( block.innerBlocks ) )
	);
}

/**
 * Whether a template's Full Story Entry takes pinned entries too: its
 * Pinned group holds a Full Story Entry group, or a Full Story Entry group
 * among its top-level blocks holds the pinned row, mirroring
 * Rolling_Coverage_Block::full_story_takes_pinned().
 *
 * @param {Object[]} blocks The template's top-level blocks.
 * @return {boolean} Whether pinned entries with a published breakout post show as Full Story entries.
 */
function fullStoryTakesPinned(
	blocks: {
		name: string;
		attributes?: Record< string, unknown >;
		innerBlocks?: unknown;
	}[]
): boolean {
	return (
		pinnedStackGroups( blocks ).some( isBreakoutEntry ) ||
		blocks.some(
			( block ) =>
				isBreakoutEntry( block ) &&
				Array.isArray( block.innerBlocks ) &&
				holdsPinnedRow( block.innerBlocks )
		)
	);
}

/**
 * The client IDs of the pinned rows and pinned labels in blocks, which an
 * entry that isn't pinned leaves out (see withoutPinnedRow()).
 *
 * @param {Object[]} blocks The blocks.
 * @return {string[]} Client IDs.
 */
function pinnedRowBlockIds(
	blocks: { name: string; [ key: string ]: unknown }[]
): string[] {
	return blocks.flatMap( ( block ) => {
		if ( isPinnedLabel( block ) || isPinnedRow( block ) ) {
			return [ block.clientId as string ];
		}

		return Array.isArray( block.innerBlocks )
			? pinnedRowBlockIds( block.innerBlocks as typeof blocks )
			: [];
	} );
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
 * Whether a block is a title that links to its entry when the entry has no
 * published breakout post, mirroring Entry_Bindings::is_entry_link_title().
 *
 * @param {Object} block            The block.
 * @param {string} block.name       Block name.
 * @param {Object} block.attributes Block attributes.
 * @return {boolean} Whether it's such a title.
 */
function isEntryLinkTitle( block: {
	name: string;
	attributes?: Record< string, unknown >;
} ): boolean {
	const className = block.attributes?.className;

	return (
		block.name === 'core/post-title' &&
		typeof className === 'string' &&
		className.split( ' ' ).includes( ENTRY_LINK_CLASS )
	);
}

/**
 * The template with the entry's title as a link, as an entry with a published
 * breakout post renders, or with `entryLinksOnly`, only the titles that link
 * to their entry without one (see Entry_Bindings::link_title_to_breakout()).
 *
 * @param {Object[]} blocks         The template blocks.
 * @param {boolean}  entryLinksOnly Whether only titles carrying ENTRY_LINK_CLASS link.
 * @return {Object[]} The blocks with the titles linked.
 */
function withLinkedTitle<
	T extends { name: string; [ key: string ]: unknown },
>( blocks: T[], entryLinksOnly = false ): T[] {
	return blocks.map( ( block ) => {
		if (
			block.name === 'core/post-title' &&
			( ! entryLinksOnly ||
				isEntryLinkTitle(
					block as {
						name: string;
						attributes?: Record< string, unknown >;
					}
				) )
		) {
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
					innerBlocks: withLinkedTitle(
						block.innerBlocks as T[],
						entryLinksOnly
					),
				}
			: block;
	} );
}

/**
 * Whether a block is the Pinned group, mirroring
 * Rolling_Coverage_Block::is_pinned_stack().
 *
 * @param {Object} block            The block.
 * @param {string} block.name       Block name.
 * @param {Object} block.attributes Block attributes.
 * @return {boolean} Whether it's the Pinned group.
 */
function isPinnedStack( block: {
	name: string;
	attributes?: Record< string, unknown >;
} ): boolean {
	const className = block.attributes?.className;

	return (
		block.name === 'core/group' &&
		typeof className === 'string' &&
		className.split( ' ' ).includes( PINNED_STACK_CLASS )
	);
}

/**
 * The entry groups a template's Pinned group holds, or none without one,
 * mirroring Rolling_Coverage_Block::pinned_stack_groups().
 *
 * @param {Object[]} blocks The template's top-level blocks.
 * @return {Object[]} The groups.
 */
function pinnedStackGroups< T extends { name: string } >( blocks: T[] ): T[] {
	const pinnedGroup = blocks.find( isPinnedStack ) as
		( T & { innerBlocks?: unknown } ) | undefined;

	return Array.isArray( pinnedGroup?.innerBlocks )
		? ( pinnedGroup.innerBlocks as T[] ).filter(
				( block ) =>
					isPinnedCard( block ) ||
					isRegularEntry( block ) ||
					isBreakoutEntry( block )
			)
		: [];
}

type InsetBlock = {
	name: string;
	attributes?: Record< string, unknown >;
	innerBlocks?: unknown;
};

/**
 * The first block with the given name among blocks, at any depth.
 *
 * @param {Object[]} blocks The blocks.
 * @param {string}   name   Block name.
 * @return {Object|undefined} The block.
 */
function firstBlockNamed(
	blocks: InsetBlock[],
	name: string
): InsetBlock | undefined {
	for ( const block of blocks ) {
		if ( block.name === name ) {
			return block;
		}

		const found = Array.isArray( block.innerBlocks )
			? firstBlockNamed( block.innerBlocks as InsetBlock[], name )
			: undefined;

		if ( found ) {
			return found;
		}
	}

	return undefined;
}

/**
 * How far in from the entries' columns the Load more button starts, mirroring
 * Rolling_Coverage_Block::load_more_inset(): past the first column of the
 * entry group's first Columns block, the space between its columns, and the
 * second column's own inline-start border and padding, less what columns
 * that all set percentage widths shrink by to fit the gap.
 *
 * @param {Object[]} template The layout's per-entry blocks.
 * @return {Object|undefined} The inset as a CSS length, and whether the row
 *                            stacks on phones; undefined without one.
 */
function loadMoreInset(
	template: InsetBlock[]
): { inset: string; stacks: boolean } | undefined {
	const entry = template.find( isRegularEntry );
	const row = Array.isArray( entry?.innerBlocks )
		? firstBlockNamed( entry.innerBlocks as InsetBlock[], 'core/columns' )
		: undefined;
	const columns = Array.isArray( row?.innerBlocks )
		? ( row.innerBlocks as InsetBlock[] )
		: [];
	const width = columns[ 0 ]?.attributes?.width;

	if ( typeof width !== 'string' || width === '' ) {
		return undefined;
	}

	const gap = (
		row?.attributes?.style as
			{ spacing?: { blockGap?: string | { left?: string } } } | undefined
	 )?.spacing?.blockGap;
	const left = typeof gap === 'object' ? gap.left : gap;
	// A unitless 0 isn't a length in calc().
	const lengthCss = ( value?: string ) =>
		value === '0' ? '0px' : blockGapCss( value );
	const gapCss = lengthCss( left ) ?? 'var(--wp--style--block-gap, 2em)';
	const parts = [ width, gapCss ];
	const widths = columns.map( ( column ) =>
		String( column.attributes?.width ?? '' )
	);
	let shrink = '';

	if (
		widths.length > 1 &&
		widths.every( ( value ) => /^\d+(\.\d+)?%$/.test( value ) )
	) {
		const total = widths.reduce(
			( sum, value ) => sum + parseFloat( value ),
			0
		);

		if ( total > 0 ) {
			shrink = ` - max(0px, ${ total }% - 100% + ${
				widths.length - 1
			} * ${ gapCss }) * ${
				Math.round( ( parseFloat( width ) / total ) * 10000 ) / 10000
			}`;
		}
	}

	const textStyle = columns[ 1 ]?.attributes?.style as
		| {
				spacing?: { padding?: { left?: string } };
				border?: { width?: string; left?: { width?: string } };
		  }
		| undefined;
	const border = textStyle?.border?.left?.width ?? textStyle?.border?.width;
	const padding = textStyle?.spacing?.padding?.left;

	[ border, padding ].forEach( ( length ) => {
		const css = lengthCss( length );

		if ( css ) {
			parts.push( css );
		}
	} );

	const inset = `calc(${ parts.join( ' + ' ) }${ shrink })`;

	// The site drops anything else, and the editor writes it into a style tag.
	if ( ! /^[\w\s.%()+*\/,-]+$/.test( inset ) || inset.includes( '/*' ) ) {
		return undefined;
	}

	return {
		inset,
		stacks: row?.attributes?.isStackedOnMobile !== false,
	};
}

/**
 * The CSS custom properties that start the Load more button where the
 * entries' text does (see loadMoreInset() and style.scss).
 *
 * @param {Object[]} template The layout's per-entry blocks.
 * @return {Object} The properties, empty without an inset.
 */
function loadMoreInsetStyle(
	template: InsetBlock[]
): Record< string, string > {
	const inset = loadMoreInset( template );

	if ( ! inset ) {
		return {};
	}

	return {
		'--newspack-rolling-coverage-load-more-inset': inset.inset,
		...( inset.stacks
			? {}
			: {
					'--newspack-rolling-coverage-load-more-inset-narrow':
						inset.inset,
				} ),
	};
}

/**
 * Whether a Load More block's button sits at the inline start, mirroring
 * Rolling_Coverage_Block::starts_at_inline_start().
 *
 * @param {Object} block The Load More block.
 * @return {boolean} Whether its Buttons block justifies its content left.
 */
function startsAtInlineStart( block: InsetBlock ): boolean {
	const buttons = Array.isArray( block.innerBlocks )
		? firstBlockNamed( block.innerBlocks as InsetBlock[], 'core/buttons' )
		: undefined;
	const layout = buttons?.attributes?.layout as
		{ justifyContent?: string } | undefined;

	return ( layout?.justifyContent ?? 'left' ) === 'left';
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
 * Whether a block is the Full Story Entry group, mirroring
 * Rolling_Coverage_Block::is_breakout_entry().
 *
 * @param {Object} block            The block.
 * @param {string} block.name       Block name.
 * @param {Object} block.attributes Block attributes.
 * @return {boolean} Whether it's the Full Story Entry group.
 */
function isBreakoutEntry( block: {
	name: string;
	attributes?: Record< string, unknown >;
} ): boolean {
	const className = block.attributes?.className;

	return (
		block.name === 'core/group' &&
		typeof className === 'string' &&
		className.split( ' ' ).includes( BREAKOUT_ENTRY_CLASS )
	);
}

/**
 * The template for one kind of entry (see
 * Rolling_Coverage_Block::for_entry_kind()). A Full Story entry shows the
 * Full Story Entry group alone, where the template holds one; every other
 * entry shows the template without it. Then, where it holds both the pinned
 * card and the entry group, a pinned entry shows the card alone, every other
 * entry the entry group alone. A template missing either is returned as it
 * is. In a template with the Pinned group, a pinned entry keeps the group
 * the Pinned group holds for its kind instead, and every other entry leaves
 * the Pinned group out.
 *
 * @param {Object[]} blocks        The template's top-level blocks.
 * @param {boolean}  isPinned      Whether the entry is pinned.
 * @param {boolean}  [isFullStory] Whether the entry shows its breakout post
 *                                 through the Full Story Entry group.
 * @return {Object[]} The blocks that kind of entry shows.
 */
function forEntryKind<
	T extends { name: string; attributes?: Record< string, unknown > },
>( blocks: T[], isPinned: boolean, isFullStory = false ): T[] {
	if ( blocks.some( isPinnedStack ) ) {
		if ( isPinned ) {
			const groups = pinnedStackGroups( blocks );
			const breakouts = groups.filter( isBreakoutEntry );

			return isFullStory && breakouts.length
				? breakouts
				: groups.filter( ( block ) => ! isBreakoutEntry( block ) );
		}

		blocks = blocks.filter( ( block ) => ! isPinnedStack( block ) );
	}

	if ( isFullStory && blocks.some( isBreakoutEntry ) ) {
		return blocks.filter(
			( block ) => ! isPinnedCard( block ) && ! isRegularEntry( block )
		);
	}

	const kinds = blocks.filter( ( block ) => ! isBreakoutEntry( block ) );

	if ( ! kinds.some( isPinnedCard ) || ! kinds.some( isRegularEntry ) ) {
		return kinds;
	}

	return kinds.filter(
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
 * The template without the separator that closes it or ends the entry group
 * or the Full Story Entry group, as a pinned entry and the last entry render
 * (see Rolling_Coverage_Block::without_closing_separator()).
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
		( isRegularEntry( last ) || isBreakoutEntry( last ) ) &&
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
 * The template without its Post Featured Images, as a Full Story entry
 * whose breakout post has no featured image renders, and without a group,
 * column or columns block they leave empty, mirroring
 * Rolling_Coverage_Block::without_featured_images().
 *
 * @param {Object[]} blocks The template blocks.
 * @return {Object[]} The blocks without featured images.
 */
function withoutFeaturedImage<
	T extends { name: string; [ key: string ]: unknown },
>( blocks: T[] ): T[] {
	return blocks.flatMap( ( block ) => {
		if ( block.name === 'core/post-featured-image' ) {
			return [];
		}

		if ( ! Array.isArray( block.innerBlocks ) ) {
			return [ block ];
		}

		const innerBlocks = withoutFeaturedImage( block.innerBlocks as T[] );

		if (
			EMPTIED_CONTAINERS.includes( block.name ) &&
			block.innerBlocks.length > 0 &&
			innerBlocks.length === 0
		) {
			return [];
		}

		return [ { ...block, innerBlocks } ];
	} );
}

/**
 * The client IDs of the Post Featured Images among blocks, at any depth, and
 * of the group, column or columns blocks they alone fill, which an editable
 * layout hides where its Full Story entry's breakout post has no featured
 * image (see withoutFeaturedImage()).
 *
 * @param {Object[]} blocks The blocks.
 * @return {string[]} Client IDs.
 */
function featuredImageBlockIds(
	blocks: { name: string; [ key: string ]: unknown }[]
): string[] {
	return blocks.flatMap( ( block ) => {
		if ( block.name === 'core/post-featured-image' ) {
			return [ block.clientId as string ];
		}

		const inner = Array.isArray( block.innerBlocks )
			? ( block.innerBlocks as typeof blocks )
			: [];
		const ids = featuredImageBlockIds( inner );
		const innerIds = new Set( ids );
		const isEmptied =
			EMPTIED_CONTAINERS.includes( block.name ) &&
			inner.length > 0 &&
			inner.every( ( child ) =>
				innerIds.has( child.clientId as string )
			);

		return isEmptied ? [ ...ids, block.clientId as string ] : ids;
	} );
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
 * preview would show a placeholder. A title carrying ENTRY_LINK_CLASS stays,
 * for withEntryLinkTitleText() to fill with the entry's opening words.
 *
 * @param {Object[]} blocks The template blocks.
 * @return {Object[]} The blocks without Post Title.
 */
function withoutPostTitle<
	T extends { name: string; [ key: string ]: unknown },
>( blocks: T[] ): T[] {
	return blocks
		.filter(
			( block ) =>
				block.name !== 'core/post-title' ||
				isEntryLinkTitle(
					block as {
						name: string;
						attributes?: Record< string, unknown >;
					}
				)
		)
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
 * The template as an untitled entry renders its titles carrying
 * ENTRY_LINK_CLASS: a heading with the title's classes and settings, its
 * text the entry's opening words as a link, the markup core's Post Title
 * gives Entry_Bindings::untitled_fallback_title(), so the theme styles it
 * as a title. Without opening words, as for a protected entry, the title
 * renders nothing.
 *
 * @param {Object[]} blocks The template blocks.
 * @param {string}   text   The entry's opening words, from the server.
 * @return {Object[]} The blocks with those titles filled in.
 */
function withEntryLinkTitleText<
	T extends { name: string; [ key: string ]: unknown },
>( blocks: T[], text: string ): T[] {
	return blocks.flatMap( ( block ) => {
		const attributes = ( block.attributes ?? {} ) as Record<
			string,
			unknown
		>;

		if ( isEntryLinkTitle( { name: block.name, attributes } ) ) {
			if ( ! text ) {
				return [];
			}

			const {
				isLink,
				linkTarget,
				rel,
				level = 2,
				className,
				...settings
			} = attributes;
			const content = `<a href="#">${ escapeHTML( text ) }</a>`;

			return [
				{
					...block,
					name: level === 0 ? 'core/paragraph' : 'core/heading',
					attributes: {
						...settings,
						...( level === 0 ? {} : { level } ),
						className: `wp-block-post-title ${ className }`,
						content,
					},
				},
			];
		}

		return [
			Array.isArray( block.innerBlocks )
				? {
						...block,
						innerBlocks: withEntryLinkTitleText(
							block.innerBlocks as T[],
							text
						),
					}
				: block,
		];
	} );
}

/**
 * The template without its author names, its avatars, or both, mirroring
 * Rolling_Coverage_Block::without_author_blocks(). A group they leave empty
 * goes, as it would still take a gap in its row. A column holding only an
 * avatar goes when asked, or stays so the entry's text lines up with the
 * others.
 *
 * @param {Object[]} blocks              The template blocks.
 * @param {Object}   drops               What goes.
 * @param {boolean}  drops.name          Whether author names go.
 * @param {boolean}  drops.avatars       Whether avatars go.
 * @param {boolean}  drops.avatarColumns Whether avatar-only columns go.
 * @return {Object[]} The blocks without them.
 */
function withoutAuthorBlocks<
	T extends { name: string; [ key: string ]: unknown },
>(
	blocks: T[],
	drops: { name: boolean; avatars: boolean; avatarColumns: boolean }
): T[] {
	return blocks.flatMap( ( block ): T[] => {
		const innerBlocks = Array.isArray( block.innerBlocks )
			? ( block.innerBlocks as T[] )
			: null;

		if (
			( drops.avatars && block.name === 'core/avatar' ) ||
			( drops.name && block.name === 'core/post-author-name' )
		) {
			return [];
		}

		if (
			drops.avatarColumns &&
			block.name === 'core/column' &&
			innerBlocks?.length === 1 &&
			innerBlocks[ 0 ].name === 'core/avatar'
		) {
			return [];
		}

		if ( ! innerBlocks ) {
			return [ block ];
		}

		const kept = withoutAuthorBlocks( innerBlocks, drops );

		if (
			block.name === 'core/group' &&
			innerBlocks.length > 0 &&
			kept.length === 0
		) {
			return [];
		}

		return [ { ...block, innerBlocks: kept } ];
	} );
}

/**
 * The template without the author's avatar and name, as an entry the Slack
 * bot wrote renders (see Rolling_Coverage_Block::render_entry()).
 * The avatar's column stays, so the entry's text lines up with the others.
 *
 * @param {Object[]} blocks The template blocks.
 * @return {Object[]} The blocks without the byline.
 */
function withoutByline< T extends { name: string; [ key: string ]: unknown } >(
	blocks: T[]
): T[] {
	return withoutAuthorBlocks( blocks, {
		name: true,
		avatars: true,
		avatarColumns: false,
	} );
}

/**
 * The template as the block's Author name and Author avatar settings show
 * it, each on its own, mirroring
 * Rolling_Coverage_Block::with_author_settings(): without the author's name
 * when Author name is on Hide, and without the avatar, with the columns
 * that held only one, when Author avatar is.
 *
 * @param {Object[]} blocks     The template blocks.
 * @param {boolean}  showAuthor The block's Author name setting.
 * @param {boolean}  showAvatar The block's Author avatar setting.
 * @return {Object[]} The blocks as the settings show them.
 */
function withAuthorSettings<
	T extends { name: string; [ key: string ]: unknown },
>( blocks: T[], showAuthor: boolean, showAvatar: boolean ): T[] {
	if ( showAuthor && showAvatar ) {
		return blocks;
	}

	return withoutAuthorBlocks( blocks, {
		name: ! showAuthor,
		avatars: ! showAvatar,
		avatarColumns: ! showAvatar,
	} );
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
	NEW_LABEL_CLASS,
	newLabel,
	isNewLabel,
	withoutNewLabel,
	newLabelBlockIds,
	hiddenNewLabelIds,
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
	entryPreviewPlacement,
	withColumnRule,
	SPLIT_FEED_LAYOUT,
	SPLIT_FEED_STYLE,
	SPLIT_FEED_GAP,
	DIGEST_FEED_STYLE,
	ENTRY_ALLOWED_BLOCKS,
	ALL_UPDATES_CLASS,
	BREAKOUT_ENTRY_CLASS,
	BREAKOUT_BOX_CLASS,
	ACCENT_TINT,
	FOLLOW_BLOCK_NAME,
	CHECK_UPDATES_BLOCK_NAME,
	LOAD_MORE_BLOCK_NAME,
	loadMoreBlock,
	loadMoreTopUp,
	STATUS_BLOCK_NAME,
	UPDATE_TIMER_BLOCK_NAME,
	holdsBlockType,
	blockIdsOfType,
	withoutCheckUpdatesButtons,
	feedTemplate,
	feedGroupOf,
	feedPathOf,
	isFeedGroup,
	feedItems,
	isCoverageNameHeading,
	isAllUpdatesParagraph,
	isCoverageItem,
	layoutParts,
	withoutFollowButtons,
	followBlockIds,
	allUpdatesLink,
	allUpdatesBlockIds,
	withoutAllUpdatesParagraph,
	withAllUpdatesText,
	allUpdatesTextOf,
	emptiedGroupIds,
	withoutPinnedRow,
	fullStoryTakesPinned,
	pinnedRowBlockIds,
	withoutBreakoutLink,
	breakoutBlockIds,
	withLinkedTitle,
	hasPinnedCard,
	isPinnedStack,
	pinnedStackGroups,
	loadMoreInset,
	loadMoreInsetStyle,
	startsAtInlineStart,
	withoutLoadMoreButtons,
	PINNED_STACK_CLASS,
	isPinnedCard,
	isRegularEntry,
	isBreakoutEntry,
	forEntryKind,
	withoutPinnedCard,
	withoutClosingSeparator,
	withoutFeaturedImage,
	featuredImageBlockIds,
	withShapedPinnedCard,
	withCenteredTitleRows,
	withoutPostTitle,
	withEntryLinkTitleText,
	withoutByline,
	withAuthorSettings,
	hiddenAuthorBlockIds,
};
