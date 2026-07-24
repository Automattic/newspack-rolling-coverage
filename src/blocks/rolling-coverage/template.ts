/**
 * Internal dependencies
 */
import type { TemplateItem } from './types';

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

export { ENTRY_TEMPLATE, ENTRY_ALLOWED_BLOCKS };
