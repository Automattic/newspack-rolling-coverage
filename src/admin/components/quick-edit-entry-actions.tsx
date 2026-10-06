/**
 * External dependencies
 */
import { useState } from '@wordpress/element';
import {
	DropdownMenu,
	MenuGroup,
	MenuItem,
	Modal,
} from '@wordpress/components';
import { useRegistry, useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { moreVertical } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { Action, Entry, QuickEditEntryActionsProps } from '../types';

/**
 * Actions Quick Edit leaves out: opening Quick Edit itself, and the status
 * changes, which the header and the Status row make instead.
 */
const EXCLUDED_ACTIONS = [
	'quick-edit',
	'quick-edit-confirm',
	'publish-entry',
	'draft-entry',
];

/**
 * Actions that work from the saved entry, so they'd miss or clash with
 * Quick Edit's unsaved changes: opening it in the full editor, copying it
 * into a breakout post, and locking or unlocking it.
 */
const SAVED_ENTRY_ACTIONS = [
	'edit',
	'edit-confirm',
	'create-breakout',
	'archive-entry',
	'unarchive-entry',
];

/**
 * The entry's actions menu in the Quick Edit sidebar, standing in for the
 * post editor's, which `@wordpress/editor` doesn't export. It offers the
 * entries list's own row actions, so each one behaves as it does there.
 *
 * @param {QuickEditEntryActionsProps} props Component props.
 */
function QuickEditEntryActions( {
	entry,
	actions,
}: QuickEditEntryActionsProps ) {
	const registry = useRegistry();
	const isDirty = useSelect(
		( select ) =>
			(
				select( editorStore ) as unknown as {
					isEditedPostDirty: () => boolean;
				}
			 ).isEditedPostDirty(),
		[]
	);
	const [ modalAction, setModalAction ] = useState< Action< Entry > | null >(
		null
	);

	const eligible = actions.filter(
		( action ) =>
			! EXCLUDED_ACTIONS.includes( action.id ) &&
			( action.isEligible?.( entry ) ?? true )
	);

	if ( ! eligible.length ) {
		return null;
	}

	const RenderModal =
		modalAction && 'RenderModal' in modalAction
			? modalAction.RenderModal
			: null;
	const closeModal = () => setModalAction( null );
	const labelOf = ( action: Action< Entry > ) =>
		typeof action.label === 'function'
			? action.label( [ entry ] )
			: action.label;

	return (
		<>
			<DropdownMenu
				icon={ moreVertical }
				label={ __( 'Actions', 'newspack-rolling-coverage' ) }
				toggleProps={ {
					size: 'small',
					className: 'editor-all-actions-button',
				} }
			>
				{ ( { onClose } ) => (
					<MenuGroup>
						{ eligible.map( ( action ) => (
							<MenuItem
								key={ action.id }
								disabled={
									isDirty &&
									SAVED_ENTRY_ACTIONS.includes( action.id )
								}
								info={
									isDirty &&
									SAVED_ENTRY_ACTIONS.includes( action.id )
										? __(
												'Save your changes first.',
												'newspack-rolling-coverage'
											)
										: undefined
								}
								onClick={ () => {
									onClose();
									if ( 'RenderModal' in action ) {
										setModalAction( action );
									} else {
										action.callback( [ entry ], {
											registry,
										} );
									}
								} }
							>
								{ labelOf( action ) }
							</MenuItem>
						) ) }
					</MenuGroup>
				) }
			</DropdownMenu>
			{ modalAction && RenderModal && (
				<Modal
					title={ labelOf( modalAction ) }
					onRequestClose={ closeModal }
					size="medium"
				>
					<RenderModal
						items={ [ entry ] }
						closeModal={ closeModal }
					/>
				</Modal>
			) }
		</>
	);
}

export { QuickEditEntryActions };
