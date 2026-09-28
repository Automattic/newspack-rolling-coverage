/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { ENTRY_BINDINGS_SOURCE } from '../shared/entry-bindings';
import type { TemplateItem, EntryEditedState } from './types';

const LOCKED = { remove: true, move: false };

/**
 * Default per-entry template: date and title stacked, content, then core buttons for
 * "Read more" and share, bound to the entry and locked against removal.
 */
const ENTRY_TEMPLATE: TemplateItem[] = [
	[
		'core/group',
		{
			layout: { type: 'flex', orientation: 'vertical' },
			style: { spacing: { blockGap: '0' } },
		},
		[
			[ 'core/post-date', { format: 'human-diff' } ],
			[ 'core/post-title', { level: 3 } ],
		],
	],
	[ 'core/post-content' ],
	[
		'core/buttons',
		{ lock: LOCKED },
		[
			[
				'core/button',
				{
					lock: LOCKED,
					metadata: {
						name: __( 'Read more', 'newspack-rolling-coverage' ),
						bindings: {
							url: {
								source: ENTRY_BINDINGS_SOURCE,
								args: { key: 'breakoutUrl' },
							},
							text: {
								source: ENTRY_BINDINGS_SOURCE,
								args: { key: 'breakoutLabel' },
							},
						},
					},
				},
			],
			[
				'core/button',
				{
					lock: LOCKED,
					text: __( 'Share', 'newspack-rolling-coverage' ),
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
};
