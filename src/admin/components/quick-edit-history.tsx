/**
 * External dependencies
 */
import { useEffect, useState } from '@wordpress/element';
import { Button, ToolbarItem } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { redo as redoIcon, undo as undoIcon } from '@wordpress/icons';
import { __, isRTL } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { EditorSelectors } from '../types';

/**
 * Undo and Redo for the open entry, in place of core's `EditorHistoryUndo`
 * and `EditorHistoryRedo`.
 *
 * Core-data keeps one undo history for the whole page, and closing Quick
 * Edit clears an entry's edits but not that history. Core's buttons would
 * step back into entries edited earlier on the same page load, where an
 * extra Undo can put an older version back into an entry saved minutes
 * before. So Undo is offered only while the open entry has unsaved edits,
 * and Redo only once it has been edited in this Quick Edit: past those
 * points the history belongs to other entries.
 *
 * Core's buttons can't be gated through props, because they set their own
 * `aria-disabled` and `onClick` after spreading them. These also leave out
 * the keyboard shortcut core shows in the tooltip, since nothing on this
 * page registers it.
 */
function QuickEditHistory() {
	const { hasUndo, hasRedo, isDirty } = useSelect( ( select ) => {
		const editor = select( editorStore ) as unknown as EditorSelectors;
		return {
			hasUndo: editor.hasEditorUndo(),
			hasRedo: editor.hasEditorRedo(),
			isDirty: editor.isEditedPostDirty(),
		};
	}, [] );
	const { undo, redo } = useDispatch( editorStore );
	const [ hasBeenEdited, setHasBeenEdited ] = useState( false );

	useEffect( () => {
		if ( isDirty ) {
			setHasBeenEdited( true );
		}
	}, [ isDirty ] );

	return (
		<>
			<ToolbarItem>
				{ ( toolbarItemProps ) => (
					<Button
						{ ...toolbarItemProps }
						icon={ isRTL() ? redoIcon : undoIcon }
						label={ __( 'Undo', 'newspack-rolling-coverage' ) }
						size="compact"
						disabled={ ! ( hasUndo && isDirty ) }
						accessibleWhenDisabled
						onClick={ () => undo() }
					/>
				) }
			</ToolbarItem>
			<ToolbarItem>
				{ ( toolbarItemProps ) => (
					<Button
						{ ...toolbarItemProps }
						icon={ isRTL() ? undoIcon : redoIcon }
						label={ __( 'Redo', 'newspack-rolling-coverage' ) }
						size="compact"
						disabled={ ! ( hasRedo && hasBeenEdited ) }
						accessibleWhenDisabled
						onClick={ () => redo() }
					/>
				) }
			</ToolbarItem>
		</>
	);
}

export { QuickEditHistory };
