/**
 * External dependencies
 */
import { useState, useCallback } from '@wordpress/element';

/**
 * Internal dependencies
 */
import AdminHeader from './components/admin-header';
import LiveblogView from './components/liveblog-view';
import EntryView from './components/entry-view';
import ConnectionPage from './components/connection-page';
import { useAdminContext } from './hooks/useAdminContext';
import type { Liveblog, ViewType } from './types';

/**
 * Root admin component. Manages liveblog/entry view state and navigation
 * between the liveblog list and the entry list for a selected liveblog.
 * Also handles conditional rendering of the Slack settings page based on
 * the server-provided `page` config value.
 */
function App() {
	const config = useAdminContext();
	const [ view, setView ] = useState< ViewType >( 'liveblogs' );
	const [ selectedLiveblog, setSelectedLiveblog ] =
		useState< Liveblog | null >( null );

	const navigateToEntries = useCallback( ( liveblog: Liveblog ) => {
		setSelectedLiveblog( liveblog );
		setView( 'entries' );
	}, [] );

	const navigateBack = useCallback( () => {
		setSelectedLiveblog( null );
		setView( 'liveblogs' );
	}, [] );

	return (
		<div className="newspack-rolling-coverage-admin">
			{ config.page === 'connection' && <ConnectionPage /> }
			{ config.page === 'admin' && (
				<>
					<AdminHeader
						view={ view }
						selectedLiveblog={ selectedLiveblog }
						onNavigateBack={ navigateBack }
					/>
					{ view === 'liveblogs' && (
						<LiveblogView
							onNavigateToEntries={ navigateToEntries }
						/>
					) }
					{ view === 'entries' && selectedLiveblog && (
						<EntryView
							key={ selectedLiveblog.id }
							liveblog={ selectedLiveblog }
						/>
					) }
				</>
			) }
		</div>
	);
}

export default App;
