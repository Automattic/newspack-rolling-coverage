/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { chevronLeft, Icon, megaphone } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { Liveblog } from '../types';

interface AdminHeaderProps {
	view: 'liveblogs' | 'entries';
	selectedLiveblog: Liveblog | null;
	onNavigateBack: () => void;
}

/**
 * Top bar showing the plugin title and a back button when viewing entries.
 */
function AdminHeader( {
	view,
	selectedLiveblog,
	onNavigateBack,
}: AdminHeaderProps ) {
	return (
		<div className="newspack-rolling-coverage-header">
			{ view === 'entries' && selectedLiveblog && (
				<Button
					className="newspack-rolling-coverage-header__nav"
					variant="tertiary"
					icon={ chevronLeft }
					onClick={ onNavigateBack }
				/>
			) }
			<Icon icon={ megaphone } size={ 40 } />
			<h1 className="newspack-rolling-coverage-header__title">
				{ __( 'Rolling Coverage', 'newspack-rolling-coverage' ) }
			</h1>
		</div>
	);
}

export default AdminHeader;
