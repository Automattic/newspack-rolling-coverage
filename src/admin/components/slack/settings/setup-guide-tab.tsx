/**
 * External dependencies
 */
import { useState, useRef, useEffect } from '@wordpress/element';
import {
	Button,
	ExternalLink,
	Card,
	CardHeader,
	CardBody,
	CardFooter,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalHStack as HStack,
} from '@wordpress/components';
import { check } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { SetupGuideTabProps } from '../../../types';

/** How long the "Copied!" confirmation stays visible after a successful copy, in ms. */
const COPIED_FEEDBACK_MS = 2000;

/**
 * Renders the Setup Guide tab: an ordered list of setup steps (with an
 * external link to the Slack app creation page), the "App Manifest" heading,
 * a read-only textarea pre-filled with the manifest JSON (auto-selected on
 * click), and a CardFooter with a "Copy Manifest" button that writes the
 * manifest to the clipboard and briefly shows a "Copied!" confirmation with a
 * check icon.
 *
 * @param {Object} props              - Component props.
 * @param {string} props.manifestJson - The Slack app manifest JSON string to display and copy.
 */
function SetupGuideTab( { manifestJson }: SetupGuideTabProps ) {
	const [ copied, setCopied ] = useState( false );
	const copyTimer = useRef< ReturnType< typeof setTimeout > | null >( null );

	// Clear any pending "Copied!" reset timer on unmount so we never touch
	// state after the component is gone.
	useEffect(
		() => () => {
			if ( copyTimer.current ) {
				clearTimeout( copyTimer.current );
			}
		},
		[]
	);

	const handleCopy = async () => {
		try {
			await navigator.clipboard.writeText( manifestJson );
			setCopied( true );
			if ( copyTimer.current ) {
				clearTimeout( copyTimer.current );
			}
			copyTimer.current = setTimeout(
				() => setCopied( false ),
				COPIED_FEEDBACK_MS
			);
		} catch {
			// Clipboard unavailable (e.g. insecure context); leave the button
			// in its idle state so the user is not shown a false "Copied!".
			setCopied( false );
		}
	};

	return (
		<Card className="newspack-rolling-coverage-slack-settings__card">
			<CardHeader>
				<HStack alignment="space-between" justify="space-between">
					<h2>
						{ __( 'Setup Guide', 'newspack-rolling-coverage' ) }
					</h2>
				</HStack>
			</CardHeader>
			<CardBody>
				<VStack spacing={ 4 }>
					<ol>
						<li>
							{ __(
								'Create a Slack app at',
								'newspack-rolling-coverage'
							) }{ ' ' }
							<ExternalLink href="https://api.slack.com/apps?new_app=1">
								api.slack.com/apps
							</ExternalLink>
						</li>
						<li>
							{ __(
								'Paste the manifest below into the app manifest editor',
								'newspack-rolling-coverage'
							) }
						</li>
						<li>
							{ __(
								'Install the app to your workspace',
								'newspack-rolling-coverage'
							) }
						</li>
						<li>
							{ __(
								'Copy the Bot User OAuth Token and Signing Secret',
								'newspack-rolling-coverage'
							) }
						</li>
						<li>
							{ __(
								'Paste them into the Credentials tab and click Verify & Connect',
								'newspack-rolling-coverage'
							) }
						</li>
					</ol>
					<h3>
						{ __( 'App Manifest', 'newspack-rolling-coverage' ) }
					</h3>
					<textarea
						className="newspack-rolling-coverage-slack-settings__manifest"
						readOnly
						value={ manifestJson }
						rows={ 20 }
						onClick={ ( e ) =>
							( e.target as HTMLTextAreaElement ).select()
						}
					/>
				</VStack>
			</CardBody>
			<CardFooter>
				<HStack justify="space-between">
					<Button
						variant="secondary"
						icon={ copied ? check : undefined }
						onClick={ handleCopy }
					>
						{ copied
							? __( 'Copied!', 'newspack-rolling-coverage' )
							: __(
									'Copy Manifest',
									'newspack-rolling-coverage'
							  ) }
					</Button>
				</HStack>
			</CardFooter>
		</Card>
	);
}

export { SetupGuideTab };
