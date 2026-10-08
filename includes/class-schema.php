<?php
/**
 * Emits schema.org/LiveBlogPosting JSON-LD for pages embedding a Rolling
 * Coverage block.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

use DateTimeImmutable;
use DateTimeZone;
use WP_Post;
use WP_Query;
use WP_Term;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Builds and prints LiveBlogPosting structured data on the front end, and
 * reports when a page embedding a coverage last changed.
 *
 * With Yoast SEO, the page's first coverage is merged into Yoast's Article so
 * search engines see one article with one set of dates. Without Yoast, or on
 * pages Yoast gives no Article, the coverage is printed as its own script.
 * The merge happens wherever Yoast builds the Article, which includes the
 * head fields it adds to REST responses.
 *
 * A page changes whenever one of its coverage's entries does, but its stored
 * modified date only knows about edits to the page itself. The stored date is
 * left alone: the later of the two is worked out when the date is read and
 * handed to the theme, Yoast and the sitemaps through their filters, so they
 * agree with the structured data.
 */
class Schema {

	const BLOCK_NAME = 'newspack-rolling-coverage/rolling-coverage';

	/**
	 * Object cache group for the built LiveBlogPosting metadata, with its own
	 * `last_changed` stamp (see bump_last_changed()). The stamp rotates the
	 * key only when something the schema shows changes: a coverage rename, an
	 * entry joining or leaving a coverage, or an author name change. The whole
	 * group must never be backed by a database transient, or every rotated key
	 * would write a fresh wp_options row.
	 */
	const CACHE_GROUP = 'newspack_rolling_coverage_schema';

	/**
	 * Coverage merged into Yoast's Article, by host post ID, so print_schema()
	 * doesn't describe it a second time.
	 *
	 * @var array<int,int>
	 */
	private static $merged_coverage_ids = [];

