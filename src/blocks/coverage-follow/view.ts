/**
 * Internal dependencies
 */
import './style.scss';
import type { OneSignalApi } from './types';

// Matches the button markup rendered server-side by Coverage_Follow_Block::render_block().
const FOLLOW_BUTTON_SELECTOR = '.newspack-rolling-coverage-follow';

/**
 * Updates a button's label and aria-pressed for a follow state.
 *
 * @param {HTMLButtonElement} button   Button to update.
 * @param {boolean}           followed Whether its tag is followed.
 */
function updateButtonState(
	button: HTMLButtonElement,
	followed: boolean
): void {
	const { labelFollow = 'Follow', labelFollowing = 'Following' } =
		button.dataset;
	button.textContent = followed ? labelFollowing : labelFollow;
	button.setAttribute( 'aria-pressed', followed ? 'true' : 'false' );
}

/**
 * Shows (or clears) a live-region message after a follow button.
 *
 * @param {HTMLButtonElement} button Follow button element.
 * @param {string}            text   Message text; an empty string hides it.
 */
function setStatusMessage( button: HTMLButtonElement, text: string ): void {
	const sibling = button.nextElementSibling;
	let message =
		sibling instanceof HTMLElement &&
		sibling.classList.contains(
			'newspack-rolling-coverage-follow__message'
		)
			? sibling
			: null;

	if ( ! message ) {
		message = document.createElement( 'p' );
		message.className =
			'newspack-rolling-coverage-follow__message wp-block-paragraph';
		message.setAttribute( 'role', 'status' );
		message.setAttribute( 'aria-live', 'polite' );
		button.insertAdjacentElement( 'afterend', message );
	}

	message.textContent = text;
	message.hidden = '' === text;
}

/**
 * Resolves the reader's notification permission via permissionChange,
 * prompting if needed.
 *
 * @param {OneSignalApi} OneSignal OneSignal SDK instance.
 * @return {Promise<boolean>} Whether permission is granted.
 */
function requestNotificationPermission(
	OneSignal: OneSignalApi
): Promise< boolean > {
	if ( OneSignal.Notifications.permission ) {
		return Promise.resolve( true );
	}

	// Once denied, the browser won't show the native prompt again, so
	// requestPermission() would be a no-op and permissionChange would never fire.
	if (
		typeof Notification !== 'undefined' &&
		Notification.permission === 'denied'
	) {
		return Promise.resolve( false );
	}

	return new Promise( ( resolve ) => {
		const onChange = ( permission: boolean ) => {
			OneSignal.Notifications.removeEventListener(
				'permissionChange',
				onChange
			);
			resolve( permission );
		};

		OneSignal.Notifications.addEventListener(
			'permissionChange',
			onChange
		);
		OneSignal.Notifications.requestPermission();
	} );
}

/**
 * Syncs every follow button's initial state from OneSignal's own synced
 * tags — the real source of truth, rather than a locally cached guess.
 *
 * Skips any button mid-click (disabled): its own pending toggle already owns
 * repainting it, and this tag data predates that click.
 *
 * @param {OneSignalApi}        OneSignal OneSignal SDK instance.
 * @param {HTMLButtonElement[]} buttons   Follow buttons to paint.
 */
function syncFollowButtons(
	OneSignal: OneSignalApi,
	buttons: HTMLButtonElement[]
): void {
	try {
		const tags = OneSignal.User.getTags() || {};
		buttons.forEach( ( button ) => {
			const tag = button.dataset.tag;
			if ( tag && ! button.disabled ) {
				updateButtonState( button, '1' === tags[ tag ] );
			}
		} );
	} catch ( error ) {
		console.error( error ); // eslint-disable-line no-console
	}
}

/**
 * Wires a follow button's click handler: toggle the OneSignal tag, with an
 * optimistic UI update reverted on failure.
 *
 * @param {HTMLButtonElement} button Follow button element.
 */
function initFollowButton( button: HTMLButtonElement ): void {
	const tag = button.dataset.tag;

	if ( ! tag ) {
		return;
	}

	button.addEventListener( 'click', () => {
		const willFollow = button.getAttribute( 'aria-pressed' ) !== 'true';

		updateButtonState( button, willFollow );
		setStatusMessage( button, '' );
		button.disabled = true;

		const revert = ( message: string ) => {
			updateButtonState( button, ! willFollow );
			setStatusMessage( button, message );
			button.disabled = false;
		};

		window.OneSignalDeferred = window.OneSignalDeferred || [];
		window.OneSignalDeferred.push( async ( OneSignal ) => {
			try {
				if ( ! willFollow ) {
					OneSignal.User.removeTag( tag );
				} else {
					if ( ! OneSignal.Notifications.isPushSupported() ) {
						revert( button.dataset.blockedMessage || '' );
						return;
					}

					const granted =
						await requestNotificationPermission( OneSignal );

					if ( ! granted ) {
						revert( button.dataset.blockedMessage || '' );
						return;
					}

					OneSignal.User.addTag( tag, '1' );
				}

				button.disabled = false;
			} catch {
				revert( button.dataset.errorMessage || '' );
			}
		} );
	} );
}

const followButtons = Array.from(
	document.querySelectorAll< HTMLButtonElement >( FOLLOW_BUTTON_SELECTOR )
);

followButtons.forEach( initFollowButton );

if ( followButtons.length ) {
	window.OneSignalDeferred = window.OneSignalDeferred || [];
	window.OneSignalDeferred.push( ( OneSignal ) =>
		syncFollowButtons( OneSignal, followButtons )
	);
}
