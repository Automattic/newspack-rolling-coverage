/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * The site's own name for entries, as Entry_Name::for_script() writes it to
 * the block wrapper: each word as it reads mid-sentence.
 */
export interface EntryName {
	singular: string;
	plural: string;
}

/**
 * Reads the site's name for entries from a block wrapper.
 *
 * @param {HTMLElement} root The block's outer wrapper element.
 * @return {EntryName | null} The name, or null when the site sets none.
 */
export function readEntryName( root: HTMLElement ): EntryName | null {
	if ( ! root.dataset.entryName ) {
		return null;
	}

	try {
		const name = JSON.parse( root.dataset.entryName ) as EntryName;

		return [ name.singular, name.plural ].every(
			( word ) => typeof word === 'string' && word !== ''
		)
			? name
			: null;
	} catch {
		return null;
	}
}

/**
 * The count of new entries: the control's label while they wait to be
 * shown, and the Update Timer's text for a moment after a check brings them.
 *
 * @param {number}         count How many new entries there are.
 * @param {EntryName|null} name  The site's name for entries.
 * @return {string} The label.
 */
export function newEntriesLabel(
	count: number,
	name: EntryName | null
): string {
	if ( name ) {
		return sprintf(
			/* translators: 1: number of new liveblog entries, waiting to be shown or just found by a check. 2: the site's own name for entries, singular or plural to match the number, as it reads mid-sentence. */
			_n(
				'%1$d new %2$s',
				'%1$d new %2$s',
				count,
				'newspack-rolling-coverage'
			),
			count,
			count === 1 ? name.singular : name.plural
		);
	}

	return sprintf(
		/* translators: %d: number of new liveblog entries, waiting to be shown or just found by a check. */
		_n(
			'%d new entry',
			'%d new entries',
			count,
			'newspack-rolling-coverage'
		),
		count
	);
}

/**
 * The Check for Updates button's label, for a moment, after a check added
 * entries.
 *
 * @param {number}         count How many entries the check added.
 * @param {EntryName|null} name  The site's name for entries.
 * @return {string} The label.
 */
export function entriesAddedButtonLabel(
	count: number,
	name: EntryName | null
): string {
	if ( name ) {
		return sprintf(
			/* translators: 1: number of liveblog entries a check just added. 2: the site's own name for entries, singular or plural to match the number, as it reads mid-sentence. */
			_n(
				'%1$d %2$s added',
				'%1$d %2$s added',
				count,
				'newspack-rolling-coverage'
			),
			count,
			count === 1 ? name.singular : name.plural
		);
	}

	return sprintf(
		/* translators: %d: number of liveblog entries a check just added. */
		_n(
			'%d entry added',
			'%d entries added',
			count,
			'newspack-rolling-coverage'
		),
		count
	);
}

/**
 * The Check for Updates button's label, for a moment, when a check finds no
 * new entries.
 *
 * @param {EntryName|null} name The site's name for entries.
 * @return {string} The label.
 */
export function noNewEntriesLabel( name: EntryName | null ): string {
	if ( name ) {
		return sprintf(
			/* translators: %s: the site's own name for liveblog entries, plural, as it reads mid-sentence. */
			__( 'No new %s', 'newspack-rolling-coverage' ),
			name.plural
		);
	}

	return __( 'No new entries', 'newspack-rolling-coverage' );
}

/**
 * The label of the control on a feed opened at a shared entry: the number of
 * newer entries, exact up to ten and from there the round number it has
 * passed, e.g. "10+ newer entries" for 11 to 50. Mirrors
 * Rolling_Coverage_Block::newer_entries_label().
 *
 * @param {number}         count How many entries are newer.
 * @param {EntryName|null} name  The site's name for entries.
 * @return {string} The label, or an empty string when there are none.
 */
