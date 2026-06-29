/**
 * External dependencies
 */
import { Outlet, useParams } from 'react-router';
import { useMemo } from '@wordpress/element';

/**
 * Internal dependencies
 */
import AdminHeader from './admin-header';
import { useCoverages } from '../hooks/useCoverages';
import type { Coverage } from '../types';

/**
 * Layout route that renders the admin header and the matched child view
 * via <Outlet />. Fetches all coverages so the header can resolve and
 * display the selected coverage's name when a coverageId param is present.
 */
function AdminLayout() {
	const params = useParams< { coverageId?: string } >();

	const { records } = useCoverages( {
		perPage: 100,
		page: 1,
	} );

	const selectedCoverage: Coverage | null = useMemo( () => {
		if ( ! params.coverageId ) {
			return null;
		}
		return (
			( records ?? [] ).find(
				( coverage ) => String( coverage.id ) === params.coverageId
			) ?? null
		);
	}, [ params.coverageId, records ] );

	return (
		<div className="newspack-rolling-coverage-admin">
			<AdminHeader selectedCoverage={ selectedCoverage } />
			<Outlet context={ selectedCoverage } />
		</div>
	);
}

export default AdminLayout;
