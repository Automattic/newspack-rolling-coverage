/**
 * WordPress dependencies
 */
import {
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalUseBlockPreview as useBlockPreview,
} from '@wordpress/block-editor';
import { memo } from '@wordpress/element';

/**
 * Internal dependencies
 */
import type { TemplateBlocks } from '../types';

/**
 * A static rendering of the per-entry template's current blocks for one real
 * entry.
 *
 * @param {Object}   props        Component props.
 * @param {Object[]} props.blocks The current per-entry template blocks.
 * @param {Object}   props.style  The container's inline style.
 */
function EntryBlockPreview( {
	blocks,
	style,
}: {
	blocks: TemplateBlocks;
	style?: Record< string, string | number >;
} ) {
	const blockPreviewProps = useBlockPreview( {
		blocks,
		props: {
			className: 'newspack-rolling-coverage-entry wp-block-post',
			...( style ? { style } : {} ),
		},
	} );

	return <div { ...blockPreviewProps } />;
}

export default memo( EntryBlockPreview );