export function newerEntriesLabel(
	count: number,
	name: EntryName | null
): string {
	if ( count < 1 ) {
		return '';
	}

	if ( count <= 10 ) {
		if ( name ) {
			return sprintf(
				/* translators: 1: number of liveblog entries newer than the one shown, from 1 to 10. 2: the site's own name for entries, singular or plural to match the number, as it reads mid-sentence. */
				_n(
					'%1$d newer %2$s',
					'%1$d newer %2$s',
					count,
					'newspack-rolling-coverage'
				),
				count,
				count === 1 ? name.singular : name.plural
			);
		}

		return sprintf(
			/* translators: %d: number of liveblog entries newer than the one shown, from 1 to 10. */
			_n(
				'%d newer entry',
				'%d newer entries',
				count,
				'newspack-rolling-coverage'
			),
			count
		);
	}

	let floor = 10;

	if ( count > 100 ) {
		floor = 100;
	} else if ( count > 50 ) {
		floor = 50;
	}

	if ( name ) {
		return sprintf(
			/* translators: 1: a round number the count of newer liveblog entries has passed: 10, 50 or 100. 2: the site's own name for entries, plural, as it reads mid-sentence. */
			_n(
				'%1$d+ newer %2$s',
				'%1$d+ newer %2$s',
				floor,
				'newspack-rolling-coverage'
			),
			floor,
			name.plural
		);
	}

	return sprintf(
		/* translators: %d: a round number the count of newer liveblog entries has passed: 10, 50 or 100. */
		_n(
			'%d+ newer entry',
			'%d+ newer entries',
			floor,
			'newspack-rolling-coverage'
		),
		floor
	);
}

/**
 * Announced when new entries are added to the feed.
 *
 * @param {number}         count How many entries were added.
 * @param {EntryName|null} name  The site's name for entries.
 * @return {string} The announcement.
 */
export function entriesAddedLabel(
	count: number,
	name: EntryName | null
): string {
	if ( name ) {
		return sprintf(
			/* translators: 1: number of new liveblog entries just added. 2: the site's own name for entries, singular or plural to match the number, as it reads mid-sentence. */
			_n(
				'%1$d new %2$s added',
				'%1$d new %2$s added',
				count,
				'newspack-rolling-coverage'
			),
			count,
			count === 1 ? name.singular : name.plural
		);
	}

	return sprintf(
		/* translators: %d: number of new liveblog entries just added. */
		_n(
			'%d new entry added',
			'%d new entries added',
			count,
			'newspack-rolling-coverage'
		),
		count
	);
}

/**
 * Announced while the live feed loads after the reader jumps to it.
 *
 * @param {EntryName|null} name The site's name for entries.
 * @return {string} The announcement.
 */
export function loadingLatestLabel( name: EntryName | null ): string {
	if ( name ) {
		return sprintf(
			/* translators: %s: the site's own name for liveblog entries, plural, as it reads mid-sentence. */
			__( 'Loading the latest %s…', 'newspack-rolling-coverage' ),
			name.plural
		);
	}

	return __( 'Loading the latest entries…', 'newspack-rolling-coverage' );
}

/**
 * Announced once the live feed shows after the reader jumps to it.
 *
 * @param {EntryName|null} name The site's name for entries.
 * @return {string} The announcement.
 */
export function showingLatestLabel( name: EntryName | null ): string {
	if ( name ) {
		return sprintf(
			/* translators: %s: the site's own name for liveblog entries, plural, as it reads mid-sentence. */
			__( 'Showing the latest %s.', 'newspack-rolling-coverage' ),
			name.plural
		);
	}

	return __( 'Showing the latest entries.', 'newspack-rolling-coverage' );
}

/**
 * Announced when pressing the Load more button fails to load older entries.
 *
 * @param {EntryName|null} name The site's name for entries.
 * @return {string} The announcement.
 */
export function loadMoreFailedLabel( name: EntryName | null ): string {
	if ( name ) {
		return sprintf(
			/* translators: %s: the site's own name for liveblog entries, plural, as it reads mid-sentence. */
			__(
				'Couldn’t load more %s. Try again.',
				'newspack-rolling-coverage'
			),
			name.plural
		);
	}

	return __(
		'Couldn’t load more entries. Try again.',
		'newspack-rolling-coverage'
	);
}

/**
 * The Update Timer's text while it counts down to the next check.
 *
 * @param {number} seconds Whole seconds until the check.
 * @return {string} The text.
 */
export function nextCheckLabel( seconds: number ): string {
	return sprintf(
		/* translators: %d: seconds until the page next checks for new liveblog entries; the trailing "s" abbreviates seconds. */
		__( 'Next check in %ds', 'newspack-rolling-coverage' ),
		seconds
	);
}
