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
 * @param {Object}  props         Component props.
 * @param {string}  props.label   What is being fetched, e.g. "Fetching entries…".
 * @param {boolean} props.compact Sizes it to stand in for a form field.
 */
export default function LoadingState( {
	label,
	compact = false,
}: {
	label: string;
	compact?: boolean;
} ) {
	// A live region that mounts with its text already in place is announced
	// unreliably, so speak() announces it instead.
	useEffect( () => {
		speak( label, 'polite' );
	}, [ label ] );

	return (
		<div
			className={
				compact
					? 'newspack-rolling-coverage-loading is-compact'
					: 'newspack-rolling-coverage-loading'
			}
		>
			<Spinner />
			<p>{ label }</p>
		</div>
	);
}
