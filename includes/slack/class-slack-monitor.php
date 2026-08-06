<?php
/**
 * Real-time Slack event monitor.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Manages a temporary log file that captures Slack integration events
 * in real time. The file is created when a user opens the Monitor tab
 * and deleted when the tab is closed, so logging only happens while
 * someone is actively watching.
 *
 * Uses the WordPress Filesystem API for create/delete/read operations.
 * The append-write path uses native PHP fopen+flock+fwrite because
 * WP_Filesystem does not support FILE_APPEND or advisory locking,
 * which are required for safe concurrent appends from parallel webhooks.
 */
class Slack_Monitor {

	const LOG_DIR_NAME  = 'newspack-rolling-coverage';
	const LOG_FILENAME  = 'slack.log';
	const MAX_LOG_BYTES = 5242880; // 5 MB cap.

	/**
	 * Transient key for tracking active monitor viewer count.
	 */
	const VIEWER_COUNT_TRANSIENT = 'rolling_coverage_slack_monitor_viewers';

	/**
	 * Transient TTL for viewer count (1 hour — re-upped on each start/stop).
	 */
	const VIEWER_COUNT_TTL = 3600;

	/**
	 * Cached log file path (resolved once from wp_upload_dir).
	 *
	 * @var string|null
	 */
	private static $log_path = null;

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );

		add_action( 'rolling_coverage_slack_channel_linked', [ __CLASS__, 'on_channel_linked' ], 10, 2 );
		add_action( 'rolling_coverage_slack_channel_unlinked', [ __CLASS__, 'on_channel_unlinked' ], 10, 1 );
		add_action( 'rolling_coverage_slack_security_event', [ __CLASS__, 'on_security_event' ], 10, 2 );
	}

	/**
	 * Clean up the log file and viewer count transient.
	 * Called on plugin deactivation.
	 */
	public static function cleanup(): void {
		$path = self::get_log_path();
		$fs   = self::get_filesystem();

		if ( null !== $path && $fs && $fs->exists( $path ) ) {
			$fs->delete( $path );
		}

		delete_transient( self::VIEWER_COUNT_TRANSIENT );
	}

	/**
	 * Register REST routes for the monitor.
	 */
	public static function register_routes() {
		register_rest_route(
			Slack::REST_NAMESPACE,
			'/slack/monitor/start',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ __CLASS__, 'handle_start' ],
				'permission_callback' => [ __CLASS__, 'can_monitor' ],
			]
		);

		register_rest_route(
			Slack::REST_NAMESPACE,
			'/slack/monitor/stop',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'handle_stop' ],
				'permission_callback' => [ __CLASS__, 'can_monitor' ],
				'args'                => [
					'_wpnonce' => [
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		register_rest_route(
			Slack::REST_NAMESPACE,
			'/slack/monitor/logs',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'handle_get_logs' ],
				'permission_callback' => [ __CLASS__, 'can_monitor' ],
				'args'                => [
					'offset' => [
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);
	}

	/**
	 * Permission check: user must be able to manage options.
	 *
	 * @return bool
	 */
	public static function can_monitor(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Get the WP_Filesystem instance (initializes once).
	 * Forces the 'direct' method since the uploads directory is
	 * always directly writable by PHP.
	 *
	 * @return \WP_Filesystem_Base|null
	 */
	private static function get_filesystem(): ?\WP_Filesystem_Base {
		global $wp_filesystem;

		if ( $wp_filesystem instanceof \WP_Filesystem_Base ) {
			return $wp_filesystem;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		// Force 'direct' method — the uploads dir is PHP-writable.
		WP_Filesystem( [ 'method' => 'direct' ] );

		return $wp_filesystem instanceof \WP_Filesystem_Base ? $wp_filesystem : null;
	}

	/**
	 * Get the full path to the log file (cached).
	 *
	 * @return string|null File path, or null if uploads dir is not writable.
	 */
	private static function get_log_path(): ?string {
		if ( null !== self::$log_path ) {
			return self::$log_path;
		}

		$uploads = wp_upload_dir();

		if ( ! empty( $uploads['error'] ) ) {
			return null;
		}

		$dir = trailingslashit( $uploads['basedir'] ) . self::LOG_DIR_NAME;

		if ( ! wp_mkdir_p( $dir ) && ! is_dir( $dir ) ) {
			return null;
		}

		self::$log_path = trailingslashit( $dir ) . self::LOG_FILENAME;

		return self::$log_path;
	}

	/**
	 * Write a log entry. No-ops if the monitor file does not exist.
	 *
	 * Uses native PHP fopen + flock(LOCK_EX) + fwrite for atomic
	 * concurrent appends. This is the only operation WP_Filesystem
	 * cannot perform (no FILE_APPEND or advisory lock support).
	 *
	 * Wrapped in try/catch so monitor failures never break webhooks.
	 *
	 * @param string $level   Log level: info, warning, error, success.
	 * @param string $message Human-readable message.
	 * @param array  $context Optional key-value context.
	 */
	public static function log( string $level, string $message, array $context = [] ): void {
		try {
			$path = self::get_log_path();

			if ( null === $path || ! file_exists( $path ) ) {
				return;
			}

			$entry = wp_json_encode(
				[
					'timestamp' => gmdate( 'Y-m-d\TH:i:s\Z' ),
					'level'     => $level,
					'message'   => $message,
					'context'   => $context,
				]
			);

			if ( false === $entry ) {
				return;
			}

			// Atomic append with exclusive lock - safe for concurrent webhooks - WP_Filesystem does not support FILE_APPEND or advisory locking.
			$handle = fopen( $path, 'a' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

			if ( ! $handle ) {
				return;
			}

			if ( flock( $handle, LOCK_EX ) ) { // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_flock
				// Enforce size cap by truncating if the file is too large.
				if ( fstat( $handle )['size'] > self::MAX_LOG_BYTES ) {
					ftruncate( $handle, 0 ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_ftruncate
				}

				fwrite( $handle, $entry . "\n" ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fwrite
				fflush( $handle );
				flock( $handle, LOCK_UN ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_flock
			}

			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		} catch ( Throwable $e ) {
			error_log( 'Slack_Monitor::log failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return;
		}
	}

	/**
	 * REST: start monitoring — create the log file if this is the
	 * first viewer. Increment the viewer count either way.
	 *
	 * @return WP_REST_Response
	 */
	public static function handle_start(): WP_REST_Response {
		$path = self::get_log_path();
		$fs   = self::get_filesystem();

		if ( null === $path || ! $fs ) {
			return new WP_REST_Response(
				[
					'success' => false,
					'error'   => 'uploads_dir_error',
				],
				500
			);
		}

		// Increment viewer count.
		$count = (int) get_transient( self::VIEWER_COUNT_TRANSIENT );
		++$count;
		set_transient( self::VIEWER_COUNT_TRANSIENT, $count, self::VIEWER_COUNT_TTL );

		// First viewer: create a fresh file. Subsequent viewers: keep the existing file so they see the same ongoing logs.
		// Edge case: if the viewer count was 0 (expired transient from a previous browser crash), truncate the stale file.
		if ( 1 === $count || ! $fs->exists( $path ) ) {
			$fs->put_contents( $path, '', FS_CHMOD_FILE );
			self::log( 'info', 'Monitor started.' );
		}

		return new WP_REST_Response( [ 'success' => true ], 200 );
	}

	/**
	 * REST: stop monitoring — decrement the viewer count and delete
	 * the log file only when the last viewer leaves.
	 *
	 * @return WP_REST_Response
	 */
	public static function handle_stop(): WP_REST_Response {
		$path = self::get_log_path();
		$fs   = self::get_filesystem();

		// Decrement viewer count.
		$count = (int) get_transient( self::VIEWER_COUNT_TRANSIENT );
		$count = max( 0, $count - 1 );

		if ( $count > 0 ) {
			set_transient( self::VIEWER_COUNT_TRANSIENT, $count, self::VIEWER_COUNT_TTL );
			return new WP_REST_Response( [ 'success' => true ], 200 );
		}

		// Last viewer — clean up.
		delete_transient( self::VIEWER_COUNT_TRANSIENT );

		if ( null !== $path && $fs && $fs->exists( $path ) ) {
			$fs->delete( $path );
		}

		return new WP_REST_Response( [ 'success' => true ], 200 );
	}

	/**
	 * REST: get log entries since a byte offset.
	 *
	 * Uses native PHP fseek + stream_get_contents to read only the
	 * new bytes since the last poll, not the entire file.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function handle_get_logs( WP_REST_Request $request ): WP_REST_Response {
		$path   = self::get_log_path();
		$offset = (int) $request->get_param( 'offset' );

		if ( null === $path || ! file_exists( $path ) ) {
			return new WP_REST_Response(
				[
					'lines'  => [],
					'offset' => 0,
					'active' => false,
				]
			);
		}

		// Read only new content since the offset using seek.
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fopen

		if ( ! $handle ) {
			return new WP_REST_Response(
				[
					'lines'  => [],
					'offset' => 0,
					'active' => true,
				]
			);
		}

		// Shared lock for consistent reads against the exclusive-write lock.
		flock( $handle, LOCK_SH ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_flock

		$size = fstat( $handle )['size'] ?? 0;
		$size = (int) $size;

		// If the file was truncated (size cap hit), reset offset.
		if ( $offset > $size ) {
			$offset = 0;
		}

		fseek( $handle, $offset );

		$buffer = stream_get_contents( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fread

		flock( $handle, LOCK_UN ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_flock
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fclose

		$new_offset = $offset + strlen( $buffer );

		$lines = [];

		foreach ( explode( "\n", trim( $buffer ) ) as $line ) {
			if ( '' === $line ) {
				continue;
			}

			$decoded = json_decode( $line, true );

			if ( is_array( $decoded ) ) {
				$lines[] = $decoded;
			}
		}

		return new WP_REST_Response(
			[
				'lines'  => $lines,
				'offset' => $new_offset,
				'active' => true,
			]
		);
	}

	/**
	 * Action: channel linked.
	 *
	 * @param string $channel_id Slack channel ID.
	 * @param int    $term_id    Coverage term ID.
	 */
	public static function on_channel_linked( $channel_id, $term_id ): void {
		self::log(
			'success',
			'Channel linked to coverage',
			[
				'channel_id' => $channel_id,
				'term_id'    => $term_id,
			]
		);
	}

	/**
	 * Action: channel unlinked.
	 *
	 * @param string $channel_id Slack channel ID.
	 */
	public static function on_channel_unlinked( $channel_id ): void {
		self::log( 'info', 'Channel unlinked from coverage', [ 'channel_id' => $channel_id ] );
	}

	/**
	 * Action: security event.
	 *
	 * @param string $type Event type.
	 * @param array  $data Context data.
	 */
	public static function on_security_event( $type, $data ): void {
		$level = in_array( $type, [ 'signature_mismatch', 'replay_attack_attempt' ], true ) ? 'error' : 'warning';
		self::log( $level, "Security event: {$type}", is_array( $data ) ? $data : [ 'data' => $data ] );
	}
}
