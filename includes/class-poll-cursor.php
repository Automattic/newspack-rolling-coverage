<?php
/**
 * Where a page's copy of a coverage's entries stands, for polling.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * A poll cursor: the second of the newest change a page holds, the entries
 * it holds as saved in that second, and the coverage's change marker when it
 * took its copy. A poll sends only the changes the page is missing.
 *
 * Entries saved in the same second don't become visible in ID order: the
 * entries list publishes a selection in parallel requests. So neither the
 * second alone nor the second and one entry ID can tell the entries a page
 * holds from ones saved in that second after it polled; the cursor names
 * them all. Saving an entry again within the second the page holds it in is
 * picked up on its next save, and so is a save stamped in the second before
 * the cursor's that only becomes visible after it, which parallel requests
 * straddling a second boundary can produce.
 *
 * The coverage's last-modified time stays put through saves within one
 * second, so it can't tell an idle poll that nothing changed. The marker,
 * which every change readers can see replaces, can.
 *
 * As a string: `{ids}:{Y-m-d H:i:s}@{marker}`, the IDs comma-separated, or
 * `0` for none, and the marker empty before the coverage's first change.
 * Clients pass it back as they got it.
 */
class Poll_Cursor {

	// Term meta replaced on every change to a coverage's entries that readers can see.
	const MARKER_META_KEY = 'rolling_coverage_change_marker';

	/**
	 * GMT `Y-m-d H:i:s` of the newest change the page holds.
	 *
	 * @var string
	 */
	public $modified;

	/**
	 * Entries the page holds as saved in that second, ascending.
	 *
	 * @var int[]
	 */
	public $ids;

	/**
	 * The coverage's change marker when the page took its copy: '' before
	 * the coverage's first change, or null for a cursor from before markers
	 * existed, which is never current.
	 *
	 * @var string|null
	 */
	public $marker;

	/**
	 * Constructor.
	 *
	 * @param string      $modified GMT `Y-m-d H:i:s` of the newest change the page holds.
	 * @param int[]       $ids      Entries the page holds as saved in that second.
	 * @param string|null $marker   The coverage's change marker when the page took its copy.
	 */
	public function __construct( string $modified, array $ids = [], ?string $marker = null ) {
		$ids = array_unique( array_filter( array_map( 'intval', $ids ), static fn( int $id ) => $id > 0 ) );
		sort( $ids );

		$this->modified = $modified;
		$this->ids      = $ids;
		$this->marker   = $marker;
	}

	/**
	 * Reads a cursor a page sent. A cursor with no `@`, from before markers
	 * existed, is never current, so its first poll looks for changes.
	 *
	 * @param string $cursor Cursor string.
	 * @return self
	 */
	public static function parse( string $cursor ): self {
		$marker = null;
		$at     = strrpos( $cursor, '@' );

		if ( false !== $at ) {
			$marker = substr( $cursor, $at + 1 );
			$cursor = substr( $cursor, 0, $at );
		}

		$parts = explode( ':', $cursor, 2 );

		return new self( $parts[1] ?? '', explode( ',', $parts[0] ), $marker );
	}

	/**
	 * The cursor for a page showing these entries, taken when the coverage
	 * carried this marker.
	 *
	 * @param WP_Post[] $entries Entries on the page.
	 * @param string    $marker  The coverage's change marker, read before the entries were.
	 * @return self
	 */
	public static function for_entries( array $entries, string $marker ): self {
		if ( ! $entries ) {
			return new self( gmdate( 'Y-m-d H:i:s' ), [], $marker );
		}

		return ( new self( '' ) )->advance( $entries, $marker );
	}

	/**
	 * The cursor once the page also holds these changes, taken when the
	 * coverage carried this marker.
	 *
	 * @param WP_Post[] $entries Changes the page receives, entries or removals.
	 * @param string    $marker  The coverage's change marker, read before the changes were.
	 * @return self
	 */
	public function advance( array $entries, string $marker ): self {
		$modified = $this->modified;
		$ids      = $this->ids;

		foreach ( $entries as $entry ) {
			if ( $entry->post_modified_gmt > $modified ) {
				$modified = $entry->post_modified_gmt;
				$ids      = [ $entry->ID ];
			} elseif ( $entry->post_modified_gmt === $modified ) {
				$ids[] = $entry->ID;
			}
		}

		return new self( $modified, $ids, $marker );
	}

	/**
	 * The cursor also holding these entries from its second.
	 *
	 * @param int[] $ids Entries saved in the cursor's second.
	 * @return self
	 */
	public function holding( array $ids ): self {
		return new self( $this->modified, array_merge( $this->ids, $ids ), $this->marker );
	}

	/**
	 * Whether nothing has changed in the coverage since the page took its copy.
	 *
	 * @param string $marker The coverage's change marker now.
	 * @return bool
	 */
	public function is_current( string $marker ): bool {
		return null !== $this->marker && $this->marker === $marker;
	}

	/**
	 * Whether the page already holds an entry as it's saved now.
	 *
	 * @param WP_Post $entry Entry post object.
	 * @return bool
	 */
	public function holds( WP_Post $entry ): bool {
		return $entry->post_modified_gmt === $this->modified && in_array( $entry->ID, $this->ids, true );
	}

	/**
	 * Whether a published entry is new to the page: first published after
	 * the cursor's second, or in it without the page holding it.
	 *
	 * @param WP_Post $entry Entry post object.
	 * @return bool
	 */
	public function is_new( WP_Post $entry ): bool {
		$published = Post_Type::get_entry_published_gmt( $entry );

		return $published > $this->modified || ( $published === $this->modified && ! in_array( $entry->ID, $this->ids, true ) );
	}

	/**
	 * The cursor as a page sends it.
	 *
	 * @return string
	 */
	public function __toString(): string {
		return ( $this->ids ? implode( ',', $this->ids ) : '0' ) . ':' . $this->modified . ( null === $this->marker ? '' : '@' . $this->marker );
	}

	/**
	 * A coverage's change marker. Read it before reading entries: a change
	 * that lands in between then still differs from the marker the page
	 * gets.
	 *
	 * @param int $coverage_id Coverage term ID.
	 * @return string The marker, or '' before the coverage's first change.
	 */
	public static function get_marker( int $coverage_id ): string {
		return (string) get_term_meta( $coverage_id, self::MARKER_META_KEY, true );
	}

	/**
	 * Replaces a coverage's change marker. Call it once what a poll reads
	 * about the change is saved: a poll in between can take the new marker
	 * without the change and never look for it again.
	 *
	 * @param int $coverage_id Coverage term ID.
	 */
	public static function mark_changed( int $coverage_id ): void {
		update_term_meta( $coverage_id, self::MARKER_META_KEY, (string) wp_rand() );
	}
}
