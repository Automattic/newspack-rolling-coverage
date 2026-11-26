/**
 * Internal dependencies
 */
import { QuickEditSaveBar } from './quick-edit-save-bar';
import { QuickEditToolbar } from './quick-edit-toolbar';
import type { QuickEditCommentLayoutProps } from '../types';

/**
 * The Summary's Quick Edit, laid out like P2's comment editor: one toolbar
 * row on top with the block toolbar pinned in it, the canvas, Cancel and
 * Save at the bottom. Renders inside `EditorProvider`.
 *
 * @param {QuickEditCommentLayoutProps} props Component props.
 */
function QuickEditCommentLayout( {
	canvas,
	isNew,
	onClose,
	onSaved,
}: QuickEditCommentLayoutProps ) {
	return (
		<>
			<QuickEditToolbar />
			{ canvas }
			<div className="newspack-rolling-coverage-quick-edit__footer">
				<QuickEditSaveBar
					isNew={ isNew }
					onClose={ onClose }
					onSaved={ onSaved }
				/>
			</div>
		</>
	);
}

export { QuickEditCommentLayout };
