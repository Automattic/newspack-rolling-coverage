/**
 * External dependencies
 */
import type { Ref } from 'react';
import { useMemo } from '@wordpress/element';
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
 * With a block theme, the blocks and the title keep to the theme's content
 * width, as in the post editor. The theme's global styles don't carry the
 * constrained layout's rules, so the post editor generates them from the
 * theme's layout setting; the blocks get them from `BlockList`'s `layout`,
 * and the title from a rule added to the canvas styles.
 *
 * @param {QuickEditCanvasProps} props Component props.
 */
function QuickEditCanvas( {
	styles,
	supportsLayout,
	themeLayout,
	titleRef,
}: QuickEditCanvasProps ) {
	const layout = useMemo(
		() =>
			supportsLayout
				? { ...themeLayout, type: 'constrained' }
				: undefined,
		[ supportsLayout, themeLayout ]
	);
	const canvasStyles = useMemo(
		() =>
			supportsLayout && themeLayout?.contentSize
				? [
						...styles,
						{
							css: `.editor-visual-editor__post-title-wrapper > * { max-width: ${ themeLayout.contentSize }; margin-inline: auto; }`,
						},
					]
				: styles,
		[ styles, supportsLayout, themeLayout ]
	);
	const layoutClassName = supportsLayout
		? ' is-layout-constrained has-global-padding'
		: '';

	return (
		<div className="newspack-rolling-coverage-quick-edit__canvas">
			<BlockCanvas height="100%" styles={ canvasStyles }>
				<div
					className={ `editor-visual-editor__post-title-wrapper edit-post-visual-editor__post-title-wrapper${
						supportsLayout ? ' has-global-padding' : ''
					}` }
					style={ { marginTop: '4rem' } }
				>
					<PostTitle
						ref={ titleRef as Ref< { focus: () => void } > }
					/>
				</div>
				<BlockList
					className={ `wp-block-post-content${ layoutClassName }` }
					layout={ layout }
				/>
			</BlockCanvas>
		</div>
	);
}

export { QuickEditCanvas };
