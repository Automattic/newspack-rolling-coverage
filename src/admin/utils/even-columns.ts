/**
 * External dependencies.
 */
import type { View } from '@wordpress/dataviews';

const TITLE_SHARE = 30;

type ColumnStyle = Record< string, unknown > & { width?: string | number };
type ColumnStyles = Record< string, ColumnStyle >;
type ViewLayout = { styles?: ColumnStyles } & Record< string, unknown >;

function getShares( view: View, fitColumns: string[] ) {
	const hasPrimary =
		( Boolean( view.titleField ) && view.showTitle !== false ) ||
		( Boolean( view.mediaField ) && view.showMedia !== false ) ||
		( Boolean( view.descriptionField ) && view.showDescription !== false );
	const shown = ( view.fields ?? [] ).filter(
		( id ) => id !== view.titleField
	);
	const fitted = shown.filter( ( id ) => fitColumns.includes( id ) );
	const columns = shown.filter( ( id ) => ! fitColumns.includes( id ) );
	const titleShare = hasPrimary
		? Math.max( TITLE_SHARE, 100 / ( columns.length + 1 ) )
		: 0;
	const columnShare = ( 100 - titleShare ) / Math.max( columns.length, 1 );
	return { shown, fitted, columns, titleShare, columnShare };
}

/**
 * Gives a table consistent column widths, as the Newspack admin lists do:
 * the title takes an equal share, never less than 30%, and every other visible
 * column an equal share of what the title leaves. Without it the browser hands
 * all spare width to the title column, so a short title still fills half the
 * table. A column's other
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
	const { shown, fitted, columns, columnShare } = getShares(
		view,
		fitColumns
	);
	if ( ! shown.length ) {
		return view;
	}
	const width = `${ Number( columnShare.toFixed( 2 ) ) }%`;
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
 * The title column's width for a table `evenColumns` sized. DataViews never
 * gives the title column a width, so the wrapper passes this on as the
 * `--newspack-rolling-coverage-title-width` custom property.
 *
 * @param {View}     view       The DataViews view.
 * @param {string[]} fitColumns Columns sized to their content.
 * @return {string|undefined} The width, or undefined when there is no title column to size.
 */
function titleColumnWidth(
	view: View,
	fitColumns: string[] = []
): string | undefined {
	if ( view.type !== 'table' ) {
		return undefined;
	}
	const { columns, titleShare } = getShares( view, fitColumns );
	return titleShare && columns.length
		? `${ Number( titleShare.toFixed( 2 ) ) }%`
		: undefined;
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

export { evenColumns, titleColumnWidth, withoutColumnWidths };
