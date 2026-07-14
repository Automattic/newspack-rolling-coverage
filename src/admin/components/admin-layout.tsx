/**
 * External dependencies
 */
import { Outlet } from 'react-router';
import { useState, useCallback } from '@wordpress/element';

/**
 * Internal dependencies
 */
import AdminHeader from './admin-header';
import type { Context } from '../types';

/**
 * Layout route that renders the admin header and the matched child view
 * via <Outlet />. Holds the shared refreshKey in context so that any
 * mutation (trash, restore, delete) instantly refreshes all DataViews,
 * including ones that mount later via navigation.
 */
function AdminLayout() {
	const [ context, setContext ] = useState< Context >( {
		selectedCoverage: null,
		refreshKey: 0,
	} );

	const refresh = useCallback( () => {
		setContext( ( prev ) => ( {
			...prev,
			refreshKey: prev.refreshKey + 1,
		} ) );
	}, [] );

	return (
		<div className="newspack-rolling-coverage-admin">
			<AdminHeader selectedCoverage={ context?.selectedCoverage } />
			<Outlet context={ [ context, setContext, refresh ] } />
		</div>
	);
}

export default AdminLayout;
