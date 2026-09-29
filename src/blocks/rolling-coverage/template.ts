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
		style: { spacing: { blockGap: 'var:preset|spacing|20' } },
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
 * Default per-entry template: the pinned row, date and title stacked with
 * the share button opposite, content, "Read more" bound to the entry and
 * locked against removal, then a separator.
 */
const ENTRY_TEMPLATE: TemplateItem[] = [
	[
		'core/group',
		{
			layout: {
				type: 'flex',
				flexWrap: 'nowrap',
				justifyContent: 'space-between',
				verticalAlignment: 'center',
			},
			style: { spacing: { blockGap: 'var:preset|spacing|30' } },
			metadata: { name: __( 'Header', 'newspack-rolling-coverage' ) },
		},
		[
			[
				'core/group',
				{
					layout: { type: 'flex', orientation: 'vertical' },
					style: { spacing: { blockGap: 'var:preset|spacing|20' } },
					metadata: {
						name: __( 'Meta', 'newspack-rolling-coverage' ),
					},
				},
				[
					PINNED_ROW,
					[
						'core/post-date',
						{ ...POST_DATE_ATTRIBUTES, format: 'human-diff' },
					],
					[ 'core/post-title', { level: 4 } ],
				],
			],
			SHARE_BUTTONS,
		],
	],
	[
		'core/post-content',
		{
			style: {
				spacing: {
					padding: { top: '0', right: '0', bottom: '0', left: '0' },
					margin: { bottom: CONTENT_GAP },
				},
			},
		},
	],
	[
		'core/buttons',
		{
			lock: LOCKED,
			metadata: { name: __( 'Read more', 'newspack-rolling-coverage' ) },
		},
		[
			[
				'core/button',
				{
					lock: LOCKED,
					text: __( 'Read more', 'newspack-rolling-coverage' ),
					metadata: {
						name: __( 'Read more', 'newspack-rolling-coverage' ),
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
	[
		'core/separator',
		{
			className: 'is-style-wide',
			style: {
				spacing: {
					margin: {
						top: 'var:preset|spacing|50',
						bottom: 'var:preset|spacing|50',
					},
				},
			},
		},
	],
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
 * breakout post renders. A buttons block left empty goes too, as
 * Rolling_Coverage_Block::drop_empty_entry_buttons() does on the front end.
 *
 * @param {Object[]} blocks The template blocks.
 * @return {Object[]} The blocks an entry without a breakout shows.
 */
function withoutBreakoutLink<
	T extends { name: string; [ key: string ]: unknown },
>( blocks: T[] ): T[] {
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

		const innerBlocks = withoutBreakoutLink( block.innerBlocks as T[] );

		if ( block.name === 'core/buttons' && ! innerBlocks.length ) {
			return [];
		}

		return [ { ...block, innerBlocks } ];
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
		label: __( 'Archived Coverage', 'newspack-rolling-coverage' ),
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
	isFollowButtons,
	withoutPinnedRow,
	withoutBreakoutLink,
	withLinkedTitle,
};
