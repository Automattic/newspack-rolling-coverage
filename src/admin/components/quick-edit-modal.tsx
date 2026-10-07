/**
 * External dependencies
 */
import classnames from 'classnames';
import {
	useMemo,
	useEffect,
	useLayoutEffect,
	useRef,
	useState,
	useCallback,
} from '@wordpress/element';
import { Modal, Popover } from '@wordpress/components';
import {
	BlockCanvas,
	BlockList,
	store as blockEditorStore,
} from '@wordpress/block-editor';
import {
	EditorProvider,
	PostTitle,
	store as editorStore,
} from '@wordpress/editor';
import { useEntityRecord, store as coreStore } from '@wordpress/core-data';
import {
	createRegistry,
	RegistryProvider,
	useDispatch,
	useRegistry,
} from '@wordpress/data';
import type { StoreDescriptor } from '@wordpress/data';
import { SnackbarNotices, store as noticesStore } from '@wordpress/notices';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { useAdminContext } from '../hooks/useAdminContext';
import { ensureEditorInitialized } from '../utils/block-registration';
import { LoadingState } from '../shared/loading-state';
import { ConfirmModal } from './confirm-modal';
import { quickEditPreferencesStore } from '../utils/quick-edit-preferences';
import { QuickEditSaveBar } from './quick-edit-save-bar';
import { QuickEditToolbar } from './quick-edit-toolbar';
import type { QuickEditModalProps, EntityRecord } from '../types';

/**
 * Gives the editor its own preferences store, with the block toolbar pinned.
 *
 * `EditorProvider` ignores `hasFixedToolbar` in its settings and reads the
 * `core.fixedToolbar` preference instead, so the preference is the only
 * switch for the floating per-block toolbar. The page's preferences store
 * cannot carry it: WordPress core installs the user's persistence layer on
 * every page that loads `wp-preferences`, so a write there reaches
 * `localStorage` and the user's saved preferences at once, pinning the
 * toolbar in their real post editor. A child registry with an in-memory
 * `core/preferences` store (`quickEditPreferencesStore`) shadows the page's;
 * the editor's preference reads and writes resolve to it, while core-data
 * and notices still fall through to the page. The trade-off: the user's
 * saved post-editor preferences (hidden block types, icon labels, focus
 * mode, caret behavior) do not apply inside Quick Edit, and editor controls
 * that write preferences, such as the link control's Advanced drawer, write
 * to this throwaway store instead.
 */
function useQuickEditRegistry() {
	const parent = useRegistry();
	const [ registry ] = useState( () => {
		const child = createRegistry( {}, parent );
		// `EditorProvider` re-creates both editor stores in its own
		// sub-registry, which copies their private selectors and actions from
		// this registry only, never from the page. Without these two here the
		// editor throws on mount.
		child.register( blockEditorStore as unknown as StoreDescriptor );
		child.register( editorStore );
		child.register( quickEditPreferencesStore );
		child
			.dispatch( quickEditPreferencesStore )
			.set( 'core', 'fixedToolbar', true );
		return child;
	} );
	return registry;
}

/**
 * Reports that the editor is ready to show. `EditorProvider` renders nothing
 * until its setup requests finish, so this mounts exactly then; a layout
 * effect lets the loading state go before the editor's first frame is painted.
 *
 * @param {Object}     props         Component props.
 * @param {() => void} props.onReady Called when the editor is ready.
 */
function EditorReadySignal( { onReady }: { onReady: () => void } ) {
	useLayoutEffect( () => {
		onReady();
	}, [ onReady ] );
	return null;
}

