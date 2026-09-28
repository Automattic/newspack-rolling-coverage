/**
 * WordPress dependencies
 */
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import {
	ONESIGNAL_INSTALLED,
	ONESIGNAL_V3_ACTIVE,
	ONESIGNAL_CONFIGURED,
} from './config';
import { OneSignalNotice } from '../shared/onesignal-notice';

/**
 * Editor preview for the Coverage Follow Button: a static, non-interactive
 * "Follow" button. The live state and click handling run on the front end.
 *
 * When OneSignal isn't installed or configured the button won't render on the
 * site, so the inspector shows a notice explaining why.
 */
export default function Edit() {
	const blockProps = useBlockProps( {
		className:
			'newspack-rolling-coverage-follow wp-element-button wp-block-button__link',
		'aria-pressed': 'false',
		type: 'button',
	} );

	return (
		<>
			{ ! ONESIGNAL_CONFIGURED && (
				<InspectorControls>
					<PanelBody
						title={ __(
							'Push Notifications',
							'newspack-rolling-coverage'
						) }
					>
						<OneSignalNotice
							installed={ ONESIGNAL_INSTALLED }
							v3Active={ ONESIGNAL_V3_ACTIVE }
						/>
					</PanelBody>
				</InspectorControls>
			) }
			<button { ...blockProps }>
				{ __( 'Follow', 'newspack-rolling-coverage' ) }
			</button>
		</>
	);
}
