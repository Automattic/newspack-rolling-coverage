<?php
/**
 * Match: scoreboard beside the feed pattern.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Page_Patterns;

defined( 'ABSPATH' ) || exit;

$newspack_rc_players = static function () {
	$items = [];

	foreach ( range( 1, 5 ) as $number ) {
		/* translators: %d: a placeholder player's number in a team's line-up. */
		$items[] = '<!-- wp:list-item -->' . "\n" . '<li>' . esc_html( sprintf( __( 'Player %d', 'newspack-rolling-coverage' ), $number ) ) . '</li>' . "\n" . '<!-- /wp:list-item -->';
	}

	return '<!-- wp:list {"fontSize":"small"} -->' . "\n" . '<ul class="wp-block-list has-small-font-size">' . implode( "\n\n", $items ) . '</ul>' . "\n" . '<!-- /wp:list -->';
};
?>
<?php echo Page_Patterns::side_columns_open(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block markup. ?><!-- wp:column {"verticalAlignment":"top","width":"33.33%"} -->
<div class="wp-block-column is-vertically-aligned-top" style="flex-basis:33.33%">
<?php
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block markup from serialize_block().
echo Page_Patterns::scoreboard(
	[
		'stacked'    => true,
		'score_size' => [ 'xxx-large', 'huge', 'xx-large' ],
		'gap'        => '30',
		'attributes' => [
			'style' => [
				'color'   => [ 'background' => 'var(--wp--preset--color--base-2, var(--newspack-theme-color-bg-light, #f7f7f7))' ],
				'spacing' => [
					'padding'  => [
						'top'    => 'var:preset|spacing|40',
						'right'  => 'var:preset|spacing|40',
						'bottom' => 'var:preset|spacing|40',
						'left'   => 'var:preset|spacing|40',
					],
					'blockGap' => 'var:preset|spacing|20',
				],
			],
		],
	]
);
?>

<!-- wp:details {"style":{"spacing":{"margin":{"top":"var:preset|spacing|50"}}}} -->
<details class="wp-block-details" style="margin-top:var(--wp--preset--spacing--50)"><summary><?php esc_html_e( 'Line-ups', 'newspack-rolling-coverage' ); ?></summary><?php echo Page_Patterns::heading( esc_html__( 'Home team', 'newspack-rolling-coverage' ), [ 'small' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block markup from serialize_block(). ?>

<?php echo $newspack_rc_players(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block markup with escaped text. ?>

<?php echo Page_Patterns::heading( esc_html__( 'Away team', 'newspack-rolling-coverage' ), [ 'small' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block markup from serialize_block(). ?>

<?php echo $newspack_rc_players(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block markup with escaped text. ?></details>
<!-- /wp:details --></div>
<!-- /wp:column -->

<!-- wp:column {"verticalAlignment":"top","width":"66.66%"} -->
<div class="wp-block-column is-vertically-aligned-top" style="flex-basis:66.66%"><!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"layout":{"type":"flex","flexWrap":"wrap","verticalAlignment":"center"}} -->
<div class="wp-block-group"><!-- wp:newspack-rolling-coverage/coverage-status {"showLastUpdated":true} /-->

<?php echo Page_Patterns::follow(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block markup from serialize_block(). ?></div>
<!-- /wp:group -->

<?php echo Page_Patterns::feed( 'clock', [ 'showAvatar' => false ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block markup from serialize_block(). ?></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->
