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
	 * Transient mutex key prefix. Final key is `prefix . md5( source . ':' . source_ref )`.
	 *
	 * @var string
	 */
	const MUTEX_PREFIX = 'rolling_coverage_source_ingest_';

	// Mutex lifetime, in seconds.
	const MUTEX_TTL = 60;

	// Title truncation length.
	const TITLE_LENGTH = 50;

	/**
	 * Ingest a normalized source event into a rolling coverage entry.
	 *
	 * @param Source_Event_Payload $payload         Normalized event.
	 * @param int                  $term_id         Resolved rolling coverage term id.
	 * @param bool                 $auto_publish    Whether to insert as 'publish' or 'draft'.
	 * @param int                  $bot_user_id     WP user id to assign as post_author.
	 * @param array<string, mixed> $provenance_meta Platform-specific meta keyed by meta_key.
	 * @return int|\WP_Error Post id on success, 0 on a clean skip, or WP_Error.
	 */
	public static function ingest(
		Source_Event_Payload $payload,
		int $term_id,
		bool $auto_publish,
		int $bot_user_id,
		array $provenance_meta
	) {
		$lock_key = self::MUTEX_PREFIX . md5( $payload->source . ':' . $payload->source_ref );

		if ( ! set_transient( $lock_key, 1, self::MUTEX_TTL ) ) {
			// Another request is already processing this pair; skip silently.
			return 0;
		}

		if ( self::entry_exists( $payload->source_ref, $term_id ) ) {
			return 0;
		}

		if ( '' === $payload->content_html ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'Source ingestion: empty content, skipping.' );
			return 0;
		}

		if ( $bot_user_id <= 0 ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'Source ingestion: bot user unavailable, skipping entry.' );
			return 0;
		}

		$postarr = [
			'post_type'    => Post_Type::CPT_SLUG,
			'post_title'   => self::truncate( wp_strip_all_tags( $payload->content_html ), self::TITLE_LENGTH ),
			'post_content' => $payload->content_html,
			'post_author'  => $bot_user_id,
			'post_status'  => $auto_publish ? 'publish' : 'draft',
		];

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

		return $post_id;
	}

	/**
	 * Check whether an entry already exists for the given source_ref + term.
	 *
	 * @param string $source_ref Platform-native message id.
	 * @param int    $term_id    Term id.
	 * @return bool
	 */
	private static function entry_exists( string $source_ref, int $term_id ): bool {
		$posts = get_posts(
			[
				'post_type'      => Post_Type::CPT_SLUG,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
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

		return ! empty( $posts );
	}

	/**
	 * Truncate a string to a given length, appending an ellipsis.
	 *
	 * @param string $text   Input text.
	 * @param int    $length Max length.
	 * @return string Truncated title.
	 */
	private static function truncate( string $text, int $length ): string {
		$clean = trim( preg_replace( '/\s+/', ' ', $text ) );

		if ( mb_strlen( $clean ) <= $length ) {
			return $clean;
		}

		return mb_substr( $clean, 0, $length ) . '…';
	}
}
