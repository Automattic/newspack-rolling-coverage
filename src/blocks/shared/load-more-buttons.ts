/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { TemplateItem } from '../rolling-coverage/types';

/**
 * The Load More block's inner blocks: a core button the view script loads
 * older entries from. It's a `<button>`, so it never navigates.
 *
 * @param {Object} buttons Settings for the Buttons block, such as its layout
 *                         or margin.
 * @return {Array} The template item.
 */
function loadMoreButtonsTemplate(
	buttons: Record< string, unknown > = {}
): TemplateItem {
	return [
		'core/buttons',
		{
			...buttons,
			metadata: {
				name: __( 'Load More', 'newspack-rolling-coverage' ),
			},
		},
		[
			[
				'core/button',
				{
					tagName: 'button',
					/* translators: Button that loads older entries at the end of a coverage's feed. */
					text: __( 'Load more', 'newspack-rolling-coverage' ),
					metadata: {
						name: __( 'Load More', 'newspack-rolling-coverage' ),
					},
				},
			],
		],
	];
}

const LOAD_MORE_BUTTONS_TEMPLATE = loadMoreButtonsTemplate();

export { LOAD_MORE_BUTTONS_TEMPLATE, loadMoreButtonsTemplate };
