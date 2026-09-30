/**
 * WordPress dependencies
 */
import { speak } from '@wordpress/a11y';
import { Spinner, Stack, Text } from '@wordpress/ui';
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
		<Stack
			className="newspack-rolling-coverage-loading"
			direction="column"
			align="center"
			justify="center"
			gap="md"
		>
			<Spinner />
			<Text variant="heading-md" render={ <p /> }>
				{ label }
			</Text>
		</Stack>
	);
}

export { LoadingState };
