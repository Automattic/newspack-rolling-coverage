<?php
/**
 * Slack author resolution and entry author display filtering.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves Slack users to WordPress authors and filters entry author display.
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
	 * The WordPress user to credit with a Slack message: the user whose
	 * stored Slack member ID or handle matches the message's author, or the
	 * Slack bot user when nobody does.
	 *
	 * The member ID is tried first. It comes with the message, so it matches
	 * even when the author's profile could not be read, and unlike a handle
	 * it can't be changed or shared by someone else. Slack shows a person's
	 * display name as their handle, and falls back to their username when the
	 * display name is empty, so both are tried next, in that order. Only
	 * users who can write entries are credited.
	 *
	 * @param string          $slack_user_id The author's Slack member ID.
	 * @param array|\WP_Error $user_info     The author's `users.info` profile.
	 * @return int WordPress user ID, or 0 when the bot user is unavailable.
	 */
	public static function resolve_author_id( string $slack_user_id, $user_info ): int {
		$candidates = [ $slack_user_id ];

		if ( is_array( $user_info ) ) {
			$candidates[] = $user_info['profile']['display_name'] ?? '';
			$candidates[] = $user_info['name'] ?? '';
		}

		foreach ( $candidates as $candidate ) {
			$candidate = is_string( $candidate ) ? self::normalize_handle( $candidate ) : '';

			if ( '' === $candidate ) {
				continue;
			}

			$user_ids = self::find_user_ids_by_handle( $candidate, 'edit_posts' );

			if ( ! empty( $user_ids ) ) {
				return (int) $user_ids[0];
			}
		}

		return self::get_slack_bot_user_id();
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
	 * Whether a stored value is a Slack member ID rather than a handle.
	 * Member IDs are uppercase letters and digits starting with `U`, or `W`
	 * on Enterprise Grid, as Slack's "Copy member ID" gives them.
	 *
	 * @param string $value Normalized handle or member ID.
	 * @return bool
	 */
	public static function is_member_id( string $value ): bool {
		return 1 === preg_match( '/^[UW][A-Z0-9]{8,}$/', $value );
	}

	/**
	 * IDs of the users mapped to a Slack handle or member ID. A handle
	 * matches ignoring case, as the database collation does; a member ID
	 * matches only exactly.
	 *
	 * @param string $handle     Normalized Slack handle or member ID.
	 * @param string $capability Capability the users must have, or '' for any user.
	 * @return int[]
	 */
	private static function find_user_ids_by_handle( string $handle, string $capability = '' ): array {
		$args = [
			'meta_key'   => self::META_SLACK_HANDLE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value' => $handle, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'fields'     => 'ID',
			'orderby'    => 'ID',
			'order'      => 'ASC',
		];

		if ( '' !== $capability ) {
			$args['capability'] = $capability;
		}

		$user_ids = array_map( 'intval', get_users( $args ) );

		if ( self::is_member_id( $handle ) ) {
			$user_ids = array_values( array_filter( $user_ids, fn( $user_id ) => get_user_meta( $user_id, self::META_SLACK_HANDLE, true ) === $handle ) );
		}

		return $user_ids;
	}

	/**
	 * Render the Rolling Coverage section on the profile and user edit screens.
	 *
	 * @param \WP_User $user The user being edited.
	 */
	public static function render_profile_section( $user ): void {
		$handle = (string) get_user_meta( $user->ID, self::META_SLACK_HANDLE, true );
		?>
		<h2><?php esc_html_e( 'Rolling Coverage', 'newspack-rolling-coverage' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="<?php echo esc_attr( self::FIELD_SLACK_HANDLE ); ?>"><?php esc_html_e( 'Slack handle', 'newspack-rolling-coverage' ); ?></label></th>
				<td>
					<input type="text" name="<?php echo esc_attr( self::FIELD_SLACK_HANDLE ); ?>" id="<?php echo esc_attr( self::FIELD_SLACK_HANDLE ); ?>" value="<?php echo esc_attr( $handle ); ?>" class="regular-text" />
					<p class="description"><?php esc_html_e( 'This user\'s Slack handle or member ID. Rolling coverage entries they post from Slack are credited to them. A member ID starts with U or W, such as U012AB3CDE; anything else is read as a handle.', 'newspack-rolling-coverage' ); ?></p>
					<p class="description"><?php esc_html_e( 'A member ID is the safer choice: it never changes and nobody else can use it, while anyone in Slack can change their display name to match a handle. To find a member ID in Slack, click the profile picture, choose Profile, then open the More (⋮) menu and choose Copy member ID.', 'newspack-rolling-coverage' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save the Slack handle or member ID from the profile and user edit
	 * screens. A value already mapped to another user is refused, so a Slack
	 * message never has two people it could be credited to.
	 *
	 * @param int $user_id ID of the user being saved.
	 */
	public static function save_profile_section( $user_id ): void {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		check_admin_referer( 'update-user_' . $user_id );

		if ( ! isset( $_POST[ self::FIELD_SLACK_HANDLE ] ) ) {
			return;
		}

		$handle = self::normalize_handle( sanitize_text_field( wp_unslash( $_POST[ self::FIELD_SLACK_HANDLE ] ) ) );

		if ( '' === $handle ) {
			delete_user_meta( $user_id, self::META_SLACK_HANDLE );
			return;
		}

		if ( ! empty( array_diff( self::find_user_ids_by_handle( $handle ), [ (int) $user_id ] ) ) ) {
			add_action(
				'user_profile_update_errors',
				static function ( \WP_Error $errors ) use ( $handle ) {
					$errors->add(
						'rolling_coverage_slack_handle_taken',
						sprintf(
							/* translators: %s: Slack handle. */
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
