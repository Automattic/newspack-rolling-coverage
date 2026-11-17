<?php
/**
 * Every live story pattern.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Page_Patterns;

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:paragraph {"fontSize":"large"} -->
<p class="has-large-font-size"><?php esc_html_e( 'Choose the stories you want alerts for. We send a short alert to your phone or computer when something important is posted.', 'newspack-rolling-coverage' ); ?></p>
<!-- /wp:paragraph -->

<?php for ( $row = 0; $row < 3; $row++ ) : ?>
<!-- wp:group {"style":{"border":{"bottom":{"color":"var(--wp--preset--color--base-3, var(--newspack-theme-color-border, #ddd))","width":"1px","style":"solid"}},"spacing":{"padding":{"top":"var:preset|spacing|40","bottom":"var:preset|spacing|40"},"blockGap":"var:preset|spacing|40"}},"layout":{"type":"flex","flexWrap":"wrap","justifyContent":"space-between","verticalAlignment":"center"}} -->
<div class="wp-block-group" style="border-bottom-color:var(--wp--preset--color--base-3, var(--newspack-theme-color-border, #ddd));border-bottom-style:solid;border-bottom-width:1px;padding-top:var(--wp--preset--spacing--40);padding-bottom:var(--wp--preset--spacing--40)"><!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|20"}},"layout":{"type":"flex","orientation":"vertical","justifyContent":"stretch"}} -->
<div class="wp-block-group"><!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"layout":{"type":"flex","flexWrap":"wrap","verticalAlignment":"center"}} -->
<div class="wp-block-group"><!-- wp:heading {"level":3,"fontSize":"large"} -->
<h3 class="wp-block-heading has-large-font-size"><?php esc_html_e( 'Liveblog name', 'newspack-rolling-coverage' ); ?></h3>
<!-- /wp:heading -->

<!-- wp:newspack-rolling-coverage/coverage-status /--></div>
<!-- /wp:group -->

<!-- wp:paragraph -->
<p><?php esc_html_e( 'One line about this liveblog.', 'newspack-rolling-coverage' ); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

	<?php echo Page_Patterns::follow(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block markup from serialize_block(). ?></div>
<!-- /wp:group -->

<?php endfor; ?>
