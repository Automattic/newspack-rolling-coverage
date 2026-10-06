/**
 * External dependencies
 */
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { Button, Popover } from '@wordpress/components';
import {
	BlockInspector,
	store as blockEditorStore,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalInspectorPopoverHeader as InspectorPopoverHeader,
} from '@wordpress/block-editor';
import { useSelect } from '@wordpress/data';
import { cog } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';
import type { MouseEvent } from 'react';

/**
 * Internal dependencies
 */
import type { BlockEditorSelectors } from '../types';

/**
 * The settings gear in the Quick Edit toolbar. Opens the block inspector in
 * a popover anchored to the gear, the way P2's comment editor does, rather
 * than in a sidebar.
 *
 * The gear is disabled until a block is selected, since the inspector has
 * nothing to show for the document. The popover closes when the selection
 * goes away and when focus leaves it, with two exceptions that keep it
 * usable: focus landing on the gear, whose click is what toggles the popover
 * and would otherwise close and reopen it in one go; and focus landing in
 * another popover, which is how the color and font-size controls inside the
 * inspector render their pickers. `onFocusOutside` fires after focus has
 * settled, so the gear's document reports the element that took it.
 *
 * The gear's `mousedown` is cancelled so a click never moves focus to it.
 * Safari, and Firefox on macOS, do not focus a clicked button at all; the
 * blur check would then see focus on `body` and close the popover just
 * before the click reopened it.
 */
function QuickEditInspector() {
	const [ isOpen, setIsOpen ] = useState( false );
	const buttonRef = useRef< HTMLButtonElement >( null );
	const hasBlockSelection = useSelect(
		( select ) =>
			Boolean(
				(
					select(
						blockEditorStore
					) as unknown as BlockEditorSelectors
				 ).getBlockSelectionStart()
			),
		[]
	);

	useEffect( () => {
		if ( ! hasBlockSelection ) {
			setIsOpen( false );
		}
	}, [ hasBlockSelection ] );

	const close = useCallback( () => setIsOpen( false ), [] );

	const closeUnlessFocusStayedNearby = useCallback( () => {
		const active = buttonRef.current?.ownerDocument.activeElement;
		if (
			active &&
			( active === buttonRef.current ||
				active.closest( '.components-popover' ) )
		) {
			return;
		}
		setIsOpen( false );
	}, [] );

	return (
		<>
			<Button
				ref={ buttonRef }
				icon={ cog }
				label={ __( 'Settings', 'newspack-rolling-coverage' ) }
				size="compact"
				isPressed={ isOpen }
				aria-expanded={ isOpen }
				disabled={ ! hasBlockSelection }
				accessibleWhenDisabled
				onMouseDown={ ( event: MouseEvent ) => event.preventDefault() }
				onClick={ () => setIsOpen( ( prev ) => ! prev ) }
			/>
			{ isOpen && (
				<Popover
					anchor={ buttonRef.current }
					placement="bottom-end"
					className="newspack-rolling-coverage-quick-edit__inspector"
					focusOnMount="firstElement"
					onClose={ close }
					onFocusOutside={ closeUnlessFocusStayedNearby }
				>
					<InspectorPopoverHeader
						title={ __( 'Block', 'newspack-rolling-coverage' ) }
						onClose={ close }
					/>
					<BlockInspector />
				</Popover>
			) }
		</>
	);
}

export { QuickEditInspector };
