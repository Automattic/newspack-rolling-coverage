/**
 * External dependencies
 */
import { useEffect, useState } from '@wordpress/element';
import { Button, Popover } from '@wordpress/components';
import {
	BlockToolbar,
	store as blockEditorStore,
} from '@wordpress/block-editor';
import { useSelect } from '@wordpress/data';
import { next, previous } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { BlockEditorSelectors } from '../types';

/**
 * The selected block's toolbar, pinned into the Quick Edit toolbar row the
 * way the post editor's Top Toolbar mode pins it.
 *
 * It reopens whenever a different block is selected and renders nothing
 * without a selection, so the row never shows an empty gap. The chevron
 * hides it to give the document tools room. The `block-toolbar` popover
 * slot has to sit outside the clipped wrapper: the block switcher, the link
 * popover and "more rich text options" render into it.
 */
function QuickEditBlockToolbar() {
	const [ isCollapsed, setIsCollapsed ] = useState( false );
	const blockSelectionStart = useSelect(
		( select ) =>
			(
				select( blockEditorStore ) as unknown as BlockEditorSelectors
			 ).getBlockSelectionStart(),
		[]
	);

	useEffect( () => {
		if ( blockSelectionStart ) {
			setIsCollapsed( false );
		}
	}, [ blockSelectionStart ] );

	if ( ! blockSelectionStart ) {
		return null;
	}

	const className = isCollapsed
		? 'newspack-rolling-coverage-quick-edit__block-toolbar is-collapsed'
		: 'newspack-rolling-coverage-quick-edit__block-toolbar';

	return (
		<>
			<div className={ className }>
				<BlockToolbar hideDragHandle />
			</div>
			<Popover.Slot name="block-toolbar" />
			<Button
				className="newspack-rolling-coverage-quick-edit__block-toolbar-toggle"
				icon={ isCollapsed ? next : previous }
				label={
					isCollapsed
						? __( 'Show block tools', 'newspack-rolling-coverage' )
						: __( 'Hide block tools', 'newspack-rolling-coverage' )
				}
				onClick={ () => setIsCollapsed( ( prev ) => ! prev ) }
				size="compact"
			/>
		</>
	);
}

export { QuickEditBlockToolbar };
