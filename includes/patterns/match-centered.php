<?php
/**
 * Match: centered scoreline pattern.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Page_Patterns;

defined( 'ABSPATH' ) || exit;
?>
<?php
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block markup from serialize_block().
echo Page_Patterns::scoreboard(
	[
		'accent'      => true,
		'name_size'   => [ 'x-large', 'large' ],
		'score_size'  => [ 'xxxx-large', 'huge', 'xx-large' ],
		'dash_size'   => [ 'xxx-large', 'x-large', 'large' ],
		'status_size' => [ 'small' ],
		'gap'         => '50',
		'attributes'  => [
			'showStatusAsBadge' => true,
			'style'             => [
				'spacing' => [
					'blockGap' => 'var:preset|spacing|20',
					'margin'   => [ 'bottom' => 'var:preset|spacing|30' ],
				],
			],
		],
	]
);
echo "\n\n" . Page_Patterns::feed( 'minute', [ 'archivedNoticeShow' => false ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block markup from serialize_block().
