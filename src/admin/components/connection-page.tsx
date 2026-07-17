/**
 * External dependencies
 */
import { useState, useEffect, useMemo } from '@wordpress/element';
import { TabPanel } from '@wordpress/components';
import { Outlet } from 'react-router';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { useAdminContext } from '../hooks/useAdminContext';
import { getAdapterFromUrl, setAdapterInUrl } from '../utils/adapter-url';

/**
 * Top-level Connection admin page shell. When multiple chat-source adapters
 * are registered, renders a TabPanel for adapter selection with the active
 * adapter's nested route rendered via <Outlet />. When only one adapter
 * exists (the common case today), the TabPanel is skipped entirely and the
 * nested route renders directly.
 */
function ConnectionPage() {
	const config = useAdminContext();
	const adapters = config.availableAdapters ?? {};

	const slugs = useMemo( () => Object.keys( adapters ), [ adapters ] );
	const urlSlug = getAdapterFromUrl();
	const initialSlug =
		urlSlug && slugs.includes( urlSlug ) ? urlSlug : slugs[ 0 ];

	const [ activeSlug, setActiveSlug ] = useState< string >( initialSlug );

	useEffect( () => {
		setAdapterInUrl( activeSlug );
	}, [ activeSlug ] );

	if ( slugs.length === 0 ) {
		return (
			<p>
				{ __(
					'No chat-source adapters are configured yet.',
					'newspack-rolling-coverage'
				) }
			</p>
		);
	}

	// Single adapter — no tab chrome needed, just render the nested route.
	if ( slugs.length <= 1 ) {
		return <Outlet />;
	}

	// Multiple adapters — render a TabPanel for adapter selection.
	const tabs = slugs.map( ( slug ) => ( {
		name: slug,
		title: adapters[ slug ],
	} ) );

	return (
		<>
			<TabPanel
				tabs={ tabs }
				initialTabName={ initialSlug }
				onSelect={ ( tabName: string ) => setActiveSlug( tabName ) }
			>
				{ () => null }
			</TabPanel>
			<Outlet />
		</>
	);
}

export default ConnectionPage;
