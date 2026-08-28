/**
 * WordPress dependencies
 */
import {
	useBlockProps,
	useInnerBlocksProps,
	RichText,
	BlockContextProvider,
} from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';

/**
 * Default inner-blocks template for the modal content.
 * Defines the layout rendered inside the modal on the front end.
 */
const MODAL_TEMPLATE = [
	[ 'core/post-title', { level: 3 } ],
	[ 'core/post-date' ],
	[ 'core/post-content' ],
	[ 'core/spacer', { height: '20px' } ],
	[ 'core/post-author-name' ],
];

/**
 * Neutral block context used so inner blocks (e.g. core/post-title) can
 * render in the editor even when no real entry is selected.
 */
const NEUTRAL_MODAL_CONTEXT = {
	postId: 0,
	postType: 'rolling_cov_entry',
};

/**
 * Editor preview for the Deep Link CTA block.
 *
 * Shown inside the Rolling Coverage block as an inner block at the top
 * of the layout. On the front end, it is shown when a shared deep link
 * points to an entry that is not in the initially rendered set.
 * Clicking the button opens a modal with the full entry content.
 */
export default function Edit( {
	attributes,
	setAttributes,
}: {
	attributes: {
		ctaText?: string;
		buttonText?: string;
	};
	setAttributes: (
		attrs: Partial< { ctaText: string; buttonText: string } >
	) => void;
} ) {
	const blockProps = useBlockProps( {
		className: 'newspack-rolling-coverage-cta',
	} );

	const innerBlocksProps = useInnerBlocksProps(
		{ className: 'newspack-rolling-coverage-cta__modal-template' },
		{
			template: MODAL_TEMPLATE,
			templateLock: false,
		}
	);

	return (
		<div { ...blockProps }>
			<div className="newspack-rolling-coverage-cta__preview">
				<p className="newspack-rolling-coverage-cta__preview-label">
					{ __(
						'Modal content template:',
						'newspack-rolling-coverage'
					) }
				</p>
				<BlockContextProvider value={ NEUTRAL_MODAL_CONTEXT }>
					<div { ...innerBlocksProps } />
				</BlockContextProvider>
			</div>
			<RichText
				tagName="p"
				className="newspack-rolling-coverage-cta__text"
				value={
					attributes.ctaText ||
					__(
						'You linked to an older entry ({{entry_title}}).',
						'newspack-rolling-coverage'
					)
				}
				onChange={ ( value ) => setAttributes( { ctaText: value } ) }
				placeholder={ __(
					'Enter CTA text… Use {{entry_title}} as a placeholder for the entry title.',
					'newspack-rolling-coverage'
				) }
				allowedFormats={ [] }
			/>
			<RichText
				tagName="button"
				type="button"
				className="newspack-rolling-coverage-cta__button wp-element-button"
				value={
					attributes.buttonText ||
					__( 'View', 'newspack-rolling-coverage' )
				}
				onChange={ ( value ) => setAttributes( { buttonText: value } ) }
				placeholder={ __( 'View', 'newspack-rolling-coverage' ) }
				allowedFormats={ [] }
				onClick={ ( event ) => event.preventDefault() }
			/>
		</div>
	);
}
