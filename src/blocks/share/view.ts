/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

const BLOCK_SELECTOR = '.wp-block-newspack-rolling-coverage-rolling-coverage';
// The core button link marked by Entry_Bindings, and the legacy Share block.
const SHARE_BUTTON_SELECTOR =
	'[data-rc-share], button.newspack-rolling-coverage-share-link';
const STATUS_SELECTOR = '.newspack-rolling-coverage-status';
const COPIED_STATE_MS = 2000;

// A share button's events reach every feed holding it. Only the closest feed
// with listeners handles each one, so the link is shared or copied once. A
// feed added after page load gets no listeners, so the closest feed around it
// that was there at load handles its buttons.
const handledEvents = new WeakSet< Event >();

type NewspackUI = {
	notices?: { createNotice?: ( message: string ) => void };
};

/**
 * Shows a Newspack UI snackbar. Newspack UI adds its snackbar to the first
 * `.newspack-ui` element, which can be a hidden modal, so it gets its own
 * container first.
 *
 * @param {Function} createNotice Newspack UI's createNotice().
 * @param {string}   message      Message to show.
 */
function showSnackbar(
	createNotice: ( message: string ) => void,
	message: string
): void {
	if ( ! document.querySelector( '.newspack-ui__snackbar' ) ) {
		const wrapper = document.createElement( 'div' );
		const snackbar = document.createElement( 'div' );

		wrapper.className = 'newspack-ui';
		snackbar.className = 'newspack-ui__snackbar';
		wrapper.appendChild( snackbar );
		document.body.appendChild( wrapper );
	}

	createNotice( message );
}

/**
 * Copies text through a hidden selection, for browsers that refuse the
 * Clipboard API, e.g. on non-HTTPS pages or without clipboard permission.
 *
 * @param {string}      text   Text to copy.
 * @param {HTMLElement} button Button to return focus to.
 * @return {boolean} Whether the text was copied.
 */
function copyWithSelection( text: string, button: HTMLElement ): boolean {
	const textarea = document.createElement( 'textarea' );

	textarea.value = text;
	textarea.setAttribute( 'readonly', '' );
	textarea.style.position = 'fixed';
	textarea.style.top = '0';
	textarea.style.opacity = '0';
	// Stops iOS zooming in when the field takes focus.
	textarea.style.fontSize = '12pt';
	document.body.appendChild( textarea );
	textarea.select();
	// iOS Safari ignores select() on its own.
	textarea.setSelectionRange( 0, text.length );

	let copied = false;
	try {
		copied = document.execCommand( 'copy' );
	} catch {
		copied = false;
	}

	textarea.remove();
	button.focus();

	return copied;
}

/**
 * Copies text to the clipboard.
 *
 * @param {string}      text   Text to copy.
 * @param {HTMLElement} button Button that asked for the copy.
 * @return {Promise<boolean>} Whether the text was copied.
 */
async function copyText(
	text: string,
	button: HTMLElement
): Promise< boolean > {
	try {
		await navigator.clipboard.writeText( text );
		return true;
	} catch {
		return copyWithSelection( text, button );
	}
}

/**
 * The first element in a feed that matches a selector, leaving out those of a
 * feed nested in one of its entries, which repeats the same classes. The
 * feed's own view script follows the same rule (ownElement() there).
 *
 * @param {HTMLElement} feed     The feed's outer wrapper element.
 * @param {string}      selector Selector to match.
 * @param {HTMLElement} [within] Part of the feed to look in; all of it by default.
 * @return {HTMLElement | null} The element, or null if the feed has none of its own.
 */
function ownElement(
	feed: HTMLElement,
	selector: string,
	within: HTMLElement = feed
): HTMLElement | null {
	return (
		Array.from( within.querySelectorAll< HTMLElement >( selector ) ).find(
			( element ) => element.closest( BLOCK_SELECTOR ) === feed
		) ?? null
	);
}

/**
 * Sets up share-button click handling for a single rolling-coverage
 * block instance. Uses event delegation on the container so buttons
 * injected by polling/pagination are handled without re-binding.
 *
 * @param {HTMLElement} root The block's outer wrapper element.
 */
