/**
 * External dependencies
 */
import { HashRouter, Routes, Route, Navigate } from 'react-router';

/**
 * WordPress dependencies
 */
import { createPortal } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import { SnackbarList } from '@wordpress/components';

/**
 * Internal dependencies
 */
import AdminLayout from './components/admin-layout';
import CoverageView from './components/coverage-view';
import EntryView from './components/entry-view';

/**
 * Root admin component. Uses react-router's HashRouter so navigation state
 * is persisted in the URL hash. The hash fragment is client-side only, so
 * the WP admin page (admin.php?page=rolling-coverage) stays loaded while
 * the SPA tracks its own route in the hash. Routes:
 *   /coverages                   — coverage list (auto-redirected from root)
 *   /coverages/{id}              — rolling coverage entries
 */
function App() {
	const notices = useSelect(
		( select ) => select( noticesStore ).getNotices(),
		[]
	);
	const { removeNotice } = useDispatch( noticesStore );

	return (
		<>
			<HashRouter>
				<Routes>
					<Route element={ <AdminLayout /> }>
						<Route
							index
							element={ <Navigate to="/coverages" replace /> }
						/>
						<Route path="/coverages" element={ <CoverageView /> } />
						<Route
							path="/coverages/:coverageId"
							element={ <EntryView /> }
						/>
						<Route
							path="*"
							element={ <Navigate to="/coverages" replace /> }
						/>
					</Route>
				</Routes>
			</HashRouter>
			{ createPortal(
				<SnackbarList
					notices={ notices }
					className="newspack-rolling-coverage-snackbar-list"
					onRemove={ removeNotice }
				/>,
				document.body
			) }
		</>
	);
}

export default App;
