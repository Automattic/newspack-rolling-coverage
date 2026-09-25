/**
 * External dependencies
 */
import { Outlet, useLocation } from 'react-router';
import { useState, useCallback, useEffect, useRef } from '@wordpress/element';
import { speak } from '@wordpress/a11y';
import { __ } from '@wordpress/i18n';
import { decodeEntities } from '@wordpress/html-entities';
import Page from 'newspack-components/dist/esm/page';

/**
 * Internal dependencies
 */
import { SLACK_TABS } from '../utils/slack-tabs';
import { useAdminContext } from '../hooks/useAdminContext';
import type { BreadcrumbItem, Context, Coverage, HeaderState } from '../types';

/**
 * Breadcrumb trail for the current route. The last crumb is the current
 * page and carries the view's item count, when it reports one.
 *
 * @param pathname         Current router pathname.
 * @param selectedCoverage Coverage shown on a coverage route, if loaded.
 * @param count            Item count reported by the current view.
 * @param isEmpty          Whether the view shows its empty state.
 * @return Breadcrumb items, last item being the current page.
 */
function getBreadcrumbItems(
	pathname: string,
	selectedCoverage: Coverage | null,
	count?: number,
	isEmpty?: boolean
): BreadcrumbItem[] {
	const root = {
		label: __( 'Rolling Coverage', 'newspack-rolling-coverage' ),
	};
	const allCoverages = __( 'All Coverages', 'newspack-rolling-coverage' );
	const allCoveragesLink = { label: allCoverages, url: '#/coverages' };

	if ( pathname.startsWith( '/coverages/' ) ) {
		const routeId = Number( pathname.split( '/' )[ 2 ] );
		const coverage =
			selectedCoverage?.id === routeId ? selectedCoverage : null;
		return [
			root,
			allCoveragesLink,
			{
				label: coverage
					? decodeEntities( coverage.name )
					: __( 'Coverage', 'newspack-rolling-coverage' ),
				count,
			},
		];
	}

	if ( pathname.startsWith( '/connection' ) ) {
		const slackConnection = __(
			'Slack Connection',
			'newspack-rolling-coverage'
		);
		const tab = SLACK_TABS.find(
			( { name } ) => pathname === `/connection/${ name }`
		);
		if ( ! tab ) {
			return [ root, { label: slackConnection } ];
		}
		return [ root, { label: slackConnection }, { label: tab.title } ];
	}

	if ( pathname === '/ai' ) {
		return [ root, { label: __( 'AI', 'newspack-rolling-coverage' ) } ];
	}

	if ( isEmpty ) {
		return [ root ];
	}

	return [ root, { label: allCoverages, count } ];
}

/**
 * Layout route that renders the admin header and the matched child view
 * via <Outlet />. Holds the shared refreshKey in context so that any
 * mutation (trash, restore, delete) instantly refreshes all DataViews,
 * including ones that mount later via navigation.
 */
function AdminLayout() {
	const { pathname } = useLocation();
	const { adminTitleSuffix } = useAdminContext();
	const isFirstLabel = useRef( true );
	const [ context, setContext ] = useState< Context >( {
		selectedCoverage: null,
		refreshKey: 0,
	} );

	const [ header, setHeader ] = useState< HeaderState >( {} );

	const refresh = useCallback( () => {
		setContext( ( prev ) => ( {
			...prev,
			refreshKey: prev.refreshKey + 1,
		} ) );
	}, [] );

	const breadcrumbItems = getBreadcrumbItems(
		pathname,
		context.selectedCoverage,
		header.count,
		header.isEmpty
	);
	const currentLabel = breadcrumbItems[ breadcrumbItems.length - 1 ].label;

	useEffect( () => {
		document.title = currentLabel + adminTitleSuffix;
		// Hash routes don't reload the page, so a new view has to be announced;
		// the first label is the page the reader just loaded.
		if ( isFirstLabel.current ) {
			isFirstLabel.current = false;
			return;
		}
		speak( currentLabel );
	}, [ currentLabel, adminTitleSuffix ] );

	return (
		<Page
			className="newspack-rolling-coverage-admin"
			breadcrumbItems={ breadcrumbItems }
			actions={ header.actions }
			badges={ header.badges }
			tabbedNavigation={ header.tabbedNavigation }
		>
			<div className="newspack-rolling-coverage-admin__content">
				<Outlet
					context={ [ context, setContext, refresh, setHeader ] }
				/>
			</div>
		</Page>
	);
}

export default AdminLayout;
