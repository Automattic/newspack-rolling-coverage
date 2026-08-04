/**
 * External dependencies
 */
import { select } from '@wordpress/data';
import { store as blocksStore } from '@wordpress/blocks';
import { registerCoreBlocks } from '@wordpress/block-library';

/**
 * Registers WordPress core blocks if they haven't been registered yet.
 *
 * Required before rendering a block editor canvas outside the normal
 * post-edit screen, where WordPress core doesn't auto-register blocks.
 */
function ensureEditorInitialized() {
	if ( ! select( blocksStore ).getBlockType( 'core/paragraph' ) ) {
		registerCoreBlocks();
	}
}

export { ensureEditorInitialized };
