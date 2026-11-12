/**
 * External dependencies
 */
import { Navigate, useParams } from 'react-router';
import { useMemo, useState } from '@wordpress/element';
import TabbedNavigation from 'newspack-components/dist/esm/tabbed-navigation';

/**
 * Internal dependencies
 */
import { useAdminContext } from '../hooks/useAdminContext';
import { useUnsavedChangesGuard } from '../hooks/useUnsavedChangesGuard';
import { getSettingsTabs } from '../utils/settings-tabs';
import GeneralSettings from './general-settings';
import AISettings from './ai-settings';
import SlackSettings from './slack-settings';

const TAB_COMPONENTS = {
	general: GeneralSettings,
	slack: SlackSettings,
	ai: AISettings,
};

/**
 * Renders the Settings page under /settings/{tab}, one tab per group of
 * settings the user can manage. A tab the user can't open, or an unknown
 * one, goes to the first tab they can; with none, to All Liveblogs.
 * Leaving the page or switching tabs with unsaved changes asks first,
 * through the one unsaved-changes guard the page mounts.
 */
function SettingsPage() {
	const { tab } = useParams();
	const config = useAdminContext();
	const tabs = useMemo( () => getSettingsTabs( config ), [ config ] );
	const [ isDirty, setIsDirty ] = useState( false );
	const unsavedChangesDialog = useUnsavedChangesGuard( isDirty );

	const tabbedNavigation = useMemo(
		() =>
			tabs.length > 1 ? (
				<TabbedNavigation
					items={ tabs.map( ( t ) => ( {
						label: t.title,
						href: `#/settings/${ t.name }`,
						selected: t.name === tab,
					} ) ) }
				/>
			) : null,
		[ tabs, tab ]
	);

	if ( ! tabs.some( ( { name } ) => name === tab ) ) {
		return (
			<Navigate
				to={
					tabs.length ? `/settings/${ tabs[ 0 ].name }` : '/coverages'
				}
				replace
			/>
		);
	}

	const TabContent = TAB_COMPONENTS[ tab as keyof typeof TAB_COMPONENTS ];

	return (
		<>
			{ unsavedChangesDialog }
			<TabContent
				tabbedNavigation={ tabbedNavigation }
				onDirtyChange={ setIsDirty }
			/>
		</>
	);
}

export default SettingsPage;
