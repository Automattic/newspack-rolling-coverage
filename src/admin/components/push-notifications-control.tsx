/**
 * External dependencies
 */
import {
	CheckboxControl,
	Notice,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { __ } from '@wordpress/i18n';

const NOTIFY_META_KEY = 'rolling_coverage_notify_on_publish';
const NOTIFIABLE_FIELD = 'rolling_coverage_has_notifiable_coverage';

type EditorPostSelectors = {
	getCurrentPostType: () => string;
	getCurrentPost: () => Record< string, unknown >;
	getEditedPostAttribute: ( attribute: string ) => unknown;
};

/**
 * Whether the entry being edited still takes the notify opt-in: an entry
 * that isn't published yet. A published entry has had its chance to notify.
 *
 * @return {boolean} Whether to show the Push Notifications control.
 */
function useCanOptInToNotify(): boolean {
	return useSelect( ( select ) => {
		const editor = select( editorStore ) as unknown as EditorPostSelectors;
		return (
			editor.getCurrentPostType() === 'rolling_cov_entry' &&
			editor.getCurrentPost().status !== 'publish'
		);
	}, [] );
}

/**
 * The opt-in to notify the coverage's followers when the entry publishes,
 * with a warning when its coverage has no canonical URL to send them to.
 * Shared by the entry editor's Push Notifications panel and Quick Edit.
 */
function PushNotificationsControl() {
	const { isOptedIn, hasNotifiableCoverage } = useSelect( ( select ) => {
		const editor = select( editorStore ) as unknown as EditorPostSelectors;
		const meta = editor.getEditedPostAttribute( 'meta' ) as
			Record< string, unknown > | undefined;
		return {
			isOptedIn: Boolean( meta?.[ NOTIFY_META_KEY ] ),
			hasNotifiableCoverage: editor.getCurrentPost()[ NOTIFIABLE_FIELD ],
		};
	}, [] );
	const { editPost } = useDispatch( editorStore );

	const warning = __(
		'This entry’s coverage doesn’t have a canonical URL set yet. Set one in the coverage’s settings, or no notification will be sent.',
		'newspack-rolling-coverage'
	);

	return (
		<VStack spacing={ 4 }>
			{ hasNotifiableCoverage === false && (
				<Notice
					status="warning"
					isDismissible={ false }
					spokenMessage={ warning }
				>
					{ warning }
				</Notice>
			) }
			<CheckboxControl
				__nextHasNoMarginBottom
				label={ __(
					'Notify subscribers when this entry publishes',
					'newspack-rolling-coverage'
				) }
				checked={ isOptedIn }
				onChange={ ( checked ) =>
					editPost( { meta: { [ NOTIFY_META_KEY ]: checked } } )
				}
			/>
		</VStack>
	);
}

export { PushNotificationsControl, useCanOptInToNotify };
