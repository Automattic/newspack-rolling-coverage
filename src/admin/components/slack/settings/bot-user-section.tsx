/**
 * External dependencies
 */
import { Stack } from '@wordpress/ui';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { SlackBotUserInfo } from '../../../types';
import { DetailRow } from '../../detail-row';

/**
 * Renders the bot user details section.
 *
 * @param {Object}                          props         - Component props.
 * @param {SlackBotUserInfo|null|undefined} props.botUser - The bot user info, or null.
 */
function BotUserSection( {
	botUser,
}: {
	botUser: SlackBotUserInfo | null | undefined;
} ) {
	return (
		<>
			{ botUser ? (
				<Stack direction="column" gap="xl">
					<DetailRow
						label={ __( 'User ID', 'newspack-rolling-coverage' ) }
					>
						<span>{ `#${ botUser.id }` }</span>
					</DetailRow>
					<DetailRow
						label={ __( 'Username', 'newspack-rolling-coverage' ) }
					>
						<span>{ botUser.login || '—' }</span>
					</DetailRow>
					<DetailRow
						label={ __(
							'Display name',
							'newspack-rolling-coverage'
						) }
					>
						<span>{ botUser.display_name || '—' }</span>
					</DetailRow>
					<DetailRow
						label={ __( 'Email', 'newspack-rolling-coverage' ) }
					>
						<span>{ botUser.email || '—' }</span>
					</DetailRow>
					<DetailRow
						label={ __( 'Roles', 'newspack-rolling-coverage' ) }
					>
						<span>{ botUser.roles.join( ', ' ) || '—' }</span>
					</DetailRow>
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
