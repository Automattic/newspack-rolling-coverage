/**
 * Internal dependencies
 */
import type { OneSignalApi } from './types';

const FOLLOW_BUTTON_SELECTOR = 'button[data-rc-follow]';

// How long to wait for the OneSignal SDK before treating a click as failed.
const SDK_WAIT_TIMEOUT_MS = 10000;

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
	// Until its state is first shown, the button reads the label set in the
	// editor, so that's the "Follow" text.
	if ( ! button.dataset.labelFollow ) {
		button.dataset.labelFollow = button.textContent?.trim() || undefined;
	}

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
		if ( '' === text ) {
			return;
		}

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
 * Every follow button on the page for a tag, so buttons sharing one stay in
 * sync.
 *
 * @param {string} tag OneSignal tag.
 * @return {HTMLButtonElement[]} Matching buttons.
 */
function buttonsForTag( tag: string ): HTMLButtonElement[] {
	return Array.from(
		document.querySelectorAll< HTMLButtonElement >( FOLLOW_BUTTON_SELECTOR )
	).filter( ( candidate ) => candidate.dataset.tag === tag );
}

/**
 * Paints follow buttons from OneSignal's tags once the SDK is ready.
 *
 * @param {HTMLButtonElement[]} buttons Follow buttons to paint.
 */
function syncWhenReady( buttons: HTMLButtonElement[] ): void {
	window.OneSignalDeferred = window.OneSignalDeferred || [];
	window.OneSignalDeferred.push( ( OneSignal ) =>
		syncFollowButtons( OneSignal, buttons )
	);
}

/**
 * The follow buttons in a node, the node itself included.
 *
 * @param {Node} node Node to look in.
 * @return {HTMLButtonElement[]} Matching buttons.
 */
function followButtonsIn( node: Node ): HTMLButtonElement[] {
	if ( ! ( node instanceof HTMLElement ) ) {
		return [];
	}

	const buttons = Array.from(
		node.querySelectorAll< HTMLButtonElement >( FOLLOW_BUTTON_SELECTOR )
	);

	return node instanceof HTMLButtonElement &&
		node.matches( FOLLOW_BUTTON_SELECTOR )
		? [ node, ...buttons ]
		: buttons;
}

/**
 * Toggles a follow button's OneSignal tag, with an optimistic UI update
 * reverted on failure.
 *
 * @param {HTMLButtonElement} button Follow button element.
 * @param {string}            tag    The button's OneSignal tag.
 */
function toggleFollow( button: HTMLButtonElement, tag: string ): void {
	const willFollow = button.getAttribute( 'aria-pressed' ) !== 'true';

	const siblings = buttonsForTag( tag );

	siblings.forEach( ( sibling ) => {
		updateButtonState( sibling, willFollow );
		sibling.disabled = true;
	} );
	siblings.forEach( ( sibling ) => setStatusMessage( sibling, '' ) );

	const settle = () =>
		siblings.forEach( ( sibling ) => {
			sibling.disabled = false;
		} );

	const revert = ( message: string ) => {
		siblings.forEach( ( sibling ) =>
			updateButtonState( sibling, ! willFollow )
		);
		setStatusMessage( button, message );
		settle();
	};

	let hasResolvedSdkWait = false;
	const timeoutId = window.setTimeout( () => {
		hasResolvedSdkWait = true;
		revert( button.dataset.errorMessage || '' );
	}, SDK_WAIT_TIMEOUT_MS );

	window.OneSignalDeferred = window.OneSignalDeferred || [];
	window.OneSignalDeferred.push( async ( OneSignal ) => {
		// Already reverted by the timeout; ignore a late-loading SDK.
		if ( hasResolvedSdkWait ) {
			return;
		}

		hasResolvedSdkWait = true;
		window.clearTimeout( timeoutId );

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

			settle();
		} catch {
			revert( button.dataset.errorMessage || '' );
		}
	} );
}

// Handled from the document, so buttons that reach the page after load work
// too, and a button that moves within the page never gets a second handler.
document.addEventListener( 'click', ( event ) => {
	const button =
		event.target instanceof Element
			? event.target.closest< HTMLButtonElement >(
					FOLLOW_BUTTON_SELECTOR
				)
			: null;
	const tag = button?.dataset.tag;

	if ( button && tag ) {
		toggleFollow( button, tag );
	}
} );

const followButtons = Array.from(
	document.querySelectorAll< HTMLButtonElement >( FOLLOW_BUTTON_SELECTOR )
);

if ( followButtons.length ) {
	syncWhenReady( followButtons );
}

// Buttons can sit in an entry's content, so they also reach the page after
// load, in entries a poll, load more or the jump to the live feed brings in.
// Each shows its tag's state as it arrives.
new MutationObserver( ( records ) => {
	const arrived = records.flatMap( ( { addedNodes } ) =>
		Array.from( addedNodes ).flatMap( followButtonsIn )
	);

	if ( arrived.length ) {
		syncWhenReady( arrived );
	}
} ).observe( document.body, { childList: true, subtree: true } );
