/**
 * Which entries count as new in a capped feed's whole-list reply: those
 * listed above the first entry the page already showed, or all of them when
 * it showed none. One listed below that, such as an older entry backfilled
 * after a takedown, isn't new to the reader.
 *
 * @param {string[]}    keptIds  The reply's entry IDs, newest first.
 * @param {Set<string>} shownIds The IDs of the entries the page showed.
 * @return {string[]} The new entries' IDs.
 */
export function newIdsInReplace(
	keptIds: string[],
	shownIds: Set< string >
): string[] {
	const firstShown = keptIds.findIndex( ( id ) => shownIds.has( id ) );

	return firstShown === -1 ? keptIds : keptIds.slice( 0, firstShown );
}

/**
 * The entries dated after a GMT date, compared as `Y-m-d H:i:s` strings.
 *
 * @param {Array<{id: string, dateGmt: string}>} entries  The entries.
 * @param {string}                               sinceGmt The date.
 * @return {string[]} The newer entries' IDs.
 */
export function idsNewerThan(
	entries: Array< { id: string; dateGmt: string } >,
	sinceGmt: string
): string[] {
	if ( ! sinceGmt ) {
		return [];
	}

	return entries
		.filter( ( { dateGmt } ) => !! dateGmt && dateGmt > sinceGmt )
		.map( ( { id } ) => id );
}
