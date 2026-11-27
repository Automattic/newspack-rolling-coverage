<?php
/**
 * Match: scoreboard beside the feed pattern.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Page_Patterns;

defined( 'ABSPATH' ) || exit;
?>
<?php echo Page_Patterns::side_columns_open(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block markup. ?><!-- wp:column {"width":"33.33%"} -->
<div class="wp-block-column" style="flex-basis:33.33%"><!-- wp:group {"style":{"position":{"type":"sticky","top":"0px"}},"layout":{"type":"default"}} -->
<div class="wp-block-group">
<?php
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block markup from serialize_block().
echo Page_Patterns::scoreboard(
	[
		'accent'       => true,
		'stacked'      => true,
		'score_size'   => [ 'xxx-large', 'huge', 'xx-large' ],
		'gap'          => '30',
		'outline'      => true,
		'status_first' => true,
		'attributes'   => [
			'showStatusAsBadge' => true,
			'style'             => [
				'spacing' => [
					'padding'  => [
						'top'    => 'var:preset|spacing|40',
						'right'  => 'var:preset|spacing|40',
						'bottom' => 'var:preset|spacing|40',
						'left'   => 'var:preset|spacing|40',
					],
					'blockGap' => 'var:preset|spacing|20',
				],
			],
		],
	]
);
?>
</div>
<!-- /wp:group --></div>
<!-- /wp:column -->

<!-- wp:column {"verticalAlignment":"top","width":"66.66%"} -->
<div class="wp-block-column is-vertically-aligned-top" style="flex-basis:66.66%"><?php echo Page_Patterns::follow(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block markup from serialize_block(). ?>

<?php echo Page_Patterns::feed( 'minute', [ 'archivedNoticeShow' => false ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block markup from serialize_block(). ?></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->
