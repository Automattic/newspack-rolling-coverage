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
import { createEntry } from '../utils/entries-api';
import { ErrorNotice } from '../shared/error-notice';
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
 * With `entryId` null it adds a new entry to `coverageId` instead, titled
 * "Add Entry". The editor needs a post to work on before anything is saved,
 * so the modal first asks the plugin's route for an empty auto-draft in the
 * coverage, as `post-new.php` does for a post, then opens it like any entry.
 * The footer offers Save Draft and Publish (or Submit for Review); the first
 * successful save closes the modal, since the entry now exists in the list,
 * and the editor's own save snackbar shows on the page. An auto-draft that
 * is never saved is deleted by core's auto-draft cleanup a week later.
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
function QuickEditModal( {
	entryId,
	coverageId,
	onClose,
	onSaved,
}: QuickEditModalProps ) {
	const config = useAdminContext();
	const isNew = entryId === null;
	const [ newEntryId, setNewEntryId ] = useState< number | null >( null );
	const [ createError, setCreateError ] = useState< string | null >( null );
	const recordId = entryId ?? newEntryId;
	const { record, isResolving, hasEdits } = useEntityRecord(
		'postType',
		config.postType,
		recordId ?? 0,
		{ enabled: recordId !== null }
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

	// A new entry is started once per opening. The request is not cancelled
	// on close: an auto-draft nobody opens is cleaned up by core.
	const hasStartedEntryRef = useRef( false );
	useEffect( () => {
		if (
			! isNew ||
			coverageId === undefined ||
			hasStartedEntryRef.current
		) {
			return;
		}
		hasStartedEntryRef.current = true;
		createEntry( config.restBaseUrls.restNamespace, coverageId ).then(
			( result ) => {
				if ( result.success && result.id ) {
					setNewEntryId( result.id );
				} else {
					setCreateError(
						result.error ||
							__(
								'Failed to create entry',
								'newspack-rolling-coverage'
							)
					);
				}
			}
		);
	}, [ isNew, coverageId, config.restBaseUrls.restNamespace ] );

	const editorRegistry = useQuickEditRegistry();

	const handleClose = useCallback( () => {
		removeAllNotices( 'snackbar' );
		if ( recordId !== null ) {
			clearEntityRecordEdits( 'postType', config.postType, recordId );
		}
		onClose();
	}, [
		removeAllNotices,
		clearEntityRecordEdits,
		config.postType,
		recordId,
		onClose,
	] );

	const handleRequestClose = useCallback( () => {
		if ( hasEdits ) {
			setShowCloseConfirm( true );
			return;
		}
		handleClose();
	}, [ hasEdits, handleClose ] );

	// An existing entry stays open after a save; a new one is in the list
	// now, so the modal closes. The editor's own "Draft saved." or "Entry
	// published." snackbar arrives a tick after the save finishes and then
	// shows on the page, so unlike Cancel this leaves the notices alone.
	const handleSaved = useCallback( () => {
		if ( isNew ) {
			onClose();
		}
		onSaved();
	}, [ isNew, onClose, onSaved ] );

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

	const title = isNew
		? __( 'Add Entry', 'newspack-rolling-coverage' )
		: __( 'Quick Edit', 'newspack-rolling-coverage' );

	const modalProps = {
		contentLabel: title,
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

	// A new entry opens with the caret in its title, as the post editor opens
	// a new post. `PostTitle` focuses itself only while nothing has focus, and
	// the frame has it by the time the title mounts in the canvas, so the
	// title's own focus method is called as it mounts instead. Once only:
	// `PostTitle` builds that handle anew on every render, so this ref is
	// called again on each keystroke, and focusing then would pull the caret
	// back out of the paragraph Enter moved it to.
	const hasFocusedNewEntryTitleRef = useRef( false );
	const focusNewEntryTitle = useCallback(
		( postTitle: { focus: () => void } | null ) => {
			if ( isNew && postTitle && ! hasFocusedNewEntryTitleRef.current ) {
				hasFocusedNewEntryTitleRef.current = true;
				postTitle.focus();
			}
		},
		[ isNew ]
	);

	// Until the editor is ready there is nothing of ours to click, so the
	// Modal keeps its own header and close button: an entry that never
	// resolves (deleted, or no longer editable), or a new entry that could
	// not be started, must still be closable.
	const loadingModalProps = isReady
		? {}
		: {
				title,
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
						{ createError ? (
							<ErrorNotice message={ createError } />
						) : (
							<LoadingState
								label={
									isNew
										? __(
												'Preparing entry…',
												'newspack-rolling-coverage'
											)
										: __(
												'Fetching entry…',
												'newspack-rolling-coverage'
											)
								}
							/>
						) }
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
										<PostTitle ref={ focusNewEntryTitle } />
									</div>
									<BlockList
										className={ `wp-block-post-content${ layoutClassName }` }
										layout={ blockListLayout }
									/>
								</BlockCanvas>
							</div>
							<div className="newspack-rolling-coverage-quick-edit__footer">
								<QuickEditSaveBar
									isNew={ isNew }
									onClose={ handleRequestClose }
									onSaved={ handleSaved }
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
