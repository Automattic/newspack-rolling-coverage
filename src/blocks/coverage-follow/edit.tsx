/**
 * WordPress dependencies
 */
import { useBlockProps } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';

/**
 * Editor preview for the Coverage Follow Button: a static, non-interactive
 * "Follow" button. The live state and click handling run on the front end.
 */
export default function Edit() {
	const blockProps = useBlockProps( {
		className:
			'newspack-rolling-coverage-follow wp-element-button wp-block-button__link',
		'aria-pressed': 'false',
		type: 'button',
	} );

	return (
		<button { ...blockProps }>
			{ __( 'Follow', 'newspack-rolling-coverage' ) }
		</button>
	);
}
