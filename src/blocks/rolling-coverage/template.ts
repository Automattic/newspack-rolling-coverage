/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { TemplateItem, EntryEditedState } from './types';

/**
 * Default per-entry template: title, date, content, and a "Read more" link
 * locked against removal and reordering in the editor UI.
 */
const ENTRY_TEMPLATE: TemplateItem[] = [
	[ 'core/post-title', { level: 3 } ],
	[ 'core/post-date' ],
	[ 'core/post-content' ],
	[
		'newspack-rolling-coverage/breakout-post-link',
		{ lock: { remove: true, move: false } },
	],
	[
		'newspack-rolling-coverage/share',
		{ lock: { remove: true, move: false } },
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
];

export { ENTRY_TEMPLATE, ENTRY_ALLOWED_BLOCKS, ENTRY_EDITED_STATES };
