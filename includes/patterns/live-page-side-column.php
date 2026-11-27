<?php
/**
 * Live page with side column pattern.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Page_Patterns;

defined( 'ABSPATH' ) || exit;
?>
<?php echo Page_Patterns::side_columns_open(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block markup. ?><!-- wp:column {"verticalAlignment":"top","width":"33.33%"} -->
<div class="wp-block-column is-vertically-aligned-top" style="flex-basis:33.33%"><!-- wp:newspack-rolling-coverage/coverage-status {"showLastUpdated":true} /-->

<?php echo Page_Patterns::follow( true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block markup from serialize_block(). ?></div>
<!-- /wp:column -->

<!-- wp:column {"verticalAlignment":"top","width":"66.66%"} -->
<div class="wp-block-column is-vertically-aligned-top" style="flex-basis:66.66%"><?php echo Page_Patterns::update_timer( [ 'small' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block markup. ?>

<?php echo Page_Patterns::feed( 'default' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block markup from serialize_block(). ?></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->
