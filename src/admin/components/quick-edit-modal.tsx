/**
 * External dependencies
 */
import { useMemo, useEffect, useState, useCallback } from '@wordpress/element';
import {
	Modal,
	Popover,
	Spinner,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalConfirmDialog as ConfirmDialog,
} from '@wordpress/components';
import { BlockCanvas, BlockList } from '@wordpress/block-editor';
import { EditorProvider, PostTitle } from '@wordpress/editor';
import { useEntityRecord, store as coreStore } from '@wordpress/core-data';
import { useDispatch, useRegistry } from '@wordpress/data';
import { SnackbarNotices, store as noticesStore } from '@wordpress/notices';
import { store as preferencesStore } from '@wordpress/preferences';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { useAdminContext } from '../hooks/useAdminContext';
import { ensureEditorInitialized } from '../utils/block-registration';
import { QuickEditSaveBar } from './quick-edit-save-bar';
import { QuickEditToolbar } from './quick-edit-toolbar';
import type {
	QuickEditModalProps,
	EntityRecord,
	PreferencesSelectors,
	PreferencesActions,
} from '../types';

/**
 * Pins the block toolbar to the toolbar row for as long as the modal is
 * mounted.
 *
 * `EditorProvider` ignores `hasFixedToolbar` in its settings and reads the
 * `core.fixedToolbar` preference instead, so the preference is the only
 * switch for the floating per-block toolbar. It lives in the parent
 * registry, which the provider's sub-registry falls through to. This admin
 * page loads no preferences persistence layer, so the user's real editor
 * preference is never written; the previous value is restored on unmount
 * all the same.
 */
function useFixedToolbarPreference() {
	const registry = useRegistry();
	useEffect( () => {
		const { get } = registry.select(
			preferencesStore
		) as unknown as PreferencesSelectors;
		const { set } = registry.dispatch(
			preferencesStore
		) as unknown as PreferencesActions;
		const previous = get( 'core', 'fixedToolbar' );
		set( 'core', 'fixedToolbar', true );
		return () => {
			set( 'core', 'fixedToolbar', previous );
		};
	}, [ registry ] );
}

/**
 * Quick-edits an entry's title and content in the block editor without
 * leaving the entries list, laid out like P2's comment editor: one toolbar
 * row on top, the canvas, Cancel and Save at the bottom. The WordPress
 * `Modal` header is hidden once the record loads; every control is ours.
 *
 * - Save notices render inside the modal through `SnackbarNotices` from
 *   `@wordpress/notices`, which replaces `EditorSnackbars` (deprecated in
 *   WordPress 7.0, removed in 7.2). Its class name is what positions the
 *   snackbar above the footer.
 * - Closing is guarded when unsaved edits exist (detected via
 *   `useEntityRecord().hasEdits`, backed by core-data's
 *   `hasEditsForEntityRecord`). A `ConfirmDialog` prompts before
 *   discarding. The editor store's `isEditedPostDirty` selector is
 *   intentionally not used because `EditorProvider` runs in a sub-registry
 *   whose editor store is invisible to selectors outside the provider.
 * - The Modal's own close paths (dismiss button, Escape, click outside) are
 *   off so its exit animation can't fire before the guard intercepts.
 *   Cancel in the footer goes through the guard instead.
 * - `EditorProvider` stays inside the Modal: its own helper modals
 *   (media editor, pattern rename and duplicate) must
 *   nest in this one, or opening them closes Quick Edit.
 * - The `Popover.Slot` inside the provider keeps the toolbar's popovers
 *   (block library, document overview, inspector) within the modal frame
 *   and its focus trap instead of the body-level fallback container.
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
	const [ showCloseConfirm, setShowCloseConfirm ] = useState( false );

	const { removeAllNotices } = useDispatch( noticesStore );
	const { clearEntityRecordEdits } = useDispatch( coreStore );

	useEffect( () => {
		ensureEditorInitialized();
	}, [] );

	useFixedToolbarPreference();

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

	const modalProps = {
		contentLabel: __( 'Quick Edit', 'newspack-rolling-coverage' ),
		shouldCloseOnClickOutside: false,
		shouldCloseOnEsc: false,
		isDismissible: false,
		__experimentalHideHeader: true,
		className: 'newspack-rolling-coverage-quick-edit',
		overlayClassName: 'newspack-rolling-coverage-quick-edit-overlay',
	};

	// While the record loads there is nothing of ours to click, so this
	// render keeps the Modal's own header and close button: an entry that
	// never resolves (deleted, or no longer editable) must still be
	// closable.
	if ( isResolving || ! typedRecord ) {
		return (
			<Modal
				{ ...modalProps }
				title={ __( 'Quick Edit', 'newspack-rolling-coverage' ) }
				__experimentalHideHeader={ false }
				isDismissible
				shouldCloseOnEsc
				onRequestClose={ onClose }
			>
				<div className="newspack-rolling-coverage-quick-edit__loading">
					<Spinner />
				</div>
			</Modal>
		);
	}

	return (
		<>
			<Modal { ...modalProps } onRequestClose={ handleRequestClose }>
				<EditorProvider post={ typedRecord } settings={ settings }>
					<QuickEditToolbar />
					<div className="newspack-rolling-coverage-quick-edit__canvas">
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
					<div className="newspack-rolling-coverage-quick-edit__footer">
						<QuickEditSaveBar
							onClose={ handleRequestClose }
							onSaved={ onSaved }
						/>
					</div>
					<SnackbarNotices className="components-editor-notices__snackbar" />
					<Popover.Slot />
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
					'Discard Changes',
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
