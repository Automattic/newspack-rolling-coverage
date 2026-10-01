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
import { LAYOUT_CATEGORY_ID } from '../config';
import { BLOCK_NAME } from '../layout';
import { getBuiltInLayouts, type BuiltInLayoutSlug } from '../layouts';
import { createLayout, getLayoutId } from '../utils';

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
	innerBlocks: () => unknown[];
};

const PREVIEW_ENTRIES = 3;
const PREVIEW_VIEWPORT_WIDTH = 800;
const NO_RECORDS: LayoutRecord[] = [];

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
 * One layout in the picker: a scaled preview of the block rendering sample
 * entries in the layout, with the layout's title.
 *
 * @param {Object}   props            Component props.
 * @param {Object}   props.card       The layout.
 * @param {boolean}  props.isCurrent  Whether the block uses this layout.
 * @param {boolean}  props.isPending  Whether this layout is being set up.
 * @param {boolean}  props.isDisabled Whether another layout is being set up.
 * @param {Function} props.onClick    Picks the layout.
 */
function LayoutPickerCard( {
	card,
	isCurrent,
	isPending,
	isDisabled,
	onClick,
}: {
	card: LayoutCard;
	isCurrent: boolean;
	isPending: boolean;
	isDisabled: boolean;
	onClick: () => void;
} ) {
	const blocks = useMemo(
		() => [
			createBlock(
				BLOCK_NAME,
				{ entriesPerPage: PREVIEW_ENTRIES },
				card.innerBlocks() as Parameters< typeof createBlock >[ 2 ]
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
				className="newspack-rolling-coverage-layout-picker__preview"
				aria-hidden="true"
			>
				<BlockPreview
					blocks={ blocks }
					viewportWidth={ PREVIEW_VIEWPORT_WIDTH }
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
 * and falls back to an unsynced copy of its template when that fails.
 *
 * @param {Object}   props                 Component props.
 * @param {number}   props.currentLayoutId The block's layout pattern ID, or 0.
 * @param {Function} props.onSelect        Called with the picked layout.
 * @param {Function} props.onClose         Closes the picker.
 */
export default function LayoutPickerModal( {
	currentLayoutId,
	onSelect,
	onClose,
}: {
	currentLayoutId: number;
	onSelect: ( choice: LayoutChoice ) => void;
	onClose: () => void;
} ) {
	const [ pendingKey, setPendingKey ] = useState< string | null >( null );
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

	const categoryId = Number( LAYOUT_CATEGORY_ID ) || 0;
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
				patternId: record ? id : 0,
				slug: layout.slug,
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
				const innerBlocks = patternInnerBlocks( record );

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

	const pick = ( card: LayoutCard ) => {
		if ( pendingKey ) {
			return;
		}

		if ( card.patternId ) {
			onSelect( { kind: 'pattern', id: card.patternId } );
			return;
		}

		const slug = card.slug;

		if ( ! slug ) {
			return;
		}

		setPendingKey( card.key );
		createLayout( slug )
			.then( ( id ) => {
				if ( categoryId ) {
					invalidateResolution( 'getEntityRecords', [
						'postType',
						'wp_block',
						layoutsQuery( categoryId ),
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
			onRequestClose={ onClose }
		>
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
								pendingKey !== null && pendingKey !== card.key
							}
							onClick={ () => pick( card ) }
						/>
					) ) }
				</div>
			) : (
				<Spinner />
			) }
		</Modal>
	);
}
