declare module '*.scss' {
	const content: Record< string, string >;
	export default content;
}

declare module '@wordpress/block-editor';

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
}
