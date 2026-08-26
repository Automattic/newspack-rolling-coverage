/**
 * WordPress dependencies
 */
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, Notice, ExternalLink } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import {
	ONESIGNAL_INSTALLED,
	ONESIGNAL_V3_ACTIVE,
	ONESIGNAL_CONFIGURED,
} from './config';

/**
 * Picks the notice message explaining why the button won't render, matching
 * whichever of the three states is actually blocking it: not installed, on
 * OneSignal's unsupported v2 (this integration requires v3), or installed on
 * v3 but not yet configured with app credentials.
 *
 * @return {string} Notice message.
 */
function getUnconfiguredNoticeMessage(): string {
	if ( ! ONESIGNAL_INSTALLED ) {
		return __(
			'Install and activate the OneSignal Push Notifications plugin for this button to appear on the site.',
			'newspack-rolling-coverage'
		);
	}

	if ( ! ONESIGNAL_V3_ACTIVE ) {
		return __(
			'The OneSignal Push Notifications plugin is on an unsupported version — update it to the latest version for this button to appear on the site.',
			'newspack-rolling-coverage'
		);
	}

	return __(
		'Configure the OneSignal Push Notifications plugin (App ID and REST API Key) for this button to appear on the site.',
		'newspack-rolling-coverage'
	);
}

/**
 * Editor preview for the Coverage Follow Button: a static, non-interactive
 * "Follow" button. The live state and click handling run on the front end.
 *
 * When OneSignal isn't installed or configured the button won't render on the
 * site, so the inspector shows a notice explaining why.
 */
export default function Edit() {
	const blockProps = useBlockProps( {
		className: 'newspack-rolling-coverage-follow wp-element-button',
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
						<Notice status="warning" isDismissible={ false }>
							{ getUnconfiguredNoticeMessage() }{ ' ' }
							<ExternalLink href="https://documentation.onesignal.com/docs/en/wordpress">
								{ __(
									'Setup guide',
									'newspack-rolling-coverage'
								) }
							</ExternalLink>
						</Notice>
					</PanelBody>
				</InspectorControls>
			) }
			<button { ...blockProps }>
				{ __( 'Follow', 'newspack-rolling-coverage' ) }
			</button>
		</>
	);
}
