/**
 * External dependencies
 */
import { Stack } from '@wordpress/ui';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { SlackBotUserInfo } from '../../../types';
import { BotUserDetail } from './bot-user-detail';

/**
 * Renders the bot user details section.
 *
 * @param {Object}                          props             - Component props.
 * @param {SlackBotUserInfo|null|undefined} props.botUser     - The bot user info, or null.
 * @param {string}                          props.editUserUrl - Base admin URL for editing a WordPress user.
 */
function BotUserSection( {
	botUser,
	editUserUrl,
}: {
	botUser: SlackBotUserInfo | null | undefined;
	editUserUrl: string;
} ) {
	return (
		<>
			{ botUser ? (
				<Stack direction="column" gap="sm">
					<div>
						<strong>
							{ __( 'User ID:', 'newspack-rolling-coverage' ) }
						</strong>{ ' ' }
						<a
							href={ `${ editUserUrl }?user_id=${ botUser.id }` }
							target="_blank"
							rel="noopener noreferrer"
						>
							{ `#${ botUser.id }` }
						</a>
					</div>
					<BotUserDetail
						label={ __( 'Username:', 'newspack-rolling-coverage' ) }
						value={ botUser.login }
					/>
					<BotUserDetail
						label={ __(
							'Display name:',
							'newspack-rolling-coverage'
						) }
						value={ botUser.display_name }
					/>
					<BotUserDetail
						label={ __( 'Email:', 'newspack-rolling-coverage' ) }
						value={ botUser.email }
					/>
					<BotUserDetail
						label={ __( 'Roles:', 'newspack-rolling-coverage' ) }
						value={ botUser.roles.join( ', ' ) }
					/>
				</Stack>
			) : (
				<p>
					{ __(
						'No WordPress bot user found.',
						'newspack-rolling-coverage'
					) }
				</p>
			) }
		</>
	);
}

export { BotUserSection };
