/**
 * External dependencies
 */
import { Outlet } from 'react-router';
import { useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import AdminHeader from './admin-header';
import type { Context } from '../types';

/**
 * Layout route that renders the admin header and the matched child view
 * via <Outlet />. Fetches all coverages so the header can resolve and
 * display the selected coverage's name when a coverageId param is present.
 */
function AdminLayout() {
	const [ context, setContext ] = useState< Context >( {
		selectedCoverage: null,
	} );

	return (
		<div className="newspack-rolling-coverage-admin">
			<AdminHeader selectedCoverage={ context?.selectedCoverage } />
			<Outlet context={ [ context, setContext ] } />
		</div>
	);
}

export default AdminLayout;
