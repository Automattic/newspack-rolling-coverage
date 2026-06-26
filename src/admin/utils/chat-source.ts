/** Chat-source adapter protocol. Each platform implements ChatSourceAdapter and self-registers via registerSource(). */

/**
 * Internal dependencies
 */
import type { IncomingMessage, SettingField } from '../types';

/**
 * Base class every chat-source platform adapter extends. Declares the optional
 * lifecycle hooks (verification, filtering, ingestion, settings) the generic
 * ingestion pipeline calls; adapters override only what differs per platform.
 */
abstract class ChatSourceAdapter {
	abstract slug(): string;

	abstract displayName(): string;

	isConfigured(): boolean {
		return false;
	}

	settingsFields(): SettingField[] {
		return [];
	}

	verifyRequest( _req?: Request ): boolean | Promise< boolean > {
		void _req;
		return true;
	}

	shouldIngest( _raw: unknown ): boolean {
		void _raw;
		return true;
	}

	ingest( _raw: unknown ): IncomingMessage | null {
		void _raw;
		return null;
	}
}

const sources: ChatSourceAdapter[] = [];

/** Register an adapter. */
function registerSource( adapter: ChatSourceAdapter ): void {
	sources.push( adapter );
}

export { ChatSourceAdapter, registerSource };
