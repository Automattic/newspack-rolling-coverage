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
	RadioControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControl as ToggleGroupControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
	Button,
	Notice,
	Placeholder,
	TextareaControl,
} from '@wordpress/components';
import {
	useState,
	useEffect,
	useCallback,
	useMemo,
	memo,
	useRef,
} from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { __ } from '@wordpress/i18n';
import { copy as copyIcon, check } from '@wordpress/icons';

/**
 * External dependencies
 */
import { activity } from 'newspack-icons';

/**
 * Internal dependencies
 */
import {
	searchCoverages,
	getCoverage,
	updateCoverageStatus,
	updateCoverageCanonicalUrl,
	fetchEntryPreviewContexts,
	generateKeyTakeaways,
} from './utils';
import {
	ENTRY_TEMPLATE,
	ENTRY_ALLOWED_BLOCKS,
	ENTRY_EDITED_STATES,
	FOLLOW_TEMPLATE,
	isFollowButtons,
	withoutPinnedRow,
	withoutBreakoutLink,
	withLinkedTitle,
} from './template';
import {
	AI_AVAILABLE,
	NEWSPACK_ADS_AVAILABLE,
	NEWSPACK_ADS_PLACEMENT_ENABLED,
	ONESIGNAL_CONFIGURED,
} from './config';
import EditedStateBar from './components/edited-state-bar';
import type {
	CoverageOption,
	ApplyNotice,
	EditProps,
	EntryContext,
	TemplateBlocks,
} from './types';

/**
 * The legacy follow button block, still rendered once at the top of
 * coverages saved before the follow button became a core button.
 */
const FOLLOW_BLOCK_NAME = 'newspack-rolling-coverage/coverage-follow';

/**
 * Every block name injected by an editor state. Used for the allowed-blocks
 * list, and to exclude these from the per-entry preview cards below.
 */
const STATE_BLOCK_NAMES = ENTRY_EDITED_STATES.flatMap( ( state ) =>
	state.blocks.map( ( [ blockName ] ) => blockName )
);

/**
 * Block names that render once at the top of the coverage (not per entry).
 * Used to split inner blocks into these vs. the per-entry template.
 */
const RENDER_ONCE_BLOCKS = [ FOLLOW_BLOCK_NAME, ...STATE_BLOCK_NAMES ];

/**
 * The editor state each state block belongs to, keyed by block name.
 */
const STATE_BY_BLOCK_NAME: Record< string, string > = Object.fromEntries(
	ENTRY_EDITED_STATES.flatMap( ( state ) =>
		state.blocks.map( ( [ blockName ] ) => [ blockName, state.value ] )
	)
);

/**
 * Default inner-blocks template for the Rolling Coverage block: the follow
 * button at the top, then every editor state's blocks, then the per-entry
 * blocks.
 */
const INNER_TEMPLATE = [
	FOLLOW_TEMPLATE,
	...ENTRY_EDITED_STATES.flatMap( ( state ) => state.blocks ),
	...ENTRY_TEMPLATE,
];

/**
 * All block types allowed inside the Rolling Coverage block's inner blocks.
 */
const ALL_ALLOWED_BLOCKS = [
	...ENTRY_ALLOWED_BLOCKS,
	FOLLOW_BLOCK_NAME,
	...STATE_BLOCK_NAMES,
];

/**
 * The Edited State bar's options, derived from ENTRY_EDITED_STATES.
 */
const EDITED_STATE_OPTIONS = ENTRY_EDITED_STATES.map( ( state ) => ( {
	value: state.value,
	label: state.label,
} ) );

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

/**
 * Picks the template variant an entry renders with on the front end: the
 * pinned row only when pinned; "Read more" and a linked title only with a
 * published breakout.
 *
 * @param {Object}       templates                         Template variants.
 * @param {Object}       templates.pinned                  Full template, title linked.
 * @param {Object}       templates.unpinned                Without the pinned row, title linked.
 * @param {Object}       templates.pinnedWithoutBreakout   Without "Read more".
 * @param {Object}       templates.unpinnedWithoutBreakout Without either.
 * @param {EntryContext} context                           The entry.
 * @return {TemplateBlocks} The blocks to preview the entry with.
 */
function previewTemplateFor(
	templates: {
		pinned: TemplateBlocks;
		unpinned: TemplateBlocks;
		pinnedWithoutBreakout: TemplateBlocks;
		unpinnedWithoutBreakout: TemplateBlocks;
	},
	context: EntryContext
): TemplateBlocks {
	if ( context.hasBreakout ) {
		return context.pinned ? templates.pinned : templates.unpinned;
	}

	return context.pinned
		? templates.pinnedWithoutBreakout
		: templates.unpinnedWithoutBreakout;
}

