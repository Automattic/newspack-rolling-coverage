/** Slack adapter for the chat-source ingestion protocol. */

/**
 * External dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { IncomingMessage, SettingField } from '../types';
import { ChatSourceAdapter } from './chat-source';

interface SlackEventEnvelope {
	event?: Record< string, unknown >;
}

/** Slack HMAC presence check (header existence only — full verification runs server-side in Slack_Signature_Verifier). */
function verifySlackSignature( req?: Request ): boolean {
	if ( ! req || ! req.headers ) {
		return false;
	}
	const signature =
		req.headers.get( 'X-Slack-Signature' ) ??
		req.headers.get( 'x-slack-signature' );
	const timestamp =
		req.headers.get( 'X-Slack-Request-Timestamp' ) ??
		req.headers.get( 'x-slack-request-timestamp' );
	return Boolean( signature && timestamp );
}

/**
 * Slack chat-source adapter: verifies Slack webhook request headers, filters
 * out non-ingestable Slack events (bot messages, edits/deletes, joins/leaves),
 * and normalizes a Slack message event into a generic IncomingMessage.
 * Platform-specific provenance meta (Slack ts/channel/author) is written by the
 * PHP webhook controller, not by this adapter.
 */
class SlackAdapter extends ChatSourceAdapter {
	slug(): string {
		return 'slack';
	}

	displayName(): string {
		return 'Slack';
	}

	isConfigured(): boolean {
		return false;
	}

	settingsFields(): SettingField[] {
		return [
			{
				key: 'bot_token',
				label: __(
					'Bot User OAuth Token',
					'newspack-rolling-coverage'
				),
				type: 'password',
				secret: true,
				help: __( 'Starts with xoxb-', 'newspack-rolling-coverage' ),
			},
			{
				key: 'signing_secret',
				label: __( 'Signing Secret', 'newspack-rolling-coverage' ),
				type: 'password',
				secret: true,
				help: __(
					'32-character hex string',
					'newspack-rolling-coverage'
				),
			},
			{
				key: 'ignore_prefix',
				label: __( 'Ignore Prefix', 'newspack-rolling-coverage' ),
				type: 'text',
				help: __(
					'Messages starting with this prefix are ignored during ingestion.',
					'newspack-rolling-coverage'
				),
			},
		];
	}

	verifyRequest( req?: Request ): boolean | Promise< boolean > {
		return verifySlackSignature( req );
	}

	shouldIngest( raw: unknown ): boolean {
		const envelope = raw as SlackEventEnvelope | undefined;
		const event = envelope?.event ?? ( raw as Record< string, unknown > );
		const subtype = String( event?.subtype ?? '' );
		const botId = String( event?.bot_id ?? '' );

		if ( 'bot_message' === subtype || '' !== botId ) {
			return false;
		}
		if ( [ 'message_changed', 'message_deleted' ].includes( subtype ) ) {
			return false;
		}
		if (
			[
				'channel_join',
				'channel_leave',
				'group_join',
				'group_leave',
			].includes( subtype )
		) {
			return false;
		}
		return true;
	}

	ingest( raw: unknown ): IncomingMessage | null {
		const envelope = raw as SlackEventEnvelope | undefined;
		const event = envelope?.event;
		if ( ! event || String( event.type ?? '' ) !== 'message' ) {
			return null;
		}

		const channelId = String( event.channel ?? '' );
		const ts = String( event.ts ?? '' );
		const userId = String( event.user ?? '' );
		if ( ! channelId || ! ts || ! userId ) {
			return null;
		}

		const text = String( event.text ?? '' );
		if ( ! text ) {
			return null; // Phase 1: skip non-text messages
		}

		const threadTs = String( event.thread_ts ?? '' );
		const timestampMs = parseFloat( ts ) * 1000;
		return {
			source: 'slack',
			source_ref: ts,
			conversation_ref: channelId,
			author_external_id: userId,
			author_display_name: null,
			content_html: text, // Slack_Content_Processor will reformat this on the server
			thread_ref: threadTs || null,
			external_timestamp: new Date( timestampMs ).toISOString(),
			raw_payload: raw,
		};
	}
}

export { SlackAdapter };
