<?php
/**
 * Follow band pattern.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Page_Patterns;

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:group {"align":"full","style":{"color":{"background":"var(--wp--preset--color--base-2, var(--newspack-theme-color-bg-light, #f7f7f7))"},"spacing":{"padding":{"top":"var:preset|spacing|60","bottom":"var:preset|spacing|60","left":"var:preset|spacing|40","right":"var:preset|spacing|40"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-background" style="background-color:var(--wp--preset--color--base-2, var(--newspack-theme-color-bg-light, #f7f7f7));padding-top:var(--wp--preset--spacing--60);padding-right:var(--wp--preset--spacing--40);padding-bottom:var(--wp--preset--spacing--60);padding-left:var(--wp--preset--spacing--40)"><!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|40"}},"layout":{"type":"flex","flexWrap":"wrap","justifyContent":"space-between","verticalAlignment":"center"}} -->
<div class="wp-block-group"><!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|20"}},"layout":{"type":"flex","orientation":"vertical","justifyContent":"stretch"}} -->
<div class="wp-block-group"><!-- wp:heading {"fontSize":"x-large"} -->
<h2 class="wp-block-heading has-x-large-font-size"><?php esc_html_e( 'Follow this story', 'newspack-rolling-coverage' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:newspack-rolling-coverage/coverage-status /-->

<!-- wp:paragraph -->
<p><?php esc_html_e( 'Get an alert on your phone or computer when we post an important update. No account needed.', 'newspack-rolling-coverage' ); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

<?php echo Page_Patterns::follow(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block markup from serialize_block(). ?></div>
<!-- /wp:group --></div>
<!-- /wp:group -->

<?php
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block markup from serialize_block().
echo Page_Patterns::feed(
	'wire',
	[
		'latestOnly'  => true,
		'latestCount' => 5,
	]
);
?>
