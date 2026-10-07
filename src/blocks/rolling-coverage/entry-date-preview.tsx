/**
 * WordPress dependencies
 */
import { createHigherOrderComponent } from '@wordpress/compose';
import { getDate } from '@wordpress/date';
import { useMemo } from '@wordpress/element';
import { addFilter } from '@wordpress/hooks';

/**
 * Internal dependencies
 */
import { ENTRY_POST_TYPE } from './config';

const LOCAL_DATETIME = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/;

type PostDateEditProps = {
	name: string;
	context?: { postType?: string };
	attributes: {
		datetime?: unknown;
		metadata?: { bindings?: { datetime?: { source?: string } } };
	};
};

/**
 * Shows an entry's Post Date at the site's time in the editor. The
 * `core/post-data` binding hands Post Date the entry's local `date` or
 * `modified`, which carry no offset, and Post Date formats them as if they
 * were in the browser's time zone, so an editor in another zone saw formatted
 * times shifted. Reading the value in the site's zone gives the time the site
 * shows. A value with an offset is left alone.
 */
const withEntrySiteDate = createHigherOrderComponent( ( BlockEdit ) => {
	const SiteDateEdit = (
		props: PostDateEditProps & { datetime: string }
	) => {
		const { datetime, ...rest } = props;
		const attributes = useMemo(
			() => ( {
				...rest.attributes,
				datetime: getDate( datetime ),
			} ),
			[ rest.attributes, datetime ]
		);

		return <BlockEdit { ...rest } attributes={ attributes } />;
	};

	return ( props: PostDateEditProps ) => {
		const { datetime, metadata } = props.attributes ?? {};

		if (
			props.name !== 'core/post-date' ||
			props.context?.postType !== ENTRY_POST_TYPE ||
			metadata?.bindings?.datetime?.source !== 'core/post-data' ||
			typeof datetime !== 'string' ||
			! LOCAL_DATETIME.test( datetime )
		) {
			return <BlockEdit { ...props } />;
		}

		return <SiteDateEdit { ...props } datetime={ datetime } />;
	};
}, 'withEntrySiteDate' );

addFilter(
	'editor.BlockEdit',
	'newspack-rolling-coverage/entry-site-date',
	withEntrySiteDate
);
