/**
 * WordPress dependencies
 */
import { useState, useEffect, useCallback, useMemo } from '@wordpress/element';
import {
	Button,
	DropdownMenu,
	Notice,
	TextareaControl,
} from '@wordpress/components';
import { moreVertical } from '@wordpress/icons';
import { __, sprintf } from '@wordpress/i18n';
import { Stack } from '@wordpress/ui';
import Grid from 'newspack-components/dist/esm/grid';
import SectionHeader from 'newspack-components/dist/esm/section-header';

/**
 * Internal dependencies
 */
import { useAdminContext } from '../hooks/useAdminContext';
import { useHeader } from '../hooks/useHeader';
import { useConfirmDialog } from './confirm-dialog';
import { fetchAiSettings, saveAiSettings } from '../utils/ai-settings-api';
import { notifySuccess } from '../utils/notices';
import type { AiSettings as AiSettingsType } from '../types';

/**
 * Whether two sets of AI settings hold the same prompts.
 *
 * @param a First settings.
 * @param b Second settings.
 * @return True when every prompt matches.
 */
function isSameSettings( a: AiSettingsType, b: AiSettingsType ): boolean {
	return ( Object.keys( a ) as Array< keyof AiSettingsType > ).every(
		( key ) => a[ key ] === b[ key ]
	);
}

/**
 * AI settings page for configuring AI prompts. Save and Reset to Defaults
 * live in the page header.
 *
 * Settings are loaded from the REST API on mount and pre-populated from
 * the server-localized config as initial values. Changes are saved via
 * a POST request to the AI settings endpoint.
 */
