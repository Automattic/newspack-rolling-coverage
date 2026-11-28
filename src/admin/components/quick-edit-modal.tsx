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
import { store as blockEditorStore } from '@wordpress/block-editor';
import { EditorProvider, store as editorStore } from '@wordpress/editor';
import { useEntityRecord, store as coreStore } from '@wordpress/core-data';
import {
	createRegistry,
	RegistryProvider,
	useDispatch,
	useRegistry,
} from '@wordpress/data';
import type { StoreDescriptor } from '@wordpress/data';
import { SnackbarNotices, store as noticesStore } from '@wordpress/notices';
import useConfirmDialog from 'newspack-components/dist/esm/hooks/use-confirm-dialog';
import {
	store as keyboardShortcutsStore,
	useShortcut,
} from '@wordpress/keyboard-shortcuts';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { useAdminContext } from '../hooks/useAdminContext';
import { ensureEditorInitialized } from '../utils/block-registration';
import { createEntry, deleteEntry, isEntryLocked } from '../utils/entries-api';
import { ErrorNotice } from '../shared/error-notice';
import { LoadingState } from '../shared/loading-state';
import { quickEditPreferencesStore } from '../utils/quick-edit-preferences';
import { QuickEditCanvas } from './quick-edit-canvas';
import { QuickEditCommentLayout } from './quick-edit-comment-layout';
import { QuickEditEditorLayout } from './quick-edit-editor-layout';
import type {
	QuickEditModalProps,
	QuickEditCanvasProps,
	EntityRecord,
	CoreSelectors,
} from '../types';

/**
 * Gives the editor its own preferences store, with the block toolbar pinned
 * or floating as the layout needs.
 *
 * `EditorProvider` ignores `hasFixedToolbar` in its settings and reads the
 * `core.fixedToolbar` preference instead, so the preference is the only
 * switch for the per-block toolbar. The Summary's layout pins it in its
 * toolbar row; an entry's layout has nowhere to pin it, so it floats there
 * even for a user who turned on Top Toolbar in the post editor, who would
 * otherwise get no block toolbar at all. The page's preferences store cannot
 * carry the flag: WordPress core installs the user's persistence layer on
 * every page that loads `wp-preferences`, so a write there reaches
 * `localStorage` and the user's saved preferences at once, changing the
 * toolbar in their real post editor. A child registry with an in-memory
 * `core/preferences` store (`quickEditPreferencesStore`) shadows the page's;
 * the editor's preference reads and writes resolve to it, while core-data
 * and notices still fall through to the page. The trade-off: the user's
 * saved post-editor preferences (hidden block types, icon labels, focus
 * mode, caret behavior) do not apply inside Quick Edit, and editor controls
 * that write preferences, such as the link control's Advanced drawer or the
 * sidebar panels' open state, write to this throwaway store instead.
 *
 * @param {boolean} hasFixedToolbar Whether to pin the block toolbar.
 */
function useQuickEditRegistry( hasFixedToolbar: boolean ) {
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
			.set( 'core', 'fixedToolbar', hasFixedToolbar );
		return child;
	} );
	return registry;
}

/**
 * Registers the editor's save shortcut (`core/editor/save`, Cmd+S or
 * Ctrl+S) while Quick Edit is open. `EditorProvider` handles the shortcut
 * but leaves registering it to the post editor's layout, so without this it
 * never fires and the browser opens its Save Page dialog instead. The
 * handler here keeps that dialog away while the entry is still loading too.
 */
