/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { dispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import type { SyncNotice, SyncNoticeEntry } from '../types';

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
 * Truncates a title for snackbar display, returning "(no title)" when empty.
 *
 * @param {string} title The entry title.
 * @return {string} Truncated title or placeholder.
 */
function formatEntryTitle( title: string ): string {
	const clean = title.trim();
	if ( ! clean ) {
		return __( '(no title)', 'newspack-rolling-coverage' );
	}
	return clean.length > 40 ? clean.slice( 0, 40 ) + '…' : clean;
}

/**
 * Builds a snackbar message for a single entry based on the notice type.
 *
 * @param {'added'|'updated'|'removed'} type  The notice type.
 * @param {SyncNoticeEntry}             entry The entry details.
 * @return {string} The translated snackbar message.
 */
function getEntryNoticeMessage(
	type: SyncNotice[ 'type' ],
	entry: SyncNoticeEntry
): string {
	const title = formatEntryTitle( entry.title );

	switch ( type ) {
		case 'added':
			return sprintf(
				/* translators: %s: entry title. */
				__( 'New entry: %s', 'newspack-rolling-coverage' ),
				title
			);
		case 'updated':
			return sprintf(
				/* translators: %s: entry title. */
				__( 'Updated: %s', 'newspack-rolling-coverage' ),
				title
			);
		case 'removed':
			return __( '1 entry removed', 'newspack-rolling-coverage' );
	}
	return '';
}

export { notifySuccess, notifyError, formatEntryTitle, getEntryNoticeMessage };
