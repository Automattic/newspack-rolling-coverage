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
	ToggleControl,
	Button,
	Notice,
	Placeholder,
	TextareaControl,
} from '@wordpress/components';
import {
	useState,
	useEffect,
	useCallback,
	memo,
	useRef,
} from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { megaphone, copy as copyIcon, check } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import {
	searchCoverages,
	getCoverage,
	updateCoverageStatus,
	fetchEntryPreviewContexts,
	generateKeyTakeaways,
} from './utils';
import { DEFAULT_TEMPLATE, ALLOWED_BLOCKS } from './template';
import {
	AI_AVAILABLE,
	NEWSPACK_ADS_AVAILABLE,
	NEWSPACK_ADS_PLACEMENT_ENABLED,
} from './config';
import type {
	CoverageOption,
	ApplyNotice,
	EditProps,
	EntryContext,
	TemplateBlocks,
} from './types';

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
	const { coverageId, pollInterval, entriesPerPage, enableAds, adsInterval } =
		attributes;
	const blockProps = useBlockProps();

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
	const [ isGenerating, setIsGenerating ] = useState( false );
	const [ aiNotice, setAiNotice ] = useState< ApplyNotice | null >( null );
	const [ generatedOutput, setGeneratedOutput ] = useState( '' );
	const [ copied, setCopied ] = useState( false );
	const copyTimer = useRef< ReturnType< typeof setTimeout > | null >( null );

	// Clear copy timer on unmount.
	useEffect(
		() => () => {
			if ( copyTimer.current ) {
				clearTimeout( copyTimer.current );
			}
		},
		[]
	);

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

	const handleGenerate = useCallback( async () => {
		if ( ! coverageId ) {
			return;
		}
		setIsGenerating( true );
		setAiNotice( null );

		const result = await generateKeyTakeaways( coverageId );

		setIsGenerating( false );

		if ( result.success && result.result ) {
			setGeneratedOutput( result.result );
			setAiNotice( {
				type: 'success',
				message: __(
					'Key takeaways generated.',
					'newspack-rolling-coverage'
				),
			} );
		} else {
			setAiNotice( {
				type: 'error',
				message:
					result.error ||
					__(
						'Failed to generate key takeaways.',
						'newspack-rolling-coverage'
					),
			} );
		}
	}, [ coverageId ] );

	const handleCopy = useCallback( async () => {
		if ( ! generatedOutput ) {
			return;
		}
		try {
			await navigator.clipboard.writeText( generatedOutput );
			setCopied( true );
			if ( copyTimer.current ) {
				clearTimeout( copyTimer.current );
			}
			copyTimer.current = setTimeout( () => setCopied( false ), 2000 );
		} catch {
			setCopied( false );
		}
	}, [ generatedOutput ] );

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

				{ coverageId && AI_AVAILABLE ? (
					<PanelBody
						title={ __( 'AI', 'newspack-rolling-coverage' ) }
						initialOpen={ false }
					>
						{ aiNotice && (
							<Notice
								status={ aiNotice.type }
								onRemove={ () => setAiNotice( null ) }
							>
								{ aiNotice.message }
							</Notice>
						) }
						<div className="newspack-rolling-coverage-ai-panel">
							<p className="newspack-rolling-coverage-ai-panel__help">
								{ __(
									"Generate a summary of key takeaways from this coverage's entries. Prompts are configured by site administrators on the AI settings page.",
									'newspack-rolling-coverage'
								) }
							</p>
							<Button
								variant="primary"
								onClick={ handleGenerate }
								isBusy={ isGenerating }
								disabled={ isGenerating }
							>
								{ __(
									'Generate Key Takeaways',
									'newspack-rolling-coverage'
								) }
							</Button>
							{ generatedOutput && (
								<>
									<TextareaControl
										label={ __(
											'Generated Output',
											'newspack-rolling-coverage'
										) }
										value={ generatedOutput }
										onChange={ () => {} }
										rows={ 8 }
										readOnly
										className="newspack-rolling-coverage-ai-output"
									/>
									<Button
										variant="secondary"
										icon={ copied ? check : copyIcon }
										onClick={ handleCopy }
									>
										{ copied
											? __(
													'Copied!',
													'newspack-rolling-coverage'
											  )
											: __(
													'Copy',
													'newspack-rolling-coverage'
											  ) }
									</Button>
								</>
							) }
						</div>
					</PanelBody>
				) : null }

				{ NEWSPACK_ADS_AVAILABLE && (
					<PanelBody
						title={ __( 'Ads', 'newspack-rolling-coverage' ) }
					>
						{ ! NEWSPACK_ADS_PLACEMENT_ENABLED && (
							<Notice
								className="newspack-rolling-coverage-ads-notice"
								status="warning"
								isDismissible={ false }
							>
								{ __(
									'Enable and configure the Rolling Coverage: Entry placement in Newspack Ads to show ads.',
									'newspack-rolling-coverage'
								) }
							</Notice>
						) }
						<ToggleControl
							label={ __(
								'Enable ads',
								'newspack-rolling-coverage'
							) }
							help={ __(
								'Shows ads at a regular interval in the feed.',
								'newspack-rolling-coverage'
							) }
							checked={ enableAds }
							onChange={ ( value: boolean ) =>
								setAttributes( { enableAds: value } )
							}
						/>
						{ enableAds && (
							<TextControl
								__next40pxDefaultSize
								type="number"
								label={ __(
									'Ads interval',
									'newspack-rolling-coverage'
								) }
								help={ __(
									'Show an ad after every N entries. Maximum 3 ads for the initial feed and load more; no cap for new entries.',
									'newspack-rolling-coverage'
								) }
								value={ String( adsInterval ) }
								min={ 1 }
								onChange={ ( value: string ) =>
									setAttributes( {
										adsInterval: value
											? parseInt( value, 10 )
											: 4,
									} )
								}
							/>
						) }
					</PanelBody>
				) }
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
