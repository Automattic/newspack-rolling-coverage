/**
 * WordPress dependencies
 */
import {
	useBlockProps,
	useInnerBlocksProps,
	InspectorControls,
	BlockContextProvider,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalUseBlockPreview as useBlockPreview,
	store as blockEditorStore,
} from '@wordpress/block-editor';
import {
	PanelBody,
	ComboboxControl,
	TextControl,
	SelectControl,
	Button,
	Notice,
	Placeholder,
} from '@wordpress/components';
import { useState, useEffect, useCallback, memo } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { megaphone } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import {
	searchLiveblogs,
	fetchLiveblog,
	updateLiveblogStatus,
	fetchEntryPreviewContexts,
} from './utils';
import { DEFAULT_TEMPLATE, ALLOWED_BLOCKS } from './template';
import type {
	LiveblogOption,
	ApplyNotice,
	EditProps,
	EntryContext,
	TemplateBlocks,
} from './types';

/**
 * Neutral block context used when a liveblog has no published entries yet,
 * so the template can still be edited against something.
 */
const NEUTRAL_ENTRY_CONTEXT: EntryContext = {
	postId: 0,
	postType: '',
	queryId: 0,
};

/**
 * Generates a unique ID for one block instance.
 *
 * @return {string} A new unique instance ID.
 */
function generateInstanceId(): string {
	return `${ Date.now().toString( 36 ) }-${ Math.random()
		.toString( 36 )
		.slice( 2 ) }`;
}

/**
 * The per-entry template canvas. Blocks can be added, moved, or removed,
 * but contextual blocks such as post-title and post-content render their
 * content read-only.
 */
function EntryTemplatePreview() {
	const innerBlocksProps = useInnerBlocksProps(
		{ className: 'newspack-rolling-coverage-entry wp-block-post' },
		{
			templateLock: false,
			allowedBlocks: ALLOWED_BLOCKS,
			template: DEFAULT_TEMPLATE,
		}
	);

	return <div { ...innerBlocksProps } />;
}

/**
 * A rendering of the per-entry template's current blocks for one real
 * entry. Clicking it makes that entry the active one, swapping in the
 * editable template canvas in its place.
 *
 * @param {Object}   props          Component props.
 * @param {Object[]} props.blocks   The current per-entry template blocks.
 * @param {boolean}  props.isHidden Whether this entry is the active one
 *                                  (and so already shown by the canvas).
 * @param {Function} props.onSelect Called when this entry is clicked.
 */
function EntryBlockPreview( {
	blocks,
	isHidden,
	onSelect,
}: {
	blocks: TemplateBlocks;
	isHidden: boolean;
	onSelect: () => void;
} ) {
	const blockPreviewProps = useBlockPreview( {
		blocks,
		props: { className: 'newspack-rolling-coverage-entry wp-block-post' },
	} );

	return (
		<div
			{ ...blockPreviewProps }
			tabIndex={ 0 }
			role="button"
			onClick={ onSelect }
			onKeyPress={ onSelect }
			style={ { display: isHidden ? 'none' : undefined } }
		/>
	);
}

const MemoizedEntryBlockPreview = memo( EntryBlockPreview );

const STATUS_OPTIONS = [
	{ label: __( 'Active', 'newspack-rolling-coverage' ), value: 'active' },
	{ label: __( 'Paused', 'newspack-rolling-coverage' ), value: 'paused' },
	{ label: __( 'Archived', 'newspack-rolling-coverage' ), value: 'archived' },
];

