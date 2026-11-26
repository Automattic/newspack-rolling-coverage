/**
 * External dependencies
 */
import { useState } from '@wordpress/element';
import { useViewportMatch } from '@wordpress/compose';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { QuickEditEditorHeader } from './quick-edit-editor-header';
import { QuickEditSidebar } from './quick-edit-sidebar';
import type { QuickEditEditorLayoutProps } from '../types';

/**
 * An entry's Quick Edit, laid out like the post editor: the header with the
 * editor's save and publish buttons, the canvas with the block toolbar
 * floating over the selected block, and the settings sidebar. The sidebar
 * starts open from the medium breakpoint up; below it, it starts closed and
 * opens over the canvas, as the post editor's does on a phone. Renders
 * inside `EditorProvider`.
 *
 * @param {QuickEditEditorLayoutProps} props Component props.
 */
function QuickEditEditorLayout( {
	canvas,
	entry,
	actions,
	canChangeStatus,
	canNotify,
	onClose,
	onSaved,
}: QuickEditEditorLayoutProps ) {
	const isMediumViewport = useViewportMatch( 'medium' );
	const [ isSidebarOpen, setIsSidebarOpen ] = useState( isMediumViewport );

	return (
		<>
			<QuickEditEditorHeader
				canChangeStatus={ canChangeStatus }
				isSidebarOpen={ isSidebarOpen }
				onToggleSidebar={ () => setIsSidebarOpen( ( prev ) => ! prev ) }
				onClose={ onClose }
				onSaved={ onSaved }
			/>
			<div className="newspack-rolling-coverage-quick-edit__body">
				{ canvas }
				{ isSidebarOpen && (
					<aside
						className="newspack-rolling-coverage-quick-edit__sidebar"
						aria-label={ __(
							'Entry settings',
							'newspack-rolling-coverage'
						) }
					>
						<QuickEditSidebar
							entry={ entry }
							actions={ actions }
							canChangeStatus={ canChangeStatus }
							canNotify={ canNotify }
						/>
					</aside>
				) }
			</div>
		</>
	);
}

export { QuickEditEditorLayout };
