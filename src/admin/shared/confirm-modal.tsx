/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';
import { Modal, Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import type { ConfirmModalProps, ConfirmModalContentProps } from '../types';

/**
 * Inner content of a confirmation dialog with loading state on the confirm button.
 *
 * @param {ConfirmModalContentProps} props Component props.
 */
function ConfirmModalContent( {
	message,
	confirmLabel = __( 'Confirm', 'newspack-rolling-coverage' ),
	cancelLabel = __( 'Cancel', 'newspack-rolling-coverage' ),
	isDestructive = false,
	onConfirm,
	onClose,
}: ConfirmModalContentProps ) {
	const [ isProcessing, setIsProcessing ] = useState( false );

	const handleConfirm = async () => {
		setIsProcessing( true );
		try {
			await onConfirm();
			onClose();
		} finally {
			setIsProcessing( false );
		}
	};

	return (
		<>
			<p id="confirm-modal-message">{ message }</p>
			<div className="newspack-rolling-coverage-modal-footer">
				<Button
					variant="tertiary"
					onClick={ onClose }
					disabled={ isProcessing }
				>
					{ cancelLabel }
				</Button>
				<Button
					variant="primary"
					isDestructive={ isDestructive }
					onClick={ handleConfirm }
					isBusy={ isProcessing }
					disabled={ isProcessing }
				>
					{ confirmLabel }
				</Button>
			</div>
		</>
	);
}

/**
 * Modal wrapper that renders a confirmation dialog with customizable labels
 * and destructive styling support.
 *
 * @param {ConfirmModalProps} props Component props.
 */
function ConfirmModal( {
	title,
	message,
	confirmLabel = __( 'Confirm', 'newspack-rolling-coverage' ),
	cancelLabel = __( 'Cancel', 'newspack-rolling-coverage' ),
	isDestructive = false,
	onConfirm,
	onClose,
}: ConfirmModalProps ) {
	return (
		<Modal title={ title } onRequestClose={ onClose }>
			<ConfirmModalContent
				message={ message }
				confirmLabel={ confirmLabel }
				cancelLabel={ cancelLabel }
				isDestructive={ isDestructive }
				onConfirm={ onConfirm }
				onClose={ onClose }
			/>
		</Modal>
	);
}

export { ConfirmModal, ConfirmModalContent };
