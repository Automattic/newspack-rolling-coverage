<?php
/**
 * Live page pattern.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Page_Patterns;

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:newspack-rolling-coverage/coverage-status {"showLastUpdated":true} /-->

<!-- wp:paragraph {"fontSize":"large"} -->
<p class="has-large-font-size"><?php esc_html_e( 'Sum up the story in one sentence and say what readers can expect from this liveblog.', 'newspack-rolling-coverage' ); ?></p>
<!-- /wp:paragraph -->

<?php echo Page_Patterns::feed( 'clock', [ 'showAvatar' => false ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block markup from serialize_block(). ?>
