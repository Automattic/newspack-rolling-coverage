/**
 * External dependencies
 */
import { useState, useCallback } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { store as editorStore } from '@wordpress/editor';
import { useDispatch, useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { QuickEditSaveBarProps } from '../types';

/**
 * Save bar for the Quick Edit modal.
 *
 * @param {QuickEditSaveBarProps} props Component props.
 */
function QuickEditSaveBar( { onClose, onSaved }: QuickEditSaveBarProps ) {
	const { savePost } = useDispatch( editorStore );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );

	const isEditorReady = useSelect(
		( select ) =>
			(
				select( editorStore ) as unknown as {
					__unstableIsEditorReady?: () => boolean;
				}
			 ).__unstableIsEditorReady?.() ?? false,
		[]
	);

	const handleSave = useCallback( async () => {
		setIsSaving( true );
		setError( null );
		try {
			await savePost();
			onSaved();
		} catch ( err ) {
			setError(
				err instanceof Error
					? err.message
					: __( 'Failed to save entry', 'newspack-rolling-coverage' )
			);
		} finally {
			setIsSaving( false );
		}
	}, [ savePost, onSaved ] );

	return (
		<>
			{ error && (
				<div className="newspack-rolling-coverage-error">{ error }</div>
			) }
			<div className="newspack-rolling-coverage-modal-footer">
				<Button
					variant="tertiary"
					onClick={ onClose }
					disabled={ isSaving }
				>
					{ __( 'Cancel', 'newspack-rolling-coverage' ) }
				</Button>
				<Button
					variant="primary"
					onClick={ handleSave }
					isBusy={ isSaving }
					disabled={ isSaving || ! isEditorReady }
				>
					{ __( 'Save', 'newspack-rolling-coverage' ) }
				</Button>
			</div>
		</>
	);
}

export { QuickEditSaveBar };
