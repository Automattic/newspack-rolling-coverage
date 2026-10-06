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
	store as blockEditorStore,
} from '@wordpress/block-editor';
import { EditorProvider, EditorSnackbars, PostTitle } from '@wordpress/editor';
import { useEntityRecord, store as coreStore } from '@wordpress/core-data';
import {
	RegistryProvider,
	useDispatch,
	useRegistry,
	useSelect,
} from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import { drawerLeft, drawerRight } from '@wordpress/icons';
import { __, isRTL } from '@wordpress/i18n';
import { Stack, Tabs } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import { useAdminContext } from '../hooks/useAdminContext';
import { ensureEditorInitialized } from '../utils/block-registration';
import { QuickEditSaveBar } from './quick-edit-save-bar';
import { QuickEditEntryPanel } from './quick-edit-entry-panel';
import { QuickEditSettingsPanels } from './quick-edit-settings-panels';
import type {
	Action,
	Entry,
	QuickEditModalProps,
	EntityRecord,
} from '../types';

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
 * The Quick Edit sidebar: Entry and Block tabs, switching between them as
 * blocks are selected and deselected, as the post editor's sidebar does.
 *
 * @param {Object}          props                 Component props.
 * @param {Entry}           props.entry           The entry's row in the list.
 * @param {Action<Entry>[]} props.actions         The list's row actions.
 * @param {boolean}         props.canChangeStatus Whether the Entry tab can change the status.
 * @param {boolean}         props.canNotify       Whether push notifications are set up.
 */
function QuickEditSidebar( {
	entry,
	actions,
	canChangeStatus,
	canNotify,
}: {
	entry: Entry;
	actions: Action< Entry >[];
	canChangeStatus: boolean;
	canNotify: boolean;
} ) {
	const hasBlockSelection = useSelect(
		( select ) =>
			Boolean(
				(
					select( blockEditorStore ) as unknown as {
						getBlockSelectionStart: () => string | null;
					}
				 ).getBlockSelectionStart()
			),
		[]
	);
	const [ tab, setTab ] = useState( () =>
		hasBlockSelection ? 'block' : 'entry'
	);

	useEffect( () => {
		setTab( hasBlockSelection ? 'block' : 'entry' );
	}, [ hasBlockSelection ] );

	return (
		<Tabs.Root
			value={ tab }
			onValueChange={ ( value ) => setTab( String( value ) ) }
		>
			<div className="components-panel__header editor-sidebar__panel-tabs">
				<Tabs.List activateOnFocus={ false }>
					<Tabs.Tab value="entry">
						{ __( 'Entry', 'newspack-rolling-coverage' ) }
					</Tabs.Tab>
					<Tabs.Tab value="block">
						{ __( 'Block', 'newspack-rolling-coverage' ) }
					</Tabs.Tab>
				</Tabs.List>
			</div>
			<Tabs.Panel value="entry" tabIndex={ -1 }>
				<QuickEditEntryPanel
					entry={ entry }
					actions={ actions }
					canChangeStatus={ canChangeStatus }
				/>
				<QuickEditSettingsPanels canNotify={ canNotify } />
			</Tabs.Panel>
			<Tabs.Panel value="block" tabIndex={ -1 }>
				<BlockInspector />
			</Tabs.Panel>
		</Tabs.Root>
	);
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
 *   intercept. The header's own close button goes through the guard instead.
 * - `EditorProvider` stays inside the Modal: its own helper modals
 *   (keyboard shortcuts, pattern rename and duplicate, media editor) must
 *   nest in this one, or opening them closes Quick Edit. The header's save
 *   and close buttons sit outside the provider, so `EditorRegistryBridge`
 *   hands them the editor's sub-registry.
 *
 * @param {QuickEditModalProps} props Component props.
 */
function QuickEditModal( {
	entry,
	actions,
	canPublish,
	onClose,
	onSaved,
}: QuickEditModalProps ) {
	const config = useAdminContext();
	const entryId = entry.id;
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

	const sidebarToggle = (
		<Button
			icon={ isRTL() ? drawerLeft : drawerRight }
			label={ __( 'Settings', 'newspack-rolling-coverage' ) }
			isPressed={ isSidebarOpen }
			onClick={ () => setIsSidebarOpen( ( prev ) => ! prev ) }
			size="compact"
		/>
	);

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
						{ editorRegistry ? (
							<RegistryProvider value={ editorRegistry }>
								<QuickEditSaveBar
									onClose={ handleRequestClose }
									onSaved={ onSaved }
									canPublish={ canPublish }
								>
									{ sidebarToggle }
								</QuickEditSaveBar>
							</RegistryProvider>
						) : (
							sidebarToggle
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
								<QuickEditSidebar
									entry={ entry }
									actions={ actions }
									canChangeStatus={ canPublish }
									canNotify={ Boolean( config.canNotify ) }
								/>
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
