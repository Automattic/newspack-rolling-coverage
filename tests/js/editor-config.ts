/**
 * The block's editor config (`Rolling_Coverage_Block::localize_block_config()`),
 * which its modules read as they load. The built-in layouts take only the
 * site's name for entries from it, and the server copies leave that to the
 * site, so it stays empty.
 */
(
	window as unknown as Record< string, unknown >
 ).newspackRollingCoverageBlock = {
	layoutIds: {},
	entryPlural: '',
};
