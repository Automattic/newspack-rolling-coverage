/**
 * External dependencies
 */
import { useCallback, useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { store as editorStore } from '@wordpress/editor';
import { useDispatch, useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { useQuickEditSaveResult } from '../hooks/useQuickEditSaveResult';
import type { QuickEditSaveBarProps, EditorSelectors } from '../types';

/**
 * Cancel and Save buttons for the footer of the Summary's Quick Edit. A new
 * Summary gets Save Draft and Publish instead of Save, or Submit for Review
 * in place of Publish for users the REST API gives no `wp:action-publish`
 * link, as the post editor's publish button does. Both stay disabled until
 * it has a title or some content (`isEditedPostSaveable()`).
 *
 * Each of those buttons sets the status it saves right before saving, so a
 * status left behind by a failed save never rides along with the next one.
 * The save itself is the editor's `savePost()`, so the core entries route
 * applies the user's capabilities and the status hooks run as they do for a
 * save in the editor. `useQuickEditSaveResult()` reports how it ended.
 *
 * @param {QuickEditSaveBarProps} props Component props.
 */
function QuickEditSaveBar( {
	isNew,
	onClose,
	onSaved,
}: QuickEditSaveBarProps ) {
	const { savePost, editPost } = useDispatch( editorStore );

	const { isEditorReady, isDirty, isSaveable, canPublish } = useSelect(
		( select ) => {
			const editor = select( editorStore ) as unknown as EditorSelectors;
			return {
				isEditorReady: editor.__unstableIsEditorReady?.() ?? false,
				isDirty: editor.isEditedPostDirty(),
				isSaveable: editor.isEditedPostSaveable(),
				canPublish: Boolean(
					editor.getCurrentPost()._links?.[ 'wp:action-publish' ]
				),
			};
		},
		[]
	);

	// The status the running save sets, so only its button shows as busy.
	const [ savingStatus, setSavingStatus ] = useState< string | null >( null );
	const clearSavingStatus = useCallback( () => setSavingStatus( null ), [] );
	const isSavingPost = useQuickEditSaveResult( onSaved, clearSavingStatus );

	const handleSave = useCallback(
		async ( status?: string ) => {
			if ( status ) {
				setSavingStatus( status );
				editPost( { status }, { undoIgnore: true } );
			}
			await savePost();
		},
		[ editPost, savePost ]
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
