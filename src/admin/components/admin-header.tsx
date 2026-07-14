/**
 * External dependencies
 */
import { useLocation, useNavigate } from 'react-router';
import { Button } from '@wordpress/components';
import { chevronLeft, chevronRight, Icon, megaphone } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { Coverage } from '../types';

interface AdminHeaderProps {
	selectedCoverage: Coverage | null;
}

/**
 * Top bar showing the plugin title and a back button when viewing entries.
 * Renders the coverage name as a subtitle when a coverage is selected.
 */
function AdminHeader( { selectedCoverage }: AdminHeaderProps ) {
	const navigate = useNavigate();
	const location = useLocation();
	const isCoverageView = location.pathname.startsWith( '/coverages/' );
	const isTrashedEntriesView = location.pathname === '/trashed-entries';

	const showBackButton = isCoverageView || isTrashedEntriesView;
	const showCoverageSubtitle = isCoverageView && selectedCoverage;
	const showTrashedSubtitle = isTrashedEntriesView;

	return (
		<div className="newspack-rolling-coverage-header">
			{ showBackButton && (
				<Button
					className="newspack-rolling-coverage-header__nav"
					variant="tertiary"
					icon={ chevronLeft }
					onClick={ () => navigate( '/coverages' ) }
				/>
			) }
			<Icon icon={ megaphone } size={ 40 } />
			<h1 className="newspack-rolling-coverage-header__title">
				{ __( 'Rolling Coverage', 'newspack-rolling-coverage' ) }
			</h1>
			{ showTrashedSubtitle && (
				<>
					<Icon icon={ chevronRight } size={ 24 } />
					<span className="newspack-rolling-coverage-header__subtitle">
						{ __( 'Trashed Entries', 'newspack-rolling-coverage' ) }
					</span>
				</>
			) }
			{ showCoverageSubtitle && (
				<>
					<Icon icon={ chevronRight } size={ 24 } />
					<span className="newspack-rolling-coverage-header__subtitle">
						{ selectedCoverage.name }
					</span>
				</>
			) }
		</div>
	);
}

export default AdminHeader;
