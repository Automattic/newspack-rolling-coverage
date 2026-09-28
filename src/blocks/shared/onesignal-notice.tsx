/**
 * WordPress dependencies
 */
import { Notice, ExternalLink } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Explains why the Follow button won't appear on the site, matching whichever
 * state is blocking it: OneSignal not installed, on its unsupported v2 (this
 * integration requires v3), or installed on v3 but missing app credentials.
 *
 * @param {Object}  props           Component props.
 * @param {boolean} props.installed Whether the OneSignal plugin is installed.
 * @param {boolean} props.v3Active  Whether OneSignal v3 is active.
 */
function OneSignalNotice( {
	installed,
	v3Active,
}: {
	installed: boolean;
	v3Active: boolean;
} ) {
	let message: string = __(
		'Configure the OneSignal Push Notifications plugin (App ID and REST API Key) for the Follow button to appear on the site.',
		'newspack-rolling-coverage'
	);

	if ( ! installed ) {
		message = __(
			'Install and activate the OneSignal Push Notifications plugin for the Follow button to appear on the site.',
			'newspack-rolling-coverage'
		);
	} else if ( ! v3Active ) {
		message = __(
			'The OneSignal Push Notifications plugin is on an unsupported version. Update it to the latest version for the Follow button to appear on the site.',
			'newspack-rolling-coverage'
		);
	}

	return (
		<Notice
			className="newspack-rolling-coverage-onesignal-notice"
			status="warning"
			isDismissible={ false }
			spokenMessage={ message }
		>
			<p>{ message }</p>
			<p>
				<ExternalLink href="https://documentation.onesignal.com/docs/en/wordpress">
					{ __( 'Setup guide', 'newspack-rolling-coverage' ) }
				</ExternalLink>
			</p>
		</Notice>
	);
}

export { OneSignalNotice };
