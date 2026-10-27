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
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControl as ToggleGroupControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
	Button,
	Disabled,
	ExternalLink,
	Notice,
	Placeholder,
	SelectControl,
	TextareaControl,
	ToolbarButton,
} from '@wordpress/components';
import {
	createInterpolateElement,
	useState,
	useEffect,
	useCallback,
	useMemo,
	useRef,
} from '@wordpress/element';
import { useDebounce, useInstanceId } from '@wordpress/compose';
import { speak } from '@wordpress/a11y';
import { useSelect, useDispatch, useRegistry } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { store as editorStore } from '@wordpress/editor';
import { store as noticesStore } from '@wordpress/notices';
import { decodeEntities } from '@wordpress/html-entities';
import { __, _n, _x, sprintf } from '@wordpress/i18n';
import { Stack, Text } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import { liveStreaming } from '../../shared/icons/live-streaming';
import {
	searchCoverages,
	getCoverage,
	updateCoverageCanonicalUrl,
	fetchEntryPreviewContexts,
	generateKeyTakeaways,
	getLayoutEditUrl,
	getLayoutId,
	getLayoutCategoryId,
	createLayout,
} from './utils';
import {
	feedPathOf,
	feedItems,
	followBlockIds,
	allUpdatesBlockIds,
	emptiedGroupIds,
	withoutAllUpdatesParagraph,
	withAllUpdatesText,
	allUpdatesTextOf,
	isPinnedCard,
	isPinnedStack,
	pinnedStackGroups,
	loadMoreInsetStyle,
	startsAtInlineStart,
	PINNED_STACK_CLASS,
	isRegularEntry,
	isBreakoutEntry,
	forEntryKind,
	breakoutBlockIds,
	featuredImageBlockIds,
	fullStoryTakesPinned,
	pinnedRowBlockIds,
	hiddenNewLabelIds,
	withoutFollowButtons,
	withoutCheckUpdatesButtons,
	withoutLoadMoreButtons,
	withAuthorSettings,
	blockIdsOfType,
	holdsBlockType,
	hiddenAuthorBlockIds,
	layoutParts,
	CHECK_UPDATES_BLOCK_NAME,
	LOAD_MORE_BLOCK_NAME,
	entryPreviewPlacement,
	withColumnRule,
	RULED_FEED_CLASS,
} from './template';
import {
	AI_AVAILABLE,
	AI_SETTINGS_URL,
	NEWSPACK_ADS_AVAILABLE,
	NEWSPACK_ADS_PLACEMENT_ENABLED,
	ONESIGNAL_CONFIGURED,
	MIN_POLL_INTERVAL,
	STATUS_LABELS,
} from './config';
import { COVERAGE_ID_CONTEXT } from '../shared/entry-bindings';
import metadata from './block.json';
import { useSampleEntries } from './samples';
import EntryBlockPreview from './components/entry-block-preview';
import LoadingState from './components/loading-state';
import LayoutPickerModal, {
	type LayoutChoice,
	layoutsQuery,
} from './components/layout-picker-modal';
import {
	getBuiltInLayouts,
	builtInLayoutSlugFor,
	isLayoutPatternPost,
	switchLayoutAttributes,
	type BuiltInLayoutSlug,
} from './layouts';
import PinnedEntryContext from './pinned-entry-context';
import FullStoryEntryContext, {
	PinnedFullStoryEntryContext,
} from './full-story-entry-context';
import UntitledEntriesContext from './untitled-entry-title';
import { receiveBreakoutPreviews } from './breakout-previews';
import {
	EntryPreviewsAnchorContext,
	EntryPreviewsContext,
	PinnedPreviewsContext,
} from './entry-previews';
import { blockGapCss } from './spacing';
import {
	BLOCK_NAME,
	innerTemplate,
	isFullStoryContext,
	useLayoutPreview,
} from './layout';
import type {
	CoverageOption,
	EditProps,
	EntryContext,
	TemplateBlocks,
} from './types';

/**
 * The layout the block offers the blocks of a layout: full width, so a bar
 * like Flash's can span the page in the editor as it does on the site.
 */
const INNER_BLOCKS_LAYOUT = {
	type: 'default',
	alignments: [ 'none', 'wide', 'full' ],
};

/**
 * What each choice of loading older entries does, as the help below it.
 */
const OLDER_ENTRIES_HELP: Record< string, () => string > = {
	scroll: () =>
		__(
			'More entries load as readers scroll down.',
			'newspack-rolling-coverage'
		),
	button: () =>
		/* translators: “Load more” is the label of the button readers press. Keep the words used to translate it. */
		__(
			'Readers load more entries with a “Load more” button.',
			'newspack-rolling-coverage'
		),
	none: () =>
		__(
			'Readers see only the first page of entries.',
			'newspack-rolling-coverage'
		),
};

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
		| {
				style?: {
					spacing?: {
						blockGap?: string | { top?: string; left?: string };
					};
				};
		  }
		| undefined;
	const blockGap = attributes?.style?.spacing?.blockGap;
	const gap = blockGapCss( blockGap );
	const columnGap =
		typeof blockGap === 'object' ? blockGapCss( blockGap.left ) : undefined;

	return {
		...( gap ? { '--newspack-rolling-coverage-gap': gap } : {} ),
		...( columnGap
			? { '--newspack-rolling-coverage-column-gap': columnGap }
			: {} ),
	};
}

/**
 * Whether Block Visibility shows a block in every viewport.
 *
 * @param {Object} block The block.
 * @return {boolean} Whether the block shows everywhere.
 */
function isShownEverywhere( block: { [ key: string ]: unknown } ): boolean {
	const visibility = (
		( block.attributes as { metadata?: unknown } | undefined )?.metadata as
			| {
					blockVisibility?:
						boolean | { viewport?: Record< string, boolean > };
			  }
			| undefined
	 )?.blockVisibility;

	return (
		visibility !== false &&
		! Object.values(
			( typeof visibility === 'object' && visibility.viewport ) || {}
		).includes( false )
	);
}

const FLEX_JUSTIFY: Record< string, string > = {
	left: 'flex-start',
	right: 'flex-end',
	center: 'center',
};

const FLEX_VERTICAL: Record< string, string > = {
	top: 'flex-start',
	center: 'center',
	bottom: 'flex-end',
};

/**
 * The flex declarations core's layout support emits for a Feed group, so the
 * preview container lays out its children the same way. A layout that isn't
 * flex has none: the stylesheet lays it out as a column, unless it's a grid
 * (see feedGridStyle()).
 *
 * @param {Object} layout The Feed group's layout attribute.
 * @return {Object|null} The container's inline style, or null.
 */
function feedFlexStyle( layout?: Record< string, string > ): {
	[ key: string ]: string;
} | null {
	if ( layout?.type !== 'flex' ) {
		return null;
	}

	const { justifyContent, verticalAlignment } = layout;

	if ( layout.orientation !== 'vertical' ) {
		const justify: Record< string, string > = {
			...FLEX_JUSTIFY,
			'space-between': 'space-between',
		};
		const vertical: Record< string, string > = {
			...FLEX_VERTICAL,
			stretch: 'stretch',
		};

		return {
			flexDirection: 'row',
			flexWrap: layout.flexWrap === 'nowrap' ? 'nowrap' : 'wrap',
			...( justifyContent && justify[ justifyContent ]
				? { justifyContent: justify[ justifyContent ] }
				: {} ),
			...( verticalAlignment && vertical[ verticalAlignment ]
				? { alignItems: vertical[ verticalAlignment ] }
				: {} ),
		};
	}

	const justify: Record< string, string > = {
		...FLEX_JUSTIFY,
		stretch: 'stretch',
	};
	const vertical: Record< string, string > = {
		...FLEX_VERTICAL,
		'space-between': 'space-between',
	};

	return {
		flexDirection: 'column',
		...( layout.flexWrap === 'nowrap' ? { flexWrap: 'nowrap' } : {} ),
		alignItems:
			justifyContent && justify[ justifyContent ]
				? justify[ justifyContent ]
				: 'flex-start',
		...( verticalAlignment && vertical[ verticalAlignment ]
			? { justifyContent: vertical[ verticalAlignment ] }
			: {} ),
	};
}

/**
 * The grid declarations core's layout support emits for a Feed group, with
 * its gap, so the preview container lays its children out in the same
 * columns. The preview shows the desktop layout, without the Feed's tablet
 * and mobile overrides. A layout that isn't grid has none.
 *
 * @param {Object} layout   The Feed group's layout attribute.
 * @param {Object} blockGap The Feed group's Block spacing setting.
 * @return {Object|null} The container's inline style, or null.
 */
function feedGridStyle(
	layout?: Record< string, string >,
	blockGap?: string | { top?: string; left?: string }
): { [ key: string ]: string } | null {
	if ( layout?.type !== 'grid' ) {
		return null;
	}

	const fallbackGap = 'var(--wp--style--block-gap, 0.5em)';
	const rowGap = blockGapCss( blockGap ) ?? fallbackGap;
	const columnGap =
		( typeof blockGap === 'object'
			? blockGapCss( blockGap.left )
			: undefined ) ?? rowGap;
	const { columnCount, minimumColumnWidth } = layout;
	const placement = layout.autoFit ? 'auto-fit' : 'auto-fill';
	const gap = rowGap === columnGap ? rowGap : `${ rowGap } ${ columnGap }`;

	if ( columnCount && ! minimumColumnWidth ) {
		return {
			gap,
			gridTemplateColumns: `repeat(${ columnCount }, minmax(0, 1fr))`,
		};
	}

	const minimum = minimumColumnWidth || '12rem';
	const track = columnCount
		? `max(min(${ minimum }, 100%), (100% - (${ columnGap } * (${ columnCount } - 1))) /${ columnCount })`
		: `min(${ minimum }, 100%)`;

	return {
		gap,
		gridTemplateColumns: `repeat(${ placement }, minmax(${ track }, 1fr))`,
		containerType: 'inline-size',
	};
}

/**
 * A group's own classes and styles (alignment, layout, color, border,
 * spacing, typography), for the container a synced layout's preview shows in
 * place of the group, so it previews as the site renders it.
 *
 * @param {Object} group The group.
 * @return {Object} The container's classNames and style.
 */
