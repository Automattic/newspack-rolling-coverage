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
	 * Seconds after which no further image of a message starts downloading.
	 * The import runs inside the webhook request, so this keeps a message with
	 * many images from holding that request, and delaying its entry, for much
	 * longer than that.
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
	 * @param array         $files       The `files` of a Slack message event.
	 * @param callable|null $on_progress Called while WordPress processes each
	 *                                   image, which can take longer than the
	 *                                   download.
	 * @return string Block markup: an image block for one imported image, a
	 *                gallery of image blocks for several, or ''.
	 */
	public function import( array $files, ?callable $on_progress = null ): string {
		$blocks      = [];
		$on_progress = $on_progress ?? static function () {};

		foreach ( $files as $file ) {
			if ( ! is_array( $file ) ) {
				continue;
			}

			// In a Slack Connect channel a file arrives without its type or URL, which only a `files.info` call returns.
			if ( 'check_file_info' === ( $file['file_access'] ?? '' ) ) {
				$this->log_failure( $file, new \WP_Error( 'slack_file_details_withheld', __( 'Slack sent the file without its details, as it does in Slack Connect channels. These files are not imported.', 'newspack-rolling-coverage' ) ) );
				continue;
			}

			if ( ! in_array( $file['mimetype'] ?? '', self::MIME_TYPES, true ) ) {
				Slack_Monitor::log( 'info', 'Ingestion: upload left out (not an image the site can show)', [ 'file' => (string) ( $file['name'] ?? '' ) ] );
				continue;
			}

			$attachment_id = $this->sideload( $file, $on_progress );

			if ( is_wp_error( $attachment_id ) ) {
				$this->log_failure( $file, $attachment_id );
				continue;
			}

			$this->attachment_ids[] = $attachment_id;

			$alt = sanitize_text_field( (string) ( $file['alt_txt'] ?? '' ) );

			if ( '' !== $alt ) {
				update_post_meta( $attachment_id, '_wp_attachment_image_alt', wp_slash( $alt ) );
			}

			$blocks[] = $this->image_block( $attachment_id, $alt );
		}

		$blocks = array_values( array_filter( $blocks ) );

		if ( count( $blocks ) < 2 ) {
			return implode( '', $blocks );
		}

		// Photos posted together are shown together, as the gallery an editor would insert, with its default layout.
		return "<!-- wp:gallery {\"linkTo\":\"none\"} -->\n<figure class=\"wp-block-gallery has-nested-images columns-default is-cropped\">" . implode( "\n\n", $blocks ) . "</figure>\n<!-- /wp:gallery -->";
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
	 * Record why an image was left out, where the newsroom and the host can
	 * each find it.
	 *
	 * @param array     $file  Slack file object.
	 * @param \WP_Error $error Reason the image was not imported.
	 * @return void
	 */
	private function log_failure( array $file, \WP_Error $error ): void {
		error_log( 'Slack ingestion: image not imported — ' . $error->get_error_code() . ': ' . $error->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		Slack_Monitor::log(
			'warning',
			'Ingestion: image not imported',
			[
				'file'    => (string) ( $file['name'] ?? $file['id'] ?? '' ),
				'error'   => $error->get_error_code(),
				'message' => $error->get_error_message(),
			]
		);
	}

	/**
	 * Download an image and add it to the media library.
	 *
	 * @param array    $file        Slack file object.
	 * @param callable $on_progress Called while WordPress processes the image.
	 * @return int|\WP_Error Attachment ID, or \WP_Error.
	 */
	private function sideload( array $file, callable $on_progress ): int|\WP_Error {
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

		// WordPress saves the image's metadata after each size it makes, which marks progress while it resizes a large photo.
		$report_progress = static function ( $metadata ) use ( $on_progress ) {
			$on_progress();

			return $metadata;
		};

		add_filter( 'wp_update_attachment_metadata', $report_progress );

		try {
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
		} finally {
			remove_filter( 'wp_update_attachment_metadata', $report_progress );
		}

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
	 * @return string Block markup, or '' when the attachment has no file.
	 */
	private function image_block( int $attachment_id, string $alt ): string {
		// WordPress resizes a GIF to a single frame, so an animated one is only shown whole from the uploaded file.
		$size = 'image/gif' === get_post_mime_type( $attachment_id ) ? 'full' : 'large';

		// The URL the editor would store. `wp_get_attachment_image_src()` would give an image CDN's URL when one filters the request.
		$copy = 'full' === $size ? false : image_get_intermediate_size( $attachment_id, $size );
		$url  = $copy['url'] ?? wp_get_attachment_url( $attachment_id );

		if ( ! $url ) {
			return '';
		}

		return sprintf(
			"<!-- wp:image {\"id\":%1\$d,\"sizeSlug\":\"%4\$s\",\"linkDestination\":\"none\"} -->\n<figure class=\"wp-block-image size-%4\$s\"><img src=\"%2\$s\" alt=\"%3\$s\" class=\"wp-image-%1\$d\"/></figure>\n<!-- /wp:image -->",
			$attachment_id,
			esc_url( $url ),
			esc_attr( $alt ),
			$size
		);
	}
}
