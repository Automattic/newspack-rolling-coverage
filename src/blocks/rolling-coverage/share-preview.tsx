/**
 * WordPress dependencies
 */
import { createHigherOrderComponent } from '@wordpress/compose';
import { addFilter } from '@wordpress/hooks';

/**
 * Internal dependencies
 */
import { ENTRY_BINDINGS_SOURCE } from '../shared/entry-bindings';

/**
 * Marks the share button in the editor so editor.scss can preview it as the
 * server renders it (see Entry_Bindings::show_share_icon()).
 */
const withSharePreview = createHigherOrderComponent(
	( BlockListBlock ) =>
		( props: {
			name: string;
			className?: string;
			attributes: {
				metadata?: {
					bindings?: {
						url?: { source?: string; args?: { key?: string } };
					};
				};
			};
		} ) => {
			const url = props.attributes?.metadata?.bindings?.url;
			const isShare =
				props.name === 'core/button' &&
				url?.source === ENTRY_BINDINGS_SOURCE &&
				url?.args?.key === 'shareUrl';

			return (
				<BlockListBlock
					{ ...props }
					className={
						isShare
							? [
									props.className,
									'newspack-rolling-coverage-share-preview',
								]
									.filter( Boolean )
									.join( ' ' )
							: props.className
					}
				/>
			);
		},
	'withSharePreview'
);

addFilter(
	'editor.BlockListBlock',
	'newspack-rolling-coverage/share-preview',
	withSharePreview
);
