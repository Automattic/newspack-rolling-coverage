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
 * in real time. The log endpoint's polling acts as a keep-alive
 * signal; the log file is created on the first poll and automatically
 * deleted once polling stops, so logging only happens while someone
 * is actively watching.
 *
 * Uses the WordPress Filesystem API for create/delete/read operations.
 * The append-write path uses native PHP fopen+flock+fwrite because
 * WP_Filesystem does not support FILE_APPEND or advisory locking,
 * which are required for safe concurrent appends from parallel webhooks.
 */
class Slack_Monitor {

	const LOG_DIR_NAME  = 'newspack-rolling-coverage';
	const MAX_LOG_BYTES = 5242880; // 5 MB cap.

	/**
	 * PHP guard written as the first line of the log file. If the
	 * unguessable filename ever leaks, the .php extension means every
	 * server hands direct requests to the PHP interpreter, which
	 * executes this guard and exits — the log contents are never
	 * served (defense in depth on top of the random name).
	 */
	const PHP_GUARD = "<?php exit; ?>\n";

	/**
	 * Option key storing the timestamp of the last logs poll. Acts as
	 * the monitor keep-alive signal: the log file is created on the
	 * first poll and cleaned up once the stamp is stale or missing.
	 */
	const LAST_SEEN_OPTION = 'rolling_coverage_slack_monitor_last_seen';

	/**
	 * Option key storing the random log filename. The name is
	 * unguessable (wp_generate_password, 32 chars alphanumeric), so
	 * even a server that serves every file in uploads/ cannot serve a
	 * log nobody can name — security that does not depend on nginx
	 * rules or .htaccess being honored.
	 */
	const LOG_FILENAME_OPTION = 'rolling_coverage_slack_monitor_filename';

	/**
	 * How long (seconds) after the last poll before the log file is
	 * considered abandoned and deleted by a subsequent log() call.
	 */
	const KEEP_ALIVE_TIMEOUT = 120; // 2 minutes.

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
	 * Clean up the log file and keep-alive options during plugin deactivation.
	 */
	public static function cleanup(): void {
		$path = self::get_log_path();
		$fs   = self::get_filesystem();

		if ( null !== $path && $fs && $fs->exists( $path ) ) {
			$fs->delete( $path );
		}

		delete_option( self::LAST_SEEN_OPTION );
		delete_option( self::LOG_FILENAME_OPTION );
	}

	/**
	 * Register REST routes for the monitor.
	 */
	public static function register_routes() {
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

		$filename = self::get_session_filename();

		if ( null === $filename ) {
			return null;
		}

		self::$log_path = trailingslashit( $dir ) . $filename;

		return self::$log_path;
	}

	/**
	 * Resolve the random log filename, generating and persisting a
	 * fresh one on first use so log() and the polls always agree on
	 * the name.
	 *
	 * @return string|null Random filename with .php extension, or null.
	 */
	private static function get_session_filename(): ?string {
		$filename = (string) get_option( self::LOG_FILENAME_OPTION, '' );

		if ( '' !== $filename ) {
			return $filename;
		}

		// Generate a fresh unguessable name.
		$filename = wp_generate_password( 32, false, false ) . '.php';

		if ( ! update_option( self::LOG_FILENAME_OPTION, $filename, false ) ) {
			return null;
		}

		return $filename;
	}

	/**
	 * Ensure the log directory denies web access.
	 *
	 * @param string $dir Absolute log directory path.
	 * @return void
	 */
	private static function protect_log_dir( string $dir ): void {
		$fs = self::get_filesystem();

		if ( ! $fs ) {
			return;
		}

		$htaccess = trailingslashit( $dir ) . '.htaccess';

		if ( ! $fs->exists( $htaccess ) ) {
			$fs->put_contents(
				$htaccess,
				"Require all denied\n<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n"
			);
		}

		$index = trailingslashit( $dir ) . 'index.php';

		if ( ! $fs->exists( $index ) ) {
			$fs->put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
	}

	/**
	 * Clean up the log file if monitoring has gone stale.
	 *
	 * @return void
	 */
	private static function cleanup_if_stale(): void {
		$path = self::get_log_path();

		if ( null === $path || ! file_exists( $path ) ) {
			return;
		}

		$last_seen = (int) get_option( self::LAST_SEEN_OPTION, 0 );

		// Only clean up once the keep-alive window has lapsed — the stamp is refreshed on every logs poll, so a live viewer keeps it fresh.
		if ( $last_seen > 0 && ( time() - $last_seen ) < self::KEEP_ALIVE_TIMEOUT ) {
			return;
		}

		// Zero stamp with a file present means a previous session's stale file (option expired or was cleaned): remove it too.
		$fs = self::get_filesystem();

		if ( $fs ) {
			$fs->delete( $path );
		}

		delete_option( self::LAST_SEEN_OPTION );
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

			self::cleanup_if_stale();

			if ( ! file_exists( $path ) ) {
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
					fwrite( $handle, self::PHP_GUARD ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fwrite
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
	 * REST: get log entries since a byte offset. Each poll doubles as
	 * the monitor keep-alive signal — it creates the log file on first
	 * call and refreshes the last-seen stamp so log() keeps writing.
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

		if ( null === $path ) {
			return new WP_REST_Response(
				[
					'lines'  => [],
					'offset' => 0,
				]
			);
		}

		// Refresh keep-alive: this poll is proof someone is watching.
		update_option( self::LAST_SEEN_OPTION, time(), false );

		// First poll of a session (or after a stale cleanup): create a fresh file with the PHP guard as its first line.
		if ( ! file_exists( $path ) ) {
			self::protect_log_dir( dirname( $path ) );

			$fs = self::get_filesystem();

			if ( $fs ) {
				$fs->put_contents( $path, self::PHP_GUARD, FS_CHMOD_FILE );
				self::log( 'info', 'Monitor started.' );
			}

			return new WP_REST_Response(
				[
					'lines'  => [],
					'offset' => strlen( self::PHP_GUARD ),
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
				]
			);
		}

		// Shared lock for consistent reads against the exclusive-write lock.
		flock( $handle, LOCK_SH ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_flock

		$size = fstat( $handle )['size'] ?? 0;
		$size = (int) $size;

		// Byte length of the PHP guard line.
		$guard_length = strlen( self::PHP_GUARD );

		if ( $offset < $guard_length ) {
			$offset = $guard_length;
		}

		// If the file was truncated (size cap hit), reset offset.
		if ( $offset > $size ) {
			$offset = $guard_length;
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
