/**
 * Builds the Slack app manifest JSON string shown in the Setup Guide tab and
 * copied to the clipboard by the "Copy Manifest" button. The manifest
 * configures display information, bot user features (slash commands),
 * OAuth redirect URLs and bot scopes, and event subscription/interactivity
 * settings, all pointing at the plugin's Slack REST endpoints.
 *
 * The output is a pretty-printed JSON string (2-space indent) that mirrors
 * the structure accepted by Slack's app manifest editor.
 *
 * @param {string} restUrl - The base REST URL for the Slack route namespace,
 *                         e.g. `https://example.com/wp-json/newspack-rolling-coverage/v1/`.
 * @return {string} Pretty-printed Slack app manifest JSON.
 */
function buildSlackManifest( restUrl: string ): string {
	return JSON.stringify(
		{
			display_information: {
				name: 'Rolling Coverage',
				description:
					'Ingest Slack channel messages as rolling coverage entries',
				background_color: '#1a1a2e',
			},
			features: {
				bot_user: {
					display_name: 'Rolling Coverage',
					always_online: true,
				},
				slash_commands: [
					{
						command: '/rolling-coverage-connect',
						url: `${ restUrl }slack/commands`,
						description: 'Connect this channel to a coverage',
					},
					{
						command: '/rolling-coverage-unlink',
						url: `${ restUrl }slack/commands`,
						description: 'Unlink this channel from its coverage',
					},
					{
						command: '/rolling-coverage-status',
						url: `${ restUrl }slack/commands`,
						description: 'Show connection status',
					},
				],
			},
			oauth_config: {
				redirect_urls: [ `${ restUrl }slack/verify` ],
				scopes: {
					bot: [
						'channels:history',
						'channels:join',
						'channels:read',
						'groups:history',
						'groups:read',
						'chat:write',
						'users:read',
						'commands',
					],
				},
			},
			settings: {
				event_subscriptions: {
					request_url: `${ restUrl }slack/events`,
					bot_events: [
						'message.channels',
						'message.groups',
						'member_joined_channel',
					],
				},
				interactivity: {
					is_enabled: true,
					request_url: `${ restUrl }slack/interactions`,
					message_menu_options_url: `${ restUrl }slack/interactions`,
				},
				org_deploy_enabled: false,
				socket_mode_enabled: false,
			},
		},
		null,
		2
	);
}

export { buildSlackManifest };
