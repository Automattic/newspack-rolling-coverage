/**
 * Internal dependencies
 */
import type { AdminConfig } from './types';

declare module '*.scss' {
	const content: Record< string, string >;
	export default content;
}

// Augment Window with the config object injected by wp_localize_script.
declare global {
	interface Window {
		newspackRollingCoverageAdmin?: AdminConfig;
	}
}
