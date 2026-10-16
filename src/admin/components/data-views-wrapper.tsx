/**
 * External dependencies.
 */
import type { ComponentType, FocusEvent } from 'react';
import { DataViews } from '@wordpress/dataviews/wp';

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
	return (
		<div
			className="newspack-rolling-coverage-dataviews"
			onFocus={ revealFocusedBulkAction }
		>
			<TypedDataViews
				data={ data }
				fields={ fields }
				view={ view }
				onChangeView={ onChangeView }
				actions={ actions }
				paginationInfo={ paginationInfo }
				isLoading={ isLoading }
				defaultLayouts={ defaultLayouts ?? { table: {} } }
				isItemClickable={ onClickItem ? () => true : undefined }
				onClickItem={ onClickItem }
				header={ header }
				config={ config }
				getItemId={ ( item: T ) => String( item.id ) }
			/>
		</div>
	);
}

export { DataViewsWrapper };
