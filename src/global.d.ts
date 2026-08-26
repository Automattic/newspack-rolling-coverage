/**
 * Internal dependencies
 */
import type { AdminConfig } from './admin/types';
import type { ComponentType, ReactNode, JSX } from 'react';

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

// Type declarations for @wordpress/block-editor, which does not ship .d.ts
// files. Only the exports used by this project are declared. The tsconfig.json
// `paths` entry redirects the import to this file.
declare module '@wordpress/block-editor' {
	import type { ComponentType, ReactNode, JSX } from 'react';

	export const BlockCanvas: ComponentType< {
		height?: string | number;
		styles?: unknown[];
		children?: ReactNode;
	} >;

	export const BlockInspector: ComponentType;

	export function useBlockProps(
		props?: Record< string, unknown >
	): Record< string, unknown >;

	export function useInnerBlocksProps(
		props?: Record< string, unknown >,
		options?: Record< string, unknown >
	): Record< string, unknown >;

	export const RichText: ComponentType< {
		tagName?: string;
		className?: string;
		value?: string;
		onChange?: ( value: string ) => void;
		placeholder?: string;
		allowedFormats?: string[];
		onClick?: ( event: React.MouseEvent ) => void;
		type?: string;
		[ key: string ]: unknown;
	} >;

	export const InspectorControls: ComponentType< {
		children?: ReactNode;
	} >;

	export function BlockContextProvider(
		props: Record< string, unknown >
	): JSX.Element;

	export const __experimentalUseBlockPreview: (
		props: Record< string, unknown >
	) => Record< string, unknown >;

	export const store: {
		name: string;
		[ key: string ]: unknown;
	};

	export const InnerBlocks: {
		Content: () => JSX.Element;
	};
}

// Type declarations for @wordpress/block-library, which does not ship .d.ts
// files.
declare module '@wordpress/block-library' {
	export function registerCoreBlocks(): void;
}