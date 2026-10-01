/**
 * WordPress dependencies
 */
import { useBlockProps } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';

export default function Edit() {
	const blockProps = useBlockProps( {
		className:
			'newspack-rolling-coverage-breakout-post-link wp-element-button wp-block-button__link',
	} );

	return (
		<a
			{ ...blockProps }
			href="#breakout-post-link-placeholder"
			onClick={ ( event ) => event.preventDefault() }
		>
			{ __( 'Read More', 'newspack-rolling-coverage' ) }
		</a>
	);
}
