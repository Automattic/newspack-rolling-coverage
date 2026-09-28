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
	 * there is one, otherwise copies the deep-link URL to the clipboard, or
	 * falls back to a prompt on non-secure contexts.
	 *
	 * @param {HTMLElement} button The clicked share button or link.
	 * @return {Promise<void>} Resolves when the share or copy attempt completes.
	 */
	async function handleShareClick( button: HTMLElement ): Promise< void > {
		const url = button.dataset.shareUrl || button.getAttribute( 'href' );
		if ( ! url ) {
			return;
		}

		const shareData: ShareData = {
			url,
			title:
				button
					.closest( 'article' )
					?.querySelector( '.wp-block-post-title' )
					?.textContent?.trim() || document.title,
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

		try {
			await navigator.clipboard.writeText( url );

			const originalText = button.textContent || '';
			const originalLabel = button.getAttribute( 'aria-label' ) || '';

			button.dataset.copied = '1';
			button.textContent = __( 'Copied!', 'newspack-rolling-coverage' );
			button.setAttribute(
				'aria-label',
				__( 'Copied!', 'newspack-rolling-coverage' )
			);

			const status = root.querySelector( STATUS_SELECTOR );
			if ( status ) {
				status.textContent = __(
					'Link copied.',
					'newspack-rolling-coverage'
				);
			}

			setTimeout( () => {
				button.textContent =
					originalText || __( 'Share', 'newspack-rolling-coverage' );
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
		} catch {
			// Clipboard API requires a secure context (HTTPS). Fall back to a prompt so the user can copy manually on HTTP dev sites.
			// eslint-disable-next-line no-alert
			window.prompt(
				__( 'Copy this link:', 'newspack-rolling-coverage' ),
				url
			);
		}
	}

	root.addEventListener( 'click', ( event ) => {
		const button = ( event.target as HTMLElement ).closest< HTMLElement >(
			SHARE_BUTTON_SELECTOR
		);

		// Modified clicks keep the link's own behaviour, e.g. a new tab.
		if (
			! button ||
			event.metaKey ||
			event.ctrlKey ||
			event.shiftKey ||
			event.altKey
		) {
			return;
		}

		event.preventDefault();
		handleShareClick( button );
	} );

	// The share link has role="button", so Space activates it like one.
	root.addEventListener( 'keydown', ( event ) => {
		const button = ( event.target as HTMLElement ).closest< HTMLElement >(
			'a[data-rc-share]'
		);

		if ( ! button || event.key !== ' ' || event.repeat ) {
			return;
		}

		event.preventDefault();
		handleShareClick( button );
	} );
}

document.querySelectorAll< HTMLElement >( BLOCK_SELECTOR ).forEach( initBlock );