/**
 * A preset slug as core writes it in a custom property, mirroring
 * _wp_to_kebab_case(), e.g. "2XLarge" becomes "2-x-large".
 *
 * @param {string} slug Preset slug.
 * @return {string} The kebab-case slug.
 */
function kebabCase( slug: string ): string {
	return slug
		.replace( /([a-z])([A-Z0-9])/g, '$1-$2' )
		.replace( /([0-9])([a-zA-Z])/g, '$1-$2' )
		.replace( /([A-Z])([A-Z][a-z])/g, '$1-$2' )
		.replace( /[\s_]+/g, '-' )
		.toLowerCase();
}

/**
 * The space between an entry's blocks as the custom property the entries
 * read in the editor, previewing the flow layout the site gives each entry
 * (see Rolling_Coverage_Block::entry_layout_class()).
 *
 * @param {string|Object} blockGap The Block spacing setting.
 * @return {Object} Inline style.
 */
function entryGapStyle(
	blockGap?: string | { top?: string }
): Record< string, string > {
	let gap = typeof blockGap === 'object' ? blockGap?.top : blockGap;

	if ( ! gap ) {
		return {};
	}

	const preset = gap.match( /^var:preset\|spacing\|(.+)$/ );
	if ( preset ) {
		gap = `var(--wp--preset--spacing--${ kebabCase( preset[ 1 ] ) })`;
	}

	return { '--newspack-rolling-coverage-entry-gap': gap };
}

const STATUS_OPTIONS = [
	{ label: __( 'Active', 'newspack-rolling-coverage' ), value: 'active' },
	{ label: __( 'Paused', 'newspack-rolling-coverage' ), value: 'paused' },
	{ label: __( 'Archived', 'newspack-rolling-coverage' ), value: 'archived' },
];

