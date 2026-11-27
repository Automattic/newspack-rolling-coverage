/**
 * Internal dependencies
 */
import { getSnackbar } from '../shared/snackbar';
import type { OneSignalApi } from './types';
import './style.scss';

const FOLLOW_BUTTON_SELECTOR = 'button[data-rc-follow]';

// How long to wait for the OneSignal SDK before treating a click as failed.
const SDK_WAIT_TIMEOUT_MS = 10000;

// The spinner shows at least this long, so a quick answer doesn't flicker.
const MIN_PENDING_MS = 1000;

const ANNOUNCER_ID = 'newspack-rolling-coverage-follow-announcer';

const FRESH_ATTRIBUTE = 'data-rc-fresh';

// After the prompt closes, how long permissionChange gets to report a grant
// before the permission is read directly.
const PERMISSION_SETTLE_MS = 500;

/**
 * Shows a button's follow state. Its labels are all in the markup, stacked by
 * the stylesheet, which shows the one for this state.
 *
 * @param {HTMLButtonElement} button   Button to update.
 * @param {boolean}           followed Whether its tag is followed.
 */
function updateButtonState(
	button: HTMLButtonElement,
	followed: boolean
): void {
	button.setAttribute( 'aria-pressed', followed ? 'true' : 'false' );
}

/**
 * Shows the spinner on a button while a toggle is pending, and keeps further
 * clicks out. It stays focusable, unlike a disabled button, so keyboard focus
 * isn't lost.
 *
 * @param {HTMLButtonElement} button Button to update.
 * @param {boolean}           busy   Whether a toggle is pending.
 */
function setBusy( button: HTMLButtonElement, busy: boolean ): void {
	if ( busy ) {
		button.setAttribute( 'aria-busy', 'true' );
		button.setAttribute( 'aria-disabled', 'true' );
	} else {
		button.removeAttribute( 'aria-busy' );
		button.removeAttribute( 'aria-disabled' );
	}
}

/**
 * Whether a button has a toggle pending.
 *
 * @param {HTMLButtonElement} button Follow button element.
 * @return {boolean} Whether it's busy.
 */
function isBusy( button: HTMLButtonElement ): boolean {
	return button.getAttribute( 'aria-busy' ) === 'true';
}

/**
 * Announces a message to screen readers without showing it, for when Newspack
 * UI's snackbar isn't on the page. The button's own label already shows the
 * new state.
 *
 * @param {string} text Message text.
 */
function announce( text: string ): void {
	getAnnouncer().textContent = text;
}

/**
 * Confirms a toggle in Newspack UI's snackbar, or announces it without
 * Newspack UI. Screen readers always hear which liveblog, e.g. "You'll get
 * alerts for “Kettle River flood”." On screen, a page with one liveblog shows
 * only "Alerts on." or "Alerts off.", since the reader is on that liveblog; a
 * page with buttons for several shows the full sentence. Newspack UI reads a
 * notice out as it opens it, so the notice opens with the full sentence and
 * then shows the short one, keeping the sentence for anyone who reads it later.
 *
 * @param {HTMLButtonElement} button   Follow button the reader pressed.
 * @param {boolean}           followed Whether alerts are now on.
 */
function confirmToggle( button: HTMLButtonElement, followed: boolean ): void {
	const { onNotice, offNotice, onMessage, offMessage } = button.dataset;
	const short = ( followed ? onNotice : offNotice ) || '';
	const full = ( followed ? onMessage : offMessage ) || short;
	const liveblogs = new Set(
		Array.from(
			document.querySelectorAll< HTMLButtonElement >(
				FOLLOW_BUTTON_SELECTOR
			)
		).map( ( candidate ) => candidate.dataset.tag )
	);
	const visible = liveblogs.size > 1 || ! short ? full : short;
	const showSnackbar = getSnackbar();

	if ( ! showSnackbar ) {
		announce( full );
		return;
	}

	const content = showSnackbar( full )?.querySelector(
		'.newspack-ui__snackbar__content'
	);

	if ( content && visible !== full ) {
		const shown = document.createElement( 'span' );
		const spoken = document.createElement( 'span' );

		shown.textContent = visible;
		shown.setAttribute( 'aria-hidden', 'true' );
		spoken.textContent = full;
		spoken.className = 'screen-reader-text';
		content.replaceChildren( shown, spoken );
	}
}

/**
 * The visually hidden live region announce() writes to. Screen readers only
 * announce changes to a region already on the page, so a toggle adds it as it
 * starts.
 *
 * @return {HTMLElement} The region.
 */
