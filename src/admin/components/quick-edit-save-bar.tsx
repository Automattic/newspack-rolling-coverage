/**
 * External dependencies
 */
import type { ComponentType } from 'react';
import { useCallback, useEffect, useRef } from '@wordpress/element';
import { Button } from '@wordpress/components';
import {
	PostPublishButton,
	PostSavedState,
	store as editorStore,
} from '@wordpress/editor';
import { store as coreStore } from '@wordpress/core-data';
import { store as noticesStore } from '@wordpress/notices';
import { useDispatch, useSelect } from '@wordpress/data';
import { close } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type {
	QuickEditSaveBarProps,
	EditorSelectors,
	CoreSelectors,
} from '../types';

// The editor's JS components carry inferred types that mark optional props
// as required.
const SavedState = PostSavedState as unknown as ComponentType;
const PublishButton = PostPublishButton as unknown as ComponentType;

/**
 * Save and close buttons for the Quick Edit modal header, with any children
 * placed before them. Saving keeps Quick Edit open, as in the post editor.
 *
 * When the entry can be published, the save buttons are the post editor's
 * own (`PostSavedState` and `PostPublishButton`), so a draft offers Save
 * draft and Publish, a published entry Save, and a contributor Submit for
 * Review. Publish goes straight through: there's no room in the modal for
 * the pre-publish panel. Otherwise a single Save keeps the entry's status:
 * for a locked entry, and while another entity has unsaved edits, since
 * core's Publish button then reads Save but still publishes a draft.
 *
 * `savePost()` never rejects on failure, so the result is detected by
 * watching `isSavingPost` transition to `false` and then reading
 * `didPostSaveRequestFail()`. All store reads go through `useSelect` so
 * they resolve in the `EditorProvider` sub-registry. On failure an error
 * snackbar is dispatched for `EditorSnackbars` to render inside the modal.
 *
 * @param {QuickEditSaveBarProps} props Component props.
 */
function QuickEditSaveBar( {
	onClose,
	onSaved,
	canPublish,
	children,
}: QuickEditSaveBarProps ) {
	const { savePost } = useDispatch( editorStore );
	const { createErrorNotice } = useDispatch( noticesStore );

	const {
		isEditorReady,
		isSavingPost,
		didFail,
		lastSaveError,
		hasNonPostEntityChanges,
	} = useSelect( ( registry ) => {
		const editor = registry( editorStore ) as unknown as EditorSelectors;
		const core = registry( coreStore ) as unknown as CoreSelectors;
		return {
			isEditorReady: editor.__unstableIsEditorReady?.() ?? false,
			isSavingPost: editor.isSavingPost(),
			didFail: editor.didPostSaveRequestFail(),
			hasNonPostEntityChanges: editor.hasNonPostEntityChanges(),
			lastSaveError: core.getLastEntitySaveError(
				'postType',
				editor.getCurrentPostType(),
				editor.getCurrentPostId()
			),
		};
	}, [] );

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
		<>
			{ children }
			{ canPublish && ! hasNonPostEntityChanges ? (
				<>
					<SavedState />
					<PublishButton />
				</>
			) : (
				<Button
					variant="primary"
					onClick={ handleSave }
					isBusy={ isSavingPost }
					disabled={ isSavingPost || ! isEditorReady }
					size="compact"
				>
					{ __( 'Save', 'newspack-rolling-coverage' ) }
				</Button>
			) }
			<Button
				icon={ close }
				label={ __( 'Close', 'newspack-rolling-coverage' ) }
				onClick={ onClose }
				disabled={ isSavingPost }
				size="compact"
			/>
		</>
	);
}

export { QuickEditSaveBar };
