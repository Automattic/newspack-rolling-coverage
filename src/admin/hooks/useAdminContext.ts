/**
 * Internal dependencies
 */
import type { AdminConfig } from '../types';

/**
 * Provides the server-side admin config localised by wp_localize_script.
 * Throws if the config is missing, which indicates a broken script enqueue.
 *
 * @return {AdminConfig} The admin configuration object.
 */
function useAdminContext(): AdminConfig {
	const config = window.newspackRollingCoverageAdmin;

	if ( ! config ) {
		throw new Error(
			'Admin config not found. Ensure PHP localization is properly enqueued.'
		);
	}

	return config;
}

export { useAdminContext };
