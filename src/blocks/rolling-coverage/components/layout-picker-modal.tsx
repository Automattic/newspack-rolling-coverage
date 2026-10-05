/**
 * WordPress dependencies
 */
import { BlockPreview } from '@wordpress/block-editor';
import {
	createBlock,
	createBlocksFromInnerBlocksTemplate,
	parse,
} from '@wordpress/blocks';
import { Modal, Spinner } from '@wordpress/components';
import { store as coreStore } from '@wordpress/core-data';
import { useDispatch, useSelect } from '@wordpress/data';
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { BLOCK_NAME } from '../layout';
import {
	getBuiltInLayouts,
	layoutCapAttributes,
	type BuiltInLayoutSlug,
} from '../layouts';
import { withoutLatestButtons } from '../template';
import LoadingState from './loading-state';
import {
	createLayout,
	getLayoutCategoryId,
	getLayoutId,
	isLayoutContent,
} from '../utils';
import type { TemplateItem } from '../types';

export type LayoutChoice =
	| { kind: 'pattern'; id: number }
	| { kind: 'template'; slug: BuiltInLayoutSlug };

type LayoutRecord = {
	id: number;
	title?: { raw?: string } | string;
	content?: { raw?: string } | string;
};

type LayoutCard = {
	key: string;
	title: string;
	patternId: number;
	slug?: BuiltInLayoutSlug;
	template?: () => TemplateItem[];
	innerBlocks: () => unknown[];
	previewWidth?: number;
};

const PREVIEW_ENTRIES = 3;
const PREVIEW_VIEWPORT_WIDTH = 800;
const NO_RECORDS: LayoutRecord[] = [];
const SETTLED_FRAMES = 3;
const REVEAL_TIMEOUT = 3000;
const LOAD_TIMEOUT = 10000;
const LOADING_SELECTOR = '.newspack-rolling-coverage-loading';

/**
 * The query listing the published layouts in the layout pattern category.
 *
 * @param {number} categoryId The layout pattern category's ID.
 * @return {Object} The query.
 */
export function layoutsQuery( categoryId: number ) {
	return {
		wp_pattern_category: categoryId,
		status: 'publish',
		per_page: 100,
		context: 'view',
	};
}

/**
 * The layout block markup a pattern holds: the Rolling Coverage block's inner
 * blocks, or null when the pattern has no Rolling Coverage block.
 *
 * @param {Object} record The pattern's record.
 * @return {Function|null} A function returning the inner blocks, or null.
 */
function patternInnerBlocks(
	record: LayoutRecord
): ( () => unknown[] ) | null {
	const raw =
		typeof record.content === 'string'
			? record.content
			: record.content?.raw;
	const holder = parse( raw ?? '' ).find(
		( block ) => block.name === BLOCK_NAME
	);

	return holder ? () => holder.innerBlocks : null;
}

/**
 * A pattern's title, as plain text.
 *
 * @param {Object} record The pattern's record.
 * @return {string} The title.
 */
function patternTitle( record: LayoutRecord ): string {
	const raw =
		typeof record.title === 'string' ? record.title : record.title?.raw;

	return (
		decodeEntities( raw ?? '' ) ||
		__( '(no title)', 'newspack-rolling-coverage' )
	);
}

/**
 * The cap and alignment a built-in layout's card previews, as the layout
 * sets them when picked; other layouts show every entry.
 *
 * @param {string} slug The built-in layout's slug, if the card is one.
 * @return {Object} The cap and alignment attributes.
 */
function previewAttributes( slug?: BuiltInLayoutSlug ): {
	latestOnly?: boolean;
	latestCount?: number;
	align?: string;
} {
	if ( ! slug ) {
		return {};
	}

	const { latestOnly, latestCount, align } = layoutCapAttributes( slug );

	return {
		...( latestOnly ? { latestOnly, latestCount } : {} ),
		...( align ? { align } : {} ),
	};
}

type PreviewRef = { current: HTMLElement | null };

/**
 * Reports once a card's preview has settled: its iframe holds the block, no
 * loading state, and a height that has not changed for a few frames.
 *
 * @param {Object}   previewRef Ref to the element wrapping the preview.
 * @param {Function} onSettled  Called once, when the preview has settled.
 */
function useSettledPreview( previewRef: PreviewRef, onSettled: () => void ) {
	const callback = useRef( onSettled );
	callback.current = onSettled;

	useEffect( () => {
		let frame = 0;
		let lastHeight = -1;
		let stableFrames = 0;

		const check = () => {
			const iframe = previewRef.current?.querySelector( 'iframe' );
			const doc = iframe?.contentDocument;
			const height = previewRef.current?.offsetHeight ?? 0;
			const isRendered =
				height > 0 &&
				!! doc?.querySelector( `[data-type="${ BLOCK_NAME }"]` ) &&
				! doc.querySelector( LOADING_SELECTOR );

			stableFrames =
				isRendered && height === lastHeight ? stableFrames + 1 : 0;
			lastHeight = height;

			if ( stableFrames >= SETTLED_FRAMES ) {
				callback.current();
				return;
			}
			frame = window.requestAnimationFrame( check );
		};

		frame = window.requestAnimationFrame( check );

		return () => window.cancelAnimationFrame( frame );
	}, [ previewRef ] );
}

type PreviewBlock = {
	name: string;
	attributes?: Record< string, unknown >;
	innerBlocks?: PreviewBlock[];
};

/**
 * One layout in the picker: a scaled preview of the block rendering sample
 * entries in the layout, without the "Jump to Latest" button, with the
 * layout's title.
 *
 * @param {Object}   props            Component props.
 * @param {Object}   props.card       The layout.
 * @param {boolean}  props.isCurrent  Whether the block uses this layout.
 * @param {boolean}  props.isPending  Whether this layout is being set up.
 * @param {boolean}  props.isDisabled Whether another layout is being set up.
 * @param {Function} props.onClick    Picks the layout.
 * @param {Function} props.onSettled  Called once the preview has settled.
 */
function LayoutPickerCard( {
	card,
	isCurrent,
	isPending,
	isDisabled,
	onClick,
	onSettled,
}: {
	card: LayoutCard;
	isCurrent: boolean;
	isPending: boolean;
	isDisabled: boolean;
	onClick: () => void;
	onSettled: () => void;
} ) {
	const previewRef = useRef< HTMLSpanElement >( null );
	useSettledPreview( previewRef, onSettled );
	const blocks = useMemo(
		() => [
			createBlock(
				BLOCK_NAME,
				{
					entriesPerPage: PREVIEW_ENTRIES,
					...previewAttributes( card.slug ),
				},
				withoutLatestButtons(
					card.innerBlocks() as PreviewBlock[]
				) as unknown as Parameters< typeof createBlock >[ 2 ]
			),
		],
		[ card ]
	);

	return (
		<button
			type="button"
			className="newspack-rolling-coverage-layout-picker__card"
			aria-pressed={ isCurrent }
			aria-busy={ isPending }
			aria-disabled={ isPending }
			disabled={ isDisabled }
			onClick={ onClick }
		>
			<span
				ref={ previewRef }
				className="newspack-rolling-coverage-layout-picker__preview"
				aria-hidden="true"
			>
				<BlockPreview
					blocks={ blocks }
					viewportWidth={
						card.previewWidth ?? PREVIEW_VIEWPORT_WIDTH
					}
				/>
			</span>
			<span className="newspack-rolling-coverage-layout-picker__title">
				{ card.title }
				{ isPending && <Spinner /> }
			</span>
		</button>
	);
}

/**
 * Picks the layout a Rolling Coverage block renders its entries in: the
 * built-in layouts, then the other published layouts in the layout pattern
 * category. Picking a built-in layout that has no pattern yet creates it,
 * and falls back to an unsynced copy of its template when that fails. The
 * modal stays hidden until every card's preview has settled, so it opens at
 * its final size, or until a timeout passes, so a slow preview or layouts
 * request can't keep it closed.
 *
 * @param {Object}   props                 Component props.
 * @param {number}   props.currentLayoutId The block's layout pattern ID, or 0.
 * @param {Function} props.onSelect        Called with the picked layout.
 * @param {Function} props.onClose         Closes the picker.
 * @param {Function} props.onReady         Called when the modal is revealed.
 */
export default function LayoutPickerModal( {
	currentLayoutId,
	onSelect,
	onClose,
	onReady,
}: {
	currentLayoutId: number;
	onSelect: ( choice: LayoutChoice ) => void;
	onClose: () => void;
	onReady?: () => void;
} ) {
	const [ pendingKey, setPendingKey ] = useState< string | null >( null );
	const [ settledKeys, setSettledKeys ] = useState< string[] >( [] );
	const [ timedOut, setTimedOut ] = useState( false );
	const contentRef = useRef< HTMLDivElement >( null );
	const isMounted = useRef( true );

	useEffect( () => {
		isMounted.current = true;
		return () => {
			isMounted.current = false;
		};
	}, [] );
	const { invalidateResolution } = useDispatch( coreStore ) as unknown as {
		invalidateResolution: ( selector: string, args: unknown[] ) => void;
	};

	const categoryId = getLayoutCategoryId();
	const { records, hasResolved } = useSelect(
		( select ) => {
			if ( ! categoryId ) {
				return { records: NO_RECORDS, hasResolved: true };
			}
			const core = select( coreStore ) as unknown as {
				getEntityRecords: (
					kind: string,
					name: string,
					query: object
				) => LayoutRecord[] | null;
				hasFinishedResolution: (
					selector: string,
					args: unknown[]
				) => boolean;
			};
			const query = layoutsQuery( categoryId );
			return {
				records:
					core.getEntityRecords( 'postType', 'wp_block', query ) ??
					NO_RECORDS,
				hasResolved: core.hasFinishedResolution( 'getEntityRecords', [
					'postType',
					'wp_block',
					query,
				] ),
			};
		},
		[ categoryId ]
	);

	const cards = useMemo( () => {
		const builtIns = getBuiltInLayouts().map( ( layout ): LayoutCard => {
			const id = getLayoutId( layout.slug );
			const record = id
				? records.find( ( item ) => item.id === id )
				: undefined;
			const fromPattern = record ? patternInnerBlocks( record ) : null;

			return {
				key: layout.slug,
				title: layout.title,
				patternId: id,
				slug: layout.slug,
				template: layout.template,
				previewWidth: layout.previewWidth,
				innerBlocks:
					fromPattern ??
					( () =>
						createBlocksFromInnerBlocksTemplate(
							layout.template()
						) ),
			};
		} );
		const builtInIds = getBuiltInLayouts()
			.map( ( layout ) => getLayoutId( layout.slug ) )
			.filter( Boolean );
		const others = records
			.filter( ( record ) => ! builtInIds.includes( record.id ) )
			.map( ( record ): LayoutCard | null => {
				const innerBlocks = isLayoutContent( record.content )
					? patternInnerBlocks( record )
					: null;

				return innerBlocks
					? {
							key: String( record.id ),
							title: patternTitle( record ),
							patternId: record.id,
							innerBlocks,
						}
					: null;
			} )
			.filter( ( card ): card is LayoutCard => card !== null )
			.sort( ( a, b ) => a.title.localeCompare( b.title ) );

		return [ ...builtIns, ...others ];
	}, [ records ] );

	const isReady =
		timedOut ||
		( hasResolved &&
			cards.every( ( card ) => settledKeys.includes( card.key ) ) );

	useEffect( () => {
		const timer = window.setTimeout(
			() => setTimedOut( true ),
			hasResolved ? REVEAL_TIMEOUT : LOAD_TIMEOUT
		);

		return () => window.clearTimeout( timer );
	}, [ hasResolved ] );

	useEffect( () => {
		if ( isReady ) {
			const content = contentRef.current;
			(
				content?.querySelector< HTMLElement >(
					'button:not(:disabled)'
				) ??
				content?.closest< HTMLElement >( '.components-modal__frame' )
			)?.focus();
			onReady?.();
		}
	}, [ isReady ] );

	useEffect( () => {
		if ( isReady ) {
			return;
		}

		const closeOnEscape = ( event: KeyboardEvent ) => {
			if ( event.key === 'Escape' ) {
				onClose();
			}
		};
		document.addEventListener( 'keydown', closeOnEscape );

		return () => document.removeEventListener( 'keydown', closeOnEscape );
	}, [ isReady, onClose ] );

	const markSettled = ( key: string ) =>
		setSettledKeys( ( keys ) =>
			keys.includes( key ) ? keys : [ ...keys, key ]
		);

	const pick = ( card: LayoutCard ) => {
		if ( pendingKey ) {
			return;
		}

		if ( card.patternId ) {
			onSelect( { kind: 'pattern', id: card.patternId } );
			return;
		}

		const { slug, template } = card;

		if ( ! slug || ! template ) {
			return;
		}

		setPendingKey( card.key );
		createLayout( slug, template )
			.then( ( id ) => {
				if ( getLayoutCategoryId() ) {
					invalidateResolution( 'getEntityRecords', [
						'postType',
						'wp_block',
						layoutsQuery( getLayoutCategoryId() ),
					] );
				}
				if ( isMounted.current ) {
					onSelect( { kind: 'pattern', id } );
				}
			} )
			.catch( () => {
				if ( isMounted.current ) {
					onSelect( { kind: 'template', slug } );
				}
			} );
	};

	return (
		<Modal
			title={ __( 'Choose a layout', 'newspack-rolling-coverage' ) }
			size="large"
			focusOnMount={ false }
			overlayClassName={ isReady ? undefined : 'is-awaiting-previews' }
			onRequestClose={ onClose }
		>
			<div ref={ contentRef }>
				{ hasResolved ? (
					<div className="newspack-rolling-coverage-layout-picker">
						{ cards.map( ( card ) => (
							<LayoutPickerCard
								key={ card.key }
								card={ card }
								isCurrent={
									currentLayoutId > 0 &&
									card.patternId === currentLayoutId
								}
								isPending={ pendingKey === card.key }
								isDisabled={
									pendingKey !== null &&
									pendingKey !== card.key
								}
								onClick={ () => pick( card ) }
								onSettled={ () => markSettled( card.key ) }
							/>
						) ) }
					</div>
				) : (
					<LoadingState
						label={ __(
							'Loading layouts…',
							'newspack-rolling-coverage'
						) }
					/>
				) }
			</div>
		</Modal>
	);
}