function getAnnouncer(): HTMLElement {
	let region = document.getElementById( ANNOUNCER_ID );

	if ( ! region ) {
		region = document.createElement( 'div' );
		region.id = ANNOUNCER_ID;
		region.setAttribute( 'role', 'status' );
		region.setAttribute( 'aria-live', 'polite' );
		Object.assign( region.style, {
			position: 'absolute',
			width: '1px',
			height: '1px',
			margin: '-1px',
			padding: '0',
			overflow: 'hidden',
			clip: 'rect(0, 0, 0, 0)',
			whiteSpace: 'nowrap',
			border: '0',
		} );
		document.body.appendChild( region );
	}

	return region;
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
 * Shows a failed follow's message in Newspack UI's error snackbar, or, without
 * Newspack UI, in a live region after the button.
 *
 * @param {HTMLButtonElement} button Follow button element.
 * @param {string}            text   Message text.
 */
function showError( button: HTMLButtonElement, text: string ): void {
	const showSnackbar = getSnackbar();

	if ( showSnackbar && '' !== text ) {
		showSnackbar( text, 'error' );
		return;
	}

	setStatusMessage( button, text );
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
		let settled = false;
		const settle = ( permission: boolean ) => {
			if ( settled ) {
				return;
			}
			settled = true;
			OneSignal.Notifications.removeEventListener(
				'permissionChange',
				onChange
			);
			resolve( permission );
		};
		const onChange = ( permission: boolean ) => settle( permission );

		OneSignal.Notifications.addEventListener(
			'permissionChange',
			onChange
		);
		// A prompt the reader closes or dismisses never fires permissionChange,
		// which would leave the spinner on for good.
		Promise.resolve( OneSignal.Notifications.requestPermission() )
			.catch( () => undefined )
			.then( () =>
				window.setTimeout(
					() =>
						settle(
							OneSignal.Notifications.permission ||
								( typeof Notification !== 'undefined' &&
									Notification.permission === 'granted' )
						),
					PERMISSION_SETTLE_MS
				)
			);
	} );
}

/**
 * Syncs every follow button's initial state from OneSignal's own synced
 * tags — the real source of truth, rather than a locally cached guess.
 *
 * Skips any button mid-click (busy): its own pending toggle already owns
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
			if ( tag && ! isBusy( button ) ) {
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
 * Toggles a follow button's OneSignal tag. Every button sharing the tag shows
 * the spinner until the browser and OneSignal answer, then the new state, or
 * the old one and an error on failure.
 *
 * @param {HTMLButtonElement} button Follow button element.
 * @param {string}            tag    The button's OneSignal tag.
 */
function toggleFollow( button: HTMLButtonElement, tag: string ): void {
	const willFollow = button.getAttribute( 'aria-pressed' ) !== 'true';
	const siblings = buttonsForTag( tag );
	const startedAt = Date.now();

	siblings.forEach( ( sibling ) => {
		setBusy( sibling, true );
		setStatusMessage( sibling, '' );
	} );

	if ( ! getSnackbar() ) {
		getAnnouncer();
	}

	const finish = ( followed: boolean, onShown: () => void ) => {
		const show = () => {
			// Includes buttons that arrived while the toggle was pending.
			new Set( [ ...siblings, ...buttonsForTag( tag ) ] ).forEach(
				( sibling ) => {
					updateButtonState( sibling, followed );
					setBusy( sibling, false );
				}
			);
			onShown();
		};
		const wait = MIN_PENDING_MS - ( Date.now() - startedAt );

		if ( wait > 0 ) {
			window.setTimeout( show, wait );
		} else {
			show();
		}
	};

	const succeed = () =>
		finish( willFollow, () => {
			// Still under the pointer or focused, the button would read "Stop
			// alerts" at once; it reads "Alerts on" until the reader moves on.
			if ( willFollow && button.matches( ':hover, :focus-visible' ) ) {
				button.setAttribute( FRESH_ATTRIBUTE, '' );
			}
			confirmToggle( button, willFollow );
		} );

	const revert = ( message: string ) =>
		finish( ! willFollow, () => showError( button, message ) );

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
					// A closed or ignored prompt isn't a block, so it needs no message.
					const denied =
						typeof Notification !== 'undefined' &&
						Notification.permission === 'denied';
					revert( denied ? button.dataset.blockedMessage || '' : '' );
					return;
				}

				OneSignal.User.addTag( tag, '1' );
			}

			succeed();
		} catch {
			revert( button.dataset.errorMessage || '' );
		}
	} );
}

/**
 * Lets a just-switched-on button show "Stop alerts" again on hover and
 * focus, once the pointer or focus has left it.
 *
 * @param {Event} event Pointer or focus event.
 */
function clearFresh( event: Event ): void {
	if (
		event.target instanceof HTMLButtonElement &&
		event.target.matches( FOLLOW_BUTTON_SELECTOR )
	) {
		event.target.removeAttribute( FRESH_ATTRIBUTE );
	}
}

document.addEventListener( 'pointerout', ( event ) => {
	const left =
		event.target instanceof Element
			? event.target.closest< HTMLButtonElement >(
					FOLLOW_BUTTON_SELECTOR
				)
			: null;

	if (
		left &&
		! (
			event.relatedTarget instanceof Node &&
			left.contains( event.relatedTarget )
		)
	) {
		left.removeAttribute( FRESH_ATTRIBUTE );
	}
} );
document.addEventListener( 'focusout', clearFresh );

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

	if ( button && tag && ! isBusy( button ) ) {
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
