<?php
/**
 * Breaking news pattern.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Page_Patterns;

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:paragraph {"fontSize":"large"} -->
<p class="has-large-font-size"><?php esc_html_e( 'Say what happened in one or two sentences.', 'newspack-rolling-coverage' ); ?></p>
<!-- /wp:paragraph -->

<?php
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block markup from serialize_block().
echo Page_Patterns::feed(
	'flash',
	[
		'latestOnly'    => true,
		'latestCount'   => 1,
		'hideWhenEnded' => true,
	]
);
?>

<!-- wp:paragraph -->
<p><?php esc_html_e( 'Tell the story: who, what, where and when.', 'newspack-rolling-coverage' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p><?php esc_html_e( 'Add what readers need to do next, and what happens from here.', 'newspack-rolling-coverage' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p><?php esc_html_e( 'Add background and context.', 'newspack-rolling-coverage' ); ?></p>
<!-- /wp:paragraph -->
