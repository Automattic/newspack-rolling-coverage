<?php
/**
 * Slack image importer.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

defined( 'ABSPATH' ) || exit;

/**
 * Copies the images uploaded with a Slack message into the media library and
 * renders them as image blocks. Other uploads are left out.
 */
class Slack_Media_Importer {

	/**
	 * Image types that are imported: the ones every browser displays.
	 *
	 * @var string[]
	 */
	const MIME_TYPES = [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ];

	/**
	 * Longest a single download may take, in seconds.
	 *
	 * @var int
	 */
	const DOWNLOAD_TIMEOUT = 10;

	/**
	 * Seconds a message's images may take in total. The downloads run inside
	 * the webhook request, under the ingestion lock, and must finish well
	 * before that lock is treated as stale (Entry_Ingestion_Service::MUTEX_TTL):
	 * past it, a delivery Slack retries would create a second entry.
	 *
	 * @var int
	 */
	const TIME_BUDGET = 20;

	/**
	 * API client.
	 *
	 * @var Slack_API_Client
	 */
	private $api_client;

	/**
	 * WP user who owns the imported images.
	 *
	 * @var int
	 */
	private $author_id;

	/**
	 * Time after which no further image is downloaded, as a Unix timestamp
	 * with microseconds.
	 *
	 * @var float
	 */
	private $deadline;

	/**
	 * IDs of the attachments created so far.
	 *
	 * @var int[]
	 */
	private $attachment_ids = [];

	/**
	 * Constructor.
	 *
	 * @param Slack_API_Client $api_client API client.
	 * @param int              $author_id  WP user who owns the imported images.
	 * @param float|null       $deadline   Time after which no further image is
	 *                                     downloaded. Defaults to TIME_BUDGET
	 *                                     seconds from now.
	 */
	public function __construct( Slack_API_Client $api_client, int $author_id, ?float $deadline = null ) {
		$this->api_client = $api_client;
		$this->author_id  = $author_id;
		$this->deadline   = $deadline ?? microtime( true ) + self::TIME_BUDGET;
	}

	/**
	 * Import a message's images.
	 *
	 * An image that cannot be imported is skipped and logged, so the rest of
	 * the message still becomes an entry.
	 *
	 * @param array $files The `files` of a Slack message event.
	 * @return string Block markup, one image block per imported image, or ''.
	 */
	public function import( array $files ): string {
		$blocks = [];

		foreach ( $files as $file ) {
			if ( ! is_array( $file ) ) {
				continue;
			}

			if ( ! in_array( $file['mimetype'] ?? '', self::MIME_TYPES, true ) ) {
				Slack_Monitor::log( 'info', 'Ingestion: upload left out (not an image the site can show)', [ 'file' => (string) ( $file['name'] ?? '' ) ] );
				continue;
			}

			$attachment_id = $this->sideload( $file );

			if ( is_wp_error( $attachment_id ) ) {
				error_log( 'Slack ingestion: image not imported — ' . $attachment_id->get_error_code() . ': ' . $attachment_id->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				Slack_Monitor::log(
					'warning',
					'Ingestion: image not imported',
					[
						'file'    => (string) ( $file['name'] ?? '' ),
						'error'   => $attachment_id->get_error_code(),
						'message' => $attachment_id->get_error_message(),
					]
				);
				continue;
			}

			$this->attachment_ids[] = $attachment_id;

			$alt = trim( (string) ( $file['alt_txt'] ?? '' ) );

			if ( '' !== $alt ) {
				update_post_meta( $attachment_id, '_wp_attachment_image_alt', wp_slash( $alt ) );
			}

			$blocks[] = $this->image_block( $attachment_id, $alt );
		}

		return implode( "\n\n", array_filter( $blocks ) );
	}

	/**
	 * Attach the imported images to the entry they were imported for.
	 *
	 * @param int $post_id Entry post ID.
	 * @return void
	 */
	public function attach_to( int $post_id ): void {
		foreach ( $this->attachment_ids as $attachment_id ) {
			wp_update_post(
				[
					'ID'          => $attachment_id,
					'post_parent' => $post_id,
				]
			);
		}
	}

	/**
	 * Delete the imported images, for a message that did not become an entry.
	 *
	 * @return void
	 */
	public function discard(): void {
		foreach ( $this->attachment_ids as $attachment_id ) {
			wp_delete_attachment( $attachment_id, true );
		}

		$this->attachment_ids = [];
	}

	/**
	 * Download an image and add it to the media library.
	 *
	 * @param array $file Slack file object.
	 * @return int|\WP_Error Attachment ID, or \WP_Error.
	 */
	private function sideload( array $file ) {
		$url       = (string) ( $file['url_private'] ?? '' );
		$remaining = $this->deadline - microtime( true );

		// Deleted files, and files Slack hides on a plan's history limit, have no URL.
		if ( '' === $url ) {
			return new \WP_Error( 'slack_file_unavailable', __( 'Slack no longer has the file.', 'newspack-rolling-coverage' ) );
		}

		if ( (int) ( $file['size'] ?? 0 ) > wp_max_upload_size() ) {
			return new \WP_Error( 'slack_file_too_large', __( 'The image is larger than the site accepts.', 'newspack-rolling-coverage' ) );
		}

		if ( $remaining <= 0 ) {
			return new \WP_Error( 'slack_file_out_of_time', __( 'The message\'s images took too long to download.', 'newspack-rolling-coverage' ) );
		}

		$path = $this->api_client->download_image( $url, (int) min( self::DOWNLOAD_TIMEOUT, ceil( $remaining ) ) );

		if ( is_wp_error( $path ) ) {
			return $path;
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// WordPress checks the content against the file name, so what Slack served must be the image it described.
		$attachment_id = media_handle_sideload(
			[
				'name'     => sanitize_file_name( (string) ( $file['name'] ?? '' ) ),
				'tmp_name' => $path,
			],
			0,
			null,
			[ 'post_author' => $this->author_id ]
		);

		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $path );
		}

		return $attachment_id;
	}

	/**
	 * Render an image block.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $alt           Alternative text.
	 * @return string Block markup, or '' when the attachment has no image.
	 */
	private function image_block( int $attachment_id, string $alt ): string {
		$image = wp_get_attachment_image_src( $attachment_id, 'large' );

		if ( ! $image ) {
			return '';
		}

		return sprintf(
			"<!-- wp:image {\"id\":%1\$d,\"sizeSlug\":\"large\",\"linkDestination\":\"none\"} -->\n<figure class=\"wp-block-image size-large\"><img src=\"%2\$s\" alt=\"%3\$s\" class=\"wp-image-%1\$d\"/></figure>\n<!-- /wp:image -->",
			$attachment_id,
			esc_url( $image[0] ),
			esc_attr( $alt )
		);
	}
}
