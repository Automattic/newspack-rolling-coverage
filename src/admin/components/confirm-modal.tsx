/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { ConfirmModalContentProps } from '../types';

/**
 * Reusable confirmation content for sensitive operations (trash, delete,
 * disconnect).
 *
 * Returns just the message and action buttons, so it must be rendered inside
 * a modal: a DataViews RenderModal or a core Modal.
 *
 * @param {ConfirmModalContentProps} props Component props.
 */
function ConfirmModal( {
	message,
	confirmLabel,
	cancelLabel,
	isDestructive,
	onConfirm,
	onClose,
}: ConfirmModalContentProps ) {
	const [ isBusy, setIsBusy ] = useState( false );

	const handleConfirm = async () => {
		setIsBusy( true );
		await onConfirm();
		setIsBusy( false );
		onClose();
	};

	return (
		<>
			<p>{ message }</p>
			<div className="newspack-rolling-coverage-modal-footer">
				<Button
					variant="tertiary"
					onClick={ onClose }
					disabled={ isBusy }
				>
					{ cancelLabel ||
						__( 'Cancel', 'newspack-rolling-coverage' ) }
				</Button>
				<Button
					variant="primary"
					isDestructive={ isDestructive }
					onClick={ handleConfirm }
					isBusy={ isBusy }
					disabled={ isBusy }
				>
					{ confirmLabel ||
						__( 'Confirm', 'newspack-rolling-coverage' ) }
				</Button>
			</div>
		</>
	);
}

export { ConfirmModal };
