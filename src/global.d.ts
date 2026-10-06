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

	export const __experimentalInspectorPopoverHeader: ComponentType< {
		title: string;
		onClose: () => void;
	} >;

	export const BlockList: ComponentType< {
		className?: string;
		layout?: Record< string, unknown >;
	} >;

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
		group?: string;
		children?: ReactNode;
	} >;

	export const __experimentalColorGradientSettingsDropdown: ComponentType< {
		settings: Record< string, unknown >[];
		panelId?: string;
		__experimentalIsRenderedInSidebar?: boolean;
		[ key: string ]: unknown;
	} >;

	export function __experimentalUseMultipleOriginColorsAndGradients(): Record<
		string,
		unknown
	>;

	export const BlockControls: ComponentType< {
		group?: string;
		children?: ReactNode;
	} >;

	export function BlockContextProvider(
		props: Record< string, unknown >
	): JSX.Element;

	export const BlockPreview: ComponentType< {
		blocks: unknown[];
		viewportWidth?: number;
		minHeight?: number;
	} >;

	export const __experimentalUseBlockPreview: (
		props: Record< string, unknown >
	) => Record< string, unknown >;

	type ClassesAndStyles = (
		attributes: Record< string, unknown >
	) => { className?: string; style?: Record< string, unknown > };

	export const __experimentalGetBorderClassesAndStyles: ClassesAndStyles;
	export const __experimentalGetColorClassesAndStyles: ClassesAndStyles;
	export const __experimentalGetDimensionsClassesAndStyles: ClassesAndStyles;
	export const __experimentalGetShadowClassesAndStyles: ClassesAndStyles;
	export const __experimentalGetSpacingClassesAndStyles: ClassesAndStyles;
	export const getTypographyClassesAndStyles: ClassesAndStyles;

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