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
				"Publishes each message as soon as it's posted in Slack.",
				'newspack-rolling-coverage'
			);
		case 'reaction':
			return __(
				"Adds a message only when someone reacts to it. The publish emoji publishes it, and the draft emoji saves a draft. Change both emoji on the Slack Connection page's Settings tab.",
				'newspack-rolling-coverage'
			);
		default:
			return __(
				'Saves each message as a draft for an editor or administrator to publish.',
				'newspack-rolling-coverage'
			);
	}
}

/**
 * A one-line summary of a publish mode, for the menu in the channels table.
 * The full help, with the emoji settings, stays in the connection drawer.
 *
 * @param {PublishMode} mode Publish mode.
 * @return {string} Summary.
 */
function getPublishModeDescription( mode: PublishMode ): string {
	switch ( mode ) {
		case 'publish':
			return __(
				"Each message publishes as soon as it's posted.",
				'newspack-rolling-coverage'
			);
		case 'reaction':
			return __(
				"Nothing's added until someone reacts with an emoji.",
				'newspack-rolling-coverage'
			);
		default:
			return __(
				'Messages arrive as drafts for an editor to review.',
				'newspack-rolling-coverage'
			);
	}
}

export { getPublishModeOptions, getPublishModeHelp, getPublishModeDescription };
