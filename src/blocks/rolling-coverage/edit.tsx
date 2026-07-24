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
import {
	useState,
	useEffect,
	useCallback,
	useMemo,
	memo,
} from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { megaphone } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import {
	searchCoverages,
	getCoverage,
	updateCoverageStatus,
	fetchEntryPreviewContexts,
} from './utils';
import { ENTRY_TEMPLATE, ENTRY_ALLOWED_BLOCKS } from './template';
import type {
	CoverageOption,
	ApplyNotice,
	EditProps,
	EntryContext,
	TemplateBlocks,
} from './types';

/**
 * Block names that belong to the per-entry template (everything after the
 * deep-link CTA). Used to split inner blocks into CTA vs. template.
 */
const CTA_BLOCK_NAME = 'newspack-rolling-coverage/deep-link-cta';

/**
 * Default inner-blocks template for the Rolling Coverage block:
 * one deep-link CTA at the top, then the per-entry template blocks.
 */
const INNER_TEMPLATE = [ [ CTA_BLOCK_NAME ], ...ENTRY_TEMPLATE ];

/**
 * All block types allowed inside the Rolling Coverage block's inner blocks.
 */
const ALL_ALLOWED_BLOCKS = [ ...ENTRY_ALLOWED_BLOCKS, CTA_BLOCK_NAME ];

/**
 * Neutral block context used when a coverage has no published entries yet,
 * so the template can still be edited against something.
 */
const NEUTRAL_ENTRY_CONTEXT: EntryContext = {
	postId: 0,
	postType: '',
	queryId: 0,
};

/**
 * A rendering of the per-entry template's current blocks for one real
 * entry. Clicking it makes that entry the active one, swapping in the
 * editable template canvas in its place.
 *
 * @param {Object}   props          Component props.
 * @param {Object[]} props.blocks   The current per-entry template blocks.
 * @param {Function} props.onSelect Called when this entry is clicked.
 */
function EntryBlockPreview( {
	blocks,
	onSelect,
}: {
	blocks: TemplateBlocks;
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
			onKeyDown={ ( event ) => {
				if ( 'Enter' === event.key || ' ' === event.key ) {
					event.preventDefault();
					onSelect();
				}
			} }
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
	const { coverageId, pollInterval, entriesPerPage } = attributes;
	const blockProps = useBlockProps();
	const innerBlocksProps = useInnerBlocksProps(
		{ className: 'newspack-rolling-coverage-layout' },
		{
			template: INNER_TEMPLATE,
			allowedBlocks: ALL_ALLOWED_BLOCKS,
			templateLock: false,
		}
	);

	const [ search, setSearch ] = useState( '' );
	const [ options, setOptions ] = useState< CoverageOption[] >( [] );
	const [ currentCoverage, setCurrentCoverage ] =
		useState< CoverageOption | null >( null );
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
	// template is edited. Filter out the CTA block — only per-entry blocks.
	const allBlocks: TemplateBlocks = useSelect(
		( select ) =>
			(
				select( blockEditorStore ) as unknown as {
					getBlocks: ( clientId: string ) => TemplateBlocks;
				}
			 ).getBlocks( clientId ),
		[ clientId ]
	);
	const templateBlocks = useMemo(
		() =>
			allBlocks.filter(
				( block: { name: string } ) => block.name !== CTA_BLOCK_NAME
			),
		[ allBlocks ]
	);

	// One-shot fetch (not the front-end's polling/pagination) — the editor
	// only needs a representative snapshot to preview the template against.
	useEffect( () => {
		let cancelled = false;
		if ( ! coverageId ) {
			setEntryContexts( [] );
			return;
		}
		fetchEntryPreviewContexts( coverageId, entriesPerPage ).then(
			( contexts ) => {
				if ( ! cancelled ) {
					setEntryContexts( contexts );
				}
			}
		);
		return () => {
			cancelled = true;
		};
	}, [ coverageId, entriesPerPage ] );

	// Populate the combobox as the user searches.
	useEffect( () => {
		let cancelled = false;
		searchCoverages( search ).then( ( results ) => {
			if ( ! cancelled ) {
				setOptions( results );
			}
		} );
		return () => {
			cancelled = true;
		};
	}, [ search ] );

	// Load the currently connected coverage's status whenever the selection changes.
	useEffect( () => {
		let cancelled = false;
		setApplyNotice( null );
		getCoverage( coverageId ).then( ( coverage ) => {
			if ( cancelled ) {
				return;
			}
			setCurrentCoverage( coverage );
			setPendingStatus( coverage?.status || 'active' );
		} );
		return () => {
			cancelled = true;
		};
	}, [ coverageId ] );

	const handleApply = useCallback( async () => {
		if ( ! coverageId ) {
			return;
		}
		setIsApplying( true );
		setApplyNotice( null );
		const success = await updateCoverageStatus( coverageId, pendingStatus );
		setIsApplying( false );
		setApplyNotice(
			success
				? {
						type: 'success',
						message: __(
							'Coverage status updated.',
							'newspack-rolling-coverage'
						),
				  }
				: {
						type: 'error',
						message: __(
							'Could not update the coverage status.',
							'newspack-rolling-coverage'
						),
				  }
		);
		if ( success ) {
			setCurrentCoverage( ( prev ) =>
				prev ? { ...prev, status: pendingStatus } : prev
			);
		}
	}, [ coverageId, pendingStatus ] );

	const statusUnchanged = currentCoverage?.status === pendingStatus;

	// Combobox for selecting the connected coverage.
	const coverageCombobox = (
		<ComboboxControl
			__next40pxDefaultSize
			label={ __( 'Coverage', 'newspack-rolling-coverage' ) }
			hideLabelFromVision
			value={ coverageId ? String( coverageId ) : '' }
			options={ options }
			placeholder={ __(
				'Search for a coverage…',
				'newspack-rolling-coverage'
			) }
			onChange={ ( value ) =>
				setAttributes( {
					coverageId: value ? parseInt( value, 10 ) : 0,
				} )
			}
			onFilterValueChange={ setSearch }
		/>
	);

	return (
		<>
			<InspectorControls>
				<PanelBody
					title={ __( 'Coverage', 'newspack-rolling-coverage' ) }
				>
					{ coverageCombobox }

					{ coverageId ? (
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
									'Writes back to the coverage itself — changes here affect every block connected to it.',
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
				{ coverageId ? (
					<>
						{ entryContexts.length === 0 && (
							<Notice status="info" isDismissible={ false }>
								{ __(
									'No published entries yet — showing the template only. Add entries to this coverage to preview real content here.',
									'newspack-rolling-coverage'
								) }
							</Notice>
						) }
						<BlockContextProvider
							value={
								entryContexts.length > 0
									? entryContexts.find(
											( c ) =>
												c.postId ===
												( activeEntryId ??
													entryContexts[ 0 ]?.postId )
									  ) ?? NEUTRAL_ENTRY_CONTEXT
									: NEUTRAL_ENTRY_CONTEXT
							}
						>
							<div { ...innerBlocksProps } />
						</BlockContextProvider>
						<div className="newspack-rolling-coverage-entries">
							{ entryContexts.length > 0 &&
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
											{ ! isActive && (
												<MemoizedEntryBlockPreview
													blocks={ templateBlocks }
													onSelect={ () =>
														setActiveEntryId(
															context.postId
														)
													}
												/>
											) }
										</BlockContextProvider>
									);
								} ) }
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
							'Select a coverage to display its entries.',
							'newspack-rolling-coverage'
						) }
						isColumnLayout
					>
						{ coverageCombobox }
					</Placeholder>
				) }
			</div>
		</>
	);
}
