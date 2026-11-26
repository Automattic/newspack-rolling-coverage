/**
 * External dependencies
 */
import type { ComponentType } from 'react';
import { Button } from '@wordpress/components';
import {
	PostPublishButton,
	PostSavedState,
	store as editorStore,
} from '@wordpress/editor';
import { useDispatch, useSelect } from '@wordpress/data';
import { close, drawerLeft, drawerRight } from '@wordpress/icons';
import { __, isRTL } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { useQuickEditSaveResult } from '../hooks/useQuickEditSaveResult';
import { QuickEditDocumentTools } from './quick-edit-document-tools';
import type { EditorSelectors, QuickEditEditorHeaderProps } from '../types';

// The editor's JS components carry inferred types that mark optional props
// as required.
const SavedState = PostSavedState as unknown as ComponentType;
const PublishButton = PostPublishButton as unknown as ComponentType;

/**
 * The header of an entry's Quick Edit, laid out like the post editor's:
 * document tools on the left; on the right the save buttons, the settings
 * sidebar toggle and a close button. Saving keeps Quick Edit open, as in the
 * post editor.
 *
 * When the entry's status can change, the save buttons are the post
 * editor's own (`PostSavedState` and `PostPublishButton`), so a draft offers
 * Save draft and Publish, a published entry Save, and a contributor Submit
 * for Review. Publish goes straight through: there's no room in the modal
 * for the pre-publish panel. Otherwise a single Save keeps the entry's
 * status: for an entry locked by Archive Mode, and while another entity has
 * unsaved edits, since core's Publish button then reads Save but still
 * publishes a draft.
 *
 * Renders inside `EditorProvider`, so the buttons resolve in its
 * sub-registry.
 *
 * @param {QuickEditEditorHeaderProps} props Component props.
 */
function QuickEditEditorHeader( {
	canChangeStatus,
	isSidebarOpen,
	onToggleSidebar,
	onClose,
	onSaved,
}: QuickEditEditorHeaderProps ) {
	const { savePost } = useDispatch( editorStore );
	const { isEditorReady, isDirty, hasNonPostEntityChanges } = useSelect(
		( select ) => {
			const editor = select( editorStore ) as unknown as EditorSelectors;
			return {
				isEditorReady: editor.__unstableIsEditorReady?.() ?? false,
				isDirty: editor.isEditedPostDirty(),
				hasNonPostEntityChanges: editor.hasNonPostEntityChanges(),
			};
		},
		[]
	);
	const isSavingPost = useQuickEditSaveResult( onSaved );

	return (
		<div className="newspack-rolling-coverage-quick-edit__header">
			<QuickEditDocumentTools />
			<div className="newspack-rolling-coverage-quick-edit__header-actions">
				{ canChangeStatus && ! hasNonPostEntityChanges ? (
					<>
						<SavedState />
						<PublishButton />
					</>
				) : (
					<Button
						variant="primary"
						onClick={ () => savePost() }
						isBusy={ isSavingPost }
						disabled={
							isSavingPost ||
							! isEditorReady ||
							! ( isDirty || hasNonPostEntityChanges )
						}
						accessibleWhenDisabled
						size="compact"
					>
						{ __( 'Save', 'newspack-rolling-coverage' ) }
					</Button>
				) }
				<Button
					icon={ isRTL() ? drawerLeft : drawerRight }
					label={ __( 'Settings', 'newspack-rolling-coverage' ) }
					isPressed={ isSidebarOpen }
					aria-expanded={ isSidebarOpen }
					onClick={ onToggleSidebar }
					size="compact"
				/>
				<Button
					icon={ close }
					label={ __( 'Close', 'newspack-rolling-coverage' ) }
					onClick={ onClose }
					disabled={ isSavingPost }
					accessibleWhenDisabled
					size="compact"
				/>
			</div>
		</div>
	);
}

export { QuickEditEditorHeader };
