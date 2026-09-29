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
 * A rendering of the per-entry template's current blocks for one real
 * entry. With `onSelect`, clicking it makes that entry the active one,
 * swapping in the editable template canvas in its place; without it, the
 * preview is static.
 *
 * @param {Object}    props          Component props.
 * @param {Object[]}  props.blocks   The current per-entry template blocks.
 * @param {Function=} props.onSelect Called when this entry is clicked.
 */
function EntryBlockPreview( {
	blocks,
	onSelect,
}: {
	blocks: TemplateBlocks;
	onSelect?: () => void;
} ) {
	const blockPreviewProps = useBlockPreview( {
		blocks,
		props: { className: 'newspack-rolling-coverage-entry wp-block-post' },
	} );

	if ( ! onSelect ) {
		return <div { ...blockPreviewProps } />;
	}

	return (
		<div
			{ ...blockPreviewProps }
			tabIndex={ 0 }
			role="button"
			onClick={ onSelect }
			onKeyDown={ ( event ) => {
				if ( 'Enter' === event.key || ' ' === event.key ) {
					event.preventDefault();
					onSelect();
				}
			} }
		/>
	);
}

export default memo( EntryBlockPreview );
