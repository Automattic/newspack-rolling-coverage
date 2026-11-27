<?php
/**
 * Live page with side column pattern.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Page_Patterns;

defined( 'ABSPATH' ) || exit;
?>
<?php echo Page_Patterns::status( [ 'small' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block markup. ?>

<?php echo Page_Patterns::side_columns_open(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block markup. ?><!-- wp:column {"verticalAlignment":"top","width":"33.33%"} -->
<div class="wp-block-column is-vertically-aligned-top" style="flex-basis:33.33%"><!-- wp:group {"style":{"border":{"width":"1px","style":"solid"},"spacing":{"padding":{"top":"var:preset|spacing|40","right":"var:preset|spacing|40","bottom":"var:preset|spacing|40","left":"var:preset|spacing|40"},"blockGap":"var:preset|spacing|30"}},"borderColor":"base-3","layout":{"type":"flex","orientation":"vertical","justifyContent":"left"}} -->
<div class="wp-block-group has-border-color has-base-3-border-color" style="border-style:solid;border-width:1px;padding-top:var(--wp--preset--spacing--40);padding-right:var(--wp--preset--spacing--40);padding-bottom:var(--wp--preset--spacing--40);padding-left:var(--wp--preset--spacing--40)"><?php echo Page_Patterns::heading( esc_html__( 'Alerts on this story', 'newspack-rolling-coverage' ), [ 'medium', 'normal' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block markup from serialize_block(). ?>

<!-- wp:paragraph {"fontSize":"small"} -->
<p class="has-small-font-size"><?php esc_html_e( 'We send an alert to your phone or computer when something important happens.', 'newspack-rolling-coverage' ); ?></p>
<!-- /wp:paragraph -->

<?php echo Page_Patterns::follow(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block markup from serialize_block(). ?></div>
<!-- /wp:group --></div>
<!-- /wp:column -->

<!-- wp:column {"verticalAlignment":"top","width":"66.66%"} -->
<div class="wp-block-column is-vertically-aligned-top" style="flex-basis:66.66%"><?php echo Page_Patterns::feed( 'default' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block markup from serialize_block(). ?></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->
