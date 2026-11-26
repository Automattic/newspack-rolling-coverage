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
import { store as coreStore } from '@wordpress/core-data';
import { useDispatch, useRegistry, useSelect } from '@wordpress/data';
import { close, drawerLeft, drawerRight } from '@wordpress/icons';
import { __, isRTL } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { useQuickEditSaveResult } from '../hooks/useQuickEditSaveResult';
import { QuickEditDocumentTools } from './quick-edit-document-tools';
import type {
	CoreSelectors,
	EditorSelectors,
	QuickEditEditorHeaderProps,
} from '../types';

// The editor's JS components carry inferred types that mark optional props
// as required.
const SavedState = PostSavedState as unknown as ComponentType;
const PublishButton = PostPublishButton as unknown as ComponentType;

/**
 * The header of an entry's Quick Edit, laid out like the post editor's:
 * document tools on the left; on the right the settings sidebar toggle, the
 * saved state, the primary save button and the close button.
 *
 * When the entry's status can change, the save buttons are the post
 * editor's own (`PostSavedState` and `PostPublishButton`), so a draft offers
 * Save draft and Publish, a published entry Save, and a contributor Submit
 * for Review. Publish goes straight through: there's no room in the modal
 * for the pre-publish panel. Otherwise a single Save keeps the entry's
 * status: for an entry locked by Archive Mode, and while another entity has
 * unsaved edits, since core's Publish button then reads Save but still
 * publishes a draft. That Save also saves the other entities' edits, which
 * the post editor leaves to its save panel, but never another entry's: the
 * page shares one undo history, so another entry's edits may come from an
 * Undo in this Quick Edit rather than from anything typed here.
 *
 * Saving keeps Quick Edit open, as the post editor stays open; the close
 * button is the way out.
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
	const registry = useRegistry();
	const { savePost } = useDispatch( editorStore );
	const { saveEditedEntityRecord } = useDispatch( coreStore );
	const { isEditorReady, isDirty, hasNonPostEntityChanges, isSavingLocked } =
		useSelect( ( select ) => {
			const editor = select(
				editorStore
			) as unknown as EditorSelectors & {
				isPostSavingLocked: () => boolean;
			};
			return {
				isEditorReady: editor.__unstableIsEditorReady?.() ?? false,
				isDirty: editor.isEditedPostDirty(),
				hasNonPostEntityChanges: editor.hasNonPostEntityChanges(),
				isSavingLocked: editor.isPostSavingLocked(),
			};
		}, [] );

	const isSavingPost = useQuickEditSaveResult( onSaved );

	const isPublishButton = canChangeStatus && ! hasNonPostEntityChanges;
	const canPlainSave =
		! isSavingPost &&
		! isSavingLocked &&
		isEditorReady &&
		( isDirty || hasNonPostEntityChanges );

	const saveEntryAndEntities = () => {
		const editor = registry.select(
			editorStore
		) as unknown as EditorSelectors;
		const postType = editor.getCurrentPostType();
		( registry.select( coreStore ) as unknown as CoreSelectors )
			.__experimentalGetDirtyEntityRecords()
			.filter(
				( record ) =>
					! ( record.kind === 'postType' && record.name === postType )
			)
			.forEach( ( record ) =>
				saveEditedEntityRecord( record.kind, record.name, record.key )
			);
		savePost();
	};

	return (
		<div className="newspack-rolling-coverage-quick-edit__header">
			<QuickEditDocumentTools />
			<div className="newspack-rolling-coverage-quick-edit__header-actions">
				<Button
					icon={ isRTL() ? drawerLeft : drawerRight }
					label={ __( 'Settings', 'newspack-rolling-coverage' ) }
					isPressed={ isSidebarOpen }
					aria-expanded={ isSidebarOpen }
					onClick={ onToggleSidebar }
					size="compact"
				/>
				{ isPublishButton && <SavedState /> }
				{ isPublishButton ? (
					<PublishButton />
				) : (
					<Button
						variant="primary"
						onClick={ saveEntryAndEntities }
						isBusy={ isSavingPost }
						disabled={ ! canPlainSave }
						accessibleWhenDisabled
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
					accessibleWhenDisabled
					size="compact"
				/>
			</div>
		</div>
	);
}

export { QuickEditEditorHeader };
