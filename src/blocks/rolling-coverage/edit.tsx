/**
 * WordPress dependencies
 */
import {
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalGetBorderClassesAndStyles as getBorderClassesAndStyles,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalGetColorClassesAndStyles as getColorClassesAndStyles,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalGetDimensionsClassesAndStyles as getDimensionsClassesAndStyles,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalGetShadowClassesAndStyles as getShadowClassesAndStyles,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalGetSpacingClassesAndStyles as getSpacingClassesAndStyles,
	getTypographyClassesAndStyles,
	useBlockProps,
	useInnerBlocksProps,
	InspectorControls,
	BlockContextProvider,
	BlockControls,
	store as blockEditorStore,
} from '@wordpress/block-editor';
import {
	parse,
	cloneBlock,
	createBlocksFromInnerBlocksTemplate,
} from '@wordpress/blocks';
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
	ToggleControl,
	ToolbarButton,
} from '@wordpress/components';
import {
	useState,
	useEffect,
	useCallback,
	useMemo,
	useRef,
} from '@wordpress/element';
import { useSelect, useDispatch, useRegistry } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { store as editorStore } from '@wordpress/editor';
import { decodeEntities } from '@wordpress/html-entities';
import { __, _x, sprintf } from '@wordpress/i18n';
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
	getLayoutEditUrl,
	getLayoutId,
	createLayout,
	PREVIEW_COVERAGE_ID,
} from './utils';
import {
	feedGroupOf,
	feedItems,
	isFollowButtons,
	isPinnedCard,
	isRegularEntry,
	forEntryKind,
	breakoutBlockIds,
} from './template';
import {
	AI_AVAILABLE,
	NEWSPACK_ADS_AVAILABLE,
	NEWSPACK_ADS_PLACEMENT_ENABLED,
	ONESIGNAL_CONFIGURED,
	LAYOUT_CATEGORY_ID,
} from './config';
import { useSampleEntries } from './samples';
import EntryBlockPreview from './components/entry-block-preview';
import LoadingState from './components/loading-state';
import LayoutPickerModal, {
	type LayoutChoice,
} from './components/layout-picker-modal';
import { getBuiltInLayouts, builtInLayoutSlugFor } from './layouts';
import PinnedEntryContext from './pinned-entry-context';
import { blockGapCss } from './spacing';
import {
	BLOCK_NAME,
	FOLLOW_BLOCK_NAME,
	innerTemplate,
	useLayoutPreview,
} from './layout';
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
 * Resets `layoutId` on every Rolling Coverage block nested in a layout, so a
 * synced block pasted into the layout can't preview the layout inside itself.
 *
 * @param {Object[]} blocks Parsed blocks.
 * @return {Object[]} The blocks, with nested Rolling Coverage blocks detached.
 */
function detachNestedBlocks( blocks: TemplateBlocks ): TemplateBlocks {
	return blocks.map( ( block ) => ( {
		...block,
		attributes:
			block.name === BLOCK_NAME
				? { ...( block.attributes as object ), layoutId: 0 }
				: block.attributes,
		innerBlocks: detachNestedBlocks(
			( block.innerBlocks ?? [] ) as TemplateBlocks
		),
	} ) );
}

/**
 * The space between the coverage's items, from the Feed group's Block
 * spacing, as the custom property the block reads, as
 * Rolling_Coverage_Block::render_block() sets it on the front end.
 *
 * @param {Object} feed The layout's Feed group.
 * @return {Object} Inline style.
 */
function feedGapStyle( feed?: {
	[ key: string ]: unknown;
} ): Record< string, string > {
	const attributes = feed?.attributes as
		| { style?: { spacing?: { blockGap?: string | { top?: string } } } }
		| undefined;
	const gap = blockGapCss( attributes?.style?.spacing?.blockGap );

	return gap ? { '--newspack-rolling-coverage-gap': gap } : {};
}

