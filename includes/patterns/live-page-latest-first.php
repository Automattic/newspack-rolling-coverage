<?php
/**
 * Live page, latest first pattern.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Page_Patterns;

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:newspack-rolling-coverage/coverage-status {"showLastUpdated":true} /-->

<?php
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block markup from serialize_block().
echo Page_Patterns::feed(
	'default',
	[
		'latestOnly'  => true,
		'latestCount' => 1,
	],
	[
		'core/post-title'   => [
			'level'    => 2,
			'fontSize' => [ 'xx-large', 'huge', 'x-large' ],
		],
		'core/post-content' => [ 'fontSize' => [ 'large' ] ],
	]
);
?>

<?php echo Page_Patterns::heading( esc_html__( 'All updates', 'newspack-rolling-coverage' ), [ 'x-large', 'large' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block markup from serialize_block(). ?>

<?php echo Page_Patterns::feed( 'stream' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block markup from serialize_block(). ?>
