<?php
/**
 * Slack author resolution.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves Slack users to WordPress authors.
 *
 * A WordPress user can enter their Slack handle or member ID on their
 * profile. Entries from Slack messages that person posts are then credited
 * to them rather than to the generic Slack bot user.
 */
class Slack_Author_Resolver {

	/**
	 * User meta holding the Slack handle or member ID mapped to a WordPress user.
	 */
	const META_SLACK_HANDLE = 'rolling_coverage_slack_handle';

	/**
	 * Name of the Slack handle or member ID field on the profile form.
	 */
	const FIELD_SLACK_HANDLE = 'rolling_coverage_slack_handle';

	/**
	 * Register the Slack handle field on the profile and user edit screens.
	 */
	public static function init(): void {
		add_action( 'show_user_profile', [ __CLASS__, 'render_profile_section' ] );
		add_action( 'edit_user_profile', [ __CLASS__, 'render_profile_section' ] );
		add_action( 'personal_options_update', [ __CLASS__, 'save_profile_section' ] );
		add_action( 'edit_user_profile_update', [ __CLASS__, 'save_profile_section' ] );
	}

	/**
	 * Get or create the Slack bot WordPress user ID.
	 *
	 * @return int WordPress user ID.
	 */
	public static function get_slack_bot_user_id(): int {
		return Slack_Config::get_or_create_bot_user_id();
	}

	/**
	 * The WordPress user to credit with a Slack message, and what matched:
	 * the user whose stored member ID or handle matches the message's author,
	 * or the Slack bot user when nobody's does.
	 *
	 * The member ID comes with the message, so it matches even when the
	 * author's profile could not be read, and only that ID is compared with
	 * stored member IDs. The author's profile names are compared with stored
	 * handles only: a name is free text its owner can change, so a name
	 * shaped like someone's member ID must never reach that person's mapping.
	 * Slack shows a person's display name, or their full name when the
	 * display name is empty; the legacy username comes last. Only users who
	 * can write entries are credited.
	 *
	 * @param string          $slack_user_id The author's Slack member ID.
	 * @param array|\WP_Error $user_info     The author's `users.info` profile.
	 * @return array{user_id: int, matched_by: string} The user ID (0 when the
	 *                                                  bot user is unavailable)
	 *                                                  and `member_id`,
	 *                                                  `display_name`,
	 *                                                  `real_name`, `name` or
	 *                                                  `bot`.
	 */
	public static function resolve_author( string $slack_user_id, array|\WP_Error $user_info ): array {
		$candidates = [ 'member_id' => $slack_user_id ];

		if ( is_array( $user_info ) ) {
			$candidates['display_name'] = $user_info['profile']['display_name'] ?? '';
			$candidates['real_name']    = $user_info['profile']['real_name'] ?? '';
			$candidates['name']         = $user_info['name'] ?? '';
		}

		foreach ( $candidates as $matched_by => $candidate ) {
			$candidate = is_string( $candidate ) ? self::normalize_handle( $candidate ) : '';

			if ( '' === $candidate ) {
				continue;
			}

			$user_ids = array_filter(
				self::find_user_ids( $candidate, 'member_id' === $matched_by ),
				fn( $user_id ) => user_can( $user_id, 'edit_posts' )
			);

			if ( ! empty( $user_ids ) ) {
				return [
					'user_id'    => (int) reset( $user_ids ),
					'matched_by' => $matched_by,
				];
			}
		}

		return [
			'user_id'    => self::get_slack_bot_user_id(),
			'matched_by' => 'bot',
		];
	}

	/**
	 * A handle as stored and compared: trimmed, without the leading `@`
	 * people tend to type.
	 *
	 * @param string $handle Slack handle.
	 * @return string
	 */
	public static function normalize_handle( string $handle ): string {
		return trim( ltrim( trim( $handle ), '@' ) );
	}

	/**
	 * Whether a stored value is a Slack member ID rather than a handle:
	 * uppercase letters and at least one digit, starting with `U`, or `W` on
	 * Enterprise Grid, as Slack's "Copy member ID" gives them. The digit keeps
	 * an all-caps handle such as `WALTERWHITE` a handle.
	 *
	 * @param string $value Normalized handle or member ID.
	 * @return bool
	 */
	public static function is_member_id( string $value ): bool {
		return 1 === preg_match( '/^[UW](?=[A-Z]*[0-9])[A-Z0-9]{8,}$/', $value );
	}

