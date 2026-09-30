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
 * @param {Object} props       Component props.
 * @param {string} props.label What is being fetched, e.g. "Fetching entries…".
 */
export default function LoadingState( { label }: { label: string } ) {
	// A live region that mounts with its text already in place is announced
	// unreliably, so speak() announces it instead.
	useEffect( () => {
		speak( label, 'polite' );
	}, [ label ] );

	return (
		<div className="newspack-rolling-coverage-loading">
			<Spinner />
			<p>{ label }</p>
		</div>
	);
}
