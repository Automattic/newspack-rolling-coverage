/**
 * External dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { handleApiError } from './api-error';
import type {
	ApiResult,
	ChannelMapping,
	SlackChannelsResult,
	SlackConnectResult,
	SlackSettingsInfo,
	SlackVerifyResult,
} from '../types';

/**
 * Connects a coverage term to a Slack channel.
 *
 * @param {string}  namespace   REST namespace string from config.restBase.slack.
 * @param {number}  termId      The coverage term ID.
 * @param {string}  channel     Channel name or ID (e.g. '#general' or 'C12345678').
 * @param {boolean} autopublish Whether to auto-publish ingested entries.
 * @return {Promise<SlackConnectResult>} Result indicating success or failure.
 */
async function connectSlackChannel(
	namespace: string,
	termId: number,
	channel: string,
	autopublish: boolean
): Promise< SlackConnectResult > {
	try {
		const raw = ( await apiFetch( {
			path: `${ namespace }/slack/connect`,
			method: 'POST',
			data: {
				term_id: termId,
				channel,
				autopublish,
			},
		} ) ) as {
			ok: boolean;
			channel_id?: string;
			channel_name?: string;
		};

		return {
			success: true,
			channel_id: raw.channel_id,
			channel_name: raw.channel_name,
		};
	} catch ( error ) {
		return {
			success: false,
			error: handleApiError( error ),
		};
	}
}

/**
 * Disconnects a coverage term from its Slack channel.
 *
 * @param {string} namespace REST namespace string from config.restBase.slack.
 * @param {number} termId    The coverage term ID.
 * @return {Promise<ApiResult>} Result indicating success or failure.
 */
async function disconnectSlackChannel(
	namespace: string,
	termId: number
): Promise< ApiResult > {
	try {
		await apiFetch( {
			path: `${ namespace }/slack/disconnect-term`,
			method: 'POST',
			data: { term_id: termId },
		} );
		return { success: true };
	} catch ( error ) {
		return { success: false, error: handleApiError( error ) };
	}
}

/**
 * Fetches the current settings (autopublish) for an already-linked channel.
 *
 * @param {string} namespace REST namespace string from config.restBase.slack.
 * @param {string} channelId Slack channel ID.
 * @return {Promise<{ success: boolean, autopublish?: boolean, error?: string }>} Result.
 */
async function getSlackChannelSettings(
	namespace: string,
	channelId: string
): Promise< {
	success: boolean;
	autopublish?: boolean;
	error?: string;
} > {
	try {
		const result = ( await apiFetch( {
			path: `${ namespace }/slack/channel/${ channelId }`,
		} ) ) as {
			ok: boolean;
			autopublish?: boolean;
		};

		return {
			success: true,
			autopublish: result.autopublish,
		};
	} catch ( error ) {
		return { success: false, error: handleApiError( error ) };
	}
}

/**
 * Updates settings for an already-linked Slack channel (e.g. toggling
 * autopublish) without re-running the connect flow.
 *
 * @param {string}  namespace   REST namespace string from config.restBase.slack.
 * @param {string}  channelId   Slack channel ID.
 * @param {boolean} autopublish Whether to auto-publish ingested entries.
 * @return {Promise<ApiResult>} Result indicating success or failure.
 */
async function updateSlackChannelSettings(
	namespace: string,
	channelId: string,
	autopublish: boolean
): Promise< ApiResult > {
	try {
		await apiFetch( {
			path: `${ namespace }/slack/channel/${ channelId }`,
			method: 'POST',
			data: { autopublish },
		} );
		return { success: true };
	} catch ( error ) {
		return { success: false, error: handleApiError( error ) };
	}
}

/**
 * Verifies Slack credentials and stores them on the server.
 *
 * @param {string} namespace     REST namespace string from config.restBase.slack.
 * @param {string} botToken      Slack bot user OAuth token (xoxb-).
 * @param {string} signingSecret Slack signing secret.
 * @return {Promise<SlackVerifyResult>} Result with the workspace team name on success.
 */
