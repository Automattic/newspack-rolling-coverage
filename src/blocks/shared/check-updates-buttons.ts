/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { TemplateItem } from '../rolling-coverage/types';

/**
 * The Check for Updates block's inner blocks: a core button the view script
 * runs a check from. It's a `<button>`, so it never navigates.
 */
const CHECK_UPDATES_BUTTONS_TEMPLATE: TemplateItem = [
	'core/buttons',
	{
		metadata: {
			name: __( 'Check for Updates', 'newspack-rolling-coverage' ),
		},
	},
	[
		[
			'core/button',
			{
				tagName: 'button',
				text: __( 'Check for Updates', 'newspack-rolling-coverage' ),
				metadata: {
					name: __(
						'Check for Updates',
						'newspack-rolling-coverage'
					),
				},
			},
		],
	],
];

export { CHECK_UPDATES_BUTTONS_TEMPLATE };
