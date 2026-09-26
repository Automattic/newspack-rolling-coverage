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
import {
	BlockCanvas,
	BlockInspector,
	BlockList,
} from '@wordpress/block-editor';
import { EditorProvider, EditorSnackbars, PostTitle } from '@wordpress/editor';
import { useEntityRecord, store as coreStore } from '@wordpress/core-data';
import { RegistryProvider, useDispatch, useRegistry } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import { drawerLeft, drawerRight } from '@wordpress/icons';
import { __, isRTL } from '@wordpress/i18n';
import { Stack } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import { useAdminContext } from '../hooks/useAdminContext';
import { ensureEditorInitialized } from '../utils/block-registration';
import { QuickEditSaveBar } from './quick-edit-save-bar';
import type { QuickEditModalProps, EntityRecord } from '../types';

/**
 * Reports the registry it renders in, so UI outside `EditorProvider` can use
 * the editor's sub-registry.
 *
 * @param {Object}   props            Component props.
 * @param {Function} props.onRegistry Called with the current registry.
 */
function EditorRegistryBridge( {
	onRegistry,
}: {
	onRegistry: ( registry: ReturnType< typeof useRegistry > ) => void;
} ) {
	const registry = useRegistry();
	useEffect( () => {
		onRegistry( registry );
	}, [ registry, onRegistry ] );
	return null;
}

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
 *   intercept. Cancel in the header goes through the guard instead.
 * - `EditorProvider` stays inside the Modal: its own helper modals
 *   (keyboard shortcuts, pattern rename and duplicate, media editor) must
 *   nest in this one, or opening them closes Quick Edit. The header's Cancel
 *   and Save sit outside the provider, so `EditorRegistryBridge` hands them
 *   the editor's sub-registry.
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
	const [ editorRegistry, setEditorRegistry ] = useState< ReturnType<
		typeof useRegistry
	> | null >( null );

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

	const layoutClassName = settings.supportsLayout
		? ' is-layout-constrained has-global-padding'
		: '';
	const blockListLayout = settings.supportsLayout
		? { type: 'constrained' }
		: undefined;

	if ( isResolving || ! typedRecord ) {
		return (
			<Modal
				title={ __( 'Quick Edit', 'newspack-rolling-coverage' ) }
				onRequestClose={ onClose }
				className="newspack-rolling-coverage-quick-edit"
				overlayClassName="newspack-rolling-coverage-quick-edit-overlay"
				isFullScreen
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
					<Stack direction="row" gap="sm" align="center">
						<Button
							icon={ isRTL() ? drawerLeft : drawerRight }
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
							isPressed={ isSidebarOpen }
							onClick={ () =>
								setIsSidebarOpen( ( prev ) => ! prev )
							}
							size="compact"
						/>
						{ editorRegistry && (
							<RegistryProvider value={ editorRegistry }>
								<QuickEditSaveBar
									onClose={ handleRequestClose }
									onSaved={ onSaved }
								/>
							</RegistryProvider>
						) }
					</Stack>
				}
				className="newspack-rolling-coverage-quick-edit"
				overlayClassName="newspack-rolling-coverage-quick-edit-overlay"
				isFullScreen
			>
				<EditorProvider post={ typedRecord } settings={ settings }>
					<EditorRegistryBridge onRegistry={ setEditorRegistry } />
					<EditorSnackbars />
					<div className="newspack-rolling-coverage-quick-edit-layout">
						<div className="newspack-rolling-coverage-quick-edit-main">
							<div className="newspack-rolling-coverage-quick-edit-canvas">
								<BlockCanvas
									height="100%"
									styles={ settings.styles as unknown[] }
								>
									<div
										className={ `editor-visual-editor__post-title-wrapper${ layoutClassName }` }
									>
										<PostTitle />
									</div>
									<BlockList
										className={ `wp-block-post-content${ layoutClassName }` }
										layout={ blockListLayout }
									/>
								</BlockCanvas>
							</div>
						</div>
						{ isSidebarOpen && (
							<aside className="newspack-rolling-coverage-quick-edit-sidebar">
								<BlockInspector />
							</aside>
						) }
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
