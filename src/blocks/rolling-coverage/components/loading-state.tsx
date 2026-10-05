/**
 * WordPress dependencies
 */
import { speak } from '@wordpress/a11y';
import { Spinner } from '@wordpress/components';
import { useEffect } from '@wordpress/element';

/**
 * Loading state shown in place of the block until its preview is ready,
 * matching the admin screens' loading state.
 *
 * @param {Object}  props          Component props.
 * @param {string}  props.label    What is being fetched, e.g. "Fetching entries…".
 * @param {boolean} props.isSilent Skips the announcement, for a second copy
 *                                 of a loading state already announced.
 */
export default function LoadingState( {
	label,
	isSilent = false,
}: {
	label: string;
	isSilent?: boolean;
} ) {
	// A live region that mounts with its text already in place is announced
	// unreliably, so speak() announces it instead.
	useEffect( () => {
		if ( ! isSilent ) {
			speak( label, 'polite' );
		}
	}, [ label, isSilent ] );

	return (
		<div className="newspack-rolling-coverage-loading">
			<Spinner />
			<p>{ label }</p>
		</div>
	);
}