function AIPage() {
	const config = useAdminContext();
	const initialSettings = {
		key_takeaways_prompt: config.aiSettings?.key_takeaways_prompt ?? '',
	};
	const [ settings, setSettings ] =
		useState< AiSettingsType >( initialSettings );
	const [ savedSettings, setSavedSettings ] =
		useState< AiSettingsType >( initialSettings );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const { requestConfirm, dialog: confirmDialog } = useConfirmDialog();

	const loadSettings = useCallback( async () => {
		setIsLoading( true );
		setError( null );

		const result = await fetchAiSettings( config.restBaseUrls.aiSettings );

		if ( result.success && result.data ) {
			setSettings( result.data );
			setSavedSettings( result.data );
		} else {
			setError(
				result.error ||
					__(
						'Failed to load AI settings.',
						'newspack-rolling-coverage'
					)
			);
		}

		setIsLoading( false );
	}, [ config.restBaseUrls.aiSettings ] );

	useEffect( () => {
		loadSettings();
	}, [ loadSettings ] );

	const handleSave = useCallback( async () => {
		setIsSaving( true );
		setError( null );

		const result = await saveAiSettings(
			config.restBaseUrls.aiSettings,
			settings
		);

		if ( result.success && result.data ) {
			setSettings( result.data );
			setSavedSettings( result.data );
			notifySuccess(
				__( 'AI settings saved.', 'newspack-rolling-coverage' )
			);
		} else {
			setError(
				result.error ||
					__(
						'Failed to save AI settings.',
						'newspack-rolling-coverage'
					)
			);
		}

		setIsSaving( false );
	}, [ config.restBaseUrls.aiSettings, settings ] );

	const handleReset = useCallback( () => {
		requestConfirm( {
			title: __( 'Reset to defaults?', 'newspack-rolling-coverage' ),
			description: __(
				'The Key Takeaways prompt goes back to the default text and is saved straight away.',
				'newspack-rolling-coverage'
			),
			confirmLabel: __( 'Reset', 'newspack-rolling-coverage' ),
			onConfirm: async () => {
				setIsSaving( true );
				const result = await saveAiSettings(
					config.restBaseUrls.aiSettings,
					config.aiDefaultSettings
				);
				setIsSaving( false );

				if ( ! result.success || ! result.data ) {
					return {
						error:
							result.error ||
							__(
								'Failed to reset AI settings.',
								'newspack-rolling-coverage'
							),
					};
				}

				setSettings( result.data );
				setSavedSettings( result.data );
				setError( null );
				notifySuccess(
					__(
						'Prompts reset to defaults.',
						'newspack-rolling-coverage'
					)
				);
			},
		} );
	}, [
		requestConfirm,
		config.restBaseUrls.aiSettings,
		config.aiDefaultSettings,
	] );

	const canEdit = config.capabilities.canManageAiSettings;
	const aiEnabled = config.aiAvailable && canEdit;
	const maxLen = config.aiMaxPromptLength ?? 2000;
	const takeawaysPromptLen = settings.key_takeaways_prompt.length;
	const takeawaysPromptOver = takeawaysPromptLen > maxLen;
	const hasOverLimit = takeawaysPromptOver;
	const isDirty = ! isSameSettings( settings, savedSettings );
	const isAtDefaults = isSameSettings( settings, config.aiDefaultSettings );

	const headerActions = useMemo(
		() => (
			<>
				<Button
					variant="primary"
					onClick={ handleSave }
					isBusy={ isSaving }
					disabled={
						isSaving ||
						isLoading ||
						! aiEnabled ||
						! isDirty ||
						hasOverLimit
					}
				>
					{ __( 'Save', 'newspack-rolling-coverage' ) }
				</Button>
				<DropdownMenu
					icon={ moreVertical }
					label={ __( 'More actions', 'newspack-rolling-coverage' ) }
					controls={ [
						{
							title: __(
								'Reset to Defaults',
								'newspack-rolling-coverage'
							),
							onClick: handleReset,
							isDisabled:
								isSaving ||
								isLoading ||
								! aiEnabled ||
								isAtDefaults,
						},
					] }
				/>
			</>
		),
		[
			handleReset,
			handleSave,
			isSaving,
			isLoading,
			aiEnabled,
			isAtDefaults,
			isDirty,
			hasOverLimit,
		]
	);

	useHeader( { actions: headerActions } );

	return (
		<>
			<Stack
				direction="column"
				gap="2xl"
				className="newspack-rolling-coverage-ai-settings"
			>
				{ error && (
					<Notice status="error" onRemove={ () => setError( null ) }>
						{ error }
					</Notice>
				) }
				{ ! config.aiAvailable && (
					<Notice status="warning" isDismissible={ false }>
						{ __(
							'AI features are not available on this site. An administrator must enable the AI plugin and configure a provider before these prompts take effect.',
							'newspack-rolling-coverage'
						) }
					</Notice>
				) }
				{ hasOverLimit && aiEnabled && (
					<Notice status="error" isDismissible={ false }>
						{ __(
							'One or more prompts exceed the maximum length. Shorten the text to stay within the limit.',
							'newspack-rolling-coverage'
						) }
					</Notice>
				) }
				<Grid columns={ 2 } gutter={ 32 } noMargin>
					<SectionHeader
						noMargin
						heading={ 2 }
						title={ __(
							'Key Takeaways',
							'newspack-rolling-coverage'
						) }
						description={ __(
							'The instruction the AI follows when it sums up a coverage in a short list of key takeaways for readers.',
							'newspack-rolling-coverage'
						) }
					/>
					<TextareaControl
						label={ __( 'Prompt', 'newspack-rolling-coverage' ) }
						help={ sprintf(
							/* translators: 1: character count, 2: max character count */
							__(
								'Use {max_takeaways} where the maximum number of takeaways should go. Keep it short: the AI already has the coverage entries. (%1$d / %2$d characters)',
								'newspack-rolling-coverage'
							),
							takeawaysPromptLen,
							maxLen
						) }
						value={ settings.key_takeaways_prompt }
						onChange={ ( value ) =>
							setSettings( ( prev ) => ( {
								...prev,
								key_takeaways_prompt: value,
							} ) )
						}
						rows={ 6 }
						disabled={ isLoading || isSaving || ! aiEnabled }
						className={
							takeawaysPromptOver
								? 'newspack-rolling-coverage-ai-settings__field--over-limit'
								: undefined
						}
					/>
				</Grid>
			</Stack>
			{ confirmDialog }
		</>
	);
}

export default AIPage;
