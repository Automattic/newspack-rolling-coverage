/**
 * External dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { PublishMode } from '../types';

/**
 * The publish modes a Slack channel can use, in the order the dropdowns
 * list them.
 *
 * @return {{ value: PublishMode, label: string }[]} Options.
 */
function getPublishModeOptions(): { value: PublishMode; label: string }[] {
	return [
		{
			value: 'publish',
			label: __( 'Auto-publish', 'newspack-rolling-coverage' ),
		},
		{ value: 'draft', label: __( 'Draft', 'newspack-rolling-coverage' ) },
		{
			value: 'reaction',
			label: __( 'Emoji trigger', 'newspack-rolling-coverage' ),
		},
	];
}

/**
 * What a publish mode does with a channel's messages.
 *
 * @param {PublishMode} mode Publish mode.
 * @return {string} Help text.
 */
function getPublishModeHelp( mode: PublishMode ): string {
	switch ( mode ) {
		case 'publish':
			return __(
				"Publishes messages as soon as they're posted in Slack.",
				'newspack-rolling-coverage'
			);
		case 'reaction':
			return __(
				'Adds a message only when someone reacts to it: the publish emoji publishes it, the draft emoji saves it as a draft. Set both emoji under Settings on the Slack Connection page.',
				'newspack-rolling-coverage'
			);
		default:
			return __(
				'Saves messages as drafts for an editor or administrator to publish.',
				'newspack-rolling-coverage'
			);
	}
}

export { getPublishModeOptions, getPublishModeHelp };
