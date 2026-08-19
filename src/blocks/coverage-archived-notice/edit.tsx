/**
 * WordPress dependencies
 */
import { useBlockProps, RichText } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';

/**
 * Default notice text, shown until the owner edits it. Mirrors the fallback
 * used by the server-side render when the saved content is empty.
 */
const DEFAULT_TEXT = __(
	'Coverage of this news event has concluded and this feed is now archived.',
	'newspack-rolling-coverage'
);

/**
 * Editor preview for the Archived Notice block.
 *
 * Shown inside the Rolling Coverage block as an inner block at the top of
 * the layout, always editable regardless of the connected coverage's current
 * status. On the front end, it is only rendered while that coverage is
 * archived.
 */
export default function Edit( {
	attributes,
	setAttributes,
}: {
	attributes: { content?: string };
	setAttributes: ( attrs: Partial< { content: string } > ) => void;
} ) {
	const blockProps = useBlockProps( {
		className: 'newspack-rolling-coverage-archived-notice',
	} );

	return (
		<RichText
			tagName="p"
			{ ...blockProps }
			value={ attributes.content || DEFAULT_TEXT }
			onChange={ ( value ) => setAttributes( { content: value } ) }
			placeholder={ DEFAULT_TEXT }
		/>
	);
}
