/**
 * WordPress dependencies
 */
import { createElement } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { ConfirmModalContent } from '../shared/confirm-modal';
import { notifySuccess, notifyError } from './notices';
import type { Action, ApiResult } from '../types';

/**
 * Factory that creates a DataViews delete action with a confirmation modal
 * and bulk support. Renders a ConfirmModalContent asking the user to confirm,
 * then bulk-deletes selected items.
 *
 * @template T - Item type with at least an `id: number` field.
 * @param {(id: number) => Promise<ApiResult>}   deleteFn          Function that deletes a single item by ID.
 * @param {{ singular: string; plural: string }} messages          Confirmation messages (singular/plural).
 * @param {{ singular: string; plural: string }} headers           Action label headers (singular/plural).
 * @param {() => void}                           onActionPerformed Callback invoked after all deletes succeed.
 *
 * @return {Action<T>} A DataViews action object for deletion.
 */
function createDeleteAction< T extends { id: number } >(
	deleteFn: ( id: number ) => Promise< ApiResult >,
	messages: { singular: string; plural: string },
	headers: { singular: string; plural: string },
	onActionPerformed?: () => void
): Action< T > {
	/**
	 * Renders the confirmation modal for deleting items and handles deletion.
	 *
	 * @param {Object}   props            The component props.
	 * @param {T[]}      props.items      The items to be deleted.
	 * @param {Function} props.closeModal Function to close the modal after action.
	 *
	 * @return {JSX.Element} The confirmation modal element.
	 */
	const DeleteModal = ( {
		items,
		closeModal,
	}: {
		items: T[];
		closeModal?: () => void;
	} ) => {
		const handleDelete = async () => {
			const results = await Promise.allSettled(
				items.map( ( item ) => deleteFn( item.id ) )
			);

			const failureCount = results.filter( ( result ) => {
				if ( result.status === 'rejected' ) {
					return true;
				}
				return ! result.value.success;
			} ).length;

			if ( failureCount === 0 ) {
				notifySuccess(
					sprintf(
						// translators: %d is the number of items deleted.
						_n(
							'%d item deleted.',
							'%d items deleted.',
							items.length,
							'newspack-rolling-coverage'
						),
						items.length
					)
				);
				onActionPerformed?.();
			} else {
				notifyError(
					sprintf(
						// translators: 1: number of items that failed to delete, 2: total number of items selected.
						__(
							'%1$d of %2$d items failed to delete.',
							'newspack-rolling-coverage'
						),
						failureCount,
						items.length
					)
				);
			}
			closeModal?.();
		};

		return createElement( ConfirmModalContent, {
			message:
				items.length > 1
					? messages.plural.replace( '%d', String( items.length ) )
					: messages.singular,
			confirmLabel: __( 'Delete', 'newspack-rolling-coverage' ),
			isDestructive: true,
			onConfirm: handleDelete,
			onClose: closeModal ?? ( () => {} ),
		} );
	};

	return {
		id: 'delete',
		label: ( items: T[] ) =>
			items.length > 1 ? headers.plural : headers.singular,
		supportsBulk: true,
		RenderModal: DeleteModal,
	};
}

export { createDeleteAction };