	/**
	 * Page dates worked out so far, by post ID. A page's date is asked for
	 * several times while it renders, and again wherever the page is listed.
	 * `salt` ties them to the state of posts and terms they were worked out
	 * from, so any change throws them away.
	 *
	 * @var array{salt:string,dates:array<int,DateTimeImmutable|null>}
	 */
	private static $page_dates = [
		'salt'  => '',
		'dates' => [],
	];

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'wp_head', [ __CLASS__, 'print_schema' ] );
		add_filter( 'wpseo_schema_article', [ __CLASS__, 'merge_into_yoast_article' ], 10, 2 );
		add_filter( 'wpseo_schema_webpage', [ __CLASS__, 'align_yoast_webpage_dates' ], 10, 2 );
		add_filter( 'wpseo_frontend_presentation', [ __CLASS__, 'set_yoast_modified_time' ], 10, 2 );
		add_filter( 'wpseo_sitemap_entry', [ __CLASS__, 'set_yoast_sitemap_lastmod' ], 10, 3 );
		add_filter( 'wp_sitemaps_posts_entry', [ __CLASS__, 'set_core_sitemap_lastmod' ], 10, 2 );
		add_filter( 'get_the_modified_date', [ __CLASS__, 'filter_the_modified_date' ], 10, 3 );
		add_filter( 'get_the_modified_time', [ __CLASS__, 'filter_the_modified_time' ], 10, 3 );
		add_action( 'edited_' . Taxonomy::TAXONOMY_SLUG, [ __CLASS__, 'bump_last_changed' ] );
		add_action( 'set_object_terms', [ __CLASS__, 'bump_last_changed_on_term_assignment' ], 10, 4 );
		add_action( 'profile_update', [ __CLASS__, 'bump_last_changed_on_profile_update' ], 10, 2 );
		add_action( 'deleted_user', [ __CLASS__, 'bump_last_changed' ] );
	}

	/**
	 * Rotates the metadata cache key when something the schema shows changes.
	 *
	 * Every cached value is keyed on the group's `last_changed` stamp, minted
	 * on first read per request when no persistent object cache is installed.
	 * The stamp only moves through this method and its wrappers below, so the
	 * key stays stable, and reused across views, however much else happens on
	 * the site.
	 *
	 * Fired from:
	 * - `edited_{taxonomy}` — a coverage rename changes the `headline`.
	 * - `deleted_user` — a user's posts are reassigned or deleted by direct
	 *   query, so no other hook covers it.
	 */
	public static function bump_last_changed() {
		wp_cache_set_last_changed( self::CACHE_GROUP );
	}

	/**
	 * Rotates the metadata cache key when an entry joins or leaves a coverage,
	 * which changes the `liveBlogUpdate` list.
	 *
	 * Fires on `set_object_terms`, after the relationship change, for the
	 * coverage taxonomy only.
	 *
	 * @param int    $object_id Object ID.
	 * @param array  $terms     Array of object term IDs or slugs.
	 * @param array  $tt_ids    Array of term taxonomy IDs.
	 * @param string $taxonomy  Taxonomy slug.
	 */
	public static function bump_last_changed_on_term_assignment( $object_id, $terms, $tt_ids, $taxonomy ) {
		if ( Taxonomy::TAXONOMY_SLUG === $taxonomy ) {
			self::bump_last_changed();
		}
	}

	/**
	 * Rotates the metadata cache key when an author's name changes, which
	 * changes the `author` of their entries in `liveBlogUpdate`.
	 *
	 * Fires on `profile_update` and compares the author's stored name before
	 * and after the update. It reads the stored user rather than the hook's
	 * `$userdata`, which wp_update_user() passes magic-quoted: a name like
	 * O'Brien would otherwise read as changed on every user write, rebuilding
	 * the metadata on each of them.
	 *
	 * @param int          $user_id       User ID.
	 * @param WP_User|null $old_user_data Object containing user's data prior to the update.
	 */
	public static function bump_last_changed_on_profile_update( $user_id, $old_user_data ) {
		$new_user = get_userdata( (int) $user_id );

		if ( ! $old_user_data instanceof WP_User || ! $new_user instanceof WP_User ) {
			return;
		}

		if ( $old_user_data->display_name !== $new_user->display_name || $old_user_data->user_nicename !== $new_user->user_nicename ) {
			self::bump_last_changed();
		}
	}

	/**
	 * Prints one JSON-LD script tag per Rolling Coverage block on the page,
	 * except the coverage already merged into Yoast's Article.
	 *
	 * The merge happens while Yoast prints its graph (`wp_head` priority 1), so
	 * this has to run later. Run it earlier and the merged coverage is printed
	 * twice.
	 */
	public static function print_schema() {
		if ( ! is_singular() ) {
			return;
		}

		$post = get_queried_object();
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$merged_coverage_id = self::$merged_coverage_ids[ $post->ID ] ?? 0;

		foreach ( self::get_page_coverages( $post ) as $coverage_id => $entries_per_page ) {
			if ( $coverage_id === $merged_coverage_id ) {
				continue;
			}

			$metadata = self::build_metadata( $post, $coverage_id, $entries_per_page );

			if ( empty( $metadata ) ) {
				continue;
			}

			// JSON_HEX_TAG escapes `<`/`>` so a user-authored `</script>` inside an
			// entry body can't break out of the JSON-LD script tag.
			printf(
				'<script type="application/ld+json">%s</script>' . "\n",
				wp_json_encode( $metadata, JSON_HEX_TAG )
			);
		}
	}

	/**
	 * Turns Yoast's Article into the page's live blog.
	 *
	 * Printed separately, the two would describe one URL as two articles that
	 * disagree on when it last changed. Yoast's values win where both describe
	 * the page (headline, publish date, main entity), since they match what
	 * readers see. The live blog adds its coverage times and updates, and
	 * dateModified becomes the page's, which also counts entry changes.
	 *
	 * @param array|mixed $data    Yoast's Article graph piece.
	 * @param object      $context Yoast's meta tags context.
	 * @return array|mixed Filtered graph piece.
	 */
	public static function merge_into_yoast_article( $data, $context ) {
		$post = is_object( $context ) ? ( $context->post ?? null ) : null;
		if ( ! is_array( $data ) || ! $post instanceof WP_Post ) {
			return $data;
		}

		$primary = self::get_primary_metadata( $post );
		if ( null === $primary ) {
			return $data;
		}

		$metadata = $primary['metadata'];

		// Keep Yoast's type (NewsArticle, for example) and add the live blog to it.
		$data['@type'] = array_values( array_unique( array_merge( (array) ( $data['@type'] ?? [] ), [ 'LiveBlogPosting' ] ) ) );

		foreach ( $metadata as $key => $value ) {
			if ( '@context' !== $key && ! array_key_exists( $key, $data ) ) {
				$data[ $key ] = $value;
			}
		}

		$page_date = self::get_page_date_modified( $post );
		if ( null !== $page_date ) {
			$data['dateModified'] = $page_date->format( 'c' );
		}

		self::$merged_coverage_ids[ $post->ID ] = $primary['coverage_id'];

		return $data;
	}

	/**
	 * Gives Yoast's WebPage the page's dateModified, so every date in the
	 * page's structured data agrees on when it last changed.
	 *
	 * @param array|mixed $data    Yoast's WebPage graph piece.
	 * @param object      $context Yoast's meta tags context.
	 * @return array|mixed Filtered graph piece.
	 */
	public static function align_yoast_webpage_dates( $data, $context ) {
		$post = is_object( $context ) ? ( $context->post ?? null ) : null;
		if ( ! is_array( $data ) || ! $post instanceof WP_Post ) {
			return $data;
		}

		$page_date = self::get_page_date_modified( $post );
		if ( null !== $page_date ) {
			$data['dateModified'] = $page_date->format( 'c' );
		}

		return $data;
	}

	/**
	 * Gives Yoast's `article:modified_time` the date the page last changed for
	 * readers.
	 *
	 * @param object|mixed $presentation Yoast's presentation of the page.
	 * @param object       $context      Yoast's meta tags context.
	 * @return object|mixed The presentation.
	 */
	public static function set_yoast_modified_time( $presentation, $context ) {
		$post = is_object( $context ) ? ( $context->post ?? null ) : null;
		if ( ! is_object( $presentation ) || ! $post instanceof WP_Post ) {
			return $presentation;
		}

		$page_date = self::get_page_date_modified( $post );
		if ( null !== $page_date ) {
			$presentation->open_graph_article_modified_time = $page_date->format( DATE_W3C );
		}

		return $presentation;
	}

	/**
	 * Gives the page's entry in Yoast's sitemap the date it last changed for
	 * readers, so search engines are told to fetch it again.
	 *
	 * Yoast hands over a post built from the few columns its sitemap query
	 * selects, without the password. The password check can't apply here, and
	 * doesn't need to: that query leaves protected posts out.
	 *
	 * @param array|mixed   $url  Sitemap entry.
	 * @param string        $type Entry type.
	 * @param WP_Post|mixed $post The post, with only the columns Yoast's sitemap query selects.
	 * @return array|mixed Sitemap entry.
	 */
	public static function set_yoast_sitemap_lastmod( $url, $type, $post ) {
		if ( ! is_array( $url ) || 'post' !== $type || ! is_object( $post ) ) {
			return $url;
		}

		$post = get_post( $post );
		if ( ! $post instanceof WP_Post ) {
			return $url;
		}

		$page_date = self::get_page_date_modified( $post );
		if ( null !== $page_date ) {
			// Yoast reads this value as UTC, whatever timezone the site is in.
			$url['mod'] = gmdate( 'Y-m-d H:i:s', $page_date->getTimestamp() );
		}

		return $url;
	}

	/**
	 * Does the same for WordPress's own sitemap.
	 *
	 * @param array|mixed   $sitemap_entry Sitemap entry.
	 * @param WP_Post|mixed $post          Post the entry is for.
	 * @return array|mixed Sitemap entry.
	 */
	public static function set_core_sitemap_lastmod( $sitemap_entry, $post ) {
		if ( ! is_array( $sitemap_entry ) || ! $post instanceof WP_Post ) {
			return $sitemap_entry;
		}

		$page_date = self::get_page_date_modified( $post );
		if ( null !== $page_date ) {
			$sitemap_entry['lastmod'] = wp_date( DATE_W3C, $page_date->getTimestamp() );
		}

		return $sitemap_entry;
	}

	/**
	 * Gives themes the date the page last changed for readers when they ask
	 * for its modified date.
	 *
	 * Parameters stay untyped because this runs for every post on the site,
	 * after other plugins' filters that may hand on unexpected types.
	 *
	 * @param string|int|false $the_time The post's own modified date, formatted.
	 * @param string           $format   Requested format, or empty for the site's date format.
	 * @param WP_Post|null     $post     Post the date is for.
	 * @return string|int|false Formatted date.
	 */
	public static function filter_the_modified_date( $the_time, $format, $post ) {
		return self::format_page_date( $the_time, $format, $post, 'date_format' );
	}

	/**
	 * Does the same when they ask for its modified time.
	 *
	 * @param string|int|false $the_time The post's own modified time, formatted.
	 * @param string           $format   Requested format, or empty for the site's time format.
	 * @param WP_Post|null     $post     Post the time is for.
	 * @return string|int|false Formatted time.
	 */
	public static function filter_the_modified_time( $the_time, $format, $post ) {
		return self::format_page_date( $the_time, $format, $post, 'time_format' );
	}

	/**
	 * Formats the date the page last changed for readers the way core formats
	 * a post's modified date, or returns the post's own when that stands.
	 *
	 * @param string|int|false $the_time              The post's own modified date, formatted.
	 * @param string           $format                Requested format.
	 * @param WP_Post|null     $post                  Post the date is for.
	 * @param string           $default_format_option Option holding the format to use when none is requested.
	 * @return string|int|false Formatted date.
	 */
	private static function format_page_date( $the_time, $format, $post, string $default_format_option ) {
		if ( ! $post instanceof WP_Post ) {
			return $the_time;
		}

		$page_date = self::get_page_date_modified( $post );
		if ( null === $page_date ) {
			return $the_time;
		}

		$format = is_string( $format ) && '' !== $format ? $format : (string) get_option( $default_format_option );

		// Core returns these two formats with the site's UTC offset added, and
		// themes compare them with the published date's, so the offset has to
		// be added here as well.
		if ( 'U' === $format || 'G' === $format ) {
			return $page_date->getTimestamp() + $page_date->setTimezone( wp_timezone() )->getOffset();
		}

		return wp_date( $format, $page_date->getTimestamp() );
	}

	/**
	 * Returns when a page last changed for readers, when that is later than
	 * the page's own date: the newest published entry of the coverages it
	 * embeds. Null means the page's own date stands.
	 *
	 * @param WP_Post $post Host post.
	 * @return DateTimeImmutable|null The later date, or null.
	 */
	public static function get_page_date_modified( WP_Post $post ): ?DateTimeImmutable {
		// Themes ask for the date of every post they list, so a post without
		// the block leaves before any work is done.
		if ( ! has_block( self::BLOCK_NAME, $post ) ) {
			return null;
		}

		$salt = wp_cache_get_last_changed( 'posts' ) . '|' . wp_cache_get_last_changed( 'terms' );
		if ( self::$page_dates['salt'] !== $salt ) {
			self::$page_dates = [
				'salt'  => $salt,
				'dates' => [],
			];
		}

		if ( array_key_exists( $post->ID, self::$page_dates['dates'] ) ) {
			return self::$page_dates['dates'][ $post->ID ];
		}

		$latest = null;

		foreach ( array_keys( self::get_page_coverages( $post ) ) as $coverage_id ) {
			if ( 'trash' === get_term_meta( $coverage_id, Taxonomy::STATUS_META_KEY, true ) ) {
				continue;
			}

			$entry_date = self::get_latest_entry_date( $coverage_id );
			if ( null !== $entry_date && ( null === $latest || $entry_date > $latest ) ) {
				$latest = $entry_date;
			}
		}

		if ( null !== $latest ) {
			$own = self::get_own_date( $post );

			if ( null !== $own && $latest <= $own ) {
				$latest = null;
			}
		}

		self::$page_dates['dates'][ $post->ID ] = $latest;

		return $latest;
	}

	/**
	 * Returns when a post itself last changed for readers: its last edit, or
	 * its publish date when that is later. A post published on schedule keeps
	 * the modified date of its last edit, from before it went live.
	 *
	 * @param WP_Post $post Post.
	 * @return DateTimeImmutable|null The date, or null when the post has none.
	 */
	private static function get_own_date( WP_Post $post ): ?DateTimeImmutable {
		$dates = array_filter(
			[
				get_post_datetime( $post, 'modified', 'gmt' ),
				get_post_datetime( $post, 'date', 'gmt' ),
			]
		);

		return empty( $dates ) ? null : max( $dates );
	}

	/**
	 * Returns the first coverage on the page that has metadata to publish.
	 *
	 * Search engines read a page as a single live blog, so only this coverage
	 * is merged into Yoast's Article. Any others keep their own script.
	 *
	 * @param WP_Post $post Host post.
	 * @return array{coverage_id:int,metadata:array}|null The coverage and its metadata, or null when there is none.
	 */
	private static function get_primary_metadata( WP_Post $post ): ?array {
		foreach ( self::get_page_coverages( $post ) as $coverage_id => $entries_per_page ) {
			$metadata = self::build_metadata( $post, $coverage_id, $entries_per_page );

			if ( ! empty( $metadata ) ) {
				return [
					'coverage_id' => $coverage_id,
					'metadata'    => $metadata,
				];
			}
		}

		return null;
	}

	/**
	 * Returns the coverages whose metadata a post may publish.
	 *
	 * @param WP_Post $post Host post.
	 * @return array<int,int> Map of coverage term id => entries-per-page attribute.
	 */
	private static function get_page_coverages( WP_Post $post ): array {
		// Withhold all metadata for password-protected posts before touching any
		// entry content, so protected bodies can't leak through the JSON-LD.
		if ( post_password_required( $post ) ) {
			return [];
		}

		if ( ! has_block( self::BLOCK_NAME, $post ) ) {
			return [];
		}

		return self::get_coverage_blocks( $post );
	}

	/**
	 * Collects the uncapped Rolling Coverage blocks embedded in a post, deduped
	 * by coverage ID. A capped block shows a few entries and links to the
	 * coverage page, so it doesn't make the post a live blog.
	 *
	 * @param WP_Post $post Host post being rendered.
	 * @return array<int,int> Map of coverage term id => entries-per-page attribute.
	 */
	private static function get_coverage_blocks( WP_Post $post ): array {
		$coverages = [];

		foreach ( self::flatten_blocks( parse_blocks( $post->post_content ) ) as $block ) {
			if ( self::BLOCK_NAME !== ( $block['blockName'] ?? '' ) || ! empty( $block['attrs']['latestOnly'] ) ) {
				continue;
			}

			$coverage_id = (int) ( $block['attrs']['coverageId'] ?? 0 );
			if ( ! $coverage_id || isset( $coverages[ $coverage_id ] ) ) {
				continue;
			}

			$entries_per_page = min(
				max( 1, (int) ( $block['attrs']['entriesPerPage'] ?? 20 ) ),
				Rolling_Coverage_Block::PER_PAGE_MAX
			);

			$coverages[ $coverage_id ] = $entries_per_page;
		}

		return $coverages;
	}

	/**
	 * Recursively flattens a parsed-block tree so nested coverage blocks are found too.
	 *
	 * @param array[] $blocks Parsed blocks.
	 * @return array[] Flat list of parsed blocks.
	 */
	public static function flatten_blocks( array $blocks ): array {
		$flat = [];

		foreach ( $blocks as $block ) {
			$flat[] = $block;
			if ( ! empty( $block['innerBlocks'] ) ) {
				$flat = array_merge( $flat, self::flatten_blocks( $block['innerBlocks'] ) );
			}
		}

		return $flat;
	}

	/**
	 * Builds the LiveBlogPosting metadata for one coverage embedded in the host post.
	 *
	 * @param WP_Post $post             Host post the block is embedded in.
	 * @param int     $coverage_id      Coverage term id.
	 * @param int     $entries_per_page Number of entries to include, mirroring the block render.
	 * @return array|null Metadata array, or null when the coverage should not emit schema.
	 */
	private static function build_metadata( WP_Post $post, int $coverage_id, int $entries_per_page ): ?array {
		$term = get_term( $coverage_id, Taxonomy::TAXONOMY_SLUG );

		if ( ! $term instanceof WP_Term ) {
			return null;
		}

		$status = get_term_meta( $coverage_id, Taxonomy::STATUS_META_KEY, true );

		if ( 'trash' === $status ) {
			return null;
		}

		$last_modified = get_term_meta( $coverage_id, Rolling_Coverage_Block::LAST_MODIFIED_META_KEY, true );
		$end_time      = get_term_meta( $coverage_id, Taxonomy::END_TIME_META_KEY, true );

		// The newest entry's date is part of the key because a scheduled entry
		// going live changes what the page shows without moving the coverage's
		// last-modified meta.
		$latest_entry_date = self::get_latest_entry_date( $coverage_id );

		// The group's last_changed stamp invalidates the key on coverage rename,
		// entry move and author rename; all other inputs are persisted, so the
		// key stays stable.
		$cache_key = 'nrc_' . $coverage_id . '_' . md5(
			$post->ID . '|' . $post->post_modified_gmt . '|' . $entries_per_page . '|' . $status . '|' . $last_modified . '|' . $end_time . '|' . ( null === $latest_entry_date ? '' : $latest_entry_date->getTimestamp() ) . '|' . wp_cache_get_last_changed( self::CACHE_GROUP )
		);

		$cached_metadata = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached_metadata ) {
			return $cached_metadata;
		}

		$permalink = get_permalink( $post );

		$coverage_name = $term->name;
		$headline      = '' === trim( $coverage_name )
			? get_the_title( $post )
			: $coverage_name;

		$metadata = [
			'@context'         => 'https://schema.org',
			'@type'            => 'LiveBlogPosting',
			'headline'         => $headline,
			'url'              => $permalink,
			'mainEntityOfPage' => $permalink,
		];

		$published_datetime = get_post_datetime( $post, 'date', 'gmt' );
		if ( false !== $published_datetime ) {
			$metadata['datePublished'] = $published_datetime->format( 'c' );
		}

		$modified_datetime = self::get_date_modified( $post, $latest_entry_date );
		if ( null !== $modified_datetime ) {
			$metadata['dateModified'] = $modified_datetime->format( 'c' );
		}

		$created_at = get_term_meta( $coverage_id, 'created_at', true );
		if ( ! empty( $created_at ) ) {
			$metadata['coverageStartTime'] = $created_at;
		}

		if ( 'archived' === $status && ! empty( $end_time ) ) {
			$metadata['coverageEndTime'] = gmdate( 'c', strtotime( $end_time ) );
		}

		$metadata['liveBlogUpdate'] = self::build_updates( $coverage_id, $entries_per_page, $permalink );

		/**
		 * Filters the LiveBlogPosting metadata before it is printed.
		 *
		 * @param array   $metadata    Metadata array.
		 * @param int     $coverage_id Coverage term id.
		 * @param WP_Post $post        Host post the block is embedded in.
		 */
		$metadata = apply_filters( 'newspack_rolling_coverage_schema_metadata', $metadata, $coverage_id, $post );

		// A week's TTL bounds how long a stale entry can be served if a cache
		// salt somehow fails to move; the versioned key is the usual path.
		wp_cache_set( $cache_key, $metadata, self::CACHE_GROUP, WEEK_IN_SECONDS );

		return $metadata;
	}

	/**
	 * Returns when the page last changed in a way readers can see: an edit to
	 * the host post or to one of the coverage's published entries.
	 *
	 * @param WP_Post                $post              Host post the block is embedded in.
	 * @param DateTimeImmutable|null $latest_entry_date When the coverage's published entries last changed.
	 * @return DateTimeImmutable|null Latest change, or null when no date is available.
	 */
	private static function get_date_modified( WP_Post $post, ?DateTimeImmutable $latest_entry_date ): ?DateTimeImmutable {
		$dates = array_filter(
			[
				self::get_own_date( $post ),
				$latest_entry_date,
			]
		);

		return empty( $dates ) ? null : max( $dates )->setTimezone( new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Returns when a coverage's published entries last changed.
	 *
	 * The coverage's last-modified term meta isn't used here because draft,
	 * pending and private entry saves move it too. The newest entry by publish
	 * date counts as well as the newest by edit: an entry published on schedule
	 * keeps the modified date of its last edit, from before it went live.
	 *
	 * The date comes back in UTC, like the dates Yoast prints beside it.
	 *
	 * @param int $coverage_id Coverage term id.
	 * @return DateTimeImmutable|null Latest change, or null when the coverage has no published entries.
	 */
	private static function get_latest_entry_date( int $coverage_id ): ?DateTimeImmutable {
		$dates = [];

		foreach ( [ 'modified', 'date' ] as $field ) {
			$query = new WP_Query(
				[
					'post_type'                   => Post_Type::CPT_SLUG,
					'post_status'                 => 'publish',
					'tax_query'                   => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
						[
							'taxonomy' => Taxonomy::TAXONOMY_SLUG,
							'field'    => 'term_id',
							'terms'    => $coverage_id,
						],
					],
					'orderby'                     => $field,
					'order'                       => 'DESC',
					'posts_per_page'              => 1,
					'no_found_rows'               => true,
					'ignore_sticky_posts'         => true,
					'update_post_meta_cache'      => false,
					'update_post_term_cache'      => false,
					Post_Type::SKIP_PIN_ORDER_VAR => true,
				]
			);

			if ( ! empty( $query->posts ) ) {
				$dates[] = self::get_own_date( $query->posts[0] );
			}
		}

		$dates = array_filter( $dates );

		return empty( $dates ) ? null : max( $dates )->setTimezone( new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Builds the liveBlogUpdate array of BlogPosting entities for a coverage's entries.
	 *
	 * @param int    $coverage_id      Coverage term id.
	 * @param int    $entries_per_page Number of entries to include.
	 * @param string $permalink        Host post permalink used to anchor each entry.
	 * @return array[] Array of BlogPosting arrays.
	 */
	private static function build_updates( int $coverage_id, int $entries_per_page, string $permalink ): array {
		$query = new WP_Query(
			[
				'post_type'           => Post_Type::CPT_SLUG,
				'post_status'         => 'publish',
				'has_password'        => false,
				'tax_query'           => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					[
						'taxonomy' => Taxonomy::TAXONOMY_SLUG,
						'field'    => 'term_id',
						'terms'    => $coverage_id,
					],
				],
				'orderby'             => 'date',
				'order'               => 'DESC',
				'posts_per_page'      => $entries_per_page,
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
			]
		);

		$updates = [];

		foreach ( $query->posts as $entry ) {
			$update = self::build_entry_update( $entry, $permalink );
			if ( null !== $update ) {
				$updates[] = $update;
			}
		}

		wp_reset_postdata();

		return $updates;
	}

	/**
	 * Maps an entry post to a BlogPosting liveBlogUpdate item, or null if it's empty.
	 *
	 * @param WP_Post $entry     Entry post.
	 * @param string  $permalink Host post permalink used to anchor the entry.
	 * @return array|null BlogPosting array, or null to skip the entry.
	 */
	private static function build_entry_update( WP_Post $entry, string $permalink ): ?array {
		// The schema is cached for every visitor, so it holds only what everyone may read. Protected entries never get here (build_updates()).
		// Replace tags with spaces to preserve word boundaries, decode entities,
		// then collapse whitespace so headline/articleBody read as clean prose.
		$article_body = preg_replace( '/<[^>]+>/', ' ', do_blocks( Entry_Bindings::public_content( $entry ) ) );
		$article_body = html_entity_decode( $article_body, ENT_QUOTES, 'UTF-8' );
		$article_body = preg_replace( '/\s+/', ' ', $article_body );
		$article_body = trim( $article_body );

		/**
		 * Filters an entry's article body before it's used in LiveBlogPosting schema.
		 *
		 * @param string  $article_body Cleaned article body.
		 * @param WP_Post $entry        Entry post object.
		 */
		$article_body = apply_filters( 'newspack_rolling_coverage_schema_article_body', $article_body, $entry );

		if ( '' === $article_body ) {
			return null;
		}

		$headline = get_the_title( $entry );
		if ( '' === trim( wp_strip_all_tags( $headline ) ) ) {
			$headline = wp_trim_words( $article_body, 10, '…' );
		}

		$update = [
			'@type'            => 'BlogPosting',
			'headline'         => $headline,
			'url'              => $permalink . '#' . Rolling_Coverage_Block::MARKUP_PREFIX . '-entry-' . $entry->ID,
			'mainEntityOfPage' => $permalink . '#' . Rolling_Coverage_Block::MARKUP_PREFIX . '-entry-' . $entry->ID,
			'articleBody'      => $article_body,
		];

		$published_datetime = get_post_datetime( $entry, 'date', 'gmt' );
		if ( false !== $published_datetime ) {
			$update['datePublished'] = $published_datetime->format( 'c' );
		}

		$modified_datetime = get_post_datetime( $entry, 'modified', 'gmt' );
		if ( false !== $modified_datetime ) {
			$update['dateModified'] = $modified_datetime->format( 'c' );
		}

		$author = self::build_author( (int) $entry->post_author );
		if ( null !== $author ) {
			$update['author'] = $author;
		}

		return $update;
	}

	/**
	 * Builds a schema.org Person object for an entry's author.
	 *
	 * @param int $author_id Author user id.
	 * @return array|null Person array, or null when the author has no name.
	 */
	private static function build_author( int $author_id ): ?array {
		if ( $author_id <= 0 ) {
			return null;
		}

		$name = get_the_author_meta( 'display_name', $author_id );
		if ( '' === trim( (string) $name ) ) {
			return null;
		}

		$author = [
			'@type' => 'Person',
			'name'  => $name,
		];

		// A URL helps search engines disambiguate the author (Google's guidance).
		$url = get_author_posts_url( $author_id );
		if ( ! empty( $url ) ) {
			$author['url'] = $url;
		}

		return $author;
	}
}