function groupPreviewParts( group?: { [ key: string ]: unknown } ): {
	classNames: unknown[];
	style: Record< string, unknown >;
} {
	const attributes = ( group?.attributes ?? {} ) as Record< string, unknown >;
	const layout = attributes.layout as Record< string, string > | undefined;
	const parts = [
		getColorClassesAndStyles( attributes ),
		getBorderClassesAndStyles( attributes ),
		getSpacingClassesAndStyles( attributes ),
		getTypographyClassesAndStyles( attributes ),
		getShadowClassesAndStyles( attributes ),
		getDimensionsClassesAndStyles( attributes ),
	];
	const flexStyle = feedFlexStyle( layout );
	const gridStyle = feedGridStyle(
		layout,
		(
			attributes.style as
				| {
						spacing?: {
							blockGap?: string | { top?: string; left?: string };
						};
				  }
				| undefined
		 )?.spacing?.blockGap
	);

	return {
		classNames: [
			flexStyle ? 'is-layout-flex' : '',
			flexStyle && layout?.orientation
				? `is-${ layout.orientation }`
				: '',
			flexStyle && layout?.flexWrap === 'nowrap' ? 'is-nowrap' : '',
			gridStyle ? 'is-layout-grid' : '',
			layout?.type === 'constrained' ? 'is-layout-constrained' : '',
			attributes.className,
			...parts.map( ( part ) => part.className ),
			typeof attributes.align === 'string'
				? `align${ attributes.align }`
				: '',
		],
		style: Object.assign(
			{},
			flexStyle ?? {},
			gridStyle ?? {},
			...parts.map( ( part ) => part.style )
		),
	};
}

/**
 * A container's className from a list of class names.
 *
 * @param {Array} classNames The class names, possibly empty or space-separated.
 * @return {string} The className.
 */
function joinClassNames( classNames: unknown[] ): string {
	const names = classNames
		.filter( ( name ): name is string => typeof name === 'string' )
		.flatMap( ( name ) => name.split( ' ' ) )
		.filter( Boolean );

	return [ ...new Set( names ) ].join( ' ' );
}

/**
 * The child sizing core gives a lone coverage-level block set in the Feed,
 * for the container its preview sits in, so a block set to fill the Feed's
 * row fills it in the preview too, and one spanning a grid Feed's columns
 * spans them. The preview shows the desktop layout; a span across every
 * column covers the full row.
 *
 * @param {Object[]} blocks     The coverage-level blocks previewed together.
 * @param {Object}   feedLayout The Feed group's layout attribute.
 * @return {Object|undefined} The container's inline style.
 */
function chromePreviewStyle(
	blocks: TemplateBlocks,
	feedLayout?: Record< string, unknown >
): Record< string, string | number > | undefined {
	const attributes = blocks.length === 1 ? blocks[ 0 ].attributes : null;
	const layout = (
		attributes as { style?: { layout?: Record< string, string > } } | null
	 )?.style?.layout;
	const columnSpan = Number( layout?.columnSpan );

	if ( feedLayout?.type === 'grid' && columnSpan > 0 ) {
		return {
			gridColumn:
				columnSpan >= Number( feedLayout.columnCount )
					? '1 / -1'
					: `span ${ columnSpan }`,
		};
	}

	if ( layout?.selfStretch === 'fill' ) {
		return String(
			( attributes as { className?: string } | null )?.className ?? ''
		)
			.split( ' ' )
			.includes( 'newspack-rolling-coverage-name' )
			? { flexGrow: 1, minWidth: 0 }
			: { flexGrow: 1 };
	}

	if ( layout?.selfStretch === 'fixed' && layout.flexSize ) {
		return { flexBasis: layout.flexSize };
	}

	if ( layout?.selfStretch === 'fixedNoShrink' && layout.flexSize ) {
		return { flexShrink: 0, flexBasis: layout.flexSize };
	}

	return undefined;
}

/**
 * A color from a group's style as CSS: a `var:preset|color|slug` reference as
 * its custom property, kebab-cased as core prints it, or any other value the
 * browser accepts as a color.
 *
 * @param {unknown} value The style value.
 * @return {string|null} The CSS value, or null when it isn't a color.
 */
function cssColor( value: unknown ): string | null {
	if ( typeof value !== 'string' || ! value ) {
		return null;
	}

	if ( value.startsWith( 'var:' ) ) {
		const segments = value
			.slice( 4 )
			.split( '|' )
			.map( ( segment ) =>
				segment
					.replace( /([a-z])([A-Z])/g, '$1-$2' )
					.replace( /([A-Z])([A-Z][a-z])/g, '$1-$2' )
					.replace( /([a-zA-Z])(\d)/g, '$1-$2' )
					.replace( /(\d)([a-zA-Z])/g, '$1-$2' )
					.replace( /[^a-zA-Z0-9]+/g, '-' )
					.replace( /^-|-$/g, '' )
					.toLowerCase()
			);

		return segments.every( ( segment ) => /^[a-z0-9-]+$/.test( segment ) )
			? `var(--wp--${ segments.join( '--' ) })`
			: null;
	}

	return CSS.supports( 'color', value ) ? value : null;
}

/**
 * The link and heading colors a group sets for the blocks inside it, as the
 * rules core's elements support prints for it on the site, scoped to the
 * container a synced layout's preview shows in place of the group.
 *
 * @param {Object} group    The group.
 * @param {string} selector The container's selector.
 * @return {string} The CSS.
 */
function groupElementsCSS(
	group: { [ key: string ]: unknown } | undefined,
	selector: string
): string {
	const elements = (
		( group?.attributes ?? {} ) as {
			style?: {
				elements?: Record<
					string,
					{
						color?: { text?: string };
						':hover'?: { color?: { text?: string } };
					}
				>;
			};
		}
	 ).style?.elements;
	const link = `${ selector } a:where(:not(.wp-element-button))`;
	const rules: [ string, unknown ][] = [
		[ link, elements?.link?.color?.text ],
		[ `${ link }:hover`, elements?.link?.[ ':hover' ]?.color?.text ],
		[
			`${ selector } :is(h1, h2, h3, h4, h5, h6)`,
			elements?.heading?.color?.text,
		],
	];

	return rules
		.map( ( [ rule, value ] ) => {
			const color = cssColor( value );
			return color ? `${ rule } { color: ${ color }; }` : '';
		} )
		.filter( Boolean )
		.join( '\n' );
}

/**
 * Whether the layout's Feed group is a ruled row, whose entries the site's
 * stylesheet lines up in one element that scrolls.
 *
 * @param {Object} feed The layout's Feed group.
 * @return {boolean} Whether it's a ruled row.
 */
function isRuledRow( feed: { [ key: string ]: unknown } | undefined ) {
	const { className, layout } = ( feed?.attributes ?? {} ) as {
		className?: string;
		layout?: { type?: string; orientation?: string };
	};

	return (
		( className ?? '' ).split( ' ' ).includes( RULED_FEED_CLASS ) &&
		layout?.type === 'flex' &&
		layout.orientation !== 'vertical'
	);
}

/**
 * Whether the layout's Feed group is a horizontal row set not to wrap, whose
 * entries the site's stylesheet lines up on one line.
 *
 * @param {Object} feed The layout's Feed group.
 * @return {boolean} Whether it's a one-line row.
 */
function isOneLineRow( feed: { [ key: string ]: unknown } | undefined ) {
	const { layout } = ( feed?.attributes ?? {} ) as {
		layout?: { type?: string; orientation?: string; flexWrap?: string };
	};

	return (
		layout?.type === 'flex' &&
		layout.orientation !== 'vertical' &&
		layout.flexWrap === 'nowrap'
	);
}

/**
 * The Feed group's own classes and styles, for the container a synced
 * layout's preview shows in place of the Feed. A ruled Feed takes its gap
 * from the block's stylesheet, which widens it to fit the rules.
 *
 * @param {Object} feed  The layout's Feed group.
 * @param {string} scope The class FeedWrappersPreview scopes the Feed's
 *                       element colors to.
 * @return {Object} The container's className and style.
 */
function feedPreviewProps(
	feed: { [ key: string ]: unknown } | undefined,
	scope: string
): {
	className: string;
	style: Record< string, unknown >;
} {
	const { classNames, style } = groupPreviewParts( feed );
	const className = joinClassNames( [
		'wp-block-group',
		'newspack-rolling-coverage-feed',
		scope,
		...classNames,
	] );

	if ( className.split( ' ' ).includes( RULED_FEED_CLASS ) ) {
		const { gap, ...rest } = style;

		return { className, style: rest };
	}

	return { className, style };
}

/**
 * The groups wrapping a synced layout's Feed, previewed around it with
 * their own classes, styles and other blocks, as the site renders them.
 *
 * @param {Object}      props          Component props.
 * @param {Object[]}    props.path     The groups leading to the Feed, the Feed last.
 * @param {Object}      props.context  The coverage's block context.
 * @param {string}      props.scope    The class the Feed's preview carries;
 *                                     each wrapper's is suffixed with its depth.
 * @param {JSX.Element} props.children The Feed's preview.
 * @return {JSX.Element} The Feed's preview inside its wrappers.
 */
function FeedWrappersPreview( {
	path,
	context,
	scope,
	children,
}: {
	path: TemplateBlocks;
	context: Record< string, unknown >;
	scope: string;
	children: JSX.Element;
} ): JSX.Element {
	const css = path
		.map( ( group, index ) =>
			groupElementsCSS(
				group,
				index === path.length - 1
					? `.${ scope }`
					: `.${ scope }-${ index }`
			)
		)
		.filter( Boolean )
		.join( '\n' );
	const tree = path.slice( 0, -1 ).reduceRight( ( inner, wrapper, index ) => {
		const siblings = ( wrapper.innerBlocks ?? [] ) as TemplateBlocks;
		const position = siblings.indexOf( path[ index + 1 ] );
		const before = siblings.slice( 0, Math.max( position, 0 ) );
		const after = position < 0 ? [] : siblings.slice( position + 1 );
		const { classNames, style } = groupPreviewParts( wrapper );

		return (
			<div
				className={ joinClassNames( [
					'wp-block-group',
					`${ scope }-${ index }`,
					...classNames,
				] ) }
				style={ style }
			>
				{ before.length > 0 && (
					<BlockContextProvider value={ context }>
						<EntryBlockPreview blocks={ before } />
					</BlockContextProvider>
				) }
				{ inner }
				{ after.length > 0 && (
					<BlockContextProvider value={ context }>
						<EntryBlockPreview blocks={ after } />
					</BlockContextProvider>
				) }
			</div>
		);
	}, children );

	return (
		<>
			{ tree }
			{ css && <style>{ css }</style> }
		</>
	);
}

/**
 * Entries per page as the server renders it: 1 to PER_PAGE_MAX (100),
 * 20 when unset.
 *
 * @param {number} value Stored or typed value.
 * @return {number} The page size.
 */
function clampEntriesPerPage( value: number ): number {
	return Math.min(
		Math.max( 1, Number.isFinite( value ) ? Math.trunc( value ) : 20 ),
		100
	);
}

