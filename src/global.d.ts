declare module '*.scss' {
	const content: Record< string, string >;
	export default content;
}

declare module '@wordpress/block-editor' {
	import type { ComponentType, Ref } from 'react';

	export const BlockCanvas: ComponentType< {
		height?: string | number;
		styles?: unknown[];
	} >;

	export const BlockInspector: ComponentType;

	export const InnerBlocks: {
		Content: ComponentType;
		Button: ComponentType;
		[ key: string ]: unknown;
	};

	export function useBlockProps(
		props?: Record< string, unknown >
	): Record< string, unknown >;

	export function useInnerBlocksProps(
		props?: Record< string, unknown >,
		config?: Record< string, unknown >
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
		children?: React.ReactNode;
	} >;

	export const BlockContextProvider: ComponentType< {
		value: unknown;
		children?: React.ReactNode;
	} >;

	// eslint-disable-next-line @typescript-eslint/no-explicit-any
	export const __experimentalUseBlockPreview: any;

	// eslint-disable-next-line @typescript-eslint/no-explicit-any
	export const store: any;
}

declare module '@wordpress/block-library' {
	export function registerCoreBlocks(): void;
}

declare module '@wordpress/editor' {
	import type { ComponentType } from 'react';

	export const EditorProvider: ComponentType< {
		post?: object;
		settings?: Record< string, unknown >;
		children?: React.ReactNode;
	} >;

	export const PostTitle: ComponentType;

	// eslint-disable-next-line @typescript-eslint/no-explicit-any
	export const store: any;

	export const EditorSnackbars: ComponentType;
}