/**
 * WordPress dependencies
 */
import { useBlockProps } from '@wordpress/block-editor';
import { createHigherOrderComponent } from '@wordpress/compose';
import { createContext, useContext } from '@wordpress/element';
import { addFilter } from '@wordpress/hooks';

/**
 * Internal dependencies
 */
import { isEntryLinkTitle } from './template';

/**
 * The entries an editable layout previews without a title, by post ID, each
 * with its opening words, set by the Rolling Coverage block around its inner
 * blocks (see useLayoutPreview()).
 */
const UntitledEntriesContext = createContext< ReadonlyMap< number, string > >(
	new Map()
);

/**
 * A title that links to its entry, as an untitled entry renders it: the
 * entry's opening words as the link (see withEntryLinkTitleText()).
 *
 * @param {Object} props       Component props.
 * @param {number} props.level The title's heading level, 0 for a paragraph.
 * @param {string} props.text  The entry's opening words.
 */
function EntryLinkTitle( { level, text }: { level: number; text: string } ) {
	const TagName = ( level === 0 ? 'p' : `h${ level }` ) as 'p';
	const blockProps = useBlockProps();

	return (
		<TagName { ...blockProps }>
			<a
				href="#entry-link-placeholder"
				onClick={ ( event ) => event.preventDefault() }
			>
				{ text }
			</a>
		</TagName>
	);
}

/**
 * Previews the editable layout's Post Title against an untitled entry as the
 * site renders it: nothing, where core's editor would show "(no title)", or
 * for a title that links to its entry, the entry's opening words, and nothing
 * without them, as for a protected entry. The editable groups preview an
 * untitled entry when no titled one is left for them, as the Entry group
 * does among the layout picker's three samples.
 */
const withUntitledEntryTitle = createHigherOrderComponent(
	( BlockEdit ) =>
		( props: {
			name: string;
			attributes: Record< string, unknown >;
			context?: Record< string, unknown >;
		} ) => {
			const untitledEntries = useContext( UntitledEntriesContext );
			const text = untitledEntries.get( props.context?.postId as number );

			if ( props.name !== 'core/post-title' || text === undefined ) {
				return <BlockEdit { ...props } />;
			}

			if ( ! text || ! isEntryLinkTitle( props ) ) {
				return null;
			}

			return (
				<EntryLinkTitle
					level={ ( props.attributes.level as number ) ?? 2 }
					text={ text }
				/>
			);
		},
	'withUntitledEntryTitle'
);

addFilter(
	'editor.BlockEdit',
	'newspack-rolling-coverage/untitled-entry-title',
	withUntitledEntryTitle
);

export default UntitledEntriesContext;
