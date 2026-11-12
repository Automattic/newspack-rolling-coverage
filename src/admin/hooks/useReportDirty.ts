/**
 * External dependencies
 */
import { useEffect } from '@wordpress/element';

/**
 * Tell the parent screen whether this view has unsaved changes, and that it
 * has none once it unmounts.
 *
 * @param onDirtyChange Receives the view's dirty state.
 * @param isDirty       Whether the view has unsaved changes.
 */
export function useReportDirty(
	onDirtyChange: ( isDirty: boolean ) => void,
	isDirty: boolean
): void {
	useEffect( () => {
		onDirtyChange( isDirty );
	}, [ onDirtyChange, isDirty ] );

	useEffect( () => () => onDirtyChange( false ), [ onDirtyChange ] );
}
