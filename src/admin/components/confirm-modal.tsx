/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { Stack, Text } from '@wordpress/ui';
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
		<Stack direction="column" gap="xl">
			<Text render={ <p /> }>{ message }</Text>
			<Stack direction="row" gap="sm" justify="flex-end">
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
			</Stack>
		</Stack>
	);
}

export { ConfirmModal };
