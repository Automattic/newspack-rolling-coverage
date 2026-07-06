/**
 * External dependencies
 */
import { useMemo, useEffect, useState } from '@wordpress/element';
import { Modal, Spinner, Button } from '@wordpress/components';
import { BlockCanvas, BlockInspector } from '@wordpress/block-editor';
import { EditorProvider, PostTitle } from '@wordpress/editor';
import { useEntityRecord } from '@wordpress/core-data';
import { drawerLeft, drawerRight } from '@wordpress/icons';
import { __, isRTL } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { useAdminContext } from '../hooks/useAdminContext';
import { ensureEditorInitialized } from '../utils/block-registration';
import { QuickEditSaveBar } from './quick-edit-save-bar';
import type { QuickEditModalProps, EntityRecord } from '../types';

/**
 * Renders a modal containing the WordPress post editor for quick-editing
 * an entry's title and content without leaving the admin page.
 *
 * @param {QuickEditModalProps} props Component props.
 */
function QuickEditModal( { entryId, onClose, onSaved }: QuickEditModalProps ) {
	const config = useAdminContext();
	const { record, isResolving } = useEntityRecord(
		'postType',
		config.postType,
		entryId
	);
	const typedRecord = record as EntityRecord | null;
	const [ isSidebarOpen, setIsSidebarOpen ] = useState( true );

	useEffect( () => {
		ensureEditorInitialized();
	}, [] );

	const settings = useMemo(
		() =>
			( {
				...config.blockEditorSettings,
				supportsTemplateMode: false,
			} ) as Record< string, unknown >,
		[ config.blockEditorSettings ]
	);

	if ( isResolving || ! typedRecord ) {
		return (
			<Modal
				title={ __( 'Quick Edit', 'newspack-rolling-coverage' ) }
				onRequestClose={ onClose }
			>
				<Spinner />
			</Modal>
		);
	}

	return (
		<Modal
			title={ __( 'Quick Edit', 'newspack-rolling-coverage' ) }
			onRequestClose={ onClose }
			shouldCloseOnClickOutside={ false }
			className="newspack-rolling-coverage-quick-edit"
			overlayClassName="newspack-rolling-coverage-quick-edit-overlay"
		>
			<EditorProvider post={ typedRecord } settings={ settings }>
				<div className="newspack-rolling-coverage-quick-edit-layout">
					<div className="newspack-rolling-coverage-quick-edit-main">
						<PostTitle />
						<div className="newspack-rolling-coverage-quick-edit-canvas">
							<BlockCanvas
								height="100%"
								styles={ settings.styles as unknown[] }
							/>
						</div>
					</div>
					{ isSidebarOpen && (
						<aside className="newspack-rolling-coverage-quick-edit-sidebar">
							<BlockInspector />
						</aside>
					) }
				</div>
				<div className="newspack-rolling-coverage-quick-edit-toolbar">
					<Button
						icon={ isRTL() ? drawerRight : drawerLeft }
						label={
							isSidebarOpen
								? __(
										'Hide sidebar',
										'newspack-rolling-coverage'
								  )
								: __(
										'Show sidebar',
										'newspack-rolling-coverage'
								  )
						}
						onClick={ () => setIsSidebarOpen( ( prev ) => ! prev ) }
						variant="tertiary"
					/>
					<QuickEditSaveBar onClose={ onClose } onSaved={ onSaved } />
				</div>
			</EditorProvider>
		</Modal>
	);
}

export { QuickEditModal };
