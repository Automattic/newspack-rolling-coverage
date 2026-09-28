/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

const BLOCK_SELECTOR = '.wp-block-newspack-rolling-coverage-rolling-coverage';
const SHARE_BUTTON_SELECTOR = '.newspack-rolling-coverage-share-link';
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

			setTimeout( () => {
				button.textContent =
					originalText || __( 'Share', 'newspack-rolling-coverage' );
				button.setAttribute(
					'aria-label',
					originalLabel ||
						__( 'Share this entry', 'newspack-rolling-coverage' )
				);
				delete button.dataset.copied;
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
		const match = ( event.target as HTMLElement ).closest< HTMLElement >(
			SHARE_BUTTON_SELECTOR
		);
		const button = match?.matches( 'a, button' )
			? match
			: match?.querySelector< HTMLElement >( 'a' );

		if ( ! button ) {
			return;
		}

		event.preventDefault();
		handleShareClick( button );
	} );
}

document.querySelectorAll< HTMLElement >( BLOCK_SELECTOR ).forEach( initBlock );
