/**
 * WordPress dependencies
 */
import { useBlockProps, useInnerBlocksProps } from '@wordpress/block-editor';

/**
 * Internal dependencies
 */
import { CHECK_UPDATES_BUTTONS_TEMPLATE } from '../shared/check-updates-buttons';

const ALLOWED_BLOCKS = [ 'core/buttons' ];
const TEMPLATE = [ CHECK_UPDATES_BUTTONS_TEMPLATE ];

/**
 * Editor for the Check for Updates block: the locked button.
 */
export default function Edit() {
	const blockProps = useBlockProps();
	const innerBlocksProps = useInnerBlocksProps( blockProps, {
		template: TEMPLATE,
		templateLock: 'all',
		allowedBlocks: ALLOWED_BLOCKS,
	} );

	return <div { ...innerBlocksProps } />;
}
