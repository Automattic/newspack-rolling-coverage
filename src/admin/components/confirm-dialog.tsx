/**
 * External dependencies
 */
import { useCallback, useRef, useState } from '@wordpress/element';
import { AlertDialog } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import type { ConfirmRequest, RequestConfirm } from '../types';

/**
 * Confirmation dialog for list actions such as Trash. Keeps the last request
 * while closed so the dialog keeps its text through the exit animation.
 *
 * @param {Object}                props         Component props.
 * @param {ConfirmRequest | null} props.request The confirmation to show, or null when closed.
 * @param {() => void}            props.onClose Clears the request.
 */
function ConfirmDialog( {
	request,
	onClose,
}: {
	request: ConfirmRequest | null;
	onClose: () => void;
} ) {
	const lastRequest = useRef< ConfirmRequest | null >( request );
	if ( request ) {
		lastRequest.current = request;
	}
	const shown = lastRequest.current;

	if ( ! shown ) {
		return null;
	}

	return (
		<AlertDialog.Root
			open={ request !== null }
			onOpenChange={ ( open ) => {
				if ( ! open ) {
					onClose();
				}
			} }
			onConfirm={ shown.onConfirm }
		>
			<AlertDialog.Popup
				className="newspack-rolling-coverage-confirm-dialog"
				title={ shown.title }
				description={ shown.description }
				confirmButtonText={ shown.confirmLabel }
				intent={ shown.intent }
			/>
		</AlertDialog.Root>
	);
}

/**
 * Holds one pending confirmation for a view and renders its dialog.
 *
 * @return {Object} `requestConfirm` to open the dialog, and the `dialog` element to render.
 */
function useConfirmDialog(): {
	requestConfirm: RequestConfirm;
	dialog: JSX.Element;
} {
	const [ request, setRequest ] = useState< ConfirmRequest | null >( null );
	const requestConfirm = useCallback< RequestConfirm >(
		( next ) => setRequest( next ),
		[]
	);
	const handleClose = useCallback( () => setRequest( null ), [] );

	return {
		requestConfirm,
		dialog: <ConfirmDialog request={ request } onClose={ handleClose } />,
	};
}

export { ConfirmDialog, useConfirmDialog };
