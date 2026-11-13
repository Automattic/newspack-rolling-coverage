/**
 * External dependencies
 */
import { createInterpolateElement, useState } from '@wordpress/element';
import {
	Button,
	ExternalLink,
	Notice,
	TextareaControl,
	TextControl,
} from '@wordpress/components';
import { EmptyState, Stack, Text } from '@wordpress/ui';
import { __ } from '@wordpress/i18n';
import { plugins } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import type { ConnectSlackProps } from '../../../types';
import { useSlackSettings } from '../../../hooks/useSlackSettings';
import { CopyManifestButton } from './copy-manifest-button';
import Divider from 'newspack-components/dist/esm/divider';

/**
 * Walks a site that isn't connected to Slack through creating the Slack app
 * from our manifest, installing it, and pasting its credentials back here.
 *
 * @param {ConnectSlackProps} props Component props.
 */
function ConnectSlack( {
	manifestJson,
	botToken,
	setBotToken,
	signingSecret,
	setSigningSecret,
	isVerifying,
	onVerify,
}: ConnectSlackProps ) {
	const [ isManifestShown, setIsManifestShown ] = useState( false );

	return (
		<Stack direction="column" align="center">
			<EmptyState.Root
				style={ { maxWidth: 'var(--wpds-dimension-surface-width-md)' } }
			>
				<EmptyState.Icon icon={ plugins } />
				<EmptyState.Title>
					{ __( 'Connect to Slack', 'newspack-rolling-coverage' ) }
				</EmptyState.Title>
				<EmptyState.Description>
					{ __(
						'Turn messages in a Slack channel into liveblog entries.',
						'newspack-rolling-coverage'
					) }
				</EmptyState.Description>
				<EmptyState.Actions>
					<Stack
						className="newspack-rolling-coverage-slack-setup"
						direction="column"
						gap="2xl"
					>
						<Stack
							className="newspack-rolling-coverage-slack-setup__step"
							direction="column"
							gap="xl"
						>
							<Stack direction="column" gap="sm">
								{ /* eslint-disable-next-line jsx-a11y/heading-has-content -- content is supplied via the Text children through @wordpress/ui's render prop. */ }
								<Text variant="heading-lg" render={ <h3 /> }>
									{ __(
										'Create a Slack app',
										'newspack-rolling-coverage'
									) }
								</Text>
								<Text render={ <p /> }>
									{ createInterpolateElement(
										/* translators: <a> wraps the link to Slack's app creation page. */
										__(
											'Create a new app from a manifest at <a>api.slack.com/apps</a>',
											'newspack-rolling-coverage'
										),
										{
											a: (
												<ExternalLink href="https://api.slack.com/apps?new_app=1">
													{ null }
												</ExternalLink>
											),
										}
									) }
								</Text>
							</Stack>
						</Stack>
						<Divider marginTop={ 0 } marginBottom={ 0 } />
						<Stack
							className="newspack-rolling-coverage-slack-setup__step"
							direction="column"
							gap="xl"
						>
							<Stack direction="column" gap="sm">
								{ /* eslint-disable-next-line jsx-a11y/heading-has-content -- content is supplied via the Text children through @wordpress/ui's render prop. */ }
								<Text variant="heading-lg" render={ <h3 /> }>
									{ __(
										'Paste the app manifest',
										'newspack-rolling-coverage'
									) }
								</Text>
								<Text render={ <p /> }>
									{ __(
										'Copy the manifest and paste it into Slack when it asks for one.',
										'newspack-rolling-coverage'
									) }
								</Text>
							</Stack>
							<Stack direction="row" gap="lg" align="center">
								<CopyManifestButton
									manifestJson={ manifestJson }
								/>
								<Button
									variant="link"
									aria-expanded={ isManifestShown }
									onClick={ () =>
										setIsManifestShown( ! isManifestShown )
									}
								>
									{ isManifestShown
										? __(
												'Hide Manifest',
												'newspack-rolling-coverage'
											)
										: __(
												'Show Manifest',
												'newspack-rolling-coverage'
											) }
								</Button>
							</Stack>
							{ isManifestShown && (
								<TextareaControl
									__nextHasNoMarginBottom
									className="newspack-rolling-coverage-slack-manifest"
									label={ __(
										'App Manifest',
										'newspack-rolling-coverage'
									) }
									hideLabelFromVision
									readOnly
									value={ manifestJson }
									rows={ 20 }
									onChange={ () => {} }
									onClick={ ( e ) =>
										(
											e.target as HTMLTextAreaElement
										 ).select()
									}
								/>
							) }
						</Stack>
						<Divider marginTop={ 0 } marginBottom={ 0 } />
						<Stack
							className="newspack-rolling-coverage-slack-setup__step"
							direction="column"
							gap="xl"
						>
							<Stack direction="column" gap="sm">
								{ /* eslint-disable-next-line jsx-a11y/heading-has-content -- content is supplied via the Text children through @wordpress/ui's render prop. */ }
								<Text variant="heading-lg" render={ <h3 /> }>
									{ __(
										'Install the app',
										'newspack-rolling-coverage'
									) }
								</Text>
								<Text render={ <p /> }>
									{ __(
										'Install the new app to your Slack workspace.',
										'newspack-rolling-coverage'
									) }
								</Text>
							</Stack>
						</Stack>
						<Divider marginTop={ 0 } marginBottom={ 0 } />
						<Stack
							className="newspack-rolling-coverage-slack-setup__step"
							direction="column"
							gap="xl"
						>
							<Stack direction="column" gap="sm">
								{ /* eslint-disable-next-line jsx-a11y/heading-has-content -- content is supplied via the Text children through @wordpress/ui's render prop. */ }
								<Text variant="heading-lg" render={ <h3 /> }>
									{ __(
										'Add the app credentials',
										'newspack-rolling-coverage'
									) }
								</Text>
								<Text render={ <p /> }>
									{ __(
										'Copy the Bot User OAuth Token from OAuth & Permissions and the Signing Secret from Basic Information, then paste them here.',
										'newspack-rolling-coverage'
									) }
								</Text>
							</Stack>
							<Stack direction="column" gap="lg">
								<TextControl
									label={ __(
										'Bot User OAuth Token',
										'newspack-rolling-coverage'
									) }
									value={ botToken }
									onChange={ setBotToken }
									type="password"
									help={ __(
										'Starts with xoxb-',
										'newspack-rolling-coverage'
									) }
								/>
								<TextControl
									label={ __(
										'Signing Secret',
										'newspack-rolling-coverage'
									) }
									value={ signingSecret }
									onChange={ setSigningSecret }
									type="password"
									help={ __(
										'32-character hex string',
										'newspack-rolling-coverage'
									) }
								/>
							</Stack>
							<Stack direction="row">
								<Button
									variant="primary"
									onClick={ onVerify }
									isBusy={ isVerifying }
									disabled={
										isVerifying ||
										! botToken ||
										! signingSecret
									}
								>
									{ __(
										'Verify & Connect',
										'newspack-rolling-coverage'
									) }
								</Button>
							</Stack>
						</Stack>
					</Stack>
				</EmptyState.Actions>
			</EmptyState.Root>
		</Stack>
	);
}

/**
 * The Slack setup screen with its own state: the credentials typed in, the
 * verify request and its error notice.
 *
 * @param {Object}   props               Component props.
 * @param {Function} [props.onConnected] Runs once Slack is connected, in place of reloading the page.
 */
function SlackSetup( { onConnected }: { onConnected?: () => void } ) {
	const {
		notice,
		clearNotice,
		manifestJson,
		botToken,
		setBotToken,
		signingSecret,
		setSigningSecret,
		isVerifying,
		handleVerify,
	} = useSlackSettings( { onVerified: onConnected } );

	return (
		<>
			{ notice && (
				<Notice status={ notice.type } onRemove={ clearNotice }>
					{ notice.message }
				</Notice>
			) }
			<ConnectSlack
				manifestJson={ manifestJson }
				botToken={ botToken }
				setBotToken={ setBotToken }
				signingSecret={ signingSecret }
				setSigningSecret={ setSigningSecret }
				isVerifying={ isVerifying }
				onVerify={ handleVerify }
			/>
		</>
	);
}

export { SlackSetup };
