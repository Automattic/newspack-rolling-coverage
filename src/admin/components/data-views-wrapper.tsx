/**
 * External dependencies.
 */
import type { ComponentType } from 'react';
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
 * Type-safe wrapper around WordPress DataViews that fills in default props
 * (getItemId, defaultLayouts, isItemClickable) so consumers don't have to.
 */
function DataViewsWrapper< T extends { id: number } >( {
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
}: DataViewsWrapperProps< T > ) {
	return (
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
			getItemId={ ( item: T ) => String( item.id ) }
		/>
	);
}

export { DataViewsWrapper };