async function verifySlackCredentials(
	namespace: string,
	botToken: string,
	signingSecret: string
): Promise< SlackVerifyResult > {
	try {
		const raw = ( await apiFetch( {
			path: `${ namespace }/slack/verify`,
			method: 'POST',
			data: {
				bot_token: botToken,
				signing_secret: signingSecret,
			},
		} ) ) as {
			ok: boolean;
			team?: string;
		};

		return {
			success: true,
			team: raw.team,
		};
	} catch ( error ) {
		return { success: false, error: handleApiError( error ) };
	}
}

/**
 * Disconnects the Slack workspace integration (clears all credentials and
 * channel mappings).
 *
 * @param {string} namespace REST namespace string from config.restBase.slack.
 * @return {Promise<ApiResult>} Result indicating success or failure.
 */
async function disconnectSlack( namespace: string ): Promise< ApiResult > {
	try {
		await apiFetch( {
			path: `${ namespace }/slack/disconnect`,
			method: 'POST',
		} );
		return { success: true };
	} catch ( error ) {
		return { success: false, error: handleApiError( error ) };
	}
}

/**
 * Saves Slack ingestion settings (currently the ignore_prefix).
 *
 * @param {string} namespace    REST namespace string from config.restBase.slack.
 * @param {string} ignorePrefix The message ignore prefix.
 * @return {Promise<ApiResult>} Result indicating success or failure.
 */
async function saveSlackSettings(
	namespace: string,
	ignorePrefix: string
): Promise< ApiResult > {
	try {
		await apiFetch( {
			path: `${ namespace }/slack/settings`,
			method: 'POST',
			data: { ignore_prefix: ignorePrefix },
		} );
		return { success: true };
	} catch ( error ) {
		return { success: false, error: handleApiError( error ) };
	}
}

/**
 * Lists all linked Slack channels with their coverage term mappings.
 *
 * @param {string} namespace REST namespace string from config.restBase.slack.
 * @return {Promise<SlackChannelsResult>} Result with the channel map on success.
 */
async function listSlackChannels(
	namespace: string
): Promise< SlackChannelsResult > {
	try {
		const channels = ( await apiFetch( {
			path: `${ namespace }/slack/channels`,
		} ) ) as ChannelMapping[];

		return { success: true, channels };
	} catch ( error ) {
		return {
			success: false,
			channels: [],
			error: handleApiError( error ),
		};
	}
}

/**
 * Unlinks a Slack channel from its coverage term.
 *
 * @param {string} namespace REST namespace string from config.restBase.slack.
 * @param {string} channelId Slack channel ID.
 * @return {Promise<ApiResult>} Result indicating success or failure.
 */
async function unlinkSlackChannel(
	namespace: string,
	channelId: string
): Promise< ApiResult > {
	try {
		await apiFetch( {
			path: `${ namespace }/slack/channel/${ channelId }`,
			method: 'DELETE',
		} );
		return { success: true };
	} catch ( error ) {
		return { success: false, error: handleApiError( error ) };
	}
}

/**
 * Fetches the merged Slack settings (workspace identity + masked bot token)
 * for the Credentials tab's connected-state display.
 *
 * @param {string} namespace REST namespace string from config.restBase.slack.
 * @return {Promise<{ success: boolean; settings?: SlackSettingsInfo; error?: string }>} Result.
 */
async function getSlackSettings( namespace: string ): Promise< {
	success: boolean;
	settings?: SlackSettingsInfo;
	error?: string;
} > {
	try {
		const result = ( await apiFetch( {
			path: `${ namespace }/slack/settings`,
		} ) ) as SlackSettingsInfo;

		return { success: true, settings: result };
	} catch ( error ) {
		return { success: false, error: handleApiError( error ) };
	}
}

export {
	connectSlackChannel,
	disconnectSlackChannel,
	getSlackChannelSettings,
	getSlackSettings,
	updateSlackChannelSettings,
	verifySlackCredentials,
	disconnectSlack,
	saveSlackSettings,
	listSlackChannels,
	unlinkSlackChannel,
};
