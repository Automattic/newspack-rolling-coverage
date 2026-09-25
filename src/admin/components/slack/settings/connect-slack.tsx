/**
 * External dependencies
 */
import { useState } from '@wordpress/element';
import { Button, ExternalLink, TextControl } from '@wordpress/components';
import { Stack } from '@wordpress/ui';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { ConnectSlackProps } from '../../../types';
import { CopyManifestButton } from './copy-manifest-button';
import { EmptyState } from 'newspack-components/dist/esm/empty-state';
import { SlackIcon } from '../../../shared/icons/slack-icon';
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
		<EmptyState.Root className="newspack-rolling-coverage-slack-empty-state">
			<EmptyState.Header
				icon={ <SlackIcon size={ 36 } /> }
				title={ __( 'Connect to Slack', 'newspack-rolling-coverage' ) }
				description={ __(
					'Turn messages in a Slack channel into rolling coverage entries.',
					'newspack-rolling-coverage'
				) }
			/>
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
						<h3>
							{ __(
								'Create a Slack app',
								'newspack-rolling-coverage'
							) }
						</h3>
						<p className="newspack-rolling-coverage-slack-setup__description">
							{ __(
								'Create a new app from a manifest at',
								'newspack-rolling-coverage'
							) }{ ' ' }
							<ExternalLink href="https://api.slack.com/apps?new_app=1">
								api.slack.com/apps
							</ExternalLink>
						</p>
					</Stack>
				</Stack>
				<Divider marginTop={ 0 } marginBottom={ 0 } />
				<Stack
					className="newspack-rolling-coverage-slack-setup__step"
					direction="column"
					gap="xl"
				>
					<Stack direction="column" gap="sm">
						<h3>
							{ __(
								'Paste the app manifest',
								'newspack-rolling-coverage'
							) }
						</h3>
						<p className="newspack-rolling-coverage-slack-setup__description">
							{ __(
								'Copy the manifest and paste it into Slack when it asks for one.',
								'newspack-rolling-coverage'
							) }
						</p>
					</Stack>
					<Stack direction="row" gap="lg" align="center">
						<CopyManifestButton manifestJson={ manifestJson } />
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
						<textarea
							className="newspack-rolling-coverage-slack-settings__manifest"
							aria-label={ __(
								'App Manifest',
								'newspack-rolling-coverage'
							) }
							readOnly
							value={ manifestJson }
							rows={ 20 }
							onClick={ ( e ) =>
								( e.target as HTMLTextAreaElement ).select()
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
						<h3>
							{ __(
								'Install the app',
								'newspack-rolling-coverage'
							) }
						</h3>
						<p className="newspack-rolling-coverage-slack-setup__description">
							{ __(
								'Install the new app to your Slack workspace.',
								'newspack-rolling-coverage'
							) }
						</p>
					</Stack>
				</Stack>
				<Divider marginTop={ 0 } marginBottom={ 0 } />
				<Stack
					className="newspack-rolling-coverage-slack-setup__step"
					direction="column"
					gap="xl"
				>
					<Stack direction="column" gap="sm">
						<h3>
							{ __(
								'Add the app credentials',
								'newspack-rolling-coverage'
							) }
						</h3>
						<p className="newspack-rolling-coverage-slack-setup__description">
							{ __(
								'Copy the Bot User OAuth Token from OAuth & Permissions and the Signing Secret from Basic Information, then paste them here.',
								'newspack-rolling-coverage'
							) }
						</p>
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
								isVerifying || ! botToken || ! signingSecret
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
		</EmptyState.Root>
	);
}

export { ConnectSlack };
