/**
 * External dependencies
 */
import { useState, useEffect, useMemo } from '@wordpress/element';
import { TabPanel } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { useAdminContext } from '../hooks/useAdminContext';
import { getAdapterFromUrl, setAdapterInUrl } from '../utils/adapter-url';
import SlackSettingsPage from './slack-settings-page';

/**
 * Top-level Connection admin page shell. Renders a TabPanel of registered
 * chat-source adapters and dispatches the body to an adapter-specific
 * component. When a new adapter ships, the only change is adding a case to
 * `renderAdapterBody()` and (separately) registering the adapter in
 * `config.availableAdapters`.
 */
function ConnectionPage() {
	const config = useAdminContext();
	const adapters = config.availableAdapters ?? {};

	const slugs = useMemo( () => Object.keys( adapters ), [ adapters ] );
	const urlSlug = getAdapterFromUrl();
	const initialSlug =
		urlSlug && slugs.includes( urlSlug ) ? urlSlug : slugs[ 0 ] ?? 'slack';

	const [ activeSlug, setActiveSlug ] = useState< string >( initialSlug );

	useEffect( () => {
		setAdapterInUrl( activeSlug );
	}, [ activeSlug ] );

	// Adapter dispatch table. Extend this when a new adapter ships.
	const renderAdapterBody = ( slug: string ) => {
		switch ( slug ) {
			case 'slack':
				return <SlackSettingsPage />;
			default:
				return (
					<p>
						{ __(
							'No settings UI is registered for this adapter yet.',
							'newspack-rolling-coverage'
						) }
					</p>
				);
		}
	};

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

	const tabs = slugs.map( ( slug ) => ( {
		name: slug,
		title: adapters[ slug ],
	} ) );

	return (
		<TabPanel
			tabs={ tabs }
			initialTabName={ initialSlug }
			onSelect={ ( tabName: string ) => setActiveSlug( tabName ) }
		>
			{ ( tab ) => renderAdapterBody( tab.name ) }
		</TabPanel>
	);
}

export default ConnectionPage;
