/**
 * External dependencies
 */
import type { ComponentType, ReactNode } from 'react';
import { TextControl } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import {
	PluginDocumentSettingPanel,
	store as editorStore,
} from '@wordpress/editor';
import { __, sprintf } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';

type BreakoutEditorData = {
	metaKey: string;
	siteLabel: string;
	maxLength: number;
};

declare global {
	interface Window {
		newspackRollingCoverageBreakoutEditor?: BreakoutEditorData;
	}
}

type EditorPostSelectors = {
	getEditedPostAttribute: ( attribute: string ) => unknown;
};

// The editor's JS components carry inferred types that mark optional props
// as required.
const DocumentSettingPanel =
	PluginDocumentSettingPanel as unknown as ComponentType< {
		name: string;
		title: string;
		children: ReactNode;
	} >;

/**
 * The breakout post's own Full story label, shown above it in the coverage
 * feed in place of the site's.
 *
 * @param {Object} props      Component props.
 * @param {Object} props.data The editor config passed by the server.
 */
function FullStoryLabelControl( { data }: { data: BreakoutEditorData } ) {
	const label = useSelect(
		( select ) => {
			const editor = select(
				editorStore
			) as unknown as EditorPostSelectors;
			const meta = editor.getEditedPostAttribute( 'meta' ) as
				Record< string, unknown > | undefined;
			const value = meta?.[ data.metaKey ];

			return typeof value === 'string' ? value : '';
		},
		[ data.metaKey ]
	);
	const { editPost } = useDispatch( editorStore );

	return (
		<TextControl
			__next40pxDefaultSize
			label={ __( 'Full story label', 'newspack-rolling-coverage' ) }
			value={ label }
			placeholder={ data.siteLabel }
			maxLength={ data.maxLength }
			help={ sprintf(
				/* translators: %s: the Full story label every other broken-out story shows. */
				__(
					'Shown above this story in the coverage feed. Leave empty to use “%s”.',
					'newspack-rolling-coverage'
				),
				data.siteLabel
			) }
			onChange={ ( value: string ) =>
				editPost( { meta: { [ data.metaKey ]: value } } )
			}
		/>
	);
}

/**
 * The Rolling Coverage panel of a breakout post's editor.
 */
function BreakoutPanel() {
	const data = window.newspackRollingCoverageBreakoutEditor;

	if ( ! data ) {
		return null;
	}

	return (
		<DocumentSettingPanel
			name="rolling-coverage-breakout"
			title={ __( 'Rolling Coverage', 'newspack-rolling-coverage' ) }
		>
			<FullStoryLabelControl data={ data } />
		</DocumentSettingPanel>
	);
}

registerPlugin( 'newspack-rolling-coverage-breakout', {
	render: BreakoutPanel,
} );
