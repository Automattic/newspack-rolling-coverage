/**
 * Which entries count as new in a capped feed's whole-list reply: those
 * listed above the first entry the page already showed. One listed below
 * that, such as an older entry backfilled after a takedown, isn't new to the
 * reader, and neither is a taken-down entry coming back. When the reply keeps
 * none of the entries the page showed, only those dated after the newest one
 * it showed are new, or all of them when it never showed one.
 *
 * @param {string[]}         keptIds      The reply's entry IDs, newest first.
 * @param {Set<string>}      shownIds     The IDs of the entries the page showed.
 * @param {Set<string>}      returningIds The IDs of taken-down entries the reply brings back.
 * @param {Set<string>|null} newerIds     The IDs dated after the newest entry the page showed, or null when it showed none.
 * @return {string[]} The new entries' IDs.
 */
export function newIdsInReplace(
	keptIds: string[],
	shownIds: Set< string >,
	returningIds: Set< string > = new Set(),
	newerIds: Set< string > | null = null
): string[] {
	const firstShown = keptIds.findIndex( ( id ) => shownIds.has( id ) );
	let candidates = keptIds.slice( 0, firstShown );

	if ( firstShown === -1 ) {
		candidates = newerIds
			? keptIds.filter( ( id ) => newerIds.has( id ) )
			: keptIds;
	}

	return candidates.filter( ( id ) => ! returningIds.has( id ) );
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