	/**
	 * IDs of the users whose stored value matches, lowest first. A member ID
	 * matches stored member IDs exactly; a handle matches stored handles,
	 * ignoring case as the database collation does.
	 *
	 * @param string $value     Normalized handle or member ID.
	 * @param bool   $member_id Whether `$value` is a member ID.
	 * @return int[]
	 */
	private static function find_user_ids( string $value, bool $member_id ): array {
		$user_ids = get_users(
			[
				'meta_key'   => self::META_SLACK_HANDLE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'fields'     => 'ID',
				'orderby'    => 'ID',
				'order'      => 'ASC',
			]
		);

		return array_values(
			array_filter(
				array_map( 'intval', $user_ids ),
				static function ( int $user_id ) use ( $value, $member_id ): bool {
					$stored = (string) get_user_meta( $user_id, self::META_SLACK_HANDLE, true );
					return $member_id ? $stored === $value : ! self::is_member_id( $stored );
				}
			)
		);
	}

	/**
	 * Render the Rolling Coverage section on the profile and user edit
	 * screens, for users who can write entries and so can be credited.
	 *
	 * @param \WP_User $user The user being edited.
	 */
	public static function render_profile_section( \WP_User $user ): void {
		if ( ! user_can( $user, 'edit_posts' ) ) {
			return;
		}

		$handle         = (string) get_user_meta( $user->ID, self::META_SLACK_HANDLE, true );
		$description_id = self::FIELD_SLACK_HANDLE . '_description';
		?>
		<h2><?php esc_html_e( 'Rolling Coverage', 'newspack-rolling-coverage' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="<?php echo esc_attr( self::FIELD_SLACK_HANDLE ); ?>"><?php esc_html_e( 'Slack handle', 'newspack-rolling-coverage' ); ?></label></th>
				<td>
					<input type="text" name="<?php echo esc_attr( self::FIELD_SLACK_HANDLE ); ?>" id="<?php echo esc_attr( self::FIELD_SLACK_HANDLE ); ?>" value="<?php echo esc_attr( $handle ); ?>" class="regular-text" aria-describedby="<?php echo esc_attr( $description_id ); ?>" />
					<div id="<?php echo esc_attr( $description_id ); ?>">
						<p class="description"><?php esc_html_e( 'A Slack handle or member ID. Rolling coverage entries posted from Slack by that person are credited to this user. A member ID is uppercase, starts with U or W and includes digits, such as U012AB3CDE; anything else is read as a handle.', 'newspack-rolling-coverage' ); ?></p>
						<p class="description"><?php esc_html_e( 'A member ID is the safer choice: it never changes, and nobody can match it by changing their name in Slack, as they can with a handle. To find a member ID in Slack, click the profile picture, choose Profile, then open the More (⋮) menu and choose Copy member ID.', 'newspack-rolling-coverage' ); ?></p>
					</div>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save the Slack handle or member ID from the profile and user edit
	 * screens. A value already mapped to another user who can be credited is
	 * refused, so a Slack message has one person it could go to. A user who
	 * later regains `edit_posts` can bring back a value someone else has
	 * since saved; the lower user ID is then credited.
	 *
	 * @param int $user_id ID of the user being saved.
	 */
	public static function save_profile_section( int $user_id ): void {
		if ( ! current_user_can( 'edit_user', $user_id ) || ! user_can( $user_id, 'edit_posts' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only checks the field was submitted; the nonce is verified next.
		if ( ! isset( $_POST[ self::FIELD_SLACK_HANDLE ] ) ) {
			return;
		}

		check_admin_referer( 'update-user_' . $user_id );

		$handle = self::normalize_handle( sanitize_text_field( wp_unslash( $_POST[ self::FIELD_SLACK_HANDLE ] ) ) );

		if ( '' === $handle ) {
			delete_user_meta( $user_id, self::META_SLACK_HANDLE );
			return;
		}

		$holders = array_filter(
			self::find_user_ids( $handle, self::is_member_id( $handle ) ),
			fn( $holder_id ) => $holder_id !== $user_id && user_can( $holder_id, 'edit_posts' )
		);

		if ( ! empty( $holders ) ) {
			add_action(
				'user_profile_update_errors',
				static function ( \WP_Error $errors ) use ( $handle ) {
					$errors->add(
						'rolling_coverage_slack_handle_taken',
						sprintf(
							/* translators: %s: Slack handle or member ID. */
							__( '<strong>Error:</strong> The Slack handle or member ID %s is already assigned to another user.', 'newspack-rolling-coverage' ),
							'<code>' . esc_html( $handle ) . '</code>'
						)
					);
				}
			);
			return;
		}

		update_user_meta( $user_id, self::META_SLACK_HANDLE, $handle );
	}
}
