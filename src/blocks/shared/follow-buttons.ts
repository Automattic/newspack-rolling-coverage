/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { TemplateItem } from '../rolling-coverage/types';
import { ENTRY_BINDINGS_SOURCE } from './entry-bindings';

/**
 * The Follow Coverage block's inner blocks: a core button, bound to the
 * coverage's notification tag. It's a `<button>`, so the bound value never
 * shows as a link; it only carries the tag to the follow script.
 */
const FOLLOW_BUTTONS_TEMPLATE: TemplateItem = [
	'core/buttons',
	{
		metadata: { name: __( 'Follow', 'newspack-rolling-coverage' ) },
	},
	[
		[
			'core/button',
			{
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

export { FOLLOW_BUTTONS_TEMPLATE };
