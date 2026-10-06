/**
 * External dependencies
 */
import { Dropdown, ToolbarButton, ToolbarItem } from '@wordpress/components';
import {
	Inserter,
	NavigableToolbar,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalListView as ListView,
} from '@wordpress/block-editor';
import { EditorHistoryRedo, EditorHistoryUndo } from '@wordpress/editor';
import { listView, plus } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { QuickEditBlockToolbar } from './quick-edit-block-toolbar';
import { QuickEditInspector } from './quick-edit-inspector';

/**
 * The single toolbar row across the top of Quick Edit, laid out like P2's
 * comment editor: document tools on the left, the selected block's toolbar
 * in the middle, the settings gear on the right.
 *
 * The inserter opens the block library in a popover under the "+" rather
 * than a sidebar, and inserts after the selected block or at the end of the
 * entry. The document overview is `ListView` in a plain `Dropdown`; core's
 * `BlockNavigationDropdown` does the same but logs a deprecation warning on
 * every mount.
 *
 * Renders inside `EditorProvider`: undo and redo read the editor store from
 * its sub-registry.
 */
function QuickEditToolbar() {
	return (
		<div className="newspack-rolling-coverage-quick-edit__toolbar">
			<NavigableToolbar
				className="newspack-rolling-coverage-quick-edit__document-tools"
				aria-label={ __(
					'Document tools',
					'newspack-rolling-coverage'
				) }
				variant="unstyled"
			>
				<Inserter
					position="bottom right"
					renderToggle={ ( { onToggle, isOpen, disabled } ) => (
						<ToolbarButton
							className="newspack-rolling-coverage-quick-edit__inserter-toggle"
							variant="primary"
							icon={ plus }
							label={ __(
								'Block Inserter',
								'newspack-rolling-coverage'
							) }
							isPressed={ isOpen }
							aria-expanded={ isOpen }
							aria-haspopup="true"
							disabled={ disabled }
							onClick={ onToggle }
						/>
					) }
				/>
				<ToolbarItem>
					{ ( toolbarItemProps ) => (
						<EditorHistoryUndo
							{ ...toolbarItemProps }
							size="compact"
						/>
					) }
				</ToolbarItem>
				<ToolbarItem>
					{ ( toolbarItemProps ) => (
						<EditorHistoryRedo
							{ ...toolbarItemProps }
							size="compact"
						/>
					) }
				</ToolbarItem>
				<Dropdown
					contentClassName="newspack-rolling-coverage-quick-edit__list-view"
					popoverProps={ { placement: 'bottom-start' } }
					renderToggle={ ( { isOpen, onToggle } ) => (
						<ToolbarButton
							icon={ listView }
							label={ __(
								'Document Overview',
								'newspack-rolling-coverage'
							) }
							isPressed={ isOpen }
							aria-expanded={ isOpen }
							aria-haspopup="true"
							onClick={ onToggle }
						/>
					) }
					renderContent={ () => <ListView isExpanded /> }
				/>
			</NavigableToolbar>
			<QuickEditBlockToolbar />
			<div className="newspack-rolling-coverage-quick-edit__settings">
				<QuickEditInspector />
			</div>
		</div>
	);
}

export { QuickEditToolbar };