function useSaveShortcut() {
	const { registerShortcut, unregisterShortcut } = useDispatch(
		keyboardShortcutsStore
	);

	useEffect( () => {
		registerShortcut( {
			name: 'core/editor/save',
			category: 'global',
			description: __(
				'Save your changes.',
				'newspack-rolling-coverage'
			),
			keyCombination: { modifier: 'primary', character: 's' },
		} );
		return () => {
			unregisterShortcut( 'core/editor/save' );
		};
	}, [ registerShortcut, unregisterShortcut ] );

	useShortcut( 'core/editor/save', ( event ) => {
		event.preventDefault();
	} );
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
 * Quick-edits an entry in the block editor without leaving the entries
 * list. An entry opens in a layout like the post editor's
 * (`QuickEditEditorLayout`): the editor's own save and publish buttons in
 * the header, and a settings sidebar with Entry and Block tabs. The
 * coverage's Summary opens in a layout like P2's comment editor
 * (`QuickEditCommentLayout`): one toolbar row on top, the canvas, Cancel and
 * Save at the bottom. This component holds what both share: loading or
 * starting the entry, the editor and its registry, the close guard and the
 * notices. The WordPress `Modal` header is hidden once the editor is ready;
 * every control is the layout's.
 *
 * With `entryId` null it adds a new entry to `coverageId` instead, titled
 * "Add Entry", or the coverage's Summary with `isSummary`, titled "Add
 * Summary". The editor needs a post to work on before anything is saved,
 * so the modal first asks the plugin's route for an empty auto-draft in the
 * coverage, as `post-new.php` does for a post, then opens it like any entry.
 * Saving keeps the modal open, except a new Summary's first successful
 * save, which closes it since the Summary is in the list now, and the
 * editor's own save snackbar then shows on the page.
 * Closing a new entry or Summary before its first save deletes the
 * auto-draft, which is still empty on the server; one left behind another
 * way (the tab closed, say) is deleted by core's auto-draft cleanup a week
 * later.
 *
 * - Save notices render inside the modal through `SnackbarNotices` from
 *   `@wordpress/notices`, which replaces `EditorSnackbars` (deprecated in
 *   WordPress 7.0, removed in 7.2). Its class name is what positions the
 *   snackbar.
 * - Closing is guarded when unsaved edits exist (detected via
 *   `useEntityRecord().hasEdits`, backed by core-data's
 *   `hasEditsForEntityRecord`). newspack-components' `useConfirmDialog`
 *   asks before discarding, set up as its unsaved-changes dialog is
 *   elsewhere in Newspack: no title bar, with Cancel focused. The editor
 *   store's `isEditedPostDirty` selector is intentionally not used because
 *   `EditorProvider` runs in a sub-registry whose editor store is invisible
 *   to selectors outside the provider.
 * - The Modal's own close paths (dismiss button, Escape, click outside) are
 *   off so its exit animation can't fire before the guard intercepts. The
 *   layouts' close buttons go through the guard instead.
 * - `EditorProvider` stays inside the Modal: its own helper modals
 *   (media editor, pattern rename and duplicate) must
 *   nest in this one, or opening them closes Quick Edit.
 * - The `Popover.Slot` inside the provider keeps the editor's popovers
 *   (block library, document overview, block toolbar, inspector, sidebar
 *   dropdowns) within the modal frame and its focus trap instead of the
 *   body-level fallback container.
 *
 * @param {QuickEditModalProps} props Component props.
 */
function QuickEditModal( {
	entryId,
	entry = null,
	actions = [],
	coverageId,
	isSummary = false,
	onClose,
	onSaved,
}: QuickEditModalProps ) {
	const config = useAdminContext();
	const isNew = entryId === null;
	const isSummaryLayout = isSummary || Boolean( entry?.isSummary );
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
		createEntry(
			config.restBaseUrls.restNamespace,
			coverageId,
			isSummary
		).then( ( result ) => {
			if ( result.success && result.id ) {
				setNewEntryId( result.id );
			} else if ( isSummary ) {
				setCreateError(
					result.error ||
						__(
							'Failed to create summary',
							'newspack-rolling-coverage'
						)
				);
			} else {
				setCreateError(
					result.error ||
						__(
							'Failed to create entry',
							'newspack-rolling-coverage'
						)
				);
			}
		} );
	}, [ isNew, isSummary, coverageId, config.restBaseUrls.restNamespace ] );

	const editorRegistry = useQuickEditRegistry( isSummaryLayout );
	useSaveShortcut();

	// A new entry that was never saved is still an empty auto-draft on the
	// server, whatever was typed, so closing it deletes it rather than
	// leaving it for core's weekly cleanup. Once saved it is a draft or
	// published, and stays. Nothing waits on the request, and a refusal (the
	// coverage ended meanwhile, say) leaves it to core.
	const isUnsavedNewEntry =
		isNew && ( ! typedRecord || typedRecord.status === 'auto-draft' );
	const handleClose = useCallback( () => {
		removeAllNotices( 'snackbar' );
		if ( recordId !== null ) {
			clearEntityRecordEdits( 'postType', config.postType, recordId );
		}
		if ( isUnsavedNewEntry && recordId !== null ) {
			deleteEntry( config, recordId, true );
		}
		onClose();
	}, [
		removeAllNotices,
		clearEntityRecordEdits,
		config,
		isUnsavedNewEntry,
		recordId,
		onClose,
	] );

	const { confirmDialog, requestConfirm } = useConfirmDialog( {
		when: hasEdits,
		message: __(
			'You have unsaved changes that will be lost. Discard changes?',
			'newspack-rolling-coverage'
		),
		confirmButtonText: __( 'Discard Changes', 'newspack-rolling-coverage' ),
		title: __( 'Unsaved changes', 'newspack-rolling-coverage' ),
		hideTitle: true,
		focusOnMount: 'firstContentElement',
	} );

	const handleRequestClose = useCallback(
		() => requestConfirm( handleClose ),
		[ requestConfirm, handleClose ]
	);

	// Saving keeps Quick Edit open and refreshes the entry's row, except a
	// new Summary's first save, which closes it once it is in the list.
	// Edits made while that save was running weren't sent, so they keep it
	// open. The editor's own "Draft saved." or "Entry published." snackbar
	// arrives a tick after the save finishes and then shows on the page, so
	// unlike Cancel this close leaves the notices alone.
	const pageRegistry = useRegistry();
	const handleSaved = useCallback( () => {
		const hasUnsavedEdits =
			recordId !== null &&
			(
				pageRegistry.select( coreStore ) as unknown as CoreSelectors
			 ).hasEditsForEntityRecord( 'postType', config.postType, recordId );
		if ( isNew && isSummaryLayout && ! hasUnsavedEdits ) {
			onClose();
			onSaved();
		} else if ( recordId !== null ) {
			onSaved( recordId );
		}
	}, [
		isNew,
		isSummaryLayout,
		recordId,
		pageRegistry,
		config.postType,
		onClose,
		onSaved,
	] );

	const settings = useMemo(
		() =>
			( {
				...config.blockEditorSettings,
				supportsTemplateMode: false,
			} ) as Record< string, unknown >,
		[ config.blockEditorSettings ]
	);

	let title: string = __( 'Quick Edit', 'newspack-rolling-coverage' );

	if ( isNew ) {
		title = isSummary
			? __( 'Add Summary', 'newspack-rolling-coverage' )
			: __( 'Add Entry', 'newspack-rolling-coverage' );
	}

	let loadingLabel: string = __(
		'Fetching entry…',
		'newspack-rolling-coverage'
	);

	if ( isNew ) {
		loadingLabel = isSummary
			? __( 'Preparing summary…', 'newspack-rolling-coverage' )
			: __( 'Preparing entry…', 'newspack-rolling-coverage' );
	}

	const modalProps = {
		contentLabel: title,
		shouldCloseOnClickOutside: false,
		shouldCloseOnEsc: false,
		isDismissible: false,
		isFullScreen: ! isSummaryLayout,
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

	const canvas = (
		<QuickEditCanvas
			styles={ settings.styles as unknown[] }
			supportsLayout={ Boolean( settings.supportsLayout ) }
			themeLayout={
				(
					settings.__experimentalFeatures as
						| { layout?: QuickEditCanvasProps[ 'themeLayout' ] }
						| undefined
				 )?.layout
			}
			titleRef={ focusNewEntryTitle }
		/>
	);

	return (
		<>
			<Modal
				ref={ modalRef }
				{ ...modalProps }
				className={ classnames( modalProps.className, {
					'is-comment-layout': isSummaryLayout,
					'is-editor-layout': ! isSummaryLayout,
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
							<LoadingState label={ loadingLabel } />
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
							{ isSummaryLayout ? (
								<QuickEditCommentLayout
									canvas={ canvas }
									isNew={ isNew }
									onClose={ handleRequestClose }
									onSaved={ handleSaved }
								/>
							) : (
								<QuickEditEditorLayout
									canvas={ canvas }
									entry={ entry }
									actions={ actions }
									canChangeStatus={
										! entry || ! isEntryLocked( entry )
									}
									canNotify={
										config.pushNotifications.isConfigured
									}
									onClose={ handleRequestClose }
									onSaved={ handleSaved }
								/>
							) }
							<SnackbarNotices className="components-editor-notices__snackbar" />
							<Popover.Slot />
						</EditorProvider>
					</RegistryProvider>
				) }
			</Modal>
			{ confirmDialog }
		</>
	);
}

export { QuickEditModal };
