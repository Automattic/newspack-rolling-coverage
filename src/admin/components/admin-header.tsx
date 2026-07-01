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

	return (
		<div className="newspack-rolling-coverage-header">
			{ isCoverageView && selectedCoverage && (
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
			{ isCoverageView && selectedCoverage && (
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
