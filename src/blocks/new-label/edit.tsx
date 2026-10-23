/**
 * WordPress dependencies
 */
import {
	InspectorControls,
	RichText,
	useBlockProps,
} from '@wordpress/block-editor';
import { PanelBody, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { NewLabelAttributes } from './types';

/**
 * Editor for the New Label block: its text, edited in place, with the
 * translated "New" as the placeholder that stands in for empty text.
 *
 * @param {Object}   props               Block props.
 * @param {Object}   props.attributes    Block attributes.
 * @param {Function} props.setAttributes Attribute setter.
 */
export default function Edit( {
	attributes,
	setAttributes,
}: {
	attributes: NewLabelAttributes;
	setAttributes: ( attrs: Partial< NewLabelAttributes > ) => void;
} ) {
	const { content, visuallyHidden } = attributes;
	const blockProps = useBlockProps( {
		className: visuallyHidden ? 'screen-reader-text' : undefined,
	} );

	return (
		<>
			<InspectorControls>
				<PanelBody
					title={ __( 'Settings', 'newspack-rolling-coverage' ) }
				>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Visually hidden',
							'newspack-rolling-coverage'
						) }
						help={ __(
							'Screen readers still announce new entries as new.',
							'newspack-rolling-coverage'
						) }
						checked={ !! visuallyHidden }
						onChange={ ( value ) =>
							setAttributes( { visuallyHidden: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<RichText
				{ ...blockProps }
				identifier="content"
				tagName="p"
				aria-label={ __( 'Label text', 'newspack-rolling-coverage' ) }
				placeholder={
					/* translators: Label on a coverage entry published while the reader has the page open. */
					__( 'New', 'newspack-rolling-coverage' )
				}
				value={ content }
				onChange={ ( value: string ) =>
					setAttributes( { content: value } )
				}
				allowedFormats={ [] }
				withoutInteractiveFormatting
				disableLineBreaks
			/>
		</>
	);
}
