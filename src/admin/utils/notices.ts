/**
 * WordPress dependencies
 */
import { dispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Dispatches a success snackbar notice.
 *
 * @param {string} message Notice message.
 */
function notifySuccess( message: string ) {
	dispatch( noticesStore ).createSuccessNotice( message, {
		type: 'snackbar',
	} );
}

/**
 * Dispatches an error snackbar notice. Uses `explicitDismiss` so the notice
 * stays on screen until the user dismisses it, rather than auto-hiding after
 * the default timeout.
 *
 * @param {string} message Notice message.
 */
function notifyError( message: string ) {
	dispatch( noticesStore ).createErrorNotice( message, {
		type: 'snackbar',
		explicitDismiss: true,
	} );
}

/**
 * Returns the singular or plural translated message depending on the item count.
 * Centralises the `count > 1 ? plural : singular` pattern used by bulk actions.
 *
 * @param {number} count    Number of items the message refers to.
 * @param {string} singular Translated singular message.
 * @param {string} plural   Translated plural message.
 * @return {string} The appropriate message for the count.
 */
function pluralize( count: number, singular: string, plural: string ): string {
	return count > 1 ? plural : singular;
}

export { notifySuccess, notifyError, pluralize };
