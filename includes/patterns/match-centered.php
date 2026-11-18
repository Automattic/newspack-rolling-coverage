<?php
/**
 * Match: centered scoreline pattern.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Page_Patterns;

defined( 'ABSPATH' ) || exit;
?>
<?php
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block markup from serialize_block().
echo Page_Patterns::scoreboard(
	[
		'name_size'   => [ 'x-large', 'large' ],
		'score_size'  => [ 'xxxxx-large', 'huge', 'xx-large' ],
		'dash_size'   => [ 'xxxx-large', 'x-large', 'large' ],
		'status_size' => [ 'small' ],
		'gap'         => '50',
		'attributes'  => [
			'style' => [
				'spacing' => [
					'blockGap' => 'var:preset|spacing|20',
					'margin'   => [ 'bottom' => 'var:preset|spacing|30' ],
				],
			],
		],
	]
);
?>

<!-- wp:newspack-rolling-coverage/coverage-status {"showLastUpdated":true,"layout":{"type":"flex","flexWrap":"wrap","verticalAlignment":"center","justifyContent":"center"}} /-->

<?php echo Page_Patterns::feed( 'clock', [ 'showAvatar' => false ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block markup from serialize_block(). ?>
