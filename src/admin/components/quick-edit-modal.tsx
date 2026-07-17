/**
 * External dependencies
 */
import { useMemo, useEffect, useState, useCallback } from '@wordpress/element';
import {
	Modal,
	Spinner,
	Button,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalConfirmDialog as ConfirmDialog,
} from '@wordpress/components';
import { BlockCanvas, BlockInspector } from '@wordpress/block-editor';
import { EditorProvider, EditorSnackbars, PostTitle } from '@wordpress/editor';
import { useEntityRecord, store as coreStore } from '@wordpress/core-data';
import { useDispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import { closeSmall, drawerLeft, drawerRight } from '@wordpress/icons';
import { __, isRTL } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { useAdminContext } from '../hooks/useAdminContext';
import { ensureEditorInitialized } from '../utils/block-registration';
import { QuickEditSaveBar } from './quick-edit-save-bar';
import type { QuickEditModalProps, EntityRecord } from '../types';

/**
 * Renders a modal containing the WordPress post editor for quick-editing
 * an entry's title and content without leaving the admin page.
 *
 * - Editor notices (success/error snackbars) are rendered inside the
 *   `EditorProvider` via `<EditorSnackbars />`.
 * - Closing is guarded when unsaved edits exist (detected via
 *   `useEntityRecord().hasEdits`, backed by core-data's
 *   `hasEditsForEntityRecord`). A `ConfirmDialog` prompts before
 *   discarding. The editor store's `isEditedPostDirty` selector is
 *   intentionally not used because `EditorProvider` runs in a sub-registry
 *   whose editor store is invisible to selectors outside the provider.
 * - The built-in Modal close button is disabled (`isDismissible={ false }`)
 *   to prevent the exit animation from firing before the guard can
 *   intercept. A custom close button is provided via `headerActions`.
 *
 * @param {QuickEditModalProps} props Component props.
 */
function QuickEditModal( { entryId, onClose, onSaved }: QuickEditModalProps ) {
	const config = useAdminContext();
	const { record, isResolving, hasEdits } = useEntityRecord(
		'postType',
		config.postType,
		entryId
	);
	const typedRecord = record as EntityRecord | null;
	const [ isSidebarOpen, setIsSidebarOpen ] = useState( true );
	const [ showCloseConfirm, setShowCloseConfirm ] = useState( false );

	const { removeAllNotices } = useDispatch( noticesStore );
	const { clearEntityRecordEdits } = useDispatch( coreStore );

	useEffect( () => {
		ensureEditorInitialized();
	}, [] );

	const handleClose = useCallback( () => {
		removeAllNotices( 'snackbar' );
		clearEntityRecordEdits( 'postType', config.postType, entryId );
		onClose();
	}, [
		removeAllNotices,
		clearEntityRecordEdits,
		config.postType,
		entryId,
		onClose,
	] );

	const handleRequestClose = useCallback( () => {
		if ( hasEdits ) {
			setShowCloseConfirm( true );
			return;
		}
		handleClose();
	}, [ hasEdits, handleClose ] );

	const settings = useMemo(
		() =>
			( {
				...config.blockEditorSettings,
				supportsTemplateMode: false,
			} ) as Record< string, unknown >,
		[ config.blockEditorSettings ]
	);

	if ( isResolving || ! typedRecord ) {
		return (
			<Modal
				title={ __( 'Quick Edit', 'newspack-rolling-coverage' ) }
				onRequestClose={ onClose }
			>
				<Spinner />
			</Modal>
		);
	}

	return (
		<>
			<Modal
				title={ __( 'Quick Edit', 'newspack-rolling-coverage' ) }
				onRequestClose={ handleRequestClose }
				shouldCloseOnClickOutside={ false }
				shouldCloseOnEsc={ false }
				isDismissible={ false }
				headerActions={
					<Button
						icon={ closeSmall }
						label={ __( 'Close', 'newspack-rolling-coverage' ) }
						onClick={ handleRequestClose }
						size="compact"
					/>
				}
				className="newspack-rolling-coverage-quick-edit"
				overlayClassName="newspack-rolling-coverage-quick-edit-overlay"
				size="large"
			>
				<EditorProvider post={ typedRecord } settings={ settings }>
					<EditorSnackbars />
					<div className="newspack-rolling-coverage-quick-edit-layout">
						<div className="newspack-rolling-coverage-quick-edit-main">
							<PostTitle />
							<div className="newspack-rolling-coverage-quick-edit-canvas">
								<BlockCanvas
									height="100%"
									styles={ settings.styles as unknown[] }
								/>
							</div>
						</div>
						{ isSidebarOpen && (
							<aside className="newspack-rolling-coverage-quick-edit-sidebar">
								<BlockInspector />
							</aside>
						) }
					</div>
					<div className="newspack-rolling-coverage-quick-edit-toolbar">
						<Button
							icon={ isRTL() ? drawerRight : drawerLeft }
							label={
								isSidebarOpen
									? __(
											'Hide sidebar',
											'newspack-rolling-coverage'
									  )
									: __(
											'Show sidebar',
											'newspack-rolling-coverage'
									  )
							}
							onClick={ () =>
								setIsSidebarOpen( ( prev ) => ! prev )
							}
							variant="tertiary"
						/>
						<QuickEditSaveBar
							onClose={ handleRequestClose }
							onSaved={ onSaved }
						/>
					</div>
				</EditorProvider>
			</Modal>
			<ConfirmDialog
				isOpen={ showCloseConfirm }
				onConfirm={ () => {
					setShowCloseConfirm( false );
					handleClose();
				} }
				onCancel={ () => setShowCloseConfirm( false ) }
				confirmButtonText={ __(
					'Discard changes',
					'newspack-rolling-coverage'
				) }
			>
				{ __(
					'You have unsaved changes. Are you sure you want to close and discard them?',
					'newspack-rolling-coverage'
				) }
			</ConfirmDialog>
		</>
	);
}

export { QuickEditModal };
