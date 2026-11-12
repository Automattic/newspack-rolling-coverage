/**
 * External dependencies
 */
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { SlackSettingsInfo } from '../types';
import { useAdminContext } from './useAdminContext';
import { getSlackSettings, saveSlackSettings } from '../utils/slack-api';
import { notifySuccess } from '../utils/notices';

/**
 * Holds the Slack settings a publisher can edit (ignore prefix, trigger
 * emoji, Highest heading) and the workspace info that shows the bot user.
 * Loads them once Slack is connected and saves all four through
 * `POST /slack/settings`.
 *
 * @return Settings state, setters, load and save state, and the save handler.
 */
function useSlackIngestionSettings() {
	const { restBase, slack } = useAdminContext();
	const namespace = restBase.slack;

	const [ ignorePrefix, setIgnorePrefix ] = useState( '~~' );
	const [ savedIgnorePrefix, setSavedIgnorePrefix ] = useState( '~~' );
	const [ publishReaction, setPublishReaction ] = useState( 'mega' );
	const [ savedPublishReaction, setSavedPublishReaction ] =
		useState( 'mega' );
	const [ draftReaction, setDraftReaction ] = useState( 'memo' );
	const [ savedDraftReaction, setSavedDraftReaction ] = useState( 'memo' );
	const [ topHeadingLevel, setTopHeadingLevel ] = useState( 0 );
	const [ savedTopHeadingLevel, setSavedTopHeadingLevel ] = useState( 0 );
	const [ workspaceInfo, setWorkspaceInfo ] =
		useState< SlackSettingsInfo | null >( null );
	const [ hasLoaded, setHasLoaded ] = useState( false );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );

	useEffect( () => {
		if ( ! slack.isConfigured ) {
			return;
		}

		let cancelled = false;

		getSlackSettings( namespace ).then( ( result ) => {
			if ( cancelled ) {
				return;
			}
			setHasLoaded( true );
			if ( ! result.success || ! result.settings ) {
				setError(
					result.error ||
						__(
							'The Slack settings could not be loaded. Reload the page to try again.',
							'newspack-rolling-coverage'
						)
				);
				return;
			}
			setWorkspaceInfo( result.settings );
			setIgnorePrefix( result.settings.ignore_prefix );
			setSavedIgnorePrefix( result.settings.ignore_prefix );
			setPublishReaction( result.settings.publish_reaction );
			setSavedPublishReaction( result.settings.publish_reaction );
			setDraftReaction( result.settings.draft_reaction );
			setSavedDraftReaction( result.settings.draft_reaction );
			setTopHeadingLevel( result.settings.top_heading_level );
			setSavedTopHeadingLevel( result.settings.top_heading_level );
		} );

		return () => {
			cancelled = true;
		};
	}, [ slack.isConfigured, namespace ] );

	const handleSave = useCallback( async () => {
		setIsSaving( true );
		setError( null );

		const result = await saveSlackSettings( namespace, {
			ignorePrefix,
			publishReaction,
			draftReaction,
			topHeadingLevel,
		} );

		setIsSaving( false );

		if ( ! result.success ) {
			setError(
				result.error ||
					__(
						'Failed to save settings.',
						'newspack-rolling-coverage'
					)
			);
			return;
		}

		const storedPrefix = result.ignorePrefix ?? ignorePrefix;
		const storedPublish = result.publishReaction ?? publishReaction;
		const storedDraft = result.draftReaction ?? draftReaction;
		const storedHeadingLevel = result.topHeadingLevel ?? topHeadingLevel;
		setIgnorePrefix( storedPrefix );
		setSavedIgnorePrefix( storedPrefix );
		setPublishReaction( storedPublish );
		setSavedPublishReaction( storedPublish );
		setDraftReaction( storedDraft );
		setSavedDraftReaction( storedDraft );
		setTopHeadingLevel( storedHeadingLevel );
		setSavedTopHeadingLevel( storedHeadingLevel );
		notifySuccess( __( 'Settings saved.', 'newspack-rolling-coverage' ) );
	}, [
		namespace,
		ignorePrefix,
		publishReaction,
		draftReaction,
		topHeadingLevel,
	] );

	return {
		ignorePrefix,
		setIgnorePrefix,
		publishReaction,
		setPublishReaction,
		draftReaction,
		setDraftReaction,
		topHeadingLevel,
		setTopHeadingLevel,
		isDirty:
			ignorePrefix !== savedIgnorePrefix ||
			publishReaction !== savedPublishReaction ||
			draftReaction !== savedDraftReaction ||
			topHeadingLevel !== savedTopHeadingLevel,
		workspaceInfo,
		hasLoaded,
		isSaving,
		error,
		clearError: () => setError( null ),
		handleSave,
	};
}

export { useSlackIngestionSettings };
