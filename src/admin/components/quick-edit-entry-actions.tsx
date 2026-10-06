/**
 * External dependencies
 */
import { useState } from '@wordpress/element';
import { DropdownMenu, MenuGroup, MenuItem } from '@wordpress/components';
import { useRegistry } from '@wordpress/data';
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
								{ typeof action.label === 'function'
									? action.label( [ entry ] )
									: action.label }
							</MenuItem>
						) ) }
					</MenuGroup>
				) }
			</DropdownMenu>
			{ RenderModal && (
				<RenderModal
					items={ [ entry ] }
					closeModal={ () => setModalAction( null ) }
				/>
			) }
		</>
	);
}

export { QuickEditEntryActions };
