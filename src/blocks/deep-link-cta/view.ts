/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import './style.scss';

const CTA_SELECTOR = '.newspack-rolling-coverage-cta';
const CTA_BUTTON_SELECTOR = '.newspack-rolling-coverage-cta__button';
const CTA_TEMPLATE_SELECTOR = '.newspack-rolling-coverage-cta__modal-template';

/**
 * Sets up the CTA modal for a single block instance.
 *
 * The modal content is pre-rendered at SSR into a hidden <template> element.
 * On click, the template is cloned into a native <dialog> and shown.
 *
 * @param {HTMLElement} root The block's outer wrapper element.
 */
function initBlock( root: HTMLElement ): void {
	if ( root.dataset.rcInitialized === '1' ) {
		return;
	}
	root.dataset.rcInitialized = '1';

	let modalOpen = false;

	/**
	 * Open the modal by cloning the pre-rendered <template> into a <dialog>.
	 *
	 * @param {HTMLTemplateElement} template The SSR'd modal template.
	 */
	function openModal( template: HTMLTemplateElement ): void {
		if ( modalOpen ) {
			return;
		}
		modalOpen = true;

		const dialog = document.createElement( 'dialog' );
		dialog.className = 'newspack-rolling-coverage-cta-modal';

		const header = document.createElement( 'div' );
		header.className = 'newspack-rolling-coverage-cta-modal__header';

		const back = document.createElement( 'button' );
		back.type = 'button';
		back.className =
			'newspack-rolling-coverage-cta-modal__back wp-element-button';
		back.textContent = '←';
		back.setAttribute(
			'aria-label',
			__( 'Close and return to coverage', 'newspack-rolling-coverage' )
		);
		back.addEventListener( 'click', () => dialog.close() );
		header.appendChild( back );

		dialog.appendChild( header );

		const body = document.createElement( 'div' );
		body.className = 'newspack-rolling-coverage-cta-modal__body';
		body.appendChild( template.content.cloneNode( true ) );
		dialog.appendChild( body );

		document.body.appendChild( dialog );
		dialog.addEventListener( 'close', () => {
			dialog.remove();
			modalOpen = false;
		} );

		try {
			dialog.showModal();
		} catch {
			dialog.remove();
			modalOpen = false;
		}
	}

	// Click on the CTA button opens the modal. Skip <a> elements — those
	// link to breakout posts and should navigate normally.
	root.addEventListener( 'click', ( event ) => {
		const button = (
			event.target as HTMLElement
		 ).closest< HTMLButtonElement >( CTA_BUTTON_SELECTOR );

		if ( ! button || button.tagName === 'A' ) {
			return;
		}

		event.preventDefault();

		const template = root.querySelector< HTMLTemplateElement >(
			CTA_TEMPLATE_SELECTOR
		);

		if ( ! template ) {
			return;
		}

		openModal( template );
	} );
}

document.querySelectorAll< HTMLElement >( CTA_SELECTOR ).forEach( initBlock );
