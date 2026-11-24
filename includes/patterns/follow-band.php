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
<div class="wp-block-group alignfull has-background" style="background-color:var(--wp--preset--color--base-2, var(--newspack-theme-color-bg-light, #f7f7f7));padding-top:var(--wp--preset--spacing--60);padding-right:var(--wp--preset--spacing--40);padding-bottom:var(--wp--preset--spacing--60);padding-left:var(--wp--preset--spacing--40)"><!-- wp:group {"align":"wide","style":{"spacing":{"blockGap":"var:preset|spacing|40"}},"layout":{"type":"flex","flexWrap":"wrap","justifyContent":"space-between","verticalAlignment":"center"}} -->
<div class="wp-block-group alignwide"><!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|20"}},"layout":{"type":"constrained","contentSize":"40rem"}} -->
<div class="wp-block-group"><!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"layout":{"type":"flex","flexWrap":"wrap","verticalAlignment":"center"}} -->
<div class="wp-block-group"><?php echo Page_Patterns::heading( esc_html__( 'Follow this story', 'newspack-rolling-coverage' ), [ 'x-large', 'large' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block markup from serialize_block(). ?>

<!-- wp:newspack-rolling-coverage/coverage-status /--></div>
<!-- /wp:group -->

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
