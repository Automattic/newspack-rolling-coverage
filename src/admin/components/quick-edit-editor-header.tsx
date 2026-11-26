/**
 * External dependencies
 */
import type { ComponentType } from 'react';
import {
	createInterpolateElement,
	useCallback,
	useRef,
} from '@wordpress/element';
import {
	Button,
	DropdownMenu,
	MenuGroup,
	MenuItem,
} from '@wordpress/components';
import {
	PostPublishButton,
	PostPublishButtonLabel,
	PostSavedState,
	store as editorStore,
} from '@wordpress/editor';
import { store as coreStore } from '@wordpress/core-data';
import { useDispatch, useRegistry, useSelect } from '@wordpress/data';
import { useShortcut } from '@wordpress/keyboard-shortcuts';
import { chevronDown, close, drawerLeft, drawerRight } from '@wordpress/icons';
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
const PublishButtonLabel = PostPublishButtonLabel as unknown as ComponentType;

/**
 * The header of an entry's Quick Edit, laid out like the post editor's:
 * document tools on the left; on the right the save buttons, the settings
 * sidebar toggle and a close button.
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
 * A save started from these buttons closes Quick Edit once it succeeds. The
 * primary button is a split button whose menu offers the same save "and
 * keep editing", which leaves it open, as does the editor's save shortcut
 * (`core/editor/save`, Cmd+S or Ctrl+S, registered by `QuickEditModal` and
 * handled by `EditorProvider`), which saves with the entry's current status.
 * Each way of saving sets whether the next save closes, and a save takes
 * that setting when it starts.
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
	const {
		isEditorReady,
		isDirty,
		hasNonPostEntityChanges,
		isSavingLocked,
		canPublishButtonSave,
	} = useSelect( ( select ) => {
		const editor = select( editorStore ) as unknown as EditorSelectors & {
			isPostSavingLocked: () => boolean;
			isEditedPostPublishable: () => boolean;
		};
		return {
			isEditorReady: editor.__unstableIsEditorReady?.() ?? false,
			isDirty: editor.isEditedPostDirty(),
			hasNonPostEntityChanges: editor.hasNonPostEntityChanges(),
			isSavingLocked: editor.isPostSavingLocked(),
			canPublishButtonSave:
				! editor.isPostSavingLocked() &&
				editor.isEditedPostSaveable() &&
				editor.isEditedPostPublishable(),
		};
	}, [] );

	const closeOnNextSaveRef = useRef( false );
	const closeOnSaveRef = useRef( false );
	const handleStarted = useCallback( () => {
		closeOnSaveRef.current = closeOnNextSaveRef.current;
		closeOnNextSaveRef.current = false;
	}, [] );
	const handleSaved = useCallback(
		() => onSaved( closeOnSaveRef.current ),
		[ onSaved ]
	);
	const isSavingPost = useQuickEditSaveResult(
		handleSaved,
		undefined,
		handleStarted
	);

	useShortcut( 'core/editor/save', () => {
		closeOnNextSaveRef.current = false;
	} );

	const publishButtonRef = useRef< HTMLSpanElement >( null );
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
	const canKeepEditing = isPublishButton
		? ! isSavingPost && canPublishButtonSave
		: canPlainSave;

	const saveAndKeepEditing = () => {
		if ( isPublishButton ) {
			// Core's button picks the status (publish, pending, future). Its
			// click marks the save as closing, so staying is set after it.
			publishButtonRef.current
				?.querySelector< HTMLButtonElement >( 'button' )
				?.click();
		} else {
			saveEntryAndEntities();
		}
		closeOnNextSaveRef.current = false;
	};

	return (
		<div className="newspack-rolling-coverage-quick-edit__header">
			<QuickEditDocumentTools />
			<div className="newspack-rolling-coverage-quick-edit__header-actions">
				<div
					className="newspack-rolling-coverage-quick-edit__save-actions"
					onClickCapture={ () => {
						closeOnNextSaveRef.current = true;
					} }
				>
					{ isPublishButton && <SavedState /> }
					<div className="newspack-rolling-coverage-quick-edit__split-button">
						{ isPublishButton ? (
							<span ref={ publishButtonRef }>
								<PublishButton />
							</span>
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
						<DropdownMenu
							icon={ chevronDown }
							label={ __(
								'More save options',
								'newspack-rolling-coverage'
							) }
							popoverProps={ { placement: 'bottom-end' } }
							toggleProps={ {
								className:
									'newspack-rolling-coverage-quick-edit__split-button-toggle',
								variant: 'primary',
								size: 'compact',
								disabled: ! canKeepEditing,
								accessibleWhenDisabled: true,
							} }
						>
							{ ( { onClose: closeMenu } ) => (
								<MenuGroup>
									<MenuItem
										onClick={ () => {
											closeMenu();
											saveAndKeepEditing();
										} }
									>
										{ isPublishButton
											? createInterpolateElement(
													/* translators: <label />: the save button's label, such as Publish or Save. */
													__(
														'<label /> and keep editing',
														'newspack-rolling-coverage'
													),
													{
														label: (
															<PublishButtonLabel />
														),
													}
												)
											: __(
													'Save and keep editing',
													'newspack-rolling-coverage'
												) }
									</MenuItem>
								</MenuGroup>
							) }
						</DropdownMenu>
					</div>
				</div>
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
