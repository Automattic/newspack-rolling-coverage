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
 * Default per-entry template: title, date, content, then core buttons for
 * "Read more" and share, bound to the entry and locked against removal.
 */
const ENTRY_TEMPLATE: TemplateItem[] = [
	[ 'core/post-title', { level: 3 } ],
	[ 'core/post-date' ],
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
					className: 'newspack-rolling-coverage-share-link',
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

export { ENTRY_TEMPLATE, ENTRY_ALLOWED_BLOCKS, ENTRY_EDITED_STATES };
