/**
 * External dependencies.
 */
import type { ComponentType, ReactNode } from 'react';
import { DataViews } from '@wordpress/dataviews/wp';
import { useEffect, useMemo, useRef } from '@wordpress/element';
import { Stack } from '@wordpress/ui';

/**
 * Internal dependencies.
 */
import type { DataViewsWrapperProps } from '../types';
import {
	evenColumns,
	titleColumnWidth,
	withoutColumnWidths,
} from '../utils/even-columns';

// DataViews has complex conditional prop types that don't cast directly.
// Double-assert through unknown to bridge the type gap (per TS2352 suggestion).
const TypedDataViews = DataViews as unknown as ComponentType<
	Record< string, unknown >
>;

/**
 * The list toolbar: filter chips beside the search, view options and any
 * `header` content on the right. Replaces the default funnel toggle, which
 * hid the filters behind a button and listed them on a row of their own.
 *
 * @param {Object}    props        Component props.
 * @param {ReactNode} props.header Content shown after the view options.
 */
function InlineFiltersLayout( { header }: { header?: ReactNode } ) {
	return (
		<>
			<Stack
				direction="row"
				justify="space-between"
				align="flex-start"
				gap="xs"
				className="dataviews__view-actions"
			>
				<Stack
					direction="row"
					align="center"
					gap="sm"
					wrap="wrap"
					className="dataviews__search"
				>
					<DataViews.Search />
					<DataViews.Filters />
				</Stack>
				<Stack
					direction="row"
					align="center"
					gap="xs"
					style={ { flexShrink: 0 } }
				>
					<DataViews.LayoutSwitcher />
					<DataViews.ViewConfig />
					{ header }
				</Stack>
			</Stack>
			<DataViews.Layout />
			<DataViews.Footer />
		</>
	);
}

/**
 * Type-safe wrapper around WordPress DataViews that fills in default props
 * (getItemId, defaultLayouts, isItemClickable) so consumers don't have to, and
 * lays out the shared list toolbar with declared filters as chips.
 *
 * DataViews makes the list inert while it loads, which drops focus to the
 * page, so once loading ends the wrapper gives focus back to the element in
 * the list that last had it, unless focus has moved elsewhere meanwhile.
 */
function DataViewsWrapper< T extends { id: number | string } >( {
	data,
	fields,
	view,
	onChangeView,
	actions,
	paginationInfo,
	isLoading,
	onClickItem,
	isItemClickable,
	header,
	defaultLayouts,
	config,
	fitColumns,
}: DataViewsWrapperProps< T > ) {
	// "Add filter" only renders beside a visible chip, so a declared filter is
	// made primary unless it opts out with `isPrimary: false`. A list with no
	// chip at all would show no filter UI.
	const shownFields = useMemo(
		() =>
			fields.map( ( field ) =>
				field.filterBy && field.filterBy.isPrimary !== false
					? {
							...field,
							filterBy: { ...field.filterBy, isPrimary: true },
						}
					: field
			),
		[ fields ]
	);
	const evenView = useMemo(
		() => evenColumns( view, fitColumns ),
		[ view, fitColumns ]
	);

	const lastFocusedRef = useRef< HTMLElement | null >( null );
	const wasLoadingRef = useRef( isLoading );
	useEffect( () => {
		if ( wasLoadingRef.current && ! isLoading ) {
			const element = lastFocusedRef.current;
			window.requestAnimationFrame( () => {
				const active = element?.ownerDocument.activeElement;
				if (
					element?.isConnected &&
					( ! active || active === element.ownerDocument.body )
				) {
					element.focus();
				}
			} );
		}
		wasLoadingRef.current = isLoading;
	}, [ isLoading ] );

	const titleWidth = useMemo(
		() => titleColumnWidth( view, fitColumns ),
		[ view, fitColumns ]
	);

	return (
		<div
			className="newspack-rolling-coverage-dataviews"
			onFocus={ ( event ) => {
				lastFocusedRef.current = event.target;
			} }
			style={
				titleWidth
					? ( {
							'--newspack-rolling-coverage-title-width':
								titleWidth,
						} as React.CSSProperties )
					: undefined
			}
		>
			<TypedDataViews
				data={ data }
				fields={ shownFields }
				view={ evenView }
				onChangeView={ ( next: typeof view ) =>
					onChangeView( withoutColumnWidths( next ) )
				}
				actions={ actions }
				paginationInfo={ paginationInfo }
				isLoading={ isLoading }
				defaultLayouts={ defaultLayouts ?? { table: {} } }
				isItemClickable={
					onClickItem
						? ( isItemClickable ?? ( () => true ) )
						: undefined
				}
				onClickItem={ onClickItem }
				config={ config }
				getItemId={ ( item: T ) => String( item.id ) }
			>
				<InlineFiltersLayout header={ header } />
			</TypedDataViews>
		</div>
	);
}

export { DataViewsWrapper };
