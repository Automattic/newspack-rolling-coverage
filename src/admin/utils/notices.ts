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

export { notifySuccess, notifyError };
