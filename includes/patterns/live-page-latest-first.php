<?php
/**
 * Live page, latest first pattern.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Page_Patterns;

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:group {"layout":{"type":"flex","flexWrap":"wrap","justifyContent":"space-between","verticalAlignment":"center"}} -->
<div class="wp-block-group"><!-- wp:newspack-rolling-coverage/coverage-status {"showLastUpdated":true} /-->

<?php echo Page_Patterns::follow(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block markup from serialize_block(). ?></div>
<!-- /wp:group -->

<?php echo Page_Patterns::feed( 'stream' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block markup from serialize_block(). ?>
