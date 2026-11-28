/**
 * External dependencies
 */
import { Dropdown, ToolbarButton } from '@wordpress/components';
import {
	Inserter,
	NavigableToolbar,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalListView as ListView,
} from '@wordpress/block-editor';
import { listView, plus } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { QuickEditHistory } from './quick-edit-history';

/**
 * Quick Edit's document tools, as in the post editor's header: the block
 * inserter, undo and redo, and the document overview.
 *
 * The inserter opens the block library in a popover under the "+" rather
 * than a sidebar, and inserts after the selected block or at the end of the
 * entry. The document overview is `ListView` in a plain `Dropdown`, the
 * pairing core points to as the replacement for its deprecated
 * `BlockNavigationDropdown`.
 *
 * Renders inside `EditorProvider`: undo and redo read the editor's stores
 * from its sub-registry.
 */
function QuickEditDocumentTools() {
	return (
		<NavigableToolbar
			className="newspack-rolling-coverage-quick-edit__document-tools"
			aria-label={ __( 'Document tools', 'newspack-rolling-coverage' ) }
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
			<QuickEditHistory />
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
	);
}

export { QuickEditDocumentTools };
