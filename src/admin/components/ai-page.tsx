/**
 * WordPress dependencies
 */
import { useState, useEffect, useCallback, useMemo } from '@wordpress/element';
import {
	Button,
	DropdownMenu,
	Notice,
	TextareaControl,
	VisuallyHidden,
} from '@wordpress/components';
import { moreVertical } from '@wordpress/icons';
import { __, sprintf } from '@wordpress/i18n';
import { Stack } from '@wordpress/ui';
import { EmptyState } from 'newspack-components/dist/esm/empty-state';
import Grid from 'newspack-components/dist/esm/grid';
import SectionHeader from 'newspack-components/dist/esm/section-header';
import { ai } from 'newspack-icons';

/**
 * Internal dependencies
 */
import { useAdminContext } from '../hooks/useAdminContext';
import { useHeader } from '../hooks/useHeader';
import { useConfirmDialog } from './confirm-dialog';
import {
	fetchAiSettings,
	saveAiSettings,
	isSameSettings,
} from '../utils/ai-settings-api';
import { notifySuccess } from '../utils/notices';
import type { AiSettings as AiSettingsType, AdminConfig } from '../types';

/**
 * What the AI page shows when key takeaways can't run: what is missing, and a
 * link to the screen where an administrator sets it up.
 *
 * @param {Object}      props        Component props.
 * @param {AdminConfig} props.config Admin config with the availability flags.
 */
function AIUnavailable( { config }: { config: AdminConfig } ) {
	let title: string = __(
		'Turn on AI key takeaways',
		'newspack-rolling-coverage'
	);
	let description: string;
	let action: { label: string; href: string } | null = null;

	if ( config.aiNeedsApproval ) {
		title = __(
			'Approve Rolling Coverage for AI',
			'newspack-rolling-coverage'
		);
		description = __(
			'Rolling Coverage is waiting for approval to use the AI provider. Once it’s approved, editors can sum up the latest entries in a coverage from the Rolling Coverage block.',
			'newspack-rolling-coverage'
		);
		action = {
			label: __(
				'Go to Connector Approvals',
				'newspack-rolling-coverage'
			),
			href: config.adminUrls.connectorApprovals,
		};
	} else if ( config.adminUrls.aiSettings ) {
		description = __(
			'Connect an AI provider and turn on Rolling Coverage Key Takeaways in the AI settings. Editors can then sum up the latest entries in a coverage from the Rolling Coverage block.',
			'newspack-rolling-coverage'
		);
		action = {
			label: __( 'Go to AI Settings', 'newspack-rolling-coverage' ),
			href: config.adminUrls.aiSettings,
		};
	} else if ( config.aiPluginActive ) {
		description = __(
			'The WordPress AI plugin is active, but AI can’t run on this site yet. Ask a developer or the site’s host to check that the site meets the plugin’s requirements.',
			'newspack-rolling-coverage'
		);
	} else if ( config.adminUrls.aiPlugin ) {
		description = __(
			'Install and activate the WordPress AI plugin, connect an AI provider, and turn on Rolling Coverage Key Takeaways in its settings. Editors can then sum up the latest entries in a coverage from the Rolling Coverage block.',
			'newspack-rolling-coverage'
		);
		action = {
			label: __( 'Go to Plugins', 'newspack-rolling-coverage' ),
			href: config.adminUrls.aiPlugin,
		};
	} else {
		description = __(
			'Ask an administrator who manages plugins to install and activate the WordPress AI plugin. Then connect an AI provider and turn on Rolling Coverage Key Takeaways in its settings.',
			'newspack-rolling-coverage'
		);
	}

	return (
		<EmptyState.Root>
			<EmptyState.Header
				icon={ ai }
				title={ title }
				description={ description }
			/>
			{ action && (
				<EmptyState.Actions>
					<Button variant="primary" href={ action.href }>
						{ action.label }
					</Button>
				</EmptyState.Actions>
			) }
		</EmptyState.Root>
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
		if ( config.aiAvailable ) {
			loadSettings();
		}
	}, [ config.aiAvailable, loadSettings ] );

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
				'The Key Takeaways prompt goes back to the default text and is saved straight away. The current prompt can’t be recovered.',
				'newspack-rolling-coverage'
			),
			confirmLabel: __( 'Reset', 'newspack-rolling-coverage' ),
			intent: 'irreversible',
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

	const aiEnabled = config.aiAvailable;
	const maxLen = config.aiMaxPromptLength ?? 2000;
	const takeawaysPromptLen = settings.key_takeaways_prompt.length;
	const takeawaysPromptOver = takeawaysPromptLen > maxLen;
	const hasOverLimit = takeawaysPromptOver;
	const isDirty = ! isSameSettings( settings, savedSettings );
	const isAtDefaults =
		isSameSettings( settings, config.aiDefaultSettings ) &&
		isSameSettings( savedSettings, config.aiDefaultSettings );

	const headerActions = useMemo(
		() => (
			<>
				<Button
					variant="primary"
					onClick={ handleSave }
					isBusy={ isSaving }
					disabled={
						isSaving || isLoading || ! isDirty || hasOverLimit
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
							isDisabled: isSaving || isLoading || isAtDefaults,
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
			isAtDefaults,
			isDirty,
			hasOverLimit,
		]
	);

	useHeader( { actions: aiEnabled ? headerActions : undefined } );

	if ( ! aiEnabled ) {
		return <AIUnavailable config={ config } />;
	}

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
				{ hasOverLimit && (
					<Notice status="error" isDismissible={ false }>
						{ __(
							'The prompt exceeds the maximum length. Shorten the text to stay within the limit.',
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
						label={
							<>
								<VisuallyHidden as="span">
									{ __(
										'Key Takeaways',
										'newspack-rolling-coverage'
									) }{ ' ' }
								</VisuallyHidden>
								{ __( 'Prompt', 'newspack-rolling-coverage' ) }
							</>
						}
						help={ sprintf(
							/* translators: 1: prompt placeholder token, 2: character count, 3: max character count */
							__(
								'Use %1$s where the maximum number of takeaways should go. Keep it short: the AI already has the coverage entries. (%2$d / %3$d characters)',
								'newspack-rolling-coverage'
							),
							'{max_takeaways}',
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
						disabled={ isLoading || isSaving }
						aria-invalid={ takeawaysPromptOver || undefined }
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