/**
 * The Feed group's own classes and styles (colour, border, spacing,
 * typography), for the container a synced layout's preview shows in place
 * of the Feed, so it previews as the site renders it.
 *
 * @param {Object} feed The layout's Feed group.
 * @return {Object} The container's className and style.
 */
function feedPreviewProps( feed?: { [ key: string ]: unknown } ): {
	className: string;
	style: Record< string, unknown >;
} {
	const attributes = ( feed?.attributes ?? {} ) as Record< string, unknown >;
	const parts = [
		getColorClassesAndStyles( attributes ),
		getBorderClassesAndStyles( attributes ),
		getSpacingClassesAndStyles( attributes ),
		getTypographyClassesAndStyles( attributes ),
		getShadowClassesAndStyles( attributes ),
		getDimensionsClassesAndStyles( attributes ),
	];
	const classNames = [
		'wp-block-group',
		'newspack-rolling-coverage-feed',
		attributes.className,
		...parts.map( ( part ) => part.className ),
	]
		.filter( ( name ): name is string => typeof name === 'string' )
		.flatMap( ( name ) => name.split( ' ' ) )
		.filter( Boolean );

	return {
		className: [ ...new Set( classNames ) ].join( ' ' ),
		style: Object.assign( {}, ...parts.map( ( part ) => part.style ) ),
	};
}

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
	const {
		coverageId,
		pollInterval,
		entriesPerPage,
		enableAds,
		adsInterval,
		archivedNoticeShow,
		archivedNotice,
		archivedNoticeShowLink,
		archivedNoticeLinkUrl,
		archivedNoticeLinkLabel,
		layoutId,
	} = attributes;
	const { currentPostType, currentPostId, patternCategories } = useSelect(
		( select ) => {
			const editor = select( editorStore ) as unknown as {
				getCurrentPostType: () => string;
				getCurrentPostId: () => number | string;
				getEditedPostAttribute: ( attribute: string ) => unknown;
			};
			return {
				currentPostType: editor.getCurrentPostType(),
				currentPostId: Number( editor.getCurrentPostId() ),
				patternCategories: editor.getEditedPostAttribute(
					'wp_pattern_category'
				) as number[] | undefined,
			};
		},
		[]
	);
	const { isNested, isPreviewMode } = useSelect(
		( select ) => {
			const blockEditor = select( blockEditorStore ) as unknown as {
				getBlockParentsByBlockName: (
					id: string,
					name: string
				) => string[];
				getSettings: () => { isPreviewMode?: boolean };
			};
			return {
				isNested:
					blockEditor.getBlockParentsByBlockName(
						clientId,
						BLOCK_NAME
					).length > 0,
				isPreviewMode: Boolean(
					blockEditor.getSettings().isPreviewMode
				),
			};
		},
		[ clientId ]
	);
	const isLayoutPattern =
		! coverageId &&
		currentPostType === 'wp_block' &&
		( ( patternCategories ?? [] ).includes(
			Number( LAYOUT_CATEGORY_ID )
		) ||
			builtInLayoutSlugFor( currentPostId ) !== null );
	const innerBlockCount = useSelect(
		( select ) =>
			(
				select( blockEditorStore ) as unknown as {
					getBlockCount: ( id: string ) => number;
				}
			 ).getBlockCount( clientId ),
		[ clientId ]
	);
	// The layout picker previews each layout as this block with the layout
	// as its inner blocks, rendering sample entries.
	const isSamplePreview =
		isPreviewMode && ! coverageId && ! layoutId && innerBlockCount > 0;
	const showsSamples = isLayoutPattern || isSamplePreview;
	const [ isPickingLayout, setIsPickingLayout ] = useState( false );
	const registry = useRegistry();
	const isSynced = layoutId > 0 && ! isNested;
	const defaultTemplate = useMemo( innerTemplate, [] );
	const patternTemplate = useMemo(
		() =>
			(
				getBuiltInLayouts().find(
					( layout ) =>
						layout.slug === builtInLayoutSlugFor( currentPostId )
				) ?? getBuiltInLayouts()[ 0 ]
			).template(),
		[ currentPostId ]
	);
	const innerBlocksProps = useInnerBlocksProps(
		{ className: 'newspack-rolling-coverage-layout' },
		{
			template:
				! isSynced && ( isLayoutPattern || isNested )
					? patternTemplate
					: undefined,
			allowedBlocks: [],
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
	const [ entriesLoadedFor, setEntriesLoadedFor ] = useState( 0 );
	const [ coverageLoadedFor, setCoverageLoadedFor ] = useState( 0 );
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
	// template is edited.
	const innerBlocks: TemplateBlocks = useSelect(
		( select ) =>
			(
				select( blockEditorStore ) as unknown as {
					getBlocks: ( clientId: string ) => TemplateBlocks;
				}
			 ).getBlocks( clientId ),
		[ clientId ]
	);
	const allBlocks = useMemo(
		() => feedItems( innerBlocks ),
		[ innerBlocks ]
	);
	const { replaceInnerBlocks, __unstableMarkNextChangeAsNotPersistent } =
		useDispatch( blockEditorStore.name ) as unknown as {
			replaceInnerBlocks: (
				id: string,
				blocks: unknown[],
				updateSelection?: boolean
			) => void;
			__unstableMarkNextChangeAsNotPersistent: () => void;
		};

	// A story saved with a coverage but no layout predates layouts; the site
	// renders it in the default layout, so the editor syncs it to that.
	const needsDefaultLayout =
		coverageId > 0 &&
		! layoutId &&
		! innerBlockCount &&
		! isNested &&
		! isLayoutPattern &&
		! isPreviewMode;

	useEffect( () => {
		if ( ! needsDefaultLayout ) {
			return;
		}

		let cancelled = false;
		const sync = ( id: number ) => {
			__unstableMarkNextChangeAsNotPersistent();
			setAttributes( { layoutId: id } );
		};
		const fallBackToLocal = () => {
			__unstableMarkNextChangeAsNotPersistent();
			replaceInnerBlocks(
				clientId,
				createBlocksFromInnerBlocksTemplate( innerTemplate() ),
				false
			);
		};

		if ( getLayoutId( 'default' ) ) {
			sync( getLayoutId( 'default' ) );
			return;
		}

		createLayout( 'default' )
			.then( ( id ) => ! cancelled && sync( id ) )
			.catch( () => ! cancelled && fallBackToLocal() );

		return () => {
			cancelled = true;
		};
	}, [
		needsDefaultLayout,
		clientId,
		setAttributes,
		replaceInnerBlocks,
		__unstableMarkNextChangeAsNotPersistent,
	] );

	const { invalidateResolution } = useDispatch( coreStore ) as unknown as {
		invalidateResolution: ( selector: string, args: unknown[] ) => void;
	};

	const { layoutRecord, hasResolvedLayout, canEditLayout } = useSelect(
		( select ) => {
			if ( ! isSynced ) {
				return {
					layoutRecord: null,
					hasResolvedLayout: true,
					canEditLayout: false,
				};
			}
			const core = select( coreStore ) as unknown as {
				getEntityRecord: (
					kind: string,
					name: string,
					id: number,
					query?: object
				) =>
					| { status?: string; content?: { raw?: string } | string }
					| undefined;
				hasFinishedResolution: (
					selector: string,
					args: unknown[]
				) => boolean;
				canUser: (
					action: string,
					resource: object
				) => boolean | undefined;
			};
			const args = [
				'postType',
				'wp_block',
				layoutId,
				{ context: 'view' },
			];
			return {
				layoutRecord: core.getEntityRecord(
					'postType',
					'wp_block',
					layoutId,
					{ context: 'view' }
				),
				hasResolvedLayout: core.hasFinishedResolution(
					'getEntityRecord',
					args
				),
				canEditLayout: Boolean(
					core.canUser( 'update', {
						kind: 'postType',
						name: 'wp_block',
						id: layoutId,
					} )
				),
			};
		},
		[ isSynced, layoutId ]
	);

	const layoutBlocks = useMemo( () => {
		if ( ! layoutRecord || layoutRecord.status !== 'publish' ) {
			return null;
		}
		const raw =
			typeof layoutRecord.content === 'string'
				? layoutRecord.content
				: layoutRecord.content?.raw;
		const holder = parse( raw ?? '' ).find(
			( block ) => block.name === BLOCK_NAME
		);
		return holder
			? detachNestedBlocks(
					holder.innerBlocks as unknown as TemplateBlocks
				)
			: null;
	}, [ layoutRecord ] );
	const isLayoutMissing = isSynced && hasResolvedLayout && ! layoutBlocks;
	const needsLayout =
		! isLayoutPattern &&
		! isNested &&
		( ( ! coverageId && ! layoutId && ! innerBlockCount ) ||
			isLayoutMissing );
	const hasLayout = coverageId > 0 || showsSamples;
	const canChangeLayout =
		! isLayoutPattern &&
		! isPreviewMode &&
		! isNested &&
		! needsLayout &&
		( isSynced || innerBlockCount > 0 );
	const defaultLayoutBlocks = useMemo(
		() =>
			createBlocksFromInnerBlocksTemplate(
				defaultTemplate
			) as unknown as TemplateBlocks,
		[ defaultTemplate ]
	);
	const syncedBlocks = layoutBlocks ?? defaultLayoutBlocks;
	const feedGroup = feedGroupOf( isSynced ? syncedBlocks : innerBlocks );
	const blockProps = useBlockProps( {
		style: feedGapStyle( feedGroup ),
	} );

	const allSampleContexts = useSampleEntries( showsSamples );
	const sampleContexts = useMemo(
		() =>
			isSamplePreview
				? allSampleContexts.slice( 0, entriesPerPage )
				: allSampleContexts,
		[ allSampleContexts, isSamplePreview, entriesPerPage ]
	);
	const entriesCoverageId =
		coverageId || ( isLayoutPattern ? PREVIEW_COVERAGE_ID : 0 );
	const isLoading =
		needsDefaultLayout ||
		( coverageId > 0 && coverageLoadedFor !== coverageId ) ||
		( entriesCoverageId > 0 && entriesLoadedFor !== entriesCoverageId ) ||
		( showsSamples && sampleContexts.length === 0 ) ||
		( isSynced && ! hasResolvedLayout );
	const previewContexts =
		showsSamples && entryContexts.length === 0
			? sampleContexts
			: entryContexts;
	const { templateBlocks, blocksForEntry } = useLayoutPreview(
		isSynced ? feedItems( syncedBlocks ) : allBlocks,
		previewContexts,
		entriesPerPage
	);
	const emptyPreviewBlocks = useMemo(
		() => forEntryKind( templateBlocks, false ),
		[ templateBlocks ]
	);

	const { setBlockEditingMode, unsetBlockEditingMode } = useDispatch(
		blockEditorStore.name
	) as unknown as {
		setBlockEditingMode: ( clientId: string, mode: string ) => void;
		unsetBlockEditingMode: ( clientId: string ) => void;
	};

	// Hidden wherever the site never renders it: without OneSignal, or when the
	// coverage is archived. It stays in the template for when it can render.
	const isFollowHidden =
		! ONESIGNAL_CONFIGURED || currentCoverage?.status === 'archived';
	// An editable layout previews the pinned card against the pinned entry
	// and the entry group against one that isn't pinned, and leaves out the
	// one the coverage has no entry for, and "Read more" where the entry
	// previewed has no breakout post.
	const hasBothKinds =
		allBlocks.some( isPinnedCard ) && allBlocks.some( isRegularEntry );
	const pinnedContext = hasBothKinds
		? previewContexts.find( ( context ) => context.pinned )
		: undefined;
	const unpinnedContexts = previewContexts.filter(
		( context ) => ! context.pinned
	);
	const titledContexts = unpinnedContexts.filter(
		( context ) => context.hasTitle !== false
	);
	const regularContext = hasBothKinds
		? ( titledContexts.find( ( context ) => context.hasBreakout ) ??
			titledContexts[ 0 ] ??
			unpinnedContexts[ 0 ] )
		: previewContexts[ 0 ];
	const layoutContext =
		regularContext ?? pinnedContext ?? NEUTRAL_ENTRY_CONTEXT;
	const isCardHidden = hasBothKinds && ! pinnedContext;
	const isEntryHidden = hasBothKinds && ! regularContext && !! pinnedContext;
	const hidesCardBreakout = pinnedContext
		? ! pinnedContext.hasBreakout
		: false;
	const hidesEntryBreakout = regularContext
		? ! regularContext.hasBreakout
		: false;
	const hiddenIds = useMemo(
		() => [
			...allBlocks
				.filter(
					( block, index ) =>
						( isFollowHidden &&
							( block.name === FOLLOW_BLOCK_NAME ||
								isFollowButtons( block ) ) ) ||
						( isCardHidden && isPinnedCard( block ) ) ||
						( isEntryHidden &&
							( isRegularEntry( block ) ||
								( block.name === 'core/separator' &&
									index === allBlocks.length - 1 ) ) )
				)
				.map( ( block ) => block.clientId as string ),
			...breakoutBlockIds(
				allBlocks.filter( ( block ) =>
					pinnedContext && isPinnedCard( block )
						? hidesCardBreakout
						: hidesEntryBreakout
				)
			),
		],
		[
			allBlocks,
			isFollowHidden,
			isCardHidden,
			isEntryHidden,
			pinnedContext,
			hidesCardBreakout,
			hidesEntryBreakout,
		]
	);
	const hiddenKey = hiddenIds.join( ',' );
	useEffect( () => {
		const ids = hiddenKey ? hiddenKey.split( ',' ) : [];
		ids.forEach( ( id ) => setBlockEditingMode( id, 'disabled' ) );
		return () => ids.forEach( ( id ) => unsetBlockEditingMode( id ) );
	}, [ hiddenKey, setBlockEditingMode, unsetBlockEditingMode ] );

	const layoutCss = useMemo(
		() =>
			hiddenIds
				.map(
					( id ) =>
						`.wp-block-newspack-rolling-coverage-rolling-coverage .newspack-rolling-coverage-layout [data-block="${ id }"] { display: none; }`
				)
				.join( '\n' ),
		[ hiddenIds ]
	);

	const syncedRenderOnceBlocks = useMemo(
		() =>
			feedItems( syncedBlocks )
				.filter(
					( block ) =>
						block.name === FOLLOW_BLOCK_NAME ||
						isFollowButtons( block )
				)
				.filter(
					( block ) =>
						! isFollowHidden ||
						( block.name !== FOLLOW_BLOCK_NAME &&
							! isFollowButtons( block ) )
				),
		[ syncedBlocks, isFollowHidden ]
	);

	const detach = useCallback( () => {
		registry.batch( () => {
			replaceInnerBlocks(
				clientId,
				syncedBlocks.map( ( block ) =>
					cloneBlock(
						block as unknown as Parameters< typeof cloneBlock >[ 0 ]
					)
				),
				false
			);
			setAttributes( { layoutId: 0 } );
		} );
	}, [
		registry,
		syncedBlocks,
		clientId,
		replaceInnerBlocks,
		setAttributes,
	] );

	const applyLayout = useCallback(
		( choice: LayoutChoice ) => {
			setIsPickingLayout( false );

			if ( choice.kind === 'pattern' ) {
				if ( isSynced && choice.id === layoutId ) {
					if ( isLayoutMissing ) {
						invalidateResolution( 'getEntityRecord', [
							'postType',
							'wp_block',
							layoutId,
							{ context: 'view' },
						] );
					}
					return;
				}
				registry.batch( () => {
					if ( ! isSynced && innerBlockCount > 0 ) {
						replaceInnerBlocks( clientId, [], false );
					}
					setAttributes( { layoutId: choice.id } );
				} );
				return;
			}

			const layout = getBuiltInLayouts().find(
				( item ) => item.slug === choice.slug
			);

			if ( ! layout ) {
				return;
			}

			registry.batch( () => {
				replaceInnerBlocks(
					clientId,
					createBlocksFromInnerBlocksTemplate( layout.template() ),
					false
				);
				setAttributes( { layoutId: 0 } );
			} );
		},
		[
			isSynced,
			isLayoutMissing,
			invalidateResolution,
			layoutId,
			innerBlockCount,
			registry,
			clientId,
			replaceInnerBlocks,
			setAttributes,
		]
	);

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
		if ( ! entriesCoverageId ) {
			setEntryContexts( [] );
			setEntriesLoadedFor( 0 );
			return;
		}
		const { getEntityRecord } = registry.resolveSelect(
			coreStore
		) as unknown as {
			getEntityRecord: (
				kind: string,
				name: string,
				id: number
			) => Promise< unknown >;
		};
		fetchEntryPreviewContexts( entriesCoverageId, entriesPerPage )
			.then( ( contexts ) =>
				// Entries are read before the preview shows, so it doesn't
				// fill in piece by piece.
				Promise.all(
					contexts.map( ( context ) =>
						getEntityRecord(
							'postType',
							context.postType,
							context.postId
						).catch( () => undefined )
					)
				).then( () => contexts )
			)
			.then( ( contexts ) => {
				if ( ! cancelled ) {
					setEntryContexts( contexts );
					setEntriesLoadedFor( entriesCoverageId );
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [ entriesCoverageId, entriesPerPage, registry ] );

	// Populate the combobox as the user searches.
	useEffect( () => {
		let cancelled = false;

		if ( isPreviewMode ) {
			return;
		}

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
	}, [ search, currentCoverage, isPreviewMode ] );

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
			setCoverageLoadedFor( coverageId );
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

	const inspector = isLayoutPattern ? (
		<InspectorControls>
			<PanelBody
				title={ __( 'Shared layout', 'newspack-rolling-coverage' ) }
			>
				<p>
					{ entryContexts.length > 0
						? __(
								'Changes to this layout apply to every story that uses it.',
								'newspack-rolling-coverage'
							)
						: __(
								'Changes to this layout apply to every story that uses it. The entries are samples.',
								'newspack-rolling-coverage'
							) }
				</p>
			</PanelBody>
		</InspectorControls>
	) : (
		<InspectorControls>
			{ ! needsLayout && (
				<PanelBody
					title={ __( 'Layout', 'newspack-rolling-coverage' ) }
				>
					<p>
						{ isSynced
							? __(
									'Uses the shared layout. Changes to it apply to every story that uses it.',
									'newspack-rolling-coverage'
								)
							: __(
									'Uses its own layout, detached from the shared one.',
									'newspack-rolling-coverage'
								) }
					</p>
				</PanelBody>
			) }
			<PanelBody title={ __( 'Coverage', 'newspack-rolling-coverage' ) }>
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

			<PanelBody title={ __( 'Display', 'newspack-rolling-coverage' ) }>
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
							entriesPerPage: value ? parseInt( value, 10 ) : 20,
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
							pollInterval: value ? parseInt( value, 10 ) : 10,
						} )
					}
				/>
			</PanelBody>

			<PanelBody
				title={ _x(
					'Archived',
					'settings panel title',
					'newspack-rolling-coverage'
				) }
				initialOpen={ false }
			>
				<ToggleControl
					label={ __(
						'Show archived notice',
						'newspack-rolling-coverage'
					) }
					help={ __(
						"Tells readers the coverage has ended. Shown at the top of the feed once it's archived.",
						'newspack-rolling-coverage'
					) }
					checked={ archivedNoticeShow }
					onChange={ ( value: boolean ) =>
						setAttributes( { archivedNoticeShow: value } )
					}
				/>
				{ archivedNoticeShow && (
					<>
						<TextareaControl
							label={ __(
								'Notice',
								'newspack-rolling-coverage'
							) }
							placeholder={
								currentCoverage?.label
									? sprintf(
											/* translators: %s: Coverage name. */
											__(
												'Coverage of “%s” has concluded and this feed is now archived.',
												'newspack-rolling-coverage'
											),
											decodeEntities(
												currentCoverage.label
											)
										)
									: __(
											'Coverage of this news event has concluded and this feed is now archived.',
											'newspack-rolling-coverage'
										)
							}
							value={ archivedNotice }
							onChange={ ( value: string ) =>
								setAttributes( { archivedNotice: value } )
							}
						/>
						<ToggleControl
							label={ __(
								'Add a link',
								'newspack-rolling-coverage'
							) }
							help={ __(
								'Points readers to where the story continues.',
								'newspack-rolling-coverage'
							) }
							checked={ archivedNoticeShowLink }
							onChange={ ( value: boolean ) =>
								setAttributes( {
									archivedNoticeShowLink: value,
								} )
							}
						/>
						{ archivedNoticeShowLink && (
							<>
								<TextControl
									__next40pxDefaultSize
									type="url"
									label={ __(
										'Link',
										'newspack-rolling-coverage'
									) }
									help={ __(
										"When empty, links to the coverage's latest breakout post, if there is one.",
										'newspack-rolling-coverage'
									) }
									placeholder="https://example.com/story"
									value={ archivedNoticeLinkUrl }
									onChange={ ( value: string ) =>
										setAttributes( {
											archivedNoticeLinkUrl: value,
										} )
									}
								/>
								<TextControl
									__next40pxDefaultSize
									label={ __(
										'Link text',
										'newspack-rolling-coverage'
									) }
									placeholder={ __(
										'Read more',
										'newspack-rolling-coverage'
									) }
									value={ archivedNoticeLinkLabel }
									onChange={ ( value: string ) =>
										setAttributes( {
											archivedNoticeLinkLabel: value,
										} )
									}
								/>
							</>
						) }
					</>
				) }
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
				<PanelBody title={ __( 'Ads', 'newspack-rolling-coverage' ) }>
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
	);

	return (
		<>
			{ inspector }

			{ ! isLoading && canChangeLayout && (
				<BlockControls group="other">
					<ToolbarButton onClick={ () => setIsPickingLayout( true ) }>
						{ __( 'Change layout', 'newspack-rolling-coverage' ) }
					</ToolbarButton>
					{ isSynced && coverageId > 0 && canEditLayout && (
						<ToolbarButton
							{ ...{
								href: getLayoutEditUrl( layoutId, coverageId ),
								target: '_blank',
							} }
						>
							{ __( 'Edit Layout', 'newspack-rolling-coverage' ) }
						</ToolbarButton>
					) }
					{ isSynced && coverageId > 0 && (
						<ToolbarButton onClick={ detach }>
							{ __( 'Detach', 'newspack-rolling-coverage' ) }
						</ToolbarButton>
					) }
				</BlockControls>
			) }

			{ isPickingLayout && (
				<LayoutPickerModal
					currentLayoutId={ isSynced ? layoutId : 0 }
					onSelect={ applyLayout }
					onClose={ () => setIsPickingLayout( false ) }
				/>
			) }

			<div { ...blockProps }>
				{ isLoading && ! isPreviewMode && (
					<LoadingState
						label={ __(
							'Fetching entries…',
							'newspack-rolling-coverage'
						) }
					/>
				) }
				{ ! isLoading && needsLayout && (
					<Placeholder
						icon={ activity }
						label="Rolling Coverage"
						instructions={
							isPreviewMode
								? undefined
								: __(
										"Choose a layout for the coverage's entries.",
										'newspack-rolling-coverage'
									)
						}
					>
						{ ! isPreviewMode && (
							<Button
								__next40pxDefaultSize
								variant="primary"
								onClick={ () => setIsPickingLayout( true ) }
							>
								{ __( 'Choose', 'newspack-rolling-coverage' ) }
							</Button>
						) }
					</Placeholder>
				) }
				{ ! isLoading &&
					! needsLayout &&
					( hasLayout ? (
						<>
							{ layoutCss && <style>{ layoutCss }</style> }
							{ ! isLayoutPattern &&
								currentCoverage?.status === 'trash' && (
									<Notice
										status="error"
										isDismissible={ false }
									>
										{ __(
											'This coverage has been trashed and is no longer available. Select a different coverage or restore it from the Rolling Coverage admin.',
											'newspack-rolling-coverage'
										) }
									</Notice>
								) }
							{ ! isLayoutPattern &&
								previewContexts.length === 0 && (
									<Notice
										status="info"
										isDismissible={ false }
									>
										{ __(
											'No published entries yet — showing the template only. Add entries to this coverage to preview real content here.',
											'newspack-rolling-coverage'
										) }
									</Notice>
								) }
							{ isSynced && (
								<div { ...feedPreviewProps( feedGroup ) }>
									{ syncedRenderOnceBlocks.length > 0 && (
										<BlockContextProvider
											value={
												previewContexts[ 0 ] ??
												NEUTRAL_ENTRY_CONTEXT
											}
										>
											<EntryBlockPreview
												blocks={
													syncedRenderOnceBlocks
												}
											/>
										</BlockContextProvider>
									) }
									<div className="newspack-rolling-coverage-entries">
										{ previewContexts.length > 0 ? (
											previewContexts.map(
												( context ) => (
													<BlockContextProvider
														key={ context.postId }
														value={ context }
													>
														<EntryBlockPreview
															blocks={ blocksForEntry(
																context
															) }
														/>
													</BlockContextProvider>
												)
											)
										) : (
											<BlockContextProvider
												value={ NEUTRAL_ENTRY_CONTEXT }
											>
												<EntryBlockPreview
													blocks={
														emptyPreviewBlocks
													}
												/>
											</BlockContextProvider>
										) }
									</div>
								</div>
							) }
							{ ! isSynced && (
								<>
									<PinnedEntryContext.Provider
										value={ pinnedContext ?? null }
									>
										<BlockContextProvider
											value={ layoutContext }
										>
											<div { ...innerBlocksProps } />
										</BlockContextProvider>
									</PinnedEntryContext.Provider>
									<div className="newspack-rolling-coverage-entries">
										{ previewContexts
											.filter(
												( context ) =>
													context !== pinnedContext &&
													context !== regularContext
											)
											.map( ( context ) => (
												<BlockContextProvider
													key={ context.postId }
													value={ context }
												>
													<EntryBlockPreview
														blocks={ blocksForEntry(
															context
														) }
													/>
												</BlockContextProvider>
											) ) }
									</div>
								</>
							) }
						</>
					) : (
						<Placeholder
							icon={ activity }
							label="Rolling Coverage"
							instructions={ __(
								'Select a coverage to display its entries.',
								'newspack-rolling-coverage'
							) }
							isColumnLayout
						>
							{ coverageCombobox }
						</Placeholder>
					) ) }
			</div>
		</>
	);
}
