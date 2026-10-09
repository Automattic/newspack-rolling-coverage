/**
 * External dependencies
 */
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
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

/** The failed-save notice, so the next save can remove it. */
const SAVE_ERROR_NOTICE_ID = 'newspack-rolling-coverage-quick-edit-save-error';

/**
 * Cancel and Save buttons for the Quick Edit footer. A new entry gets Save
 * Draft and Publish instead of Save, or Submit for Review in place of
 * Publish for users the REST API gives no `wp:action-publish` link, as the
 * post editor's publish button does. Both stay disabled until the entry has
 * a title or some content (`isEditedPostSaveable()`).
 *
 * Each of those buttons sets the status it saves right before saving, so a
 * status left behind by a failed save never rides along with the next one.
 * The save itself is the editor's `savePost()`, so the core entries route
 * applies the user's capabilities and the status hooks run as they do for a
 * save in the editor.
 *
 * `savePost()` never rejects on failure, so the result is detected by
 * watching `isSavingPost` transition to `false` and then reading
 * `didPostSaveRequestFail()`. All store reads go through `useSelect` so
 * they resolve in the `EditorProvider` sub-registry. On failure an error
 * snackbar is dispatched for `SnackbarNotices` to render inside the modal.
 * It carries a fixed ID, so the next save removes it: a new entry's first
 * successful save closes the modal without clearing the page's snackbars,
 * as the editor's own success notice is on its way, and an error left from
 * an earlier attempt would otherwise show on the page beside it.
 *
 * @param {QuickEditSaveBarProps} props Component props.
 */
function QuickEditSaveBar( {
	isNew,
	onClose,
	onSaved,
}: QuickEditSaveBarProps ) {
	const { savePost, editPost } = useDispatch( editorStore );
	const { createErrorNotice, removeNotice } = useDispatch( noticesStore );

	const {
		isEditorReady,
		isDirty,
		isSaveable,
		isSavingPost,
		didFail,
		lastSaveError,
		canPublish,
	} = useSelect( ( registry ) => {
		const editor = registry( editorStore ) as unknown as EditorSelectors;
		const core = registry( coreStore ) as unknown as CoreSelectors;
		return {
			isEditorReady: editor.__unstableIsEditorReady?.() ?? false,
			isDirty: editor.isEditedPostDirty(),
			isSaveable: editor.isEditedPostSaveable(),
			isSavingPost: editor.isSavingPost(),
			didFail: editor.didPostSaveRequestFail(),
			lastSaveError: core.getLastEntitySaveError(
				'postType',
				editor.getCurrentPostType(),
				editor.getCurrentPostId()
			),
			canPublish: Boolean(
				editor.getCurrentPost()._links?.[ 'wp:action-publish' ]
			),
		};
	}, [] );

	// The status the running save sets, so only its button shows as busy.
	const [ savingStatus, setSavingStatus ] = useState< string | null >( null );
	const wasSavingRef = useRef( false );

	useEffect( () => {
		if ( wasSavingRef.current && ! isSavingPost ) {
			wasSavingRef.current = false;
			setSavingStatus( null );
			if ( didFail ) {
				createErrorNotice(
					lastSaveError?.message ||
						__(
							'Failed to save entry.',
							'newspack-rolling-coverage'
						),
					{
						id: SAVE_ERROR_NOTICE_ID,
						type: 'snackbar',
						explicitDismiss: true,
					}
				);
			} else {
				onSaved();
			}
		}
		if ( isSavingPost ) {
			wasSavingRef.current = true;
		}
	}, [ isSavingPost, didFail, lastSaveError, createErrorNotice, onSaved ] );

	const handleSave = useCallback(
		async ( status?: string ) => {
			removeNotice( SAVE_ERROR_NOTICE_ID );
			if ( status ) {
				setSavingStatus( status );
				editPost( { status }, { undoIgnore: true } );
			}
			await savePost();
		},
		[ editPost, removeNotice, savePost ]
	);

	const publishStatus = canPublish ? 'publish' : 'pending';
	const isNewEntrySaveDisabled =
		isSavingPost || ! isEditorReady || ! isSaveable;

	return (
		<>
			<Button
				variant="tertiary"
				onClick={ onClose }
				disabled={ isSavingPost }
				size="compact"
			>
				{ __( 'Cancel', 'newspack-rolling-coverage' ) }
			</Button>
			{ isNew ? (
				<>
					<Button
						variant="secondary"
						onClick={ () => handleSave( 'draft' ) }
						isBusy={ isSavingPost && savingStatus === 'draft' }
						disabled={ isNewEntrySaveDisabled }
						accessibleWhenDisabled
						size="compact"
					>
						{ __( 'Save Draft', 'newspack-rolling-coverage' ) }
					</Button>
					<Button
						variant="primary"
						onClick={ () => handleSave( publishStatus ) }
						isBusy={
							isSavingPost && savingStatus === publishStatus
						}
						disabled={ isNewEntrySaveDisabled }
						accessibleWhenDisabled
						size="compact"
					>
						{ canPublish
							? __( 'Publish', 'newspack-rolling-coverage' )
							: __(
									'Submit for Review',
									'newspack-rolling-coverage'
								) }
					</Button>
				</>
			) : (
				<Button
					variant="primary"
					onClick={ () => handleSave() }
					isBusy={ isSavingPost }
					disabled={ isSavingPost || ! isEditorReady || ! isDirty }
					accessibleWhenDisabled
					size="compact"
				>
					{ __( 'Save', 'newspack-rolling-coverage' ) }
				</Button>
			) }
		</>
	);
}

export { QuickEditSaveBar };