export default function Edit( {
	clientId,
	isSelected,
	attributes,
	setAttributes,
}: EditProps ) {
	const {
		coverageId,
		pollInterval,
		entriesPerPage,
		enableAds,
		adsInterval,
		pinnedLabel,
	} = attributes;
	const [ editedState, setEditedState ] = useState(
		EDITED_STATE_OPTIONS[ 0 ].value
	);
	const blockProps = useBlockProps( {
		'data-editor-state': editedState,
		style: entryGapStyle( attributes.style?.spacing?.blockGap ),
	} );
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
	const [ pendingCanonicalUrl, setPendingCanonicalUrl ] =
		useState< string >( '' );
	const [ isApplyingUrl, setIsApplyingUrl ] = useState( false );
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
	// template is edited. Filter out the render-once blocks (follow, CTA,
	// and editor-state blocks) — only per-entry blocks.
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
				( block ) =>
					! RENDER_ONCE_BLOCKS.includes( block.name ) &&
					! isFollowButtons( block )
			),
		[ allBlocks ]
	);
	const previewTemplates = useMemo( () => {
		const unpinned = withoutPinnedRow( templateBlocks );

		return {
			pinned: withLinkedTitle( templateBlocks ),
			unpinned: withLinkedTitle( unpinned ),
			pinnedWithoutBreakout: withoutBreakoutLink( templateBlocks ),
			unpinnedWithoutBreakout: withoutBreakoutLink( unpinned ),
		};
	}, [ templateBlocks ] );

	// Disabled blocks drop out of List View and can't be selected, so only
	// the current editor state's blocks show there.
	const { setBlockEditingMode, unsetBlockEditingMode } = useDispatch(
		blockEditorStore.name
	) as unknown as {
		setBlockEditingMode: ( clientId: string, mode: string ) => void;
		unsetBlockEditingMode: ( clientId: string ) => void;
	};
	const stateBlocksKey = allBlocks
		.filter( ( block ) => STATE_BY_BLOCK_NAME[ block.name ] )
		.map(
			( block ) =>
				`${ block.clientId }:${ STATE_BY_BLOCK_NAME[ block.name ] }`
		)
		.join( ',' );
	const stateBlockIds = useMemo(
		() =>
			( stateBlocksKey ? stateBlocksKey.split( ',' ) : [] ).map(
				( pair ) => {
					const [ id, state ] = pair.split( ':' );
					return { clientId: id, state };
				}
			),
		[ stateBlocksKey ]
	);
	useEffect( () => {
		stateBlockIds.forEach( ( { clientId: id, state } ) => {
			if ( state === editedState ) {
				unsetBlockEditingMode( id );
			} else {
				setBlockEditingMode( id, 'disabled' );
			}
		} );
		return () =>
			stateBlockIds.forEach( ( { clientId: id } ) =>
				unsetBlockEditingMode( id )
			);
	}, [
		stateBlockIds,
		editedState,
		setBlockEditingMode,
		unsetBlockEditingMode,
	] );

	// Hidden wherever the site never renders it: without OneSignal, or when the
	// coverage is archived or previewed as archived. It stays in the template
	// for when it can render.
	const isFollowHidden =
		! ONESIGNAL_CONFIGURED ||
		currentCoverage?.status === 'archived' ||
		editedState === 'archived';
	const hiddenFollowIds = useMemo(
		() =>
			! isFollowHidden
				? []
				: allBlocks
						.filter(
							( block ) =>
								block.name === FOLLOW_BLOCK_NAME ||
								isFollowButtons( block )
						)
						.map( ( block ) => block.clientId ),
		[ allBlocks, isFollowHidden ]
	);
	const hiddenFollowKey = hiddenFollowIds.join( ',' );
	useEffect( () => {
		const ids = hiddenFollowKey ? hiddenFollowKey.split( ',' ) : [];
		ids.forEach( ( id ) => setBlockEditingMode( id, 'disabled' ) );
		return () => ids.forEach( ( id ) => unsetBlockEditingMode( id ) );
	}, [ hiddenFollowKey, setBlockEditingMode, unsetBlockEditingMode ] );

	// A hidden block still counts as the previous sibling for the entry gap,
	// so the first block left showing in this editor state drops its margin.
	const layoutCss = useMemo( () => {
		const firstVisible = allBlocks.find(
			( block ) =>
				! hiddenFollowIds.includes( block.clientId ) &&
				( ! STATE_BY_BLOCK_NAME[ block.name ] ||
					STATE_BY_BLOCK_NAME[ block.name ] === editedState )
		);
		const layout =
			'.wp-block-newspack-rolling-coverage-rolling-coverage .newspack-rolling-coverage-layout >';
		return [
			...hiddenFollowIds.map(
				( id ) =>
					`${ layout } [data-block="${ id }"] { display: none; }`
			),
			firstVisible
				? `${ layout } .wp-block[data-block="${ firstVisible.clientId }"] { margin-top: 0; }`
				: '',
		].join( '\n' );
	}, [ hiddenFollowIds, allBlocks, editedState ] );

	// Derives the current page's permalink, and whether it's still a
	// placeholder ".../auto-draft/" URL because the post is unsaved.
	const { currentPagePermalink, isCurrentPageUnsaved } = useSelect(
		( select ) => {
			const editor = select( editorStore ) as unknown as {
				getPermalink: () => string | null;
				isEditedPostNew: () => boolean;
			};
			return {
				currentPagePermalink: editor.getPermalink(),
				isCurrentPageUnsaved: editor.isEditedPostNew(),
			};
		},
		[]
	);

	// Drives auto-applying the canonical URL on post save, below.
	const { isSavingPost, isAutosavingPost } = useSelect( ( select ) => {
		const editor = select( editorStore ) as unknown as {
			isSavingPost: () => boolean;
			isAutosavingPost: () => boolean;
		};
		return {
			isSavingPost: editor.isSavingPost(),
			isAutosavingPost: editor.isAutosavingPost(),
		};
	}, [] );

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
			if ( cancelled ) {
				return;
			}

			// Ensure the currently-selected coverage is always present in the dropdown, even if it was trashed after being selected.
			if (
				currentCoverage &&
				! results.some( ( opt ) => opt.value === currentCoverage.value )
			) {
				results = [ currentCoverage, ...results ];
			}

			setOptions( results );
		} );

		return () => {
			cancelled = true;
		};
	}, [ search, currentCoverage ] );

	// Load the currently connected coverage's status and canonical URL
	// whenever the selection changes.
	useEffect( () => {
		let cancelled = false;
		setApplyNotice( null );
		getCoverage( coverageId ).then( ( coverage ) => {
			if ( cancelled ) {
				return;
			}
			setCurrentCoverage( coverage );
			setPendingStatus( coverage?.status || 'active' );
			setPendingCanonicalUrl( coverage?.canonicalUrl || '' );
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
	const coverageAdsDisabled = currentCoverage?.adsDisabled ?? false;

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

	const handleApplyCanonicalUrl = useCallback( async () => {
		if ( ! coverageId ) {
			return;
		}
		setIsApplyingUrl( true );
		const success = await updateCoverageCanonicalUrl(
			coverageId,
			pendingCanonicalUrl
		);
		setIsApplyingUrl( false );
		if ( success ) {
			setCurrentCoverage( ( prev ) =>
				prev ? { ...prev, canonicalUrl: pendingCanonicalUrl } : prev
			);
		}
	}, [ coverageId, pendingCanonicalUrl ] );

	const canonicalUrlUnchanged =
		( currentCoverage?.canonicalUrl || '' ) === pendingCanonicalUrl;

	// Applies the canonical URL automatically when the post is manually saved.
	const wasSavingPost = useRef( false );
	useEffect( () => {
		if (
			isSavingPost &&
			! isAutosavingPost &&
			! wasSavingPost.current &&
			coverageId &&
			! canonicalUrlUnchanged
		) {
			handleApplyCanonicalUrl();
		}
		wasSavingPost.current = isSavingPost;
	}, [
		isSavingPost,
		isAutosavingPost,
		coverageId,
		canonicalUrlUnchanged,
		handleApplyCanonicalUrl,
	] );

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
						<>
							<div className="newspack-rolling-coverage-panel-group">
								<RadioControl
									label={ __(
										'Status',
										'newspack-rolling-coverage'
									) }
									selected={ pendingStatus }
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
									{ __(
										'Apply',
										'newspack-rolling-coverage'
									) }
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
							<div className="newspack-rolling-coverage-panel-group">
								<TextControl
									__next40pxDefaultSize
									type="url"
									label={ __(
										'Canonical URL',
										'newspack-rolling-coverage'
									) }
									placeholder={ __(
										'https://example.com/live-coverage',
										'newspack-rolling-coverage'
									) }
									value={ pendingCanonicalUrl }
									onChange={ setPendingCanonicalUrl }
									disabled={ isApplyingUrl }
									help={ __(
										"The page readers land on when they open a link to one of this coverage's entries. Shared across every block connected to this coverage.",
										'newspack-rolling-coverage'
									) }
								/>
								<Button
									variant="secondary"
									onClick={ () =>
										setPendingCanonicalUrl(
											currentPagePermalink || ''
										)
									}
									disabled={
										isCurrentPageUnsaved ||
										! currentPagePermalink
									}
								>
									{ __(
										'Use this page',
										'newspack-rolling-coverage'
									) }
								</Button>
								{ ( isCurrentPageUnsaved ||
									! currentPagePermalink ) && (
									<p className="components-base-control__help">
										{ __(
											'Save this page to get its permalink.',
											'newspack-rolling-coverage'
										) }
									</p>
								) }
							</div>
						</>
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
					<TextControl
						__next40pxDefaultSize
						label={ __(
							'Pinned label',
							'newspack-rolling-coverage'
						) }
						help={ __(
							'Shown on pinned entries.',
							'newspack-rolling-coverage'
						) }
						placeholder={ __(
							'Pinned',
							'newspack-rolling-coverage'
						) }
						value={ pinnedLabel }
						onChange={ ( value: string ) =>
							setAttributes( { pinnedLabel: value } )
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
						{ coverageAdsDisabled ? (
							<Notice
								className="newspack-rolling-coverage-ads-notice"
								status="warning"
								isDismissible={ false }
							>
								{ __(
									'Ads are disabled for this coverage. Enable them in the coverage settings to configure ad settings here.',
									'newspack-rolling-coverage'
								) }
							</Notice>
						) : (
							! NEWSPACK_ADS_PLACEMENT_ENABLED && (
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
							)
						) }
						<ToggleGroupControl
							__next40pxDefaultSize
							isBlock
							label={ __(
								'Advertising',
								'newspack-rolling-coverage'
							) }
							help={ __(
								'Shows ads at a regular interval in the feed.',
								'newspack-rolling-coverage'
							) }
							value={ enableAds ? 'enabled' : 'disabled' }
							disabled={ coverageAdsDisabled }
							onChange={ ( value ) =>
								setAttributes( {
									enableAds: value === 'enabled',
								} )
							}
						>
							<ToggleGroupControlOption
								value="enabled"
								label={ __(
									'Enabled',
									'newspack-rolling-coverage'
								) }
							/>
							<ToggleGroupControlOption
								value="disabled"
								label={ __(
									'Disabled',
									'newspack-rolling-coverage'
								) }
							/>
						</ToggleGroupControl>
						{ enableAds && ! coverageAdsDisabled && (
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
						{ layoutCss && <style>{ layoutCss }</style> }
						<EditedStateBar
							options={ EDITED_STATE_OPTIONS }
							value={ editedState }
							onChange={ setEditedState }
							isVisible={ isSelected }
						/>
						{ currentCoverage?.status === 'trash' && (
							<Notice status="error" isDismissible={ false }>
								{ __(
									'This coverage has been trashed and is no longer available. Select a different coverage or restore it from the Rolling Coverage admin.',
									'newspack-rolling-coverage'
								) }
							</Notice>
						) }
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
									? ( entryContexts.find(
											( c ) =>
												c.postId ===
												( activeEntryId ??
													entryContexts[ 0 ]?.postId )
										) ?? NEUTRAL_ENTRY_CONTEXT )
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
													blocks={ previewTemplateFor(
														previewTemplates,
														context
													) }
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
						icon={ activity }
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
