/**
 * External dependencies
 */
import type { Ref } from 'react';
import { BlockCanvas, BlockList } from '@wordpress/block-editor';
import { PostTitle } from '@wordpress/editor';

/**
 * Internal dependencies
 */
import type { QuickEditCanvasProps } from '../types';

/**
 * The entry's title and blocks in the editor canvas, shared by both Quick
 * Edit layouts. Renders inside `EditorProvider`.
 *
 * @param {QuickEditCanvasProps} props Component props.
 */
function QuickEditCanvas( {
	styles,
	supportsLayout,
	titleRef,
}: QuickEditCanvasProps ) {
	const layoutClassName = supportsLayout
		? ' is-layout-constrained has-global-padding'
		: '';

	return (
		<div className="newspack-rolling-coverage-quick-edit__canvas">
			<BlockCanvas height="100%" styles={ styles }>
				<div
					className={ `editor-visual-editor__post-title-wrapper${ layoutClassName }` }
				>
					<PostTitle
						ref={ titleRef as Ref< { focus: () => void } > }
					/>
				</div>
				<BlockList
					className={ `wp-block-post-content${ layoutClassName }` }
					layout={
						supportsLayout ? { type: 'constrained' } : undefined
					}
				/>
			</BlockCanvas>
		</div>
	);
}

export { QuickEditCanvas };
