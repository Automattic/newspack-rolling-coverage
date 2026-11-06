/**
 * External dependencies.
 */
import type { ComponentType, FocusEvent, ReactNode } from 'react';
import { DataViews } from '@wordpress/dataviews/wp';
import { useMemo } from '@wordpress/element';
import { Stack } from '@wordpress/ui';

/**
 * Internal dependencies.
 */
import type { DataViewsWrapperProps } from '../types';

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
 * Scrolls a bulk action that takes keyboard focus fully into its row. On narrow
 * screens the row scrolls sideways under a fade and a pinned Cancel, and
 * browsers leave a mostly visible button where it is, half hidden.
 *
 * @param {FocusEvent<HTMLDivElement>} event The focus event.
 */
function revealFocusedBulkAction( event: FocusEvent< HTMLDivElement > ) {
	const target: Element = event.target;
	if (
		target.matches( ':focus-visible' ) &&
		target.parentElement?.classList.contains(
			'dataviews-bulk-actions-footer__action-buttons'
		)
	) {
		target.scrollIntoView( { block: 'nearest', inline: 'nearest' } );
	}
}

/**
 * Type-safe wrapper around WordPress DataViews that fills in default props
 * (getItemId, defaultLayouts, isItemClickable) so consumers don't have to.
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
	header,
	defaultLayouts,
	config,
}: DataViewsWrapperProps< T > ) {
	// "Add filter" only renders beside a visible chip, so every declared
	// filter is made primary to keep it reachable without the funnel toggle.
	const shownFields = useMemo(
		() =>
			fields.map( ( field ) =>
				field.filterBy
					? {
							...field,
							filterBy: { ...field.filterBy, isPrimary: true },
						}
					: field
			),
		[ fields ]
	);

	return (
		<div
			className="newspack-rolling-coverage-dataviews"
			onFocus={ revealFocusedBulkAction }
		>
			<TypedDataViews
				data={ data }
				fields={ shownFields }
				view={ view }
				onChangeView={ onChangeView }
				actions={ actions }
				paginationInfo={ paginationInfo }
				isLoading={ isLoading }
				defaultLayouts={ defaultLayouts ?? { table: {} } }
				isItemClickable={ onClickItem ? () => true : undefined }
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
