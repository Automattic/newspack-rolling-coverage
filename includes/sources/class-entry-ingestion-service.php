<?php
/**
 * Generic entry ingestion pipeline.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

defined( 'ABSPATH' ) || exit;

/**
 * Generic chat-source ingestion pipeline: dedup, insert, term-link, write meta.
 */
class Entry_Ingestion_Service {

	/**
	 * Mutex option key prefix. Final key is `prefix . md5( source . ':' . source_ref )`.
	 *
	 * @var string
	 */
	const MUTEX_PREFIX = 'rolling_coverage_source_ingest_';

	/**
	 * Mutex lifetime in seconds. If a process is hard-killed (OOM, execution
	 * timeout) mid-insert, the `finally` block won't run and the lock option
	 * stays forever. This TTL allows stale locks to be reclaimed: when
	 * add_option() fails, we check the stored timestamp and break the lock
	 * if it's older than this. Media work refreshes the timestamp as it
	 * progresses, so a long import keeps its lock.
	 *
	 * @var int
	 */
	const MUTEX_TTL = 60;

	// Skip result when the target coverage is archived.
	const SKIP_ARCHIVED_COVERAGE = -1;

	// Skip result when another request is still ingesting the same event.
	const SKIP_IN_PROGRESS = -2;

	/**
	 * Ingest a normalized source event into a rolling coverage entry.
	 *
	 * @param Source_Event_Payload $payload         Normalized event.
	 * @param int                  $term_id         Resolved rolling coverage term id.
	 * @param bool                 $auto_publish    Whether to insert as 'publish' or 'draft'.
	 * @param int                  $author_id       WP user id to assign as post_author.
	 * @param array<string, mixed> $provenance_meta Platform-specific meta keyed by meta_key.
	 * @param callable|null        $render_media    Returns block markup for the event's
	 *                                              media, added after the content. Only
	 *                                              called for an event that is about to
	 *                                              become an entry, so a redelivered
	 *                                              event does not import its media twice.
	 *                                              It receives a callable to call as the
	 *                                              work progresses, which keeps the
	 *                                              event's lock from going stale.
	 * @return int|\WP_Error Post id on success, 0 on a clean skip,
	 *                       self::SKIP_ARCHIVED_COVERAGE when the coverage is
	 *                       archived, self::SKIP_IN_PROGRESS when another
	 *                       request holds the event's lock, or WP_Error.
	 */
	public static function ingest(
		Source_Event_Payload $payload,
		int $term_id,
		bool $auto_publish,
		int $author_id,
		array $provenance_meta,
		?callable $render_media = null
	) {
		if ( Archive_Mode::is_coverage_archived( $term_id ) ) {
			return self::SKIP_ARCHIVED_COVERAGE;
		}

		$lock_key = self::MUTEX_PREFIX . md5( $payload->source . ':' . $payload->source_ref );

		// TOCTOU race condition : Lock already exists.
		if ( ! add_option( $lock_key, time(), '', false ) ) {
			// Lock exists — check if it's stale (the process was hard-killed).
			$lock_time = (int) get_option( $lock_key, 0 );

			// Lock is fresh — another request is actively processing; skip.
			if ( $lock_time > 0 && ( time() - $lock_time ) < self::MUTEX_TTL ) {
				return self::SKIP_IN_PROGRESS;
			}

			// Lock is stale — reclaim it by updating the timestamp and proceed.
			update_option( $lock_key, time(), false );
		}

		try {
			if ( self::find_entry_id( $payload->source_ref, $term_id ) > 0 ) {
				return 0;
			}

			if ( $author_id <= 0 ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'Source ingestion: bot user unavailable, skipping entry.' );
				return 0;
			}

			$content = $payload->content_html;

			if ( null !== $render_media ) {
				$keep_lock = static fn() => update_option( $lock_key, time(), false );
				$content   = implode( "\n\n", array_filter( [ $content, (string) $render_media( $keep_lock ) ], 'strlen' ) );

				// The import can outlast another delivery of the same event that got past the lock.
				if ( self::find_entry_id( $payload->source_ref, $term_id ) > 0 ) {
					return 0;
				}
			}

			if ( '' === $content ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'Source ingestion: empty content, skipping.' );
				return 0;
			}

			$postarr = [
				'post_type'    => Post_Type::CPT_SLUG,
				'post_title'   => '',
				'post_content' => wp_slash( $content ),
				'post_author'  => $author_id,
				'post_status'  => $auto_publish ? 'publish' : 'draft',
			];

			// A reply is a child of the entry for the message its thread starts
			// from, so the two can be shown together. When that message has no
			// entry in this coverage, the reply is a top-level entry.
			if ( null !== $payload->thread_ref && '' !== $payload->thread_ref ) {
				$postarr['post_parent'] = self::find_entry_id( $payload->thread_ref, $term_id );
			}

			try {
				$post_id = wp_insert_post( $postarr, true );
			} catch ( \Throwable $e ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'Source ingestion: wp_insert_post exception: ' . $e->getMessage() );
				return new \WP_Error( 'rolling_coverage_insert_exception', $e->getMessage() );
			}

			if ( is_wp_error( $post_id ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'Source ingestion: wp_insert_post error: ' . $post_id->get_error_message() );
				return $post_id;
			}

			$post_id = (int) $post_id;

			wp_set_object_terms( $post_id, [ $term_id ], Taxonomy::TAXONOMY_SLUG );

			// Canonical dedup key — must not duplicate, hence the unique flag.
			add_post_meta( $post_id, Post_Type::META_SOURCE_REF, $payload->source_ref, true );
			add_post_meta( $post_id, Post_Type::META_ENTRY_SOURCE, $payload->source );

			foreach ( $provenance_meta as $meta_key => $meta_value ) {
				if ( ! is_string( $meta_key ) || '' === $meta_key ) {
					continue;
				}

				add_post_meta( $post_id, $meta_key, $meta_value );
			}

			/**
			 * Fires once an entry from a chat source is saved with its
			 * coverage and meta.
			 *
			 * @param int $post_id Entry post id.
			 */
			do_action( 'newspack_rolling_coverage_entry_ingested', $post_id );

			return $post_id;
		} finally {
			delete_option( $lock_key );
		}
	}

	/**
	 * Find the entry for the given source_ref + term.
	 *
	 * @param string $source_ref Platform-native message id.
	 * @param int    $term_id    Term id.
	 * @return int Entry post id, or 0 when there is none.
	 */
	private static function find_entry_id( string $source_ref, int $term_id ): int {
		$posts = get_posts(
			[
				'post_type'      => Post_Type::CPT_SLUG,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				// A cached answer cannot see an entry another request saved since.
				'cache_results'  => false,
				'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Checks whether post is from same source e.g. slack.
					[
						'key'   => Post_Type::META_SOURCE_REF,
						'value' => $source_ref,
					],
				],
				'tax_query'      => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Required for our architecture.
					[
						'taxonomy' => Taxonomy::TAXONOMY_SLUG,
						'field'    => 'term_id',
						'terms'    => $term_id,
					],
				],
			]
		);

		return (int) ( $posts[0] ?? 0 );
	}
}
