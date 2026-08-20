/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import './style.scss';

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
	 * Handle click on a share button — copies the deep-link URL to the
	 * clipboard, or falls back to a prompt on non-secure contexts.
	 *
	 * @param {HTMLButtonElement} button The clicked share button.
	 * @return {Promise<void>} Resolves when the copy attempt completes.
	 */
	async function handleShareClick(
		button: HTMLButtonElement
	): Promise< void > {
		const url = button.dataset.shareUrl;
		if ( ! url ) {
			return;
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
		const button = (
			event.target as HTMLElement
		 ).closest< HTMLButtonElement >( SHARE_BUTTON_SELECTOR );

		if ( ! button ) {
			return;
		}

		event.preventDefault();
		handleShareClick( button );
	} );
}

document.querySelectorAll< HTMLElement >( BLOCK_SELECTOR ).forEach( initBlock );
