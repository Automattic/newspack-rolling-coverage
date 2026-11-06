/**
 * External dependencies.
 */
import type { View } from '@wordpress/dataviews';

const TITLE_SHARE = 30;

type ColumnStyle = Record< string, unknown > & { width?: string | number };
type ColumnStyles = Record< string, ColumnStyle >;
type ViewLayout = { styles?: ColumnStyles } & Record< string, unknown >;

/**
 * Gives a table consistent column widths, as the Newspack admin lists do:
 * every visible column except the title takes an equal share of 70%, and the
 * title keeps what is left. Without it the browser hands all spare width to the
 * title column, so a short title still fills half the table. A column's other
 * settings, such as its alignment, are kept. The widths follow whichever
 * columns the viewer has switched on, so they are worked out on every render
 * and never stored with the view.
 *
 * Columns named in `fitColumns`, such as a lone button, shrink to their content
 * instead of taking a share.
 *
 * @param {View}     view       The DataViews view.
 * @param {string[]} fitColumns Columns sized to their content.
 * @return {View} The view with column widths, unchanged for any layout but a table.
 */
function evenColumns< V extends View >(
	view: V,
	fitColumns: string[] = []
): V {
	if ( view.type !== 'table' ) {
		return view;
	}
	const hasPrimary =
		( Boolean( view.titleField ) && view.showTitle !== false ) ||
		( Boolean( view.mediaField ) && view.showMedia !== false ) ||
		( Boolean( view.descriptionField ) && view.showDescription !== false );
	const shown = ( view.fields ?? [] ).filter(
		( id ) => id !== view.titleField
	);
	if ( ! shown.length ) {
		return view;
	}
	const fitted = shown.filter( ( id ) => fitColumns.includes( id ) );
	const columns = shown.filter( ( id ) => ! fitColumns.includes( id ) );
	const share =
		( hasPrimary ? 100 - TITLE_SHARE : 100 ) /
		Math.max( columns.length, 1 );
	const width = `${ Number( share.toFixed( 2 ) ) }%`;
	const layout = ( view.layout ?? {} ) as ViewLayout;
	const styles: ColumnStyles = { ...layout.styles };
	columns.forEach( ( id ) => {
		styles[ id ] = { ...styles[ id ], width };
	} );
	fitted.forEach( ( id ) => {
		styles[ id ] = { ...styles[ id ], width: '1%' };
	} );
	return { ...view, layout: { ...layout, styles } } as V;
}

/**
 * Drops the widths `evenColumns` adds, so a view handed back by DataViews is
 * passed on without them. Other column settings stay.
 *
 * @param {View} view The DataViews view.
 * @return {View} The view without column widths.
 */
function withoutColumnWidths< V extends View >( view: V ): V {
	const layout = ( view as { layout?: ViewLayout } ).layout;
	if ( ! layout?.styles ) {
		return view;
	}
	const styles: ColumnStyles = {};
	Object.entries( layout.styles ).forEach( ( [ id, style ] ) => {
		const { width, ...rest } = style; // eslint-disable-line @typescript-eslint/no-unused-vars
		if ( Object.keys( rest ).length ) {
			styles[ id ] = rest;
		}
	} );
	return { ...view, layout: { ...layout, styles } } as V;
}

export { evenColumns, withoutColumnWidths };