/**
 * Copies text to the clipboard, falling back to the copy command where the
 * Clipboard API is missing (pages served over http).
 *
 * @param text Text to copy.
 * @return Whether the text was copied.
 */
async function copyText( text: string ): Promise< boolean > {
	try {
		if ( window.navigator.clipboard?.writeText ) {
			await window.navigator.clipboard.writeText( text );
			return true;
		}
		const { activeElement } = document;
		const textarea = document.createElement( 'textarea' );
		textarea.value = text;
		textarea.setAttribute( 'readonly', '' );
		textarea.style.position = 'fixed';
		textarea.style.left = '-9999px';
		document.body.appendChild( textarea );
		textarea.select();
		const isCopied = document.execCommand( 'copy' );
		textarea.remove();
		if ( activeElement instanceof HTMLElement ) {
			activeElement.focus();
		}
		return isCopied;
	} catch {
		return false;
	}
}

/**
 * Copies text that is still being fetched. The clipboard write starts at once,
 * while the click that asked for it still counts as a user action: Safari and
 * Firefox refuse a write that starts after an awaited request.
 *
 * @param text Text to copy, once it resolves. A rejection copies nothing.
 * @return Whether the text was copied.
 */
async function copyTextWhenReady(
	text: Promise< string >
): Promise< boolean > {
	const { clipboard } = window.navigator;
	if ( clipboard?.write && typeof window.ClipboardItem === 'function' ) {
		const isWritten = await clipboard
			.write( [
				new window.ClipboardItem( {
					'text/plain': text.then(
						( value ) =>
							new Blob( [ value ], { type: 'text/plain' } )
					),
				} ),
			] )
			.then(
				() => true,
				() => false
			);
		if ( isWritten ) {
			return true;
		}
	}
	return text.then( copyText, () => false );
}

/** Shared id, so each AI message replaces the last one instead of stacking. */
const AI_NOTICE_ID = 'newspack-rolling-coverage-ai-takeaways';