function initBlock( root: HTMLElement ): void {
	if ( root.dataset.rcShareInitialized === '1' ) {
		return;
	}
	root.dataset.rcShareInitialized = '1';

	/**
	 * Handle click on a share button — opens the device's share sheet where
	 * there is one, otherwise copies the deep-link URL to the clipboard.
	 *
	 * @param {HTMLElement} button The clicked share button or link.
	 * @return {Promise<void>} Resolves when the share or copy attempt completes.
	 */
	async function handleShareClick( button: HTMLElement ): Promise< void > {
		const url = button.dataset.shareUrl || button.getAttribute( 'href' );
		if ( ! url ) {
			return;
		}

		// Not always root: a feed added after load has no listeners of its own.
		const ownFeed = button.closest< HTMLElement >( BLOCK_SELECTOR ) ?? root;
		const entry = button.closest< HTMLElement >( 'article' );
		// A layout without titles, such as Stream, would otherwise share the
		// title of the first entry in a feed nested in this entry's content.
		const title = entry
			? ownElement( ownFeed, '.wp-block-post-title', entry )
			: null;
		const shareData: ShareData = {
			url,
			title: title?.textContent?.trim() || document.title,
		};

		if ( navigator.share && navigator.canShare?.( shareData ) !== false ) {
			try {
				await navigator.share( shareData );
				return;
			} catch ( error ) {
				// The reader closed the share sheet.
				if ( ( error as DOMException ).name === 'AbortError' ) {
					return;
				}
			}
		}

		// Ignore re-clicks while the "Copied!" state is active so the label isn't snapshotted as "Copied!" and stuck on restore.
		if ( button.dataset.copied === '1' ) {
			return;
		}

		const createNotice = ( window as Window & { newspackUI?: NewspackUI } )
			.newspackUI?.notices?.createNotice;
		// The snackbar announces itself, so the status region stays empty.
		// Otherwise the button's feed announces the copy, or the feed handling
		// the tap when the button's feed is capped and renders no region.
		const status = createNotice
			? null
			: ( ownElement( ownFeed, STATUS_SELECTOR ) ??
				ownElement( root, STATUS_SELECTOR ) );
		const notify = ( message: string ) => {
			if ( createNotice ) {
				showSnackbar( createNotice, message );
			} else if ( status ) {
				status.textContent = message;
			}
		};

		if ( ! ( await copyText( url, button ) ) ) {
			notify(
				__( "Couldn't copy the link.", 'newspack-rolling-coverage' )
			);
			if ( status ) {
				setTimeout( () => {
					status.textContent = '';
				}, COPIED_STATE_MS );
			}
			return;
		}

		const originalContent = Array.from( button.childNodes );
		const originalLabel = button.getAttribute( 'aria-label' ) || '';
		// The icon-only share button keeps its icon; its label and the snackbar say it's copied.
		const isIconOnly = !! button.querySelector( 'svg' );

		button.dataset.copied = '1';
		if ( ! isIconOnly ) {
			button.textContent = __( 'Copied!', 'newspack-rolling-coverage' );
		}
		button.setAttribute(
			'aria-label',
			__( 'Copied!', 'newspack-rolling-coverage' )
		);
		notify( __( 'Link copied.', 'newspack-rolling-coverage' ) );

		setTimeout( () => {
			if ( ! isIconOnly ) {
				if ( originalContent.length ) {
					button.replaceChildren( ...originalContent );
				} else {
					button.textContent = __(
						'Share',
						'newspack-rolling-coverage'
					);
				}
			}
			if ( originalLabel ) {
				button.setAttribute( 'aria-label', originalLabel );
			} else {
				button.removeAttribute( 'aria-label' );
			}
			delete button.dataset.copied;
			if ( status ) {
				status.textContent = '';
			}
		}, COPIED_STATE_MS );
	}

	root.addEventListener( 'click', ( event ) => {
		const button = ( event.target as HTMLElement ).closest< HTMLElement >(
			SHARE_BUTTON_SELECTOR
		);

		// Modified clicks keep the link's own behaviour, e.g. a new tab.
		if (
			! button ||
			handledEvents.has( event ) ||
			event.metaKey ||
			event.ctrlKey ||
			event.shiftKey ||
			event.altKey
		) {
			return;
		}

		handledEvents.add( event );
		event.preventDefault();
		handleShareClick( button );
	} );

	// The share link has role="button", so Space activates it like one.
	root.addEventListener( 'keydown', ( event ) => {
		const button = ( event.target as HTMLElement ).closest< HTMLElement >(
			'a[data-rc-share]'
		);

		if (
			! button ||
			handledEvents.has( event ) ||
			event.key !== ' ' ||
			event.repeat
		) {
			return;
		}

		handledEvents.add( event );
		event.preventDefault();
		handleShareClick( button );
	} );
}

document.querySelectorAll< HTMLElement >( BLOCK_SELECTOR ).forEach( initBlock );
