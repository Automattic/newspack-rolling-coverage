<?php
/**
 * Follow page pattern.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Page_Patterns;

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:paragraph {"fontSize":"large"} -->
<p class="has-large-font-size"><?php esc_html_e( 'Get an alert on your phone or computer when we post an important update to this story.', 'newspack-rolling-coverage' ); ?></p>
<!-- /wp:paragraph -->

<?php echo Page_Patterns::follow(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block markup from serialize_block(). ?>

<!-- wp:columns {"style":{"spacing":{"margin":{"top":"var:preset|spacing|60"}}}} -->
<div class="wp-block-columns" style="margin-top:var(--wp--preset--spacing--60)"><!-- wp:column -->
<div class="wp-block-column"><?php echo Page_Patterns::heading( esc_html__( 'An alert when it matters', 'newspack-rolling-coverage' ), [ 'medium', 'normal' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block markup from serialize_block(). ?>

<!-- wp:paragraph -->
<p><?php esc_html_e( 'We send an alert for the updates that matter, not for every entry.', 'newspack-rolling-coverage' ); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><?php echo Page_Patterns::heading( esc_html__( 'No account needed', 'newspack-rolling-coverage' ), [ 'medium', 'normal' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block markup from serialize_block(). ?>

<!-- wp:paragraph -->
<p><?php esc_html_e( 'There is nothing to sign up for. Your browser asks for permission the first time you select Follow.', 'newspack-rolling-coverage' ); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><?php echo Page_Patterns::heading( esc_html__( 'Stop anytime', 'newspack-rolling-coverage' ), [ 'medium', 'normal' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block markup from serialize_block(). ?>

<!-- wp:paragraph -->
<p><?php esc_html_e( 'Select Following to stop, or turn alerts off in your browser settings.', 'newspack-rolling-coverage' ); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->

<?php echo Page_Patterns::heading( esc_html__( 'Latest updates', 'newspack-rolling-coverage' ), [ 'x-large', 'large' ], 2, '60' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block markup from serialize_block(). ?>

<?php
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block markup from serialize_block().
echo Page_Patterns::feed(
	'digest',
	[
		'latestOnly'  => true,
		'latestCount' => 3,
	]
);
?>
