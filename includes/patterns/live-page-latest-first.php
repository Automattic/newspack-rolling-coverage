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

<!-- wp:heading {"fontSize":"x-large"} -->
<h2 class="wp-block-heading has-x-large-font-size"><?php esc_html_e( 'All updates', 'newspack-rolling-coverage' ); ?></h2>
<!-- /wp:heading -->

<?php echo Page_Patterns::feed( 'stream' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block markup from serialize_block(). ?>
