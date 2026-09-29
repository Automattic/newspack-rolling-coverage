<?php
/**
 * Normalized source event payload value object.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

defined( 'ABSPATH' ) || exit;

/**
 * Normalized value object produced by every chat-source adapter before calling Entry_Ingestion_Service::ingest().
 */
class Source_Event_Payload {

	/**
	 * Normalized chat-source payload. Promoted parameters become public fields.
	 *
	 * @param string      $source               Platform slug.
	 * @param string      $source_ref           Platform-native message id.
	 * @param string      $conversation_ref     Platform-native conversation id.
	 * @param string|null $author_external_id   Platform-native author id.
	 * @param string|null $author_display_name  Resolved display name.
	 * @param string      $content_html         Sanitized HTML body.
	 * @param string|null $thread_ref           Platform-native thread id.
	 * @param string      $external_timestamp   ISO 8601 timestamp.
	 * @param array       $raw_payload          Original platform-native payload.
	 */
	public function __construct(
		public string $source,
		public string $source_ref,
		public string $conversation_ref,
		public ?string $author_external_id,
		public ?string $author_display_name,
		public string $content_html,
		public ?string $thread_ref,
		public string $external_timestamp,
		public array $raw_payload,
	) {
	}
}
