/**
 * External dependencies
 */
import { Button, SelectControl, TextControl } from '@wordpress/components';
import { Stack } from '@wordpress/ui';
import { __, _x, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useRef } from '@wordpress/element';
import type { MouseEvent } from 'react';
import Grid from 'newspack-components/dist/esm/grid';
import Divider from 'newspack-components/dist/esm/divider';
import SectionHeader from 'newspack-components/dist/esm/section-header';

/**
 * Internal dependencies
 */
import type {
	IngestionSettingsTabProps,
	SlackSettingsInfo,
} from '../../../types';
import { BotUserSection } from './bot-user-section';
import { useAdminContext } from '../../../hooks/useAdminContext';

/**
 * Heading levels a line starting with # can get, after 0 for off. A Heading 1
 * would compete with the page title. Mirrors Slack_Config::TOP_HEADING_LEVELS.
 */
const TOP_HEADING_LEVELS = [ 0, 2, 3, 4, 5, 6 ];

/**
 * Renders the Settings tab: the message ignore prefix, the emoji that publish
 * or draft a message in channels set to Emoji trigger, the heading level for
 * lines starting with # (or off), and the WordPress bot user that authors
 * ingested entries. Saving happens from the page header.
 *
 * @param {Object}                   props                    - Component props.
 * @param {string}                   props.ignorePrefix       - The ignore-prefix setting value.
 * @param {(v: string) => void}      props.setIgnorePrefix    - Ignore prefix setter.
 * @param {string}                   props.publishReaction    - Emoji name that publishes a message.
 * @param {(v: string) => void}      props.setPublishReaction - Publish emoji setter.
 * @param {string}                   props.draftReaction      - Emoji name that saves a message as a draft.
 * @param {(v: string) => void}      props.setDraftReaction   - Draft emoji setter.
 * @param {number}                   props.topHeadingLevel    - Heading level for a line starting with #, or 0 for off.
 * @param {(v: number) => void}      props.setTopHeadingLevel - Heading level setter.
 * @param {SlackSettingsInfo | null} props.workspaceInfo      - Fetched workspace settings, or null.
 * @param {string}                   props.editUserUrl        - Base admin URL for editing a WordPress user.
 */
