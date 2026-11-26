/**
 * Internal dependencies
 */
import { QuickEditBlockToolbar } from './quick-edit-block-toolbar';
import { QuickEditDocumentTools } from './quick-edit-document-tools';
import { QuickEditInspector } from './quick-edit-inspector';

/**
 * The single toolbar row across the top of the Summary's Quick Edit, laid
 * out like P2's comment editor: document tools on the left, the selected
 * block's toolbar in the middle, the settings gear on the right.
 *
 * Renders inside `EditorProvider`: undo, redo and the block toolbar read the
 * editor's stores from its sub-registry.
 */
function QuickEditToolbar() {
	return (
		<div className="newspack-rolling-coverage-quick-edit__toolbar">
			<QuickEditDocumentTools />
			<QuickEditBlockToolbar />
			<div className="newspack-rolling-coverage-quick-edit__settings">
				<QuickEditInspector />
			</div>
		</div>
	);
}

export { QuickEditToolbar };
