/**
 * WordPress dependencies
 */
import { useBlockProps, useInnerBlocksProps } from '@wordpress/block-editor';

/**
 * Internal dependencies
 */
import { LOAD_MORE_BUTTONS_TEMPLATE } from '../shared/load-more-buttons';

const ALLOWED_BLOCKS = [ 'core/buttons' ];
const TEMPLATE = [ LOAD_MORE_BUTTONS_TEMPLATE ];

/**
 * Editor for the Load More block: the locked button.
 */
export default function Edit() {
	const blockProps = useBlockProps( {
		className: 'newspack-rolling-coverage-load-more',
	} );
	const innerBlocksProps = useInnerBlocksProps( blockProps, {
		template: TEMPLATE,
		templateLock: 'all',
		allowedBlocks: ALLOWED_BLOCKS,
	} );

	return <div { ...innerBlocksProps } />;
}
