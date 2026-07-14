/**
 * WordPress dependencies
 */
import { useState, useEffect, useCallback } from '@wordpress/element';
import {
	Button,
	TextareaControl,
	Card,
	CardHeader,
	CardBody,
	CardFooter,
	TabPanel,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalHStack as HStack,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { useAdminContext } from '../hooks/useAdminContext';
import { fetchAiSettings, saveAiSettings } from '../utils/ai-settings-api';
import { notifySuccess } from '../utils/notices';
import type { AiSettings as AiSettingsType } from '../types';

/**
 * AI settings page with tabbed interface for configuring AI prompts.
 *
 * Settings are loaded from the REST API on mount and pre-populated from
 * the server-localized config as initial values. Changes are saved via
 * a POST request to the AI settings endpoint.
 */
function AIPage() {
	const config = useAdminContext();
	const [ settings, setSettings ] = useState< AiSettingsType >( {
		system_prompt: config.aiSettings?.system_prompt ?? '',
		key_takeaways_prompt: config.aiSettings?.key_takeaways_prompt ?? '',
	} );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );

	const loadSettings = useCallback( async () => {
		setIsLoading( true );
		setError( null );

		const result = await fetchAiSettings( config.restBaseUrls.aiSettings );

		if ( result.success && result.data ) {
			setSettings( result.data );
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

	const handleChange = useCallback(
		( field: keyof AiSettingsType, value: string ) => {
			setSettings( ( prev ) => ( { ...prev, [ field ]: value } ) );
		},
		[]
	);

	const handleReset = useCallback( () => {
		setSettings( config.aiDefaultSettings );
		notifySuccess(
			__(
				'Prompts reset to defaults. Click Save Settings to persist.',
				'newspack-rolling-coverage'
			)
		);
	}, [ config.aiDefaultSettings ] );

	const tabs = [
		{
			name: 'key-takeaways',
			title: __( 'Key Takeaways', 'newspack-rolling-coverage' ),
		},
	];

	return (
		<div className="newspack-rolling-coverage-ai-settings">
			{ error && (
				<div className="newspack-rolling-coverage-error">{ error }</div>
			) }
			{ ! config.aiAvailable && (
				<div className="newspack-rolling-coverage-ai-settings__notice">
					{ __(
						'AI features are not available on this site. Settings can still be configured but will not take effect until an AI provider is configured.',
						'newspack-rolling-coverage'
					) }
				</div>
			) }
			<TabPanel tabs={ tabs } initialTabName="key-takeaways">
				{ () => (
					<Card className="newspack-rolling-coverage-ai-settings__card">
						<CardHeader>
							<HStack
								alignment="space-between"
								justify="space-between"
							>
								<h2>
									{ __(
										'Prompt Configuration',
										'newspack-rolling-coverage'
									) }
								</h2>
							</HStack>
						</CardHeader>
						<CardBody>
							<VStack spacing={ 4 }>
								<TextareaControl
									label={ __(
										'System Prompt',
										'newspack-rolling-coverage'
									) }
									help={ __(
										'Sets the AI assistant persona and behaviour for key takeaways generation.',
										'newspack-rolling-coverage'
									) }
									value={ settings.system_prompt }
									onChange={ ( value ) =>
										handleChange( 'system_prompt', value )
									}
									rows={ 6 }
									disabled={ isLoading || isSaving }
								/>
								<TextareaControl
									label={ __(
										'Key Takeaways Prompt',
										'newspack-rolling-coverage'
									) }
									help={ __(
										'The instruction sent to the AI along with coverage entries. Use {max_takeaways} as a placeholder for the maximum number of takeaways.',
										'newspack-rolling-coverage'
									) }
									value={ settings.key_takeaways_prompt }
									onChange={ ( value ) =>
										handleChange(
											'key_takeaways_prompt',
											value
										)
									}
									rows={ 6 }
									disabled={ isLoading || isSaving }
								/>
							</VStack>
						</CardBody>
						<CardFooter>
							<HStack justify="space-between">
								<Button
									variant="secondary"
									onClick={ handleReset }
									disabled={ isSaving || isLoading }
								>
									{ __(
										'Reset to Defaults',
										'newspack-rolling-coverage'
									) }
								</Button>
								<Button
									variant="primary"
									onClick={ handleSave }
									isBusy={ isSaving }
									disabled={ isSaving || isLoading }
								>
									{ __(
										'Save Settings',
										'newspack-rolling-coverage'
									) }
								</Button>
							</HStack>
						</CardFooter>
					</Card>
				) }
			</TabPanel>
		</div>
	);
}

export default AIPage;
