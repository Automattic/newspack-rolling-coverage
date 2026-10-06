/**
 * WordPress dependencies
 */
import {
	InspectorControls,
	useBlockProps,
	useInnerBlocksProps,
} from '@wordpress/block-editor';
import { PanelBody } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { CHECK_UPDATES_BUTTONS_TEMPLATE } from '../shared/check-updates-buttons';

const ALLOWED_BLOCKS = [ 'core/buttons' ];
const TEMPLATE = [ CHECK_UPDATES_BUTTONS_TEMPLATE ];

/**
 * Editor for the Check for Updates block: the locked button, with a note on
 * what the block does to the feed that holds it.
 */
export default function Edit() {
	const blockProps = useBlockProps();
	const innerBlocksProps = useInnerBlocksProps( blockProps, {
		template: TEMPLATE,
		templateLock: 'all',
		allowedBlocks: ALLOWED_BLOCKS,
	} );

	return (
		<>
			<InspectorControls>
				<PanelBody
					title={ __( 'Settings', 'newspack-rolling-coverage' ) }
				>
					<p>
						{ __(
							'The Rolling Coverage feed this block sits in stops checking for new entries on its own. Readers press the button to load them. It doesn’t appear in feeds that show only the latest entries.',
							'newspack-rolling-coverage'
						) }
					</p>
				</PanelBody>
			</InspectorControls>
			<div { ...innerBlocksProps } />
		</>
	);
}