/**
 * Quick-edits an entry's title and content in the block editor without
 * leaving the entries list, laid out like P2's comment editor: one toolbar
 * row on top, the canvas, Cancel and Save at the bottom. The WordPress
 * `Modal` header is hidden once the editor is ready; every control is ours.
 *
 * - Save notices render inside the modal through `SnackbarNotices` from
 *   `@wordpress/notices`, which replaces `EditorSnackbars` (deprecated in
 *   WordPress 7.0, removed in 7.2). Its class name is what positions the
 *   snackbar above the footer.
 * - Closing is guarded when unsaved edits exist (detected via
 *   `useEntityRecord().hasEdits`, backed by core-data's
 *   `hasEditsForEntityRecord`). A small `Modal` around `ConfirmModal` asks
 *   before discarding, as the edit-anyway confirm does. Core's
 *   `ConfirmDialog` is not used: showing its title also shows its header's
 *   close button, and Enter on that button confirms the discard. The editor
 *   store's `isEditedPostDirty` selector is intentionally not used because
 *   `EditorProvider` runs in a sub-registry whose editor store is invisible
 *   to selectors outside the provider.
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
	const [ isEditorReady, setIsEditorReady ] = useState( false );
	const handleEditorReady = useCallback( () => setIsEditorReady( true ), [] );

	const { removeAllNotices } = useDispatch( noticesStore );
	const { clearEntityRecordEdits } = useDispatch( coreStore );

	useEffect( () => {
		ensureEditorInitialized();
	}, [] );

	const editorRegistry = useQuickEditRegistry();

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

	const isRecordLoaded = ! isResolving && !! typedRecord;
	const isReady = isRecordLoaded && isEditorReady;

	// While loading, the header's Close button is the frame's only tab stop,
	// and the header unmounts once the editor is ready. If that button had
	// focus, it would fall to the page behind the dialog, so it goes back to
	// the frame, where the Modal puts it on mount.
	const modalRef = useRef< HTMLDivElement >( null );
	useLayoutEffect( () => {
		if ( ! isReady ) {
			return;
		}
		const frame = modalRef.current?.querySelector< HTMLElement >(
			'.components-modal__frame'
		);
		if ( frame && ! frame.contains( frame.ownerDocument.activeElement ) ) {
			frame.focus();
		}
	}, [ isReady ] );

	// Until the editor is ready there is nothing of ours to click, so the
	// Modal keeps its own header and close button: an entry that never
	// resolves (deleted, or no longer editable) must still be closable.
	const loadingModalProps = isReady
		? {}
		: {
				title: __( 'Quick Edit', 'newspack-rolling-coverage' ),
				__experimentalHideHeader: false,
				isDismissible: true,
				shouldCloseOnEsc: true,
				onRequestClose: onClose,
			};

	return (
		<>
			<Modal
				ref={ modalRef }
				{ ...modalProps }
				className={ classnames( modalProps.className, {
					'is-ready': isReady,
				} ) }
				onRequestClose={ handleRequestClose }
				{ ...loadingModalProps }
			>
				{ ! isReady && (
					<div className="newspack-rolling-coverage-quick-edit__loading">
						<LoadingState
							label={ __(
								'Fetching entry…',
								'newspack-rolling-coverage'
							) }
						/>
					</div>
				) }
				{ isRecordLoaded && (
					<RegistryProvider value={ editorRegistry }>
						<EditorProvider
							post={ typedRecord }
							settings={ settings }
						>
							<EditorReadySignal onReady={ handleEditorReady } />
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
					</RegistryProvider>
				) }
			</Modal>
			{ showCloseConfirm && (
				<Modal
					title={ __(
						'Discard Changes?',
						'newspack-rolling-coverage'
					) }
					size="small"
					onRequestClose={ () => setShowCloseConfirm( false ) }
				>
					<ConfirmModal
						message={ __(
							'You have unsaved changes. Are you sure you want to close and discard them?',
							'newspack-rolling-coverage'
						) }
						confirmLabel={ __(
							'Discard Changes',
							'newspack-rolling-coverage'
						) }
						onConfirm={ async () => handleClose() }
						onClose={ () => setShowCloseConfirm( false ) }
					/>
				</Modal>
			) }
		</>
	);
}

export { QuickEditModal };