export default function Edit( {
	clientId,
	attributes,
	setAttributes,
}: EditProps ) {
	const {
		coverageId,
		latestOnly,
		latestCount,
		allUpdatesLink,
		allUpdatesLinkText,
		pollInterval,
		entriesPerPage,
		olderEntries,
		enableAds,
		adsInterval,
		hideWhenEnded,
		showAuthor,
		showAvatar,
		archivedNoticeShow,
		archivedNotice,
		archivedNoticeShowLink,
		archivedNoticeLinkUrl,
		archivedNoticeLinkLabel,
		layoutId,
		align,
	} = attributes;
	const pageSize = clampEntriesPerPage( entriesPerPage );
	const previewScope = useInstanceId(
		Edit,
		'newspack-rolling-coverage-preview'
	) as string;
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
		isLayoutPatternPost(
			currentPostType,
			currentPostId,
			patternCategories
		);
	// The pattern itself carries no cap, so its preview borrows the one picking the layout sets.
	const patternLatest = isLayoutPattern
		? getBuiltInLayouts().find(
				( layout ) =>
					layout.slug === builtInLayoutSlugFor( currentPostId )
			)?.latest
		: undefined;
	const isCapped = patternLatest ? true : !! latestOnly;
	const cappedCount = patternLatest ?? latestCount;
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
	const [ isPickerReady, setIsPickerReady ] = useState( false );
	const isPickerLoading = isPickingLayout && ! isPickerReady;
	const [ latestCountInput, setLatestCountInput ] = useState< string | null >(
		null
	);
	const [ entriesPerPageInput, setEntriesPerPageInput ] = useState<
		string | null
	>( null );
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
			layout: INNER_BLOCKS_LAYOUT,
			renderAppender: false,
		}
	);

	const [ search, setSearch ] = useState( '' );
	const setSearchDebounced = useDebounce( setSearch, 300 );
	const [ options, setOptions ] = useState< CoverageOption[] >( [] );
	const [ loadedSearch, setLoadedSearch ] = useState< string | null >( null );
	const [ currentCoverage, setCurrentCoverage ] =
		useState< CoverageOption | null >( null );
	const [ pendingCanonicalUrl, setPendingCanonicalUrl ] =
		useState< string >( '' );
	const [ isApplyingUrl, setIsApplyingUrl ] = useState( false );
	const [ entryContexts, setEntryContexts ] = useState< EntryContext[] >(
		[]
	);
	const [ hasMoreEntries, setHasMoreEntries ] = useState( false );
	const [ entriesLoadedFor, setEntriesLoadedFor ] = useState( 0 );
	const [ coverageLoadedFor, setCoverageLoadedFor ] = useState( 0 );
	const [ isGenerating, setIsGenerating ] = useState( false );
	const { createSuccessNotice, createErrorNotice } =
		useDispatch( noticesStore );
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

	const { invalidateResolution } = useDispatch( coreStore ) as unknown as {
		invalidateResolution: ( selector: string, args: unknown[] ) => void;
	};

	// Stories saved before layouts existed render in the default layout.
	const hadCoverageOnLoad = useRef( coverageId > 0 ).current;
	const needsDefaultLayout =
		hadCoverageOnLoad &&
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

		createLayout( 'default', innerTemplate )
			.then( ( id ) => {
				if ( getLayoutCategoryId() ) {
					invalidateResolution( 'getEntityRecords', [
						'postType',
						'wp_block',
						layoutsQuery( getLayoutCategoryId() ),
					] );
				}
				return ! cancelled && sync( id );
			} )
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
		invalidateResolution,
	] );

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
					| {
							status?: string;
							content?: { raw?: string } | string;
							title?: { raw?: string } | string;
					  }
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
	const layoutTitle = layoutBlocks
		? decodeEntities(
				( typeof layoutRecord?.title === 'string'
					? layoutRecord.title
					: layoutRecord?.title?.raw ) ?? ''
			).trim()
		: '';
	let layoutDescription:
		ReturnType< typeof createInterpolateElement > | string = __(
		'Uses its own layout, detached from the shared one.',
		'newspack-rolling-coverage'
	);
	if ( isSynced ) {
		layoutDescription = layoutTitle
			? createInterpolateElement(
					/* translators: <name /> is the shared layout's name, such as "Rail". */
					__(
						'Uses the shared <name /> layout. Changes to it apply to every story that uses it.',
						'newspack-rolling-coverage'
					),
					{ name: <strong>{ layoutTitle }</strong> }
				)
			: __(
					'Uses the shared layout. Changes to it apply to every story that uses it.',
					'newspack-rolling-coverage'
				);
	}
	const isChoosing =
		! isLayoutPattern &&
		! isNested &&
		( ( ! layoutId && ! innerBlockCount ) || isLayoutMissing );
	const needsLayout = isChoosing && coverageId > 0 && ! needsDefaultLayout;
	const hasLayout = coverageId > 0 || showsSamples;
	const canChangeLayout =
		! isLayoutPattern &&
		! isPreviewMode &&
		! isNested &&
		! isChoosing &&
		( isSynced || innerBlockCount > 0 );
	const defaultLayoutBlocks = useMemo(
		() =>
			createBlocksFromInnerBlocksTemplate(
				defaultTemplate
			) as unknown as TemplateBlocks,
		[ defaultTemplate ]
	);
	const syncedBlocks = layoutBlocks ?? defaultLayoutBlocks;
	const feedPath = feedPathOf( isSynced ? syncedBlocks : innerBlocks );
	const feedGroup = feedPath[ feedPath.length - 1 ];
	const feedLayout = (
		feedGroup?.attributes as { layout?: Record< string, unknown > }
	 )?.layout;
	const blockProps = useBlockProps( {
		style: feedGapStyle( feedGroup ),
	} );

	const allSampleContexts = useSampleEntries(
		showsSamples,
		( isSynced ? feedItems( syncedBlocks ) : allBlocks ).some(
			isPinnedStack
		)
	);
	const sampleContexts = useMemo( () => {
		if ( isCapped ) {
			return allSampleContexts
				.slice( 0, cappedCount )
				.map( ( context ) => ( { ...context, pinned: false } ) );
		}
		return isSamplePreview
			? allSampleContexts.slice( 0, pageSize )
			: allSampleContexts;
	}, [
		allSampleContexts,
		isSamplePreview,
		pageSize,
		isCapped,
		cappedCount,
	] );
	// A layout edited on its own previews the sample entries, whatever story
	// it was opened from, so it shows every kind of entry.
	const entriesCoverageId = isChoosing ? 0 : coverageId;
	const isLoading =
		needsDefaultLayout ||
		( ! isChoosing &&
			coverageId > 0 &&
			coverageLoadedFor !== coverageId ) ||
		( entriesCoverageId > 0 && entriesLoadedFor !== entriesCoverageId ) ||
		( showsSamples && sampleContexts.length === 0 ) ||
		( isSynced && ! hasResolvedLayout );
	const showsSampleContexts = showsSamples && entryContexts.length === 0;
	const previewContexts = showsSampleContexts
		? sampleContexts
		: entryContexts;
	const previewHasMore =
		! isCapped &&
		olderEntries !== 'none' &&
		( showsSampleContexts
			? allSampleContexts.length > sampleContexts.length
			: hasMoreEntries );
	const {
		headerBlocks,
		footerBlocks,
		templateBlocks,
		blocksForEntry,
		untitledEntries,
	} = useLayoutPreview(
		isSynced ? feedItems( syncedBlocks ) : allBlocks,
		previewContexts,
		pageSize,
		! previewHasMore,
		{ showAuthor, showAvatar }
	);
	const emptyPreviewBlocks = useMemo(
		() => forEntryKind( templateBlocks, false ),
		[ templateBlocks ]
	);
	// In a grid Feed, each entry's preview takes the cell the site places its
	// article in, the first pinned entry shown as the pinned card the card's
	// and a Full Story entry the Full Story Entry group's. With the Pinned
	// group, every pinned entry previews inside it, in its cell. Kept between
	// renders, so the previews don't render again for a new style object.
	const takesPinned = fullStoryTakesPinned( templateBlocks );
	const stacksPinned = templateBlocks.some( isPinnedStack );
	const leadPinContext = stacksPinned
		? undefined
		: previewContexts.find(
				( context ) =>
					context.pinned &&
					! isFullStoryContext( context, takesPinned )
			);
	const showsPin = stacksPinned
		? previewContexts.some( ( context ) => context.pinned )
		: Boolean( leadPinContext );
	const showsEntries = templateBlocks.length > 0;
	const previewPlacements = useMemo(
		() => ( {
			lead: entryPreviewPlacement(
				templateBlocks,
				feedLayout,
				true,
				true
			),
			other: entryPreviewPlacement(
				templateBlocks,
				feedLayout,
				false,
				showsPin
			),
			fullStory: entryPreviewPlacement(
				templateBlocks,
				feedLayout,
				false,
				showsPin,
				true
			),
		} ),
		[ templateBlocks, feedLayout, showsPin ]
	);
	// Measured on the entries as they show, without a dropped avatar column.
	const loadMoreInsetProps = useMemo(
		() =>
			loadMoreInsetStyle(
				withAuthorSettings(
					templateBlocks,
					showAuthor !== false,
					showAvatar !== false
				)
			),
		[ templateBlocks, showAuthor, showAvatar ]
	);
	const loadMorePlacement = useMemo(
		() => ( { ...previewPlacements.other, ...loadMoreInsetProps } ),
		[ previewPlacements.other, loadMoreInsetProps ]
	);
	// The layout pattern editor shows it for publishers to style; a feed shows
	// it where the site does.
	const isLoadMoreHidden =
		! isLayoutPattern && ( olderEntries !== 'button' || ! previewHasMore );
	const holdsLoadMore = useMemo( () => {
		const parts = layoutParts(
			isSynced ? feedItems( syncedBlocks ) : allBlocks
		);

		return holdsBlockType(
			[ ...parts.header, ...parts.footer ],
			LOAD_MORE_BLOCK_NAME
		);
	}, [ isSynced, syncedBlocks, allBlocks ] );
	const loadMorePreview = useMemo(
		() =>
			olderEntries === 'button' &&
			previewHasMore &&
			! holdsLoadMore && (
				<Disabled
					className="newspack-rolling-coverage-load-more"
					style={ loadMorePlacement }
				>
					<button
						type="button"
						className="wp-element-button wp-block-button__link"
					>
						{
							/* translators: Button that loads older entries at the end of a coverage's feed. */
							__( 'Load more', 'newspack-rolling-coverage' )
						}
					</button>
				</Disabled>
			),
		[ olderEntries, previewHasMore, holdsLoadMore, loadMorePlacement ]
	);
	const renderSyncedChromeItem = ( item: TemplateBlocks, index: number ) =>
		item[ 0 ]?.name === LOAD_MORE_BLOCK_NAME ? (
			<EntryBlockPreview
				key={ index }
				blocks={ item }
				className="newspack-rolling-coverage-load-more"
				style={ {
					...previewPlacements.other,
					...( startsAtInlineStart( item[ 0 ] )
						? loadMoreInsetProps
						: {} ),
				} }
			/>
		) : (
			<EntryBlockPreview
				key={ index }
				blocks={ item }
				style={ chromePreviewStyle( item, feedLayout ) }
			/>
		);
	const previewPlacementFor = ( context: EntryContext ) => {
		if ( context === leadPinContext ) {
			return previewPlacements.lead;
		}

		return isFullStoryContext( context, takesPinned )
			? previewPlacements.fullStory
			: previewPlacements.other;
	};
	// The entry after the lead pin, or after the Pinned group's entries,
	// heads the entries beside them, and carries the card's top border too.
	let columnHeadContext: EntryContext | undefined;

	if ( stacksPinned ) {
		columnHeadContext = showsPin
			? previewContexts.find( ( context ) => ! context.pinned )
			: undefined;
	} else if ( leadPinContext ) {
		columnHeadContext =
			previewContexts[ previewContexts.indexOf( leadPinContext ) + 1 ];
	}
	const columnHeadBlocks = useMemo(
		() =>
			columnHeadContext && ! columnHeadContext.pinned
				? withColumnRule(
						blocksForEntry( columnHeadContext ),
						templateBlocks,
						feedLayout
					)
				: undefined,
		[ columnHeadContext, blocksForEntry, templateBlocks, feedLayout ]
	);
	const pinnedStackStyle = useMemo(
		() => ( {
			...previewPlacements.lead,
			display: 'flex',
			flexDirection: 'column' as const,
		} ),
		[ previewPlacements.lead ]
	);
	const renderSyncedPreview = ( context: EntryContext ) => (
		<BlockContextProvider key={ context.postId } value={ context }>
			<EntryBlockPreview
				blocks={
					context === columnHeadContext && columnHeadBlocks
						? columnHeadBlocks
						: blocksForEntry( context )
				}
				style={
					stacksPinned && context.pinned
						? undefined
						: previewPlacementFor( context )
				}
			/>
		</BlockContextProvider>
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
	const isAllUpdatesHidden = ! isCapped || allUpdatesLink === false;
	const isCheckUpdatesHidden =
		isCapped || currentCoverage?.status === 'archived';
	const offersAuthor = holdsBlockType(
		templateBlocks,
		'core/post-author-name'
	);
	const offersAvatar = holdsBlockType( templateBlocks, 'core/avatar' );
	const checksOnRequest = useMemo( () => {
		const parts = layoutParts(
			isSynced ? feedItems( syncedBlocks ) : allBlocks
		);

		return holdsBlockType(
			[ ...parts.header, ...parts.footer ],
			CHECK_UPDATES_BLOCK_NAME
		);
	}, [ isSynced, syncedBlocks, allBlocks ] );
	// An editable layout previews the pinned card against a pinned entry
	// shown as the card, the Full Story Entry group against a Full Story
	// entry (see isFullStoryContext()), and the entry group against one that
	// is neither. It leaves out the ones the coverage has no entry for,
	// "Read more" where the entry previewed has no breakout post, and the
	// Full Story Entry's pinned row where its entry isn't pinned. The sample
	// entries have one of each.
	const pinnedGroups = pinnedStackGroups( allBlocks );
	const entryGroupBlocks = [
		...allBlocks.filter( ( block ) => ! isPinnedStack( block ) ),
		...pinnedGroups,
	];
	const hasBothKinds =
		entryGroupBlocks.some( isPinnedCard ) &&
		entryGroupBlocks.some( isRegularEntry );
	const hasFullStory = entryGroupBlocks.some( isBreakoutEntry );
	const hasPinnedFullStory = pinnedGroups.some( isBreakoutEntry );
	const entryKindContexts = hasFullStory
		? previewContexts.filter(
				( context ) => ! isFullStoryContext( context, takesPinned )
			)
		: previewContexts;
	// The pinned card and the entry group prefer an entry with a title, as an
	// untitled one previews without its Post Title (see
	// untitled-entry-title.tsx).
	const isTitled = ( context: EntryContext ) => context.hasTitle !== false;
	const pinnedContexts = entryKindContexts.filter(
		( context ) => context.pinned
	);
	const pinnedContext = hasBothKinds
		? ( pinnedContexts.find( isTitled ) ?? pinnedContexts[ 0 ] )
		: undefined;
	const unpinnedContexts = entryKindContexts.filter(
		( context ) => ! context.pinned
	);
	const titledContexts = unpinnedContexts.filter( isTitled );
	const regularContext = hasBothKinds
		? ( titledContexts.find( ( context ) => context.hasBreakout ) ??
			titledContexts[ 0 ] ??
			unpinnedContexts[ 0 ] )
		: ( entryKindContexts.find( isTitled ) ?? entryKindContexts[ 0 ] );
	const fullStoryContexts = hasFullStory
		? previewContexts.filter(
				( context ) =>
					isFullStoryContext( context, takesPinned ) &&
					! ( hasPinnedFullStory && context.pinned )
			)
		: [];
	const fullStoryContext =
		fullStoryContexts.find( ( context ) => context.breakout?.title ) ??
		fullStoryContexts[ 0 ];
	const pinnedFullStoryContexts = hasPinnedFullStory
		? previewContexts.filter(
				( context ) => context.pinned && Boolean( context.breakout )
			)
		: [];
	const pinnedFullStoryContext =
		pinnedFullStoryContexts.find(
			( context ) => context.breakout?.title
		) ?? pinnedFullStoryContexts[ 0 ];
	const layoutContext =
		regularContext ?? pinnedContext ?? NEUTRAL_ENTRY_CONTEXT;
	const coverageContext = useMemo(
		() => ( { [ COVERAGE_ID_CONTEXT ]: entriesCoverageId } ),
		[ entriesCoverageId ]
	);
	const isCardHidden = hasBothKinds && ! pinnedContext;
	const isEntryHidden =
		( hasBothKinds || hasFullStory ) &&
		! regularContext &&
		!! ( pinnedContext || fullStoryContext );
	const isFullStoryHidden = hasFullStory && ! fullStoryContext;
	const isPinnedFullStoryHidden =
		hasPinnedFullStory && ! pinnedFullStoryContext;
	const hidesFullStoryImage =
		!! fullStoryContext && ! fullStoryContext.breakout?.imageId;
	const hidesPinnedFullStoryImage =
		!! pinnedFullStoryContext && ! pinnedFullStoryContext.breakout?.imageId;
	const hidesFullStoryPin = !! fullStoryContext && ! fullStoryContext.pinned;
	// Core skips rendering a hidden block, filters included, so the previews
	// follow the last block shown in every viewport.
	const entryPreviewsAnchorId =
		(
			( templateBlocks.findLast( isShownEverywhere ) ??
				templateBlocks.at( -1 ) ) as { clientId?: string } | undefined
		 )?.clientId ?? null;
	const isRow = isRuledRow( feedGroup );
	const wrapsEntries = isRow || isOneLineRow( feedGroup );
	// Pinned entries beyond the editable ones preview inside the Pinned group,
	// as the site stacks them.
	const pinnedPreviewsAnchorId =
		( stacksPinned &&
			( pinnedGroups.at( -1 ) as { clientId?: string } | undefined )
				?.clientId ) ||
		null;
	const entryPreviewsAnchor = useMemo(
		() =>
			entryPreviewsAnchorId
				? {
						clientId: entryPreviewsAnchorId,
						wrapsEntries,
						pinnedClientId: pinnedPreviewsAnchorId,
					}
				: null,
		[ entryPreviewsAnchorId, wrapsEntries, pinnedPreviewsAnchorId ]
	);
	const pinnedPreviews = useMemo(
		() =>
			stacksPinned
				? previewContexts
						.filter(
							( context ) =>
								context.pinned &&
								! [
									pinnedContext,
									pinnedFullStoryContext,
								].includes( context )
						)
						.map( ( context ) => (
							<BlockContextProvider
								key={ context.postId }
								value={ context }
							>
								<EntryBlockPreview
									blocks={ blocksForEntry( context ) }
								/>
							</BlockContextProvider>
						) )
				: null,
		[
			stacksPinned,
			previewContexts,
			pinnedContext,
			pinnedFullStoryContext,
			blocksForEntry,
		]
	);
	const entryPreviews = useMemo(
		() => (
			<>
				{ previewContexts
					.filter(
						( context ) =>
							! [
								pinnedContext,
								regularContext,
								fullStoryContext,
								pinnedFullStoryContext,
							].includes( context ) &&
							! ( stacksPinned && context.pinned )
					)
					.map( ( context ) => (
						<BlockContextProvider
							key={ context.postId }
							value={ context }
						>
							<EntryBlockPreview
								blocks={ blocksForEntry( context ) }
								style={
									isFullStoryContext( context, takesPinned )
										? previewPlacements.fullStory
										: previewPlacements.other
								}
							/>
						</BlockContextProvider>
					) ) }
				{ loadMorePreview }
			</>
		),
		[
			previewContexts,
			pinnedContext,
			regularContext,
			fullStoryContext,
			pinnedFullStoryContext,
			stacksPinned,
			blocksForEntry,
			takesPinned,
			previewPlacements.other,
			previewPlacements.fullStory,
			loadMorePreview,
		]
	);
	const hidesCardBreakout = pinnedContext
		? ! pinnedContext.hasBreakout
		: false;
	const hidesEntryBreakout = regularContext
		? ! regularContext.hasBreakout
		: false;
	const newestContext =
		previewContexts.find( ( context ) => ! context.pinned ) ??
		previewContexts[ 0 ];
	const hiddenIds = useMemo( () => {
		const ids = [
			...( isFollowHidden ? followBlockIds( allBlocks ) : [] ),
			...( isCheckUpdatesHidden
				? blockIdsOfType( allBlocks, CHECK_UPDATES_BLOCK_NAME )
				: [] ),
			...( isLoadMoreHidden
				? blockIdsOfType( allBlocks, LOAD_MORE_BLOCK_NAME )
				: [] ),
			...( isAllUpdatesHidden ? allUpdatesBlockIds( allBlocks ) : [] ),
			...pinnedGroups
				.filter(
					( block ) =>
						( isCardHidden && isPinnedCard( block ) ) ||
						( isPinnedFullStoryHidden && isBreakoutEntry( block ) )
				)
				.map( ( block ) => block.clientId as string ),
			...allBlocks
				.filter(
					( block, index ) =>
						( isCardHidden && isPinnedCard( block ) ) ||
						( isFullStoryHidden && isBreakoutEntry( block ) ) ||
						( isEntryHidden &&
							( isRegularEntry( block ) ||
								( block.name === 'core/separator' &&
									index === allBlocks.length - 1 ) ) )
				)
				.map( ( block ) => block.clientId as string ),
			...breakoutBlockIds(
				entryGroupBlocks.filter( ( block ) => {
					if ( isBreakoutEntry( block ) ) {
						return false;
					}

					return pinnedContext && isPinnedCard( block )
						? hidesCardBreakout
						: hidesEntryBreakout;
				} )
			),
			...( hidesFullStoryImage
				? featuredImageBlockIds( allBlocks.filter( isBreakoutEntry ) )
				: [] ),
			...( hidesPinnedFullStoryImage
				? featuredImageBlockIds(
						pinnedGroups.filter( isBreakoutEntry )
					)
				: [] ),
			...( hidesFullStoryPin
				? pinnedRowBlockIds( allBlocks.filter( isBreakoutEntry ) )
				: [] ),
			...hiddenNewLabelIds(
				entryGroupBlocks,
				pinnedGroups,
				{
					card: pinnedContext,
					entry: regularContext,
					fullStory: fullStoryContext,
					pinnedFullStory: pinnedFullStoryContext,
				},
				newestContext
			),
			...hiddenAuthorBlockIds(
				layoutParts( allBlocks ).template,
				showAuthor !== false,
				showAvatar !== false
			),
		];

		return [ ...ids, ...emptiedGroupIds( allBlocks, ids ) ];
	}, [
		allBlocks,
		isFollowHidden,
		isCheckUpdatesHidden,
		isLoadMoreHidden,
		isAllUpdatesHidden,
		isCardHidden,
		isEntryHidden,
		isFullStoryHidden,
		isPinnedFullStoryHidden,
		hidesFullStoryImage,
		hidesPinnedFullStoryImage,
		hidesFullStoryPin,
		pinnedContext,
		regularContext,
		fullStoryContext,
		pinnedFullStoryContext,
		newestContext,
		hidesCardBreakout,
		hidesEntryBreakout,
		showAuthor,
		showAvatar,
	] );
	const hiddenKey = hiddenIds.join( ',' );
	useEffect( () => {
		const ids = hiddenKey ? hiddenKey.split( ',' ) : [];
		ids.forEach( ( id ) => setBlockEditingMode( id, 'disabled' ) );
		return () => ids.forEach( ( id ) => unsetBlockEditingMode( id ) );
	}, [ hiddenKey, setBlockEditingMode, unsetBlockEditingMode ] );

	const loadMoreCss = useMemo( () => {
		if ( isSynced ) {
			return '';
		}

		const parts = layoutParts( allBlocks );
		const gridColumn = /^[\w\s/-]+$/.test(
			previewPlacements.other?.gridColumn ?? ''
		)
			? previewPlacements.other?.gridColumn
			: undefined;

		return [ ...parts.header, ...parts.footer ]
			.filter( ( block ) => block.name === LOAD_MORE_BLOCK_NAME )
			.map( ( block ) => {
				const declarations = Object.entries( {
					...( gridColumn ? { 'grid-column': gridColumn } : {} ),
					...( startsAtInlineStart( block )
						? loadMoreInsetProps
						: {} ),
				} )
					.map(
						( [ property, value ] ) => `${ property }: ${ value };`
					)
					.join( ' ' );
				const selector = `.wp-block-newspack-rolling-coverage-rolling-coverage .newspack-rolling-coverage-layout [data-block="${ block.clientId }"]`;

				return declarations
					? `${ selector } { ${ declarations } margin-inline-start: var(--newspack-rolling-coverage-load-more-inset-narrow, 0); } @media (min-width: 782px) { ${ selector } { margin-inline-start: var(--newspack-rolling-coverage-load-more-inset, 0); } }`
					: '';
			} )
			.filter( Boolean )
			.join( '\n' );
	}, [ isSynced, allBlocks, previewPlacements.other, loadMoreInsetProps ] );
	const layoutCss = useMemo(
		() =>
			[
				...hiddenIds.map(
					( id ) =>
						`.wp-block-newspack-rolling-coverage-rolling-coverage .newspack-rolling-coverage-layout [data-block="${ id }"]:not(.newspack-rolling-coverage-layout .block-editor-block-preview__live-content *) { display: none; }`
				),
				loadMoreCss,
			]
				.filter( Boolean )
				.join( '\n' ),
		[ hiddenIds, loadMoreCss ]
	);

	const linkText = isSynced ? ( allUpdatesLinkText ?? '' ).trim() : '';
	const layoutLinkText = useMemo(
		() =>
			isSynced && hasResolvedLayout
				? allUpdatesTextOf( [ ...headerBlocks, ...footerBlocks ] )
				: null,
		[ isSynced, hasResolvedLayout, headerBlocks, footerBlocks ]
	);

	const syncedHeaderBlocks = useMemo( () => {
		let blocks = isFollowHidden
			? withoutFollowButtons( headerBlocks )
			: headerBlocks;
		blocks = isCheckUpdatesHidden
			? withoutCheckUpdatesButtons( blocks )
			: blocks;
		blocks = isLoadMoreHidden ? withoutLoadMoreButtons( blocks ) : blocks;
		if ( isAllUpdatesHidden ) {
			return withoutAllUpdatesParagraph( blocks );
		}
		return linkText ? withAllUpdatesText( blocks, linkText ) : blocks;
	}, [
		headerBlocks,
		isFollowHidden,
		isCheckUpdatesHidden,
		isLoadMoreHidden,
		isAllUpdatesHidden,
		linkText,
	] );
	const syncedFooterBlocks = useMemo( () => {
		let blocks = isFollowHidden
			? withoutFollowButtons( footerBlocks )
			: footerBlocks;
		blocks = isCheckUpdatesHidden
			? withoutCheckUpdatesButtons( blocks )
			: blocks;
		blocks = isLoadMoreHidden ? withoutLoadMoreButtons( blocks ) : blocks;
		if ( isAllUpdatesHidden ) {
			return withoutAllUpdatesParagraph( blocks );
		}
		return linkText ? withAllUpdatesText( blocks, linkText ) : blocks;
	}, [
		footerBlocks,
		isFollowHidden,
		isCheckUpdatesHidden,
		isLoadMoreHidden,
		isAllUpdatesHidden,
		linkText,
	] );
	const syncedHeaderItems = useMemo(
		() => syncedHeaderBlocks.map( ( block ) => [ block ] ),
		[ syncedHeaderBlocks ]
	);
	const syncedFooterItems = useMemo(
		() => syncedFooterBlocks.map( ( block ) => [ block ] ),
		[ syncedFooterBlocks ]
	);

	const detach = useCallback( () => {
		registry.batch( () => {
			replaceInnerBlocks(
				clientId,
				( linkText
					? withAllUpdatesText( syncedBlocks, linkText )
					: syncedBlocks
				).map( ( block ) =>
					cloneBlock(
						block as unknown as Parameters< typeof cloneBlock >[ 0 ]
					)
				),
				false
			);
			setAttributes( { layoutId: 0, allUpdatesLinkText: '' } );
		} );
	}, [
		registry,
		syncedBlocks,
		linkText,
		clientId,
		replaceInnerBlocks,
		setAttributes,
	] );

	const closePicker = useCallback( () => {
		setIsPickingLayout( false );
		setIsPickerReady( false );
	}, [] );

	// Claims Escape before the editor canvas moves focus to its stop.
	const closeLoadingPickerOnEscape = ( event: {
		key: string;
		preventDefault: () => void;
	} ) => {
		if ( isPickerLoading && event.key === 'Escape' ) {
			event.preventDefault();
			closePicker();
		}
	};

	const applyLayout = useCallback(
		( choice: LayoutChoice ) => {
			closePicker();

			const syncedSlug = isSynced
				? builtInLayoutSlugFor( layoutId )
				: null;
			let replaced: BuiltInLayoutSlug[] = [];

			if ( syncedSlug ) {
				replaced = [ syncedSlug ];
			} else if ( ! isSynced && innerBlockCount > 0 ) {
				replaced = getBuiltInLayouts().map( ( layout ) => layout.slug );
			}

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
					const patternSlug = builtInLayoutSlugFor( choice.id );
					setAttributes( {
						layoutId: choice.id,
						...( patternSlug
							? switchLayoutAttributes(
									patternSlug,
									replaced,
									align
								)
							: {} ),
					} );
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
				setAttributes( {
					layoutId: 0,
					allUpdatesLinkText: '',
					...switchLayoutAttributes( layout.slug, replaced, align ),
				} );
			} );
		},
		[
			align,
			closePicker,
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
			setHasMoreEntries( false );
			setEntriesLoadedFor( 0 );
			return;
		}
		const { getEntityRecord, getUser } = registry.resolveSelect(
			coreStore
		) as unknown as {
			getEntityRecord: (
				kind: string,
				name: string,
				id: number,
				query?: Record< string, string >
			) => Promise< unknown >;
			getUser: (
				id: number,
				query?: Record< string, string >
			) => Promise< unknown >;
		};
		const perPage = isCapped ? cappedCount : pageSize;
		// One entry past the page tells whether more would load.
		fetchEntryPreviewContexts(
			entriesCoverageId,
			isCapped ? perPage : perPage + 1,
			isCapped
		)
			.then( ( fetched ) => {
				const contexts = fetched.slice( 0, perPage );

				receiveBreakoutPreviews( contexts );

				// Entries, their authors and their breakout posts' images
				// are read before the preview shows, so it doesn't fill in
				// piece by piece.
				const images = Promise.all(
					contexts.map( ( { breakout } ) =>
						breakout?.imageId
							? getEntityRecord(
									'postType',
									'attachment',
									breakout.imageId,
									{ context: 'view' }
								).catch( () => undefined )
							: undefined
					)
				);

				return Promise.all(
					contexts.map( ( context ) =>
						getEntityRecord(
							'postType',
							context.postType,
							context.postId
						).catch( () => undefined )
					)
				)
					.then( ( records ) => {
						const authorIds = new Set< number >();
						records.forEach( ( record ) => {
							const author = ( record as { author?: number } )
								?.author;
							if ( author ) {
								authorIds.add( author );
							}
						} );

						// Post Author reads the view context; Post Author
						// Name and Avatar read the default one.
						return Promise.all(
							[ ...authorIds ].flatMap( ( authorId ) => [
								getUser( authorId ).catch( () => undefined ),
								getUser( authorId, {
									context: 'view',
								} ).catch( () => undefined ),
							] )
						);
					} )
					.then( () => images )
					.then( () => ( {
						contexts,
						hasMore: fetched.length > perPage,
					} ) );
			} )
			.then( ( { contexts, hasMore } ) => {
				if ( ! cancelled ) {
					setEntryContexts( contexts );
					setHasMoreEntries( hasMore );
					setEntriesLoadedFor( entriesCoverageId );
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [ entriesCoverageId, pageSize, isCapped, cappedCount, registry ] );

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
			setLoadedSearch( search );
		} );

		return () => {
			cancelled = true;
		};
	}, [ search, currentCoverage, isPreviewMode ] );

	useEffect( () => {
		let cancelled = false;
		getCoverage( coverageId ).then( ( coverage ) => {
			if ( cancelled ) {
				return;
			}
			setCurrentCoverage( coverage );
			setCoverageLoadedFor( coverageId );
			setPendingCanonicalUrl( coverage?.canonicalUrl || '' );
		} );
		return () => {
			cancelled = true;
		};
	}, [ coverageId ] );

	const coverageAdsDisabled = currentCoverage?.adsDisabled ?? false;

	const showCopied = useCallback( () => {
		setCopied( true );
		if ( copyTimer.current ) {
			clearTimeout( copyTimer.current );
		}
		copyTimer.current = setTimeout( () => setCopied( false ), 2000 );
	}, [] );

	const coverageIdRef = useRef( coverageId );
	useEffect( () => {
		coverageIdRef.current = coverageId;
	}, [ coverageId ] );

	const handleGenerate = useCallback( async () => {
		if ( ! coverageId ) {
			return;
		}
		const requestedCoverageId = coverageId;
		const isCurrentCoverage = () =>
			coverageIdRef.current === requestedCoverageId;
		setIsGenerating( true );

		const request = generateKeyTakeaways( requestedCoverageId );
		// The write must start inside the click, before the request resolves.
		// eslint-disable-next-line @wordpress/no-unused-vars-before-return
		const copying = copyTextWhenReady(
			request.then( ( response ) => {
				if (
					! response.success ||
					! response.result ||
					! isCurrentCoverage()
				) {
					throw new Error( 'Nothing to copy.' );
				}
				return response.result;
			} )
		);
		const result = await request;

		setIsGenerating( false );

		if ( ! isCurrentCoverage() ) {
			return;
		}

		if ( ! result.success || ! result.result ) {
			createErrorNotice(
				result.error ||
					__(
						'Failed to generate key takeaways.',
						'newspack-rolling-coverage'
					),
				{ type: 'snackbar', id: AI_NOTICE_ID, explicitDismiss: true }
			);
			return;
		}

		setGeneratedOutput( result.result );
		const isCopied = await copying;
		if ( isCopied ) {
			showCopied();
		}
		createSuccessNotice(
			isCopied
				? __(
						'Key takeaways copied. Paste them into the page.',
						'newspack-rolling-coverage'
					)
				: /* translators: "Copy Generated Output" is the label of the button below Generated Output; use the same translation. */
					__(
						'Key takeaways generated. Select Copy Generated Output to copy them.',
						'newspack-rolling-coverage'
					),
			{ type: 'snackbar', id: AI_NOTICE_ID }
		);
	}, [ coverageId, showCopied, createSuccessNotice, createErrorNotice ] );

	const handleCopy = useCallback( async () => {
		if ( await copyText( generatedOutput ) ) {
			showCopied();
			speak( __( 'Key takeaways copied.', 'newspack-rolling-coverage' ) );
			return;
		}
		createErrorNotice(
			__(
				'The key takeaways could not be copied. Select the text in Generated Output and copy it.',
				'newspack-rolling-coverage'
			),
			{ type: 'snackbar', id: AI_NOTICE_ID, explicitDismiss: true }
		);
	}, [ generatedOutput, showCopied, createErrorNotice ] );

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

	/**
	 * Combobox for selecting the connected coverage, or its loading state
	 * until the coverages arrive.
	 *
	 * @param {boolean} isSilent Whether the loading state skips its
	 *                           announcement, for the second of two copies.
	 */
	const renderCoverageCombobox = ( isSilent = false ) =>
		! isPreviewMode && loadedSearch === null ? (
			<LoadingState
				isSilent={ isSilent }
				label={ __(
					'Loading coverages…',
					'newspack-rolling-coverage'
				) }
			/>
		) : (
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
				onFilterValueChange={ setSearchDebounced }
				isLoading={ ! isPreviewMode && loadedSearch !== search }
			/>
		);

	const inspector = isLayoutPattern ? (
		<InspectorControls>
			<PanelBody
				title={ __( 'Shared Layout', 'newspack-rolling-coverage' ) }
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
			<PanelBody title={ __( 'Layout', 'newspack-rolling-coverage' ) }>
				<Stack direction="column" gap="lg">
					<Text render={ <p /> }>{ layoutDescription }</Text>
					{ canChangeLayout && (
						<Button
							className="newspack-rolling-coverage-centered-button"
							variant="secondary"
							isBusy={ isPickerLoading }
							accessibleWhenDisabled
							disabled={ isPickerLoading }
							onClick={ () => setIsPickingLayout( true ) }
							onKeyDownCapture={ closeLoadingPickerOnEscape }
						>
							{ __(
								'Change Layout',
								'newspack-rolling-coverage'
							) }
						</Button>
					) }
				</Stack>
			</PanelBody>
			<PanelBody title={ __( 'Coverage', 'newspack-rolling-coverage' ) }>
				<Stack direction="column" gap="lg">
					{ renderCoverageCombobox( ! coverageId ) }

					{ coverageId ? (
						<div>
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
							{ ! latestOnly && (
								<Stack direction="column" gap="sm">
									<Button
										className="newspack-rolling-coverage-centered-button"
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
											'Use This Page',
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
								</Stack>
							) }
						</div>
					) : null }
				</Stack>
			</PanelBody>

			<PanelBody title={ __( 'Entries', 'newspack-rolling-coverage' ) }>
				{ ! ( ( isRow || ! showsEntries ) && latestOnly ) && (
					<ToggleGroupControl
						__next40pxDefaultSize
						isBlock
						label={ _x(
							'Show',
							'which entries the feed shows',
							'newspack-rolling-coverage'
						) }
						help={
							latestOnly
								? __(
										'Only the most recent entries. Pinned entries aren’t kept at the top.',
										'newspack-rolling-coverage'
									)
								: __(
										'Every entry. Pinned entries stay at the top.',
										'newspack-rolling-coverage'
									)
						}
						value={ latestOnly ? 'latest' : 'all' }
						onChange={ ( value ) =>
							setAttributes( { latestOnly: value === 'latest' } )
						}
					>
						<ToggleGroupControlOption
							value="all"
							label={ _x(
								'All',
								'which entries the feed shows',
								'newspack-rolling-coverage'
							) }
							aria-label={
								/* translators: Screen reader name for the “All” option. Keep the word used to translate “All”. */
								__( 'All entries', 'newspack-rolling-coverage' )
							}
						/>
						<ToggleGroupControlOption
							value="latest"
							label={ _x(
								'Latest',
								'which entries the feed shows',
								'newspack-rolling-coverage'
							) }
							aria-label={
								/* translators: Screen reader name for the “Latest” option. Keep the word used to translate “Latest”. */
								__(
									'Latest entries',
									'newspack-rolling-coverage'
								)
							}
						/>
					</ToggleGroupControl>
				) }
				{ latestOnly ? (
					<>
						{ showsEntries && (
							<TextControl
								__next40pxDefaultSize
								type="number"
								label={ __(
									'Number of entries',
									'newspack-rolling-coverage'
								) }
								value={
									latestCountInput ?? String( latestCount )
								}
								min={ 1 }
								max={ 100 }
								onChange={ ( value: string ) => {
									setLatestCountInput( value );
									const parsed = parseInt( value, 10 );
									if ( ! Number.isNaN( parsed ) ) {
										setAttributes( {
											latestCount: Math.min(
												Math.max( parsed, 1 ),
												100
											),
										} );
									}
								} }
								onBlur={ () => setLatestCountInput( null ) }
							/>
						) }
						<ToggleGroupControl
							__next40pxDefaultSize
							isBlock
							label={ __(
								'Link to all updates',
								'newspack-rolling-coverage'
							) }
							help={ __(
								'Links to the coverage page. Hidden on that page.',
								'newspack-rolling-coverage'
							) }
							value={ allUpdatesLink !== false ? 'show' : 'hide' }
							onChange={ ( value ) =>
								setAttributes( {
									allUpdatesLink: value === 'show',
								} )
							}
						>
							<ToggleGroupControlOption
								value="show"
								label={ _x(
									'Show',
									'link to all updates',
									'newspack-rolling-coverage'
								) }
								aria-label={
									/* translators: Screen reader name for the “Show” option. Keep the word used to translate “Show”. */
									__(
										'Show link to all updates',
										'newspack-rolling-coverage'
									)
								}
							/>
							<ToggleGroupControlOption
								value="hide"
								label={ _x(
									'Hide',
									'link to all updates',
									'newspack-rolling-coverage'
								) }
								aria-label={
									/* translators: Screen reader name for the “Hide” option. Keep the word used to translate “Hide”. */
									__(
										'Hide link to all updates',
										'newspack-rolling-coverage'
									)
								}
							/>
						</ToggleGroupControl>
						{ allUpdatesLink !== false &&
							layoutLinkText !== null && (
								<TextControl
									__next40pxDefaultSize
									label={ __(
										'Link text',
										'newspack-rolling-coverage'
									) }
									help={
										layoutLinkText
											? sprintf(
													/* translators: %s: the link text the shared layout sets, e.g. "See all entries". */
													__(
														'Leave empty to use the layout’s text, “%s”.',
														'newspack-rolling-coverage'
													),
													layoutLinkText
												)
											: __(
													'Leave empty to use the layout’s text.',
													'newspack-rolling-coverage'
												)
									}
									placeholder={ layoutLinkText }
									value={ allUpdatesLinkText ?? '' }
									onChange={ ( value: string ) =>
										setAttributes( {
											allUpdatesLinkText: value,
										} )
									}
								/>
							) }
					</>
				) : (
					<>
						<SelectControl
							__next40pxDefaultSize
							label={ __(
								'Older entries',
								'newspack-rolling-coverage'
							) }
							help={ OLDER_ENTRIES_HELP[ olderEntries ]?.() }
							value={ olderEntries }
							options={ [
								{
									value: 'scroll',
									label: __(
										'Load on scroll',
										'newspack-rolling-coverage'
									),
								},
								{
									value: 'button',
									label:
										/* translators: “Load more” is the label of the button readers press. Keep the words used to translate it. */
										__(
											'“Load more” button',
											'newspack-rolling-coverage'
										),
								},
								{
									value: 'none',
									label: __(
										'Don’t load',
										'newspack-rolling-coverage'
									),
								},
							] }
							onChange={ ( value ) =>
								setAttributes( { olderEntries: value } )
							}
						/>
						<TextControl
							__next40pxDefaultSize
							type="number"
							label={ __(
								'Entries per page',
								'newspack-rolling-coverage'
							) }
							help={
								olderEntries === 'none'
									? __(
											'How many entries the feed shows.',
											'newspack-rolling-coverage'
										)
									: __(
											'How many entries show first, and how many each load of older entries adds.',
											'newspack-rolling-coverage'
										)
							}
							value={
								entriesPerPageInput ?? String( entriesPerPage )
							}
							min={ 1 }
							max={ 100 }
							onChange={ ( value: string ) => {
								setEntriesPerPageInput( value );
								const parsed = parseInt( value, 10 );
								if ( ! Number.isNaN( parsed ) ) {
									setAttributes( {
										entriesPerPage:
											clampEntriesPerPage( parsed ),
									} );
								}
							} }
							onBlur={ () => setEntriesPerPageInput( null ) }
						/>
					</>
				) }
				{ offersAuthor && (
					<ToggleGroupControl
						__next40pxDefaultSize
						isBlock
						label={ _x(
							'Author name',
							'whether entries show their author’s name',
							'newspack-rolling-coverage'
						) }
						help={ __(
							'The name each author chose under “Display name publicly as” in their profile.',
							'newspack-rolling-coverage'
						) }
						value={ showAuthor !== false ? 'show' : 'hide' }
						onChange={ ( value ) =>
							setAttributes( { showAuthor: value === 'show' } )
						}
					>
						<ToggleGroupControlOption
							value="show"
							label={ _x(
								'Show',
								'entry author name',
								'newspack-rolling-coverage'
							) }
							aria-label={
								/* translators: Screen reader name for the “Show” option. Keep the word used to translate “Show”. */
								__(
									'Show author name',
									'newspack-rolling-coverage'
								)
							}
						/>
						<ToggleGroupControlOption
							value="hide"
							label={ _x(
								'Hide',
								'entry author name',
								'newspack-rolling-coverage'
							) }
							aria-label={
								/* translators: Screen reader name for the “Hide” option. Keep the word used to translate “Hide”. */
								__(
									'Hide author name',
									'newspack-rolling-coverage'
								)
							}
						/>
					</ToggleGroupControl>
				) }
				{ offersAvatar && (
					<ToggleGroupControl
						__next40pxDefaultSize
						isBlock
						label={ _x(
							'Author avatar',
							'whether entries show their author’s photo',
							'newspack-rolling-coverage'
						) }
						help={ __(
							'Each author’s profile picture, from their profile or Gravatar.',
							'newspack-rolling-coverage'
						) }
						value={ showAvatar !== false ? 'show' : 'hide' }
						onChange={ ( value ) =>
							setAttributes( { showAvatar: value === 'show' } )
						}
					>
						<ToggleGroupControlOption
							value="show"
							label={ _x(
								'Show',
								'entry author avatar',
								'newspack-rolling-coverage'
							) }
							aria-label={
								/* translators: Screen reader name for the “Show” option. Keep the word used to translate “Show”. */
								__(
									'Show author avatar',
									'newspack-rolling-coverage'
								)
							}
						/>
						<ToggleGroupControlOption
							value="hide"
							label={ _x(
								'Hide',
								'entry author avatar',
								'newspack-rolling-coverage'
							) }
							aria-label={
								/* translators: Screen reader name for the “Hide” option. Keep the word used to translate “Hide”. */
								__(
									'Hide author avatar',
									'newspack-rolling-coverage'
								)
							}
						/>
					</ToggleGroupControl>
				) }
				{ ( latestOnly || ! checksOnRequest ) && (
					<TextControl
						__next40pxDefaultSize
						type="number"
						label={ __(
							'Poll interval (seconds)',
							'newspack-rolling-coverage'
						) }
						value={ String( pollInterval ) }
						min={ Math.max( 1, MIN_POLL_INTERVAL ) }
						help={
							MIN_POLL_INTERVAL > 0
								? sprintf(
										/* translators: %d: The fewest seconds this site waits between checks for new entries. "this": the Poll interval setting above. */
										_n(
											'This site waits at least %d second between checks, even when this is set lower.',
											'This site waits at least %d seconds between checks, even when this is set lower.',
											MIN_POLL_INTERVAL,
											'newspack-rolling-coverage'
										),
										MIN_POLL_INTERVAL
									)
								: undefined
						}
						onChange={ ( value: string ) =>
							setAttributes( {
								pollInterval: value
									? parseInt( value, 10 )
									: metadata.attributes.pollInterval.default,
							} )
						}
					/>
				) }
			</PanelBody>

			<PanelBody title={ STATUS_LABELS.archived } initialOpen={ false }>
				<ToggleGroupControl
					__next40pxDefaultSize
					isBlock
					label={ __( 'When ended', 'newspack-rolling-coverage' ) }
					help={ sprintf(
						/* translators: %s: The status that ends a coverage, e.g. "Ended". “Hide” is the option above; keep the word used to translate it. */
						__(
							'Hide removes the whole block once the coverage’s status is set to “%s” in All Coverages.',
							'newspack-rolling-coverage'
						),
						STATUS_LABELS.archived
					) }
					value={ hideWhenEnded ? 'hide' : 'show' }
					onChange={ ( value ) =>
						setAttributes( { hideWhenEnded: value === 'hide' } )
					}
				>
					<ToggleGroupControlOption
						value="show"
						label={ _x(
							'Show',
							'when ended',
							'newspack-rolling-coverage'
						) }
						aria-label={
							/* translators: Screen reader name for the “Show” option. Keep the word used to translate “Show”. */
							__( 'Show when ended', 'newspack-rolling-coverage' )
						}
					/>
					<ToggleGroupControlOption
						value="hide"
						label={ _x(
							'Hide',
							'when ended',
							'newspack-rolling-coverage'
						) }
						aria-label={
							/* translators: Screen reader name for the “Hide” option. Keep the word used to translate “Hide”. */
							__( 'Hide when ended', 'newspack-rolling-coverage' )
						}
					/>
				</ToggleGroupControl>
				{ ! hideWhenEnded && (
					<>
						<ToggleGroupControl
							__next40pxDefaultSize
							isBlock
							label={ __(
								'Notice',
								'newspack-rolling-coverage'
							) }
							help={ sprintf(
								/* translators: %s: The status that ends a coverage, e.g. "Ended". */
								__(
									'Tells readers the coverage has ended. Shown at the top of the feed once its status is set to “%s” in All Coverages.',
									'newspack-rolling-coverage'
								),
								STATUS_LABELS.archived
							) }
							value={ archivedNoticeShow ? 'show' : 'hide' }
							onChange={ ( value ) =>
								setAttributes( {
									archivedNoticeShow: value === 'show',
								} )
							}
						>
							<ToggleGroupControlOption
								value="show"
								label={ _x(
									'Show',
									'ended notice',
									'newspack-rolling-coverage'
								) }
								aria-label={
									/* translators: Screen reader name for the “Show” option. Keep the word used to translate “Show”. */
									__(
										'Show notice',
										'newspack-rolling-coverage'
									)
								}
							/>
							<ToggleGroupControlOption
								value="hide"
								label={ _x(
									'Hide',
									'ended notice',
									'newspack-rolling-coverage'
								) }
								aria-label={
									/* translators: Screen reader name for the “Hide” option. Keep the word used to translate “Hide”. */
									__(
										'Hide notice',
										'newspack-rolling-coverage'
									)
								}
							/>
						</ToggleGroupControl>
						{ archivedNoticeShow && (
							<>
								<TextareaControl
									label={ __(
										'Notice text',
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
										setAttributes( {
											archivedNotice: value,
										} )
									}
								/>
								<ToggleGroupControl
									__next40pxDefaultSize
									isBlock
									label={ __(
										'Link',
										'newspack-rolling-coverage'
									) }
									help={ __(
										'Points readers to where the story continues.',
										'newspack-rolling-coverage'
									) }
									value={
										archivedNoticeShowLink ? 'show' : 'hide'
									}
									onChange={ ( value ) =>
										setAttributes( {
											archivedNoticeShowLink:
												value === 'show',
										} )
									}
								>
									<ToggleGroupControlOption
										value="show"
										label={ _x(
											'Show',
											'ended notice link',
											'newspack-rolling-coverage'
										) }
										aria-label={
											/* translators: Screen reader name for the “Show” option. Keep the word used to translate “Show”. */
											__(
												'Show link',
												'newspack-rolling-coverage'
											)
										}
									/>
									<ToggleGroupControlOption
										value="hide"
										label={ _x(
											'Hide',
											'ended notice link',
											'newspack-rolling-coverage'
										) }
										aria-label={
											/* translators: Screen reader name for the “Hide” option. Keep the word used to translate “Hide”. */
											__(
												'Hide link',
												'newspack-rolling-coverage'
											)
										}
									/>
								</ToggleGroupControl>
								{ archivedNoticeShowLink && (
									<>
										<TextControl
											__next40pxDefaultSize
											type="url"
											label={ __(
												'URL',
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
													archivedNoticeLinkUrl:
														value,
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
													archivedNoticeLinkLabel:
														value,
												} )
											}
										/>
									</>
								) }
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
					<Stack direction="column" gap="lg">
						<Text render={ <p /> }>
							{ __(
								'Sums up the latest entries in this coverage as key takeaways and copies them, ready to paste into the page. Generate again as new entries come in.',
								'newspack-rolling-coverage'
							) }
						</Text>
						{ AI_SETTINGS_URL && (
							<div>
								<ExternalLink href={ AI_SETTINGS_URL }>
									{ __(
										'Edit the prompt',
										'newspack-rolling-coverage'
									) }
								</ExternalLink>
							</div>
						) }
						<Button
							className="newspack-rolling-coverage-centered-button"
							variant="secondary"
							onClick={ handleGenerate }
							isBusy={ isGenerating }
							accessibleWhenDisabled
							disabled={ isGenerating }
						>
							{ __(
								'Generate Key Takeaways',
								'newspack-rolling-coverage'
							) }
						</Button>
						{ generatedOutput && (
							<>
								<div>
									<TextareaControl
										label={ __(
											'Generated Output',
											'newspack-rolling-coverage'
										) }
										value={ generatedOutput }
										onChange={ () => {} }
										rows={ 8 }
										readOnly
									/>
								</div>
								<Button
									className="newspack-rolling-coverage-centered-button"
									variant="tertiary"
									onClick={ handleCopy }
								>
									{ copied
										? __(
												'Copied!',
												'newspack-rolling-coverage'
											)
										: __(
												'Copy Generated Output',
												'newspack-rolling-coverage'
											) }
								</Button>
							</>
						) }
					</Stack>
				</PanelBody>
			) : null }

			{ NEWSPACK_ADS_AVAILABLE && (
				<PanelBody title={ __( 'Ads', 'newspack-rolling-coverage' ) }>
					<Stack direction="column" gap="lg">
						{ coverageAdsDisabled ? (
							<Notice status="warning" isDismissible={ false }>
								{ __(
									'Ads are disabled for this coverage. Enable them in the coverage settings to configure ad settings here.',
									'newspack-rolling-coverage'
								) }
							</Notice>
						) : (
							! NEWSPACK_ADS_PLACEMENT_ENABLED && (
								<Notice
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
						<div>
							<ToggleGroupControl
								__next40pxDefaultSize
								isBlock
								label={ __(
									'Advertising',
									'newspack-rolling-coverage'
								) }
								help={
									latestOnly
										? __(
												'Not shown while the feed shows only the latest entries.',
												'newspack-rolling-coverage'
											)
										: __(
												'Shows ads at a regular interval in the feed.',
												'newspack-rolling-coverage'
											)
								}
								value={ enableAds ? 'enabled' : 'disabled' }
								disabled={ coverageAdsDisabled || latestOnly }
								onChange={ ( value ) =>
									setAttributes( {
										enableAds: value === 'enabled',
									} )
								}
							>
								<ToggleGroupControlOption
									value="enabled"
									label={ _x(
										'Enabled',
										'advertising',
										'newspack-rolling-coverage'
									) }
									aria-label={
										/* translators: Screen reader name for the “Enabled” option. Keep the word used to translate “Enabled”. */
										__(
											'Advertising enabled',
											'newspack-rolling-coverage'
										)
									}
								/>
								<ToggleGroupControlOption
									value="disabled"
									label={ _x(
										'Disabled',
										'advertising',
										'newspack-rolling-coverage'
									) }
									aria-label={
										/* translators: Screen reader name for the “Disabled” option. Keep the word used to translate “Disabled”. */
										__(
											'Advertising disabled',
											'newspack-rolling-coverage'
										)
									}
								/>
							</ToggleGroupControl>
						</div>
						{ enableAds &&
							! coverageAdsDisabled &&
							! latestOnly && (
								<TextControl
									__next40pxDefaultSize
									type="number"
									label={ __(
										'Ads interval',
										'newspack-rolling-coverage'
									) }
									help={ sprintf(
										/* translators: 1: the entry the first ad follows, 2: the entry the second ad follows, 3: the entry the third ad follows. */
										__(
											'Up to 3 ads, after entries %1$d, %2$d and %3$d. New entries that arrive while the page is open get one at this interval, with no limit.',
											'newspack-rolling-coverage'
										),
										adsInterval,
										adsInterval * 2,
										adsInterval * 3
									) }
									value={ String( adsInterval ) }
									min={ 1 }
									onChange={ ( value: string ) =>
										setAttributes( {
											adsInterval: value
												? Math.max(
														1,
														parseInt( value, 10 ) ||
															1
													)
												: 4,
										} )
									}
								/>
							) }
					</Stack>
				</PanelBody>
			) }
		</InspectorControls>
	);

	return (
		<>
			{ ! isChoosing && inspector }

			{ ! isLoading && isSynced && coverageId > 0 && (
				<BlockControls group="other">
					{ canEditLayout && (
						<ToolbarButton
							{ ...{
								href: getLayoutEditUrl( layoutId ),
								target: '_blank',
							} }
						>
							{ __( 'Edit Layout', 'newspack-rolling-coverage' ) }
						</ToolbarButton>
					) }
					<ToolbarButton onClick={ detach }>
						{ __( 'Detach', 'newspack-rolling-coverage' ) }
					</ToolbarButton>
				</BlockControls>
			) }

			{ isPickingLayout && (
				<LayoutPickerModal
					currentLayoutId={ isSynced ? layoutId : 0 }
					onSelect={ applyLayout }
					onClose={ closePicker }
					onReady={ () => setIsPickerReady( true ) }
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
						icon={ liveStreaming }
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
								isBusy={ isPickerLoading }
								accessibleWhenDisabled
								disabled={ isPickerLoading }
								onClick={ () => setIsPickingLayout( true ) }
								onKeyDownCapture={ closeLoadingPickerOnEscape }
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
								hideWhenEnded &&
								currentCoverage?.status === 'archived' && (
									<Notice
										status="info"
										isDismissible={ false }
									>
										{ __(
											'This feed is hidden on the site because the coverage has ended.',
											'newspack-rolling-coverage'
										) }
									</Notice>
								) }
							{ ! isLayoutPattern &&
								showsEntries &&
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
								<FeedWrappersPreview
									path={ feedPath }
									context={ coverageContext }
									scope={ previewScope }
								>
									<div
										{ ...feedPreviewProps(
											feedGroup,
											previewScope
										) }
									>
										{ syncedHeaderBlocks.length > 0 && (
											<BlockContextProvider
												value={ coverageContext }
											>
												{ syncedHeaderItems.map(
													renderSyncedChromeItem
												) }
											</BlockContextProvider>
										) }
										<div className="newspack-rolling-coverage-entries">
											{ previewContexts.length > 0 ? (
												<>
													{ stacksPinned &&
														showsPin && (
															<div
																className={
																	PINNED_STACK_CLASS
																}
																style={
																	pinnedStackStyle
																}
															>
																{ previewContexts
																	.filter(
																		(
																			context
																		) =>
																			context.pinned
																	)
																	.map(
																		renderSyncedPreview
																	) }
															</div>
														) }
													{ previewContexts
														.filter(
															( context ) =>
																! stacksPinned ||
																! context.pinned
														)
														.map(
															renderSyncedPreview
														) }
												</>
											) : (
												<BlockContextProvider
													value={
														NEUTRAL_ENTRY_CONTEXT
													}
												>
													<EntryBlockPreview
														blocks={
															emptyPreviewBlocks
														}
														style={
															previewPlacements.other
														}
													/>
												</BlockContextProvider>
											) }
										</div>
										{ loadMorePreview }
										{ syncedFooterBlocks.length > 0 && (
											<BlockContextProvider
												value={ coverageContext }
											>
												{ syncedFooterItems.map(
													renderSyncedChromeItem
												) }
											</BlockContextProvider>
										) }
									</div>
								</FeedWrappersPreview>
							) }
							{ ! isSynced && (
								<PinnedEntryContext.Provider
									value={ pinnedContext ?? null }
								>
									<FullStoryEntryContext.Provider
										value={ fullStoryContext ?? null }
									>
										<PinnedFullStoryEntryContext.Provider
											value={
												pinnedFullStoryContext ?? null
											}
										>
											<EntryPreviewsAnchorContext.Provider
												value={ entryPreviewsAnchor }
											>
												<EntryPreviewsContext.Provider
													value={ entryPreviews }
												>
													<PinnedPreviewsContext.Provider
														value={ pinnedPreviews }
													>
														<BlockContextProvider
															value={ {
																...layoutContext,
																...coverageContext,
															} }
														>
															<UntitledEntriesContext.Provider
																value={
																	untitledEntries
																}
															>
																<div
																	{ ...innerBlocksProps }
																/>
															</UntitledEntriesContext.Provider>
														</BlockContextProvider>
													</PinnedPreviewsContext.Provider>
												</EntryPreviewsContext.Provider>
											</EntryPreviewsAnchorContext.Provider>
										</PinnedFullStoryEntryContext.Provider>
									</FullStoryEntryContext.Provider>
								</PinnedEntryContext.Provider>
							) }
						</>
					) : (
						<Placeholder
							icon={ liveStreaming }
							label="Rolling Coverage"
							instructions={ __(
								'Select a coverage to display its entries.',
								'newspack-rolling-coverage'
							) }
							isColumnLayout
						>
							{ renderCoverageCombobox() }
						</Placeholder>
					) ) }
			</div>
		</>
	);
}
