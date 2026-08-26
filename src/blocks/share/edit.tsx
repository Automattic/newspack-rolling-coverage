/**
 * WordPress dependencies
 */
import { useBlockProps, RichText } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';

export default function Edit( {
	attributes,
	setAttributes,
}: {
	attributes: { label?: string };
	setAttributes: ( attrs: Partial< { label: string } > ) => void;
} ) {
	const blockProps = useBlockProps( {
		className: 'newspack-rolling-coverage-share-link wp-element-button',
	} );

	return (
		<RichText
			{ ...blockProps }
			tagName="button"
			type="button"
			aria-label={ __( 'Share this entry', 'newspack-rolling-coverage' ) }
			value={
				attributes.label || __( 'Share', 'newspack-rolling-coverage' )
			}
			onChange={ ( value ) => setAttributes( { label: value } ) }
			placeholder={ __( 'Share', 'newspack-rolling-coverage' ) }
			allowedFormats={ [] }
			onClick={ ( event ) => event.preventDefault() }
		/>
	);
}
