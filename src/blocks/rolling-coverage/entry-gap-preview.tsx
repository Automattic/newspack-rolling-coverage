/**
 * WordPress dependencies
 */
import { createHigherOrderComponent } from '@wordpress/compose';
import { addFilter } from '@wordpress/hooks';

/**
 * Internal dependencies
 */
import { blockGapCss } from './spacing';
import { isPinnedCard, isRegularEntry } from './template';

/**
 * Gives the pinned card and the entry group the space between their blocks
 * that editor.scss reads, as the server lays them out from their own Block
 * spacing (see Rolling_Coverage_Block::entry_layout_class()).
 */
const withEntryGapPreview = createHigherOrderComponent(
	( BlockListBlock ) =>
		( props: {
			name: string;
			attributes: {
				style?: { spacing?: { blockGap?: string | { top?: string } } };
			};
			wrapperProps?: { style?: Record< string, string > };
		} ) => {
			const gap =
				( isPinnedCard( props ) || isRegularEntry( props ) ) &&
				blockGapCss( props.attributes?.style?.spacing?.blockGap );

			if ( ! gap ) {
				return <BlockListBlock { ...props } />;
			}

			return (
				<BlockListBlock
					{ ...props }
					wrapperProps={ {
						...props.wrapperProps,
						style: {
							...props.wrapperProps?.style,
							'--newspack-rolling-coverage-entry-gap': gap,
						},
					} }
				/>
			);
		},
	'withEntryGapPreview'
);

addFilter(
	'editor.BlockListBlock',
	'newspack-rolling-coverage/entry-gap-preview',
	withEntryGapPreview
);