export default function Edit( {
	clientId,
	attributes,
	setAttributes,
}: EditProps ) {
	const { liveblogId, pollInterval, entriesPerPage, instanceId } = attributes;
	const blockProps = useBlockProps();

	const [ search, setSearch ] = useState( '' );
	const [ options, setOptions ] = useState< LiveblogOption[] >( [] );
	const [ currentLiveblog, setCurrentLiveblog ] =
		useState< LiveblogOption | null >( null );
	const [ pendingStatus, setPendingStatus ] = useState< string >( 'active' );
	const [ isApplying, setIsApplying ] = useState( false );
	const [ applyNotice, setApplyNotice ] = useState< ApplyNotice | null >(
		null
	);
	const [ entryContexts, setEntryContexts ] = useState< EntryContext[] >(
		[]
	);
	const [ activeEntryId, setActiveEntryId ] = useState< number >();

	// Read live from the store so preview copies stay in sync as the
	// template above is edited.
	const templateBlocks: TemplateBlocks = useSelect(
		( select ) =>
			(
				select( blockEditorStore ) as unknown as {
					getBlocks: ( clientId: string ) => TemplateBlocks;
				}
			 ).getBlocks( clientId ),
		[ clientId ]
	);

	// Set a persisted instance ID once, so the front-end can identify this block instance across renders.
	useEffect( () => {
		if ( ! instanceId ) {
			setAttributes( { instanceId: generateInstanceId() } );
		}
	}, [ instanceId, setAttributes ] );

	// One-shot fetch (not the front-end's polling/pagination) — the editor
	// only needs a representative snapshot to preview the template against.
	useEffect( () => {
		let cancelled = false;
		if ( ! liveblogId ) {
			setEntryContexts( [] );
			return;
		}
		fetchEntryPreviewContexts( liveblogId, entriesPerPage ).then(
			( contexts ) => {
				if ( ! cancelled ) {
					setEntryContexts( contexts );
				}
			}
		);
		return () => {
			cancelled = true;
		};
	}, [ liveblogId, entriesPerPage ] );

	// Populate the combobox as the user searches.
	useEffect( () => {
		let cancelled = false;
		searchLiveblogs( search ).then( ( results ) => {
			if ( ! cancelled ) {
				setOptions( results );
			}
		} );
		return () => {
			cancelled = true;
		};
	}, [ search ] );

	// Load the currently connected liveblog's status whenever the selection changes.
	useEffect( () => {
		let cancelled = false;
		setApplyNotice( null );
		fetchLiveblog( liveblogId ).then( ( liveblog ) => {
			if ( cancelled ) {
				return;
			}
			setCurrentLiveblog( liveblog );
			setPendingStatus( liveblog?.status || 'active' );
		} );
		return () => {
			cancelled = true;
		};
	}, [ liveblogId ] );

	const handleApply = useCallback( async () => {
		if ( ! liveblogId ) {
			return;
		}
		setIsApplying( true );
		setApplyNotice( null );
		const success = await updateLiveblogStatus( liveblogId, pendingStatus );
		setIsApplying( false );
		setApplyNotice(
			success
				? {
						type: 'success',
						message: __(
							'Liveblog status updated.',
							'newspack-rolling-coverage'
						),
				  }
				: {
						type: 'error',
						message: __(
							'Could not update the liveblog status.',
							'newspack-rolling-coverage'
						),
				  }
		);
		if ( success ) {
			setCurrentLiveblog( ( prev ) =>
				prev ? { ...prev, status: pendingStatus } : prev
			);
		}
	}, [ liveblogId, pendingStatus ] );

	const statusUnchanged = currentLiveblog?.status === pendingStatus;

	// Combobox for selecting the connected liveblog.
	const liveblogCombobox = (
		<ComboboxControl
			__next40pxDefaultSize
			label={ __( 'Liveblog', 'newspack-rolling-coverage' ) }
			hideLabelFromVision
			value={ liveblogId ? String( liveblogId ) : '' }
			options={ options }
			placeholder={ __(
				'Search for a liveblog…',
				'newspack-rolling-coverage'
			) }
			onChange={ ( value ) =>
				setAttributes( {
					liveblogId: value ? parseInt( value, 10 ) : 0,
				} )
			}
			onFilterValueChange={ setSearch }
		/>
	);

	return (
		<>
			<InspectorControls>
				<PanelBody
					title={ __( 'Liveblog', 'newspack-rolling-coverage' ) }
				>
					{ liveblogCombobox }

					{ liveblogId ? (
						<div className="newspack-rolling-coverage-status-control">
							<SelectControl
								__next40pxDefaultSize
								label={ __(
									'Status',
									'newspack-rolling-coverage'
								) }
								value={ pendingStatus }
								options={ STATUS_OPTIONS }
								onChange={ setPendingStatus }
								help={ __(
									'Writes back to the liveblog itself — changes here affect every block connected to it.',
									'newspack-rolling-coverage'
								) }
							/>
							<Button
								variant="secondary"
								onClick={ handleApply }
								isBusy={ isApplying }
								disabled={ isApplying || statusUnchanged }
							>
								{ __( 'Apply', 'newspack-rolling-coverage' ) }
							</Button>
							{ applyNotice && (
								<Notice
									status={ applyNotice.type }
									isDismissible={ false }
								>
									{ applyNotice.message }
								</Notice>
							) }
						</div>
					) : null }
				</PanelBody>

				<PanelBody
					title={ __( 'Display', 'newspack-rolling-coverage' ) }
				>
					<TextControl
						__next40pxDefaultSize
						type="number"
						label={ __(
							'Entries per page',
							'newspack-rolling-coverage'
						) }
						help={ __(
							'Used for both the initial number of entries shown and the infinite-scroll page size.',
							'newspack-rolling-coverage'
						) }
						value={ String( entriesPerPage ) }
						min={ 1 }
						max={ 100 }
						onChange={ ( value: string ) =>
							setAttributes( {
								entriesPerPage: value
									? parseInt( value, 10 )
									: 20,
							} )
						}
					/>
					<TextControl
						__next40pxDefaultSize
						type="number"
						label={ __(
							'Poll interval (seconds)',
							'newspack-rolling-coverage'
						) }
						value={ String( pollInterval ) }
						min={ 1 }
						onChange={ ( value: string ) =>
							setAttributes( {
								pollInterval: value
									? parseInt( value, 10 )
									: 10,
							} )
						}
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				{ liveblogId ? (
					<>
						{ entryContexts.length === 0 && (
							<Notice status="info" isDismissible={ false }>
								{ __(
									'No published entries yet — showing the template only. Add entries to this liveblog to preview real content here.',
									'newspack-rolling-coverage'
								) }
							</Notice>
						) }
						<div className="newspack-rolling-coverage-entries">
							{ entryContexts.length === 0 ? (
								<BlockContextProvider
									value={ NEUTRAL_ENTRY_CONTEXT }
								>
									<EntryTemplatePreview />
								</BlockContextProvider>
							) : (
								entryContexts.map( ( context ) => {
									const isActive =
										context.postId ===
										( activeEntryId ??
											entryContexts[ 0 ]?.postId );

									return (
										<BlockContextProvider
											key={ context.postId }
											value={ context }
										>
											{ isActive ? (
												<EntryTemplatePreview />
											) : null }
											<MemoizedEntryBlockPreview
												blocks={ templateBlocks }
												isHidden={ isActive }
												onSelect={ () =>
													setActiveEntryId(
														context.postId
													)
												}
											/>
										</BlockContextProvider>
									);
								} )
							) }
						</div>
					</>
				) : (
					<Placeholder
						icon={ megaphone }
						label={ __(
							'Rolling Coverage',
							'newspack-rolling-coverage'
						) }
						instructions={ __(
							'Select a liveblog to display its entries.',
							'newspack-rolling-coverage'
						) }
						isColumnLayout
					>
						{ liveblogCombobox }
					</Placeholder>
				) }
			</div>
		</>
	);
}