function IngestionSettingsTab( {
	ignorePrefix,
	setIgnorePrefix,
	publishReaction,
	setPublishReaction,
	draftReaction,
	setDraftReaction,
	topHeadingLevel,
	setTopHeadingLevel,
	workspaceInfo,
	editUserUrl,
}: IngestionSettingsTabProps ) {
	const { supportsHandoff } = useAdminContext();

	// With newspack-plugin active, a handoff gives the profile screen a
	// banner that brings the admin back here; without it, the link is plain.
	const isHandingOff = useRef( false );

	// A page restored from the back-forward cache keeps the guard set, which
	// would leave Edit User doing nothing.
	useEffect( () => {
		const reset = ( event: PageTransitionEvent ) => {
			if ( event.persisted ) {
				isHandingOff.current = false;
			}
		};
		window.addEventListener( 'pageshow', reset );
		return () => window.removeEventListener( 'pageshow', reset );
	}, [] );
	const handleEditUser = ( event: MouseEvent< HTMLAnchorElement > ) => {
		const isModifiedClick =
			event.metaKey ||
			event.ctrlKey ||
			event.shiftKey ||
			event.altKey ||
			event.button !== 0;
		if ( ! supportsHandoff || isModifiedClick ) {
			return;
		}
		event.preventDefault();
		if ( isHandingOff.current ) {
			return;
		}
		isHandingOff.current = true;
		const destinationUrl = event.currentTarget.href;
		apiFetch< { HandoffLink: string } >( {
			path: '/newspack/v1/handoff',
			method: 'POST',
			data: {
				destinationUrl,
				handoffReturnUrl: window.location.href,
				bannerText: __(
					'Return to the Slack connection after editing the bot user.',
					'newspack-rolling-coverage'
				),
				bannerButtonText: __(
					'Back to Slack Connection',
					'newspack-rolling-coverage'
				),
			},
		} )
			.then( ( response ) => {
				window.location.href = response.HandoffLink;
			} )
			.catch( () => {
				window.location.href = destinationUrl;
			} );
	};

	return (
		<>
			<Grid columns={ 2 } gutter={ 32 } noMargin>
				<SectionHeader
					noMargin
					heading={ 2 }
					title={ __( 'Ingestion', 'newspack-rolling-coverage' ) }
					description={ __(
						'Choose which Slack messages become entries.',
						'newspack-rolling-coverage'
					) }
				/>
				<Stack direction="column" gap="xl">
					<TextControl
						label={ __(
							'Ignore Prefix',
							'newspack-rolling-coverage'
						) }
						value={ ignorePrefix }
						onChange={ setIgnorePrefix }
						help={ __(
							'In Auto-publish and Draft channels, messages starting with this prefix are ignored during ingestion, and so are the replies in their threads. In Emoji trigger channels, a reaction adds the message anyway.',
							'newspack-rolling-coverage'
						) }
					/>
					<TextControl
						label={ __(
							'Publish Emoji',
							'newspack-rolling-coverage'
						) }
						value={ publishReaction }
						onChange={ setPublishReaction }
						help={ __(
							'In channels set to Emoji trigger, reacting to a message with this emoji publishes it. Enter the emoji name, such as mega.',
							'newspack-rolling-coverage'
						) }
					/>
					<TextControl
						label={ __(
							'Draft Emoji',
							'newspack-rolling-coverage'
						) }
						value={ draftReaction }
						onChange={ setDraftReaction }
						help={ __(
							'In channels set to Emoji trigger, reacting to a message with this emoji saves it as a draft. Enter the emoji name, such as memo.',
							'newspack-rolling-coverage'
						) }
					/>
				</Stack>
			</Grid>
			<Divider alignment="full-width" variant="tertiary" />
			<Grid columns={ 2 } gutter={ 32 } noMargin>
				<SectionHeader
					noMargin
					heading={ 2 }
					title={ __( 'Formatting', 'newspack-rolling-coverage' ) }
					description={ __(
						'Choose how Slack messages are formatted in entries.',
						'newspack-rolling-coverage'
					) }
				/>
				<SelectControl
					__next40pxDefaultSize
					label={ __(
						'Highest Heading',
						'newspack-rolling-coverage'
					) }
					value={ String( topHeadingLevel ) }
					options={ TOP_HEADING_LEVELS.map( ( level ) => ( {
						value: String( level ),
						label:
							0 === level
								? _x(
										'Off',
										'Highest Heading option: no titles or headings',
										'newspack-rolling-coverage'
									)
								: sprintf(
										/* translators: %d: heading level, from 2 to 6. */
										__(
											'Heading %d',
											'newspack-rolling-coverage'
										),
										level
									),
					} ) ) }
					onChange={ ( next ) =>
						setTopHeadingLevel( Number( next ) )
					}
					help={ __(
						"When set, a first line that starts with # and a space becomes the entry title, and other lines that start with #, ## or ### become headings. A message's highest heading takes this level and the others follow it, one level lower per extra #, down to Heading 6. Most feed layouts show entry titles as Heading 4. Entries already added keep their formatting.",
						'newspack-rolling-coverage'
					) }
				/>
			</Grid>
			<Divider alignment="full-width" variant="tertiary" />
			<Grid columns={ 2 } gutter={ 32 } noMargin>
				<Stack direction="column" gap="xl" align="flex-start">
					<SectionHeader
						noMargin
						heading={ 2 }
						title={ __( 'Bot User', 'newspack-rolling-coverage' ) }
						description={ __(
							"This WordPress user is created automatically. Entries ingested from Slack are credited to it, unless the person who posted them has added their Slack handle or member ID to their WordPress profile. Change the bot user's display name or avatar from its WordPress profile.",
							'newspack-rolling-coverage'
						) }
					/>
					{ workspaceInfo?.bot_user && (
						<Button
							variant="secondary"
							href={ `${ editUserUrl }?user_id=${ workspaceInfo.bot_user.id }` }
							onClick={ handleEditUser }
						>
							{ __( 'Edit User', 'newspack-rolling-coverage' ) }
						</Button>
					) }
				</Stack>
				<BotUserSection botUser={ workspaceInfo?.bot_user } />
			</Grid>
		</>
	);
}

export { IngestionSettingsTab };
