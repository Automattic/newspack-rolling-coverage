/**
 * External dependencies
 */
import { useEffect, useRef } from '@wordpress/element';
import { store as editorStore } from '@wordpress/editor';
import { store as coreStore } from '@wordpress/core-data';
import { store as noticesStore } from '@wordpress/notices';
import { useDispatch, useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { EditorSelectors, CoreSelectors } from '../types';

/** The failed-save notice, so the next save can remove it. */
const SAVE_ERROR_NOTICE_ID = 'newspack-rolling-coverage-quick-edit-save-error';

/**
 * Reports how each Quick Edit save ends, whichever button started it: ours,
 * or the editor's own `PostSavedState` and `PostPublishButton`.
 *
 * `savePost()` never rejects on failure, so the result is detected by
 * watching `isSavingPost` transition to `false` and then reading
 * `didPostSaveRequestFail()`. All store reads go through `useSelect`, so
 * this must run inside `EditorProvider` to resolve in its sub-registry.
 *
 * The editor's own failure notice isn't a snackbar, so a failed save gets an
 * error snackbar for `SnackbarNotices` to render inside the modal. It carries
 * a fixed ID and the next save removes it: a new Summary's first successful
 * save closes the modal without clearing the page's snackbars, as the
 * editor's own success notice is on its way, and an error left from an
 * earlier attempt would otherwise show on the page beside it.
 *
 * @param onSaved   Called after each successful save.
 * @param onSettled Called after each save, successful or not.
 * @param onStarted Called as each save starts.
 * @return Whether a save is running.
 */
function useQuickEditSaveResult(
	onSaved: () => void,
	onSettled?: () => void,
	onStarted?: () => void
): boolean {
	const { createErrorNotice, removeNotice } = useDispatch( noticesStore );

	const { isSavingPost, didFail, lastSaveError } = useSelect( ( select ) => {
		const editor = select( editorStore ) as unknown as EditorSelectors;
		const core = select( coreStore ) as unknown as CoreSelectors;
		return {
			isSavingPost: editor.isSavingPost(),
			didFail: editor.didPostSaveRequestFail(),
			lastSaveError: core.getLastEntitySaveError(
				'postType',
				editor.getCurrentPostType(),
				editor.getCurrentPostId()
			),
		};
	}, [] );

	const wasSavingRef = useRef( false );

	useEffect( () => {
		if ( isSavingPost && ! wasSavingRef.current ) {
			wasSavingRef.current = true;
			removeNotice( SAVE_ERROR_NOTICE_ID );
			onStarted?.();
			return;
		}
		if ( ! wasSavingRef.current || isSavingPost ) {
			return;
		}
		wasSavingRef.current = false;
		onSettled?.();
		if ( didFail ) {
			createErrorNotice(
				lastSaveError?.message ||
					__( 'Failed to save entry.', 'newspack-rolling-coverage' ),
				{
					id: SAVE_ERROR_NOTICE_ID,
					type: 'snackbar',
					explicitDismiss: true,
				}
			);
		} else {
			onSaved();
		}
	}, [
		isSavingPost,
		didFail,
		lastSaveError,
		createErrorNotice,
		removeNotice,
		onSaved,
		onSettled,
		onStarted,
	] );

	return isSavingPost;
}

export { useQuickEditSaveResult };
