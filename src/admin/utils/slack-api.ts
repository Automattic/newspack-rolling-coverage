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
	SlackMonitorLogEntry,
	SlackMonitorLogsResult,
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

/**
 * Start the Slack monitor (create the log file).
 *
 * @param {string} namespace REST namespace.
 * @return {Promise<ApiResult>} Result.
 */
async function startSlackMonitor( namespace: string ): Promise< ApiResult > {
	try {
		await apiFetch( {
			path: `${ namespace }/slack/monitor/start`,
			method: 'POST',
		} );
		return { success: true };
	} catch ( error ) {
		return { success: false, error: handleApiError( error as Error ) };
	}
}

/**
 * Stop the Slack monitor (delete the log file).
 *
 * This function must reliably deliver a "stop" request to the server
 * even when the browser tab is being closed or navigated away from.
 * Standard fetch/XHR requests are cancelled by the browser during
 * page unload, so two alternative transports are used:
 *
 * 1. Image beacon (primary): Setting `new Image().src = url` triggers
 *    a GET request that the browser treats as a background resource
 *    load. Unlike XHR/fetch, image loads are not cancelled when the
 *    page is torn down — the browser completes the request even after
 *    the document is destroyed.
 *
 *    Authentication: image beacons cannot set custom HTTP headers
 *    (like X-WP-Nonce), so the WP REST nonce is passed as a `_wpnonce`
 *    query parameter. WordPress REST API natively validates `_wpnonce`
 *    from the query string for cookie-authenticated requests.
 *
 * 2. fetch with keepalive (fallback): If the Image constructor throws
 *    (extremely rare — would only happen if the browser blocks Image
 *    entirely), we fall back to `fetch(url, { keepalive: true })`. The
 *    `keepalive` flag tells the browser to complete the request even
 *    after the page unloads, similar to sendBeacon but with the full
 *    fetch API available.
 *
 * @param {string} namespace REST namespace (from config.restBase.slack).
 * @param {string} nonce     WP REST nonce (from config.nonce, generated
 *                           server-side via wp_create_nonce('wp_rest')).
 */
async function stopSlackMonitor(
	namespace: string,
	nonce?: string
): Promise< ApiResult > {
	const stopUrl = `${
		window.location.origin
	}/wp-json/${ namespace }/slack/monitor/stop?_wpnonce=${ encodeURIComponent(
		nonce ?? ''
	) }`;

	// Primary: image beacon (GET) — survives page unload reliably.
	try {
		const img = new Image();
		img.src = stopUrl;
		return { success: true };
	} catch {
		// Fallback: fetch with keepalive — modern alternative.
		try {
			await fetch( stopUrl, {
				method: 'GET',
				keepalive: true,
			} );
			return { success: true };
		} catch ( error ) {
			return { success: false, error: handleApiError( error as Error ) };
		}
	}
}

/**
 * Fetch Slack monitor log entries since a byte offset.
 *
 * @param {string} namespace REST namespace.
 * @param {number} offset    Byte offset to read from.
 *
 * @return {Promise<SlackMonitorLogsResult>} Log entries and new offset.
 */
async function getSlackMonitorLogs(
	namespace: string,
	offset: number
): Promise< SlackMonitorLogsResult > {
	try {
		const result = ( await apiFetch( {
			path: `${ namespace }/slack/monitor/logs?offset=${ offset }`,
		} ) ) as {
			lines: SlackMonitorLogEntry[];
			offset: number;
			active: boolean;
		};
		return {
			success: true,
			lines: result.lines,
			offset: result.offset,
			active: result.active,
		};
	} catch ( error ) {
		return { success: false, error: handleApiError( error as Error ) };
	}
}

/**
 * Format a context object as a readable "key: value" string.
 *
 * @param {Record<string, unknown>} ctx Context object from a log entry.
 * @return {string} Formatted string, e.g. "channel: C123, post_id: 42".
 */
function formatContext( ctx: Record< string, unknown > ): string {
	return Object.entries( ctx )
		.map( ( [ k, v ] ) => `${ k }: ${ String( v ) }` )
		.join( ', ' );
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
	startSlackMonitor,
	stopSlackMonitor,
	getSlackMonitorLogs,
	formatContext,
};
