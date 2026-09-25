/**
 * WordPress dependencies
 */
import { speak } from '@wordpress/a11y';
import {
	Spinner,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalText as Text,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { useEffect } from '@wordpress/element';

/**
 * Loading state for a view's first fetch, before there is a list to show.
 * Once DataViews has rendered, its own loading treatment takes over.
 *
 * @param props       Component props.
 * @param props.label What is being fetched, e.g. "Fetching coverages…".
 */
function LoadingState( { label }: { label: string } ) {
	// A live region that mounts with its text already in place is announced
	// unreliably, so speak() announces it instead.
	useEffect( () => {
		speak( label, 'polite' );
	}, [ label ] );

	return (
		<VStack
			className="newspack-rolling-coverage-loading"
			alignment="center"
			spacing={ 3 }
		>
			<Spinner />
			<Text as="p">{ label }</Text>
		</VStack>
	);
}

export { LoadingState };
