/**
 * WordPress dependencies
 */
import { useBlockProps } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';

export default function Edit() {
	const blockProps = useBlockProps( {
		className:
			'newspack-rolling-coverage-breakout-post-link wp-element-button',
	} );

	return (
		<a
			{ ...blockProps }
			href="#breakout-post-link-placeholder"
			onClick={ ( event ) => event.preventDefault() }
		>
			{ __( 'Read more', 'newspack-rolling-coverage' ) }
		</a>
	);
}
