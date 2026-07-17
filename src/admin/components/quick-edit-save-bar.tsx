/**
 * External dependencies
 */
import { useCallback, useEffect, useRef } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { store as editorStore } from '@wordpress/editor';
import { store as coreStore } from '@wordpress/core-data';
import { store as noticesStore } from '@wordpress/notices';
import { useDispatch, useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type {
	QuickEditSaveBarProps,
	EditorSelectors,
	CoreSelectors,
} from '../types';

/**
 * Save bar for the Quick Edit modal.
 *
 * `savePost()` never rejects on failure, so the result is detected by
 * watching `isSavingPost` transition to `false` and then reading
 * `didPostSaveRequestFail()`. All store reads go through `useSelect` so
 * they resolve in the `EditorProvider` sub-registry. On failure an error
 * snackbar is dispatched for `EditorSnackbars` to render inside the modal.
 *
 * @param {QuickEditSaveBarProps} props Component props.
 */
function QuickEditSaveBar( { onClose, onSaved }: QuickEditSaveBarProps ) {
	const { savePost } = useDispatch( editorStore );
	const { createErrorNotice } = useDispatch( noticesStore );

	const { isEditorReady, isSavingPost, didFail, lastSaveError } = useSelect(
		( registry ) => {
			const editor = registry(
				editorStore
			) as unknown as EditorSelectors;
			const core = registry( coreStore ) as unknown as CoreSelectors;
			return {
				isEditorReady: editor.__unstableIsEditorReady?.() ?? false,
				isSavingPost: editor.isSavingPost(),
				didFail: editor.didPostSaveRequestFail(),
				lastSaveError: core.getLastEntitySaveError(
					'postType',
					editor.getCurrentPostType(),
					editor.getCurrentPostId()
				),
			};
		},
		[]
	);

	const wasSavingRef = useRef( false );

	useEffect( () => {
		if ( wasSavingRef.current && ! isSavingPost ) {
			wasSavingRef.current = false;
			if ( didFail ) {
				createErrorNotice(
					lastSaveError?.message ||
						__(
							'Failed to save entry.',
							'newspack-rolling-coverage'
						),
					{ type: 'snackbar', explicitDismiss: true }
				);
			} else {
				onSaved();
			}
		}
		if ( isSavingPost ) {
			wasSavingRef.current = true;
		}
	}, [ isSavingPost, didFail, lastSaveError, createErrorNotice, onSaved ] );

	const handleSave = useCallback( async () => {
		await savePost();
	}, [ savePost ] );

	return (
		<div className="newspack-rolling-coverage-modal-footer">
			<Button
				variant="tertiary"
				onClick={ onClose }
				disabled={ isSavingPost }
			>
				{ __( 'Cancel', 'newspack-rolling-coverage' ) }
			</Button>
			<Button
				variant="primary"
				onClick={ handleSave }
				isBusy={ isSavingPost }
				disabled={ isSavingPost || ! isEditorReady }
			>
				{ __( 'Save', 'newspack-rolling-coverage' ) }
			</Button>
		</div>
	);
}

export { QuickEditSaveBar };
