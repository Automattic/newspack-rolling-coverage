/**
 * External dependencies
 */
import { useState, useRef, useEffect } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { speak } from '@wordpress/a11y';
import { useDispatch } from '@wordpress/data';
import { check } from '@wordpress/icons';
import { store as noticesStore } from '@wordpress/notices';
import { __ } from '@wordpress/i18n';

/** Shortest time the button shows as busy, so the click reads as acknowledged. */
const MIN_BUSY_MS = 900;

/** Shared notice id, so repeated copies replace the snackbar instead of stacking. */
const COPY_NOTICE_ID = 'newspack-rolling-coverage-copy-manifest';

/** How long the inline "Copied!" label stays up. */
const COPIED_LABEL_MS = 2000;

/**
 * Copies text through a temporary textarea, for browsers or contexts where the
 * Clipboard API is unavailable or refuses the write.
 *
 * @param text    Text to copy.
 * @param trigger Element to hand focus back to afterwards.
 * @return Whether the copy succeeded.
 */
function legacyCopy( text: string, trigger: HTMLElement | null ): boolean {
	const field = document.createElement( 'textarea' );
	field.value = text;
	field.setAttribute( 'readonly', '' );
	field.style.position = 'fixed';
	field.style.opacity = '0';
	document.body.appendChild( field );
	field.select();
	let copied = false;
	try {
		copied = document.execCommand( 'copy' );
	} catch {
		copied = false;
	}
	field.remove();
	trigger?.focus();
	return copied;
}

/**
 * Copies the Slack app manifest to the clipboard and confirms the result.
 *
 * Snackbars are hidden while a modal is open, so inside a drawer the button
 * confirms on its own label instead.
 *
 * @param {Object}                props              - Component props.
 * @param {string}                props.manifestJson - The Slack app manifest JSON string to copy.
 * @param {'snackbar' | 'inline'} props.feedback     - How to confirm the copy.
 */
function CopyManifestButton( {
	manifestJson,
	feedback = 'snackbar',
}: {
	manifestJson: string;
	feedback?: 'snackbar' | 'inline';
} ) {
	const [ isBusy, setIsBusy ] = useState( false );
	const [ isCopied, setIsCopied ] = useState( false );
	const buttonRef = useRef< HTMLButtonElement | null >( null );
	const busyTimer = useRef< ReturnType< typeof setTimeout > | null >( null );
	const { createSuccessNotice, createErrorNotice, removeNotice } =
		useDispatch( noticesStore );

	useEffect(
		() => () => {
			if ( busyTimer.current ) {
				clearTimeout( busyTimer.current );
			}
		},
		[]
	);

	const handleCopy = async () => {
		if ( isBusy ) {
			return;
		}
		if ( busyTimer.current ) {
			clearTimeout( busyTimer.current );
		}
		setIsCopied( false );
		setIsBusy( true );
		const startedAt = Date.now();

		let copied = false;
		try {
			await navigator.clipboard.writeText( manifestJson );
			copied = true;
		} catch {
			copied = legacyCopy( manifestJson, buttonRef.current );
		}

		busyTimer.current = setTimeout(
			() => {
				setIsBusy( false );
				if ( feedback === 'inline' ) {
					if ( copied ) {
						setIsCopied( true );
						speak(
							__(
								'Manifest copied to clipboard.',
								'newspack-rolling-coverage'
							)
						);
						busyTimer.current = setTimeout(
							() => setIsCopied( false ),
							COPIED_LABEL_MS
						);
					} else {
						speak(
							__(
								'Could not copy the manifest.',
								'newspack-rolling-coverage'
							),
							'assertive'
						);
					}
					return;
				}
				removeNotice( COPY_NOTICE_ID );
				if ( copied ) {
					createSuccessNotice(
						__(
							'Manifest copied to clipboard.',
							'newspack-rolling-coverage'
						),
						{ id: COPY_NOTICE_ID, type: 'snackbar' }
					);
				} else {
					createErrorNotice(
						__(
							'Could not copy the manifest.',
							'newspack-rolling-coverage'
						),
						{ id: COPY_NOTICE_ID, type: 'snackbar' }
					);
				}
			},
			Math.max( 0, MIN_BUSY_MS - ( Date.now() - startedAt ) )
		);
	};

	return (
		<Button
			ref={ buttonRef }
			variant="secondary"
			onClick={ handleCopy }
			isBusy={ isBusy }
			aria-disabled={ isBusy }
			icon={ isCopied ? check : undefined }
		>
			{ isCopied
				? __( 'Copied!', 'newspack-rolling-coverage' )
				: __( 'Copy Manifest', 'newspack-rolling-coverage' ) }
		</Button>
	);
}

export { CopyManifestButton };
