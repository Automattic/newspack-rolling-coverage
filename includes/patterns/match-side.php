<?php
/**
 * Match: scoreboard beside the feed pattern.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Page_Patterns;

defined( 'ABSPATH' ) || exit;

$newspack_rc_lineups = static function () {
	$accent = 'var(--newspack-rolling-coverage-accent, var(--wp--preset--color--accent, var(--newspack-theme-color-primary)))';
	$block  = static function ( string $name, array $attrs, string $open, array $inner = [], string $close = '' ): array {
		return [
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => $inner,
			'innerHTML'    => $open . $close,
			'innerContent' => $inner ? array_merge( [ $open ], array_fill( 0, count( $inner ), null ), [ $close ] ) : [ $open ],
		];
	};

	$label_html = '<p class="use-header-font has-text-color has-small-font-size" style="color:' . $accent . ';font-weight:700">' . esc_html__( 'Line-ups', 'newspack-rolling-coverage' ) . '</p>';
	$label      = $block(
		'core/paragraph',
		[
			'fontSize'  => 'small',
			'className' => 'use-header-font',
			'style'     => [
				'color'      => [ 'text' => $accent ],
				'typography' => [ 'fontWeight' => '700' ],
			],
		],
		$label_html
	);

	$tables = [];
	foreach ( [ __( 'Home team', 'newspack-rolling-coverage' ), __( 'Away team', 'newspack-rolling-coverage' ) ] as $team ) {
		$rows = '';
		foreach ( range( 1, 5 ) as $number ) {
			/* translators: %d: a placeholder player's number in a team's line-up. */
			$rows .= '<tr><td>' . esc_html( sprintf( __( 'Player %d', 'newspack-rolling-coverage' ), $number ) ) . '</td></tr>';
		}
		$tables[] = $block(
			'core/table',
			[ 'fontSize' => 'small' ],
			'<figure class="wp-block-table has-small-font-size"><table><thead><tr><th>' . esc_html( $team ) . '</th></tr></thead><tbody>' . $rows . '</tbody></table></figure>'
		);
	}

	$card = $block(
		'core/group',
		[
			'style'  => [
				'border'  => [
					'color' => $accent,
					'style' => 'solid',
					'width' => '1px',
				],
				'color'   => [ 'background' => 'var(--newspack-rolling-coverage-accent-tint)' ],
				'spacing' => [
					'margin'   => [ 'top' => 'var:preset|spacing|50' ],
					'padding'  => [
						'top'    => 'var:preset|spacing|40',
						'right'  => 'var:preset|spacing|40',
						'bottom' => 'var:preset|spacing|40',
						'left'   => 'var:preset|spacing|40',
					],
					'blockGap' => 'var:preset|spacing|30',
				],
			],
			'layout' => [
				'type'           => 'flex',
				'orientation'    => 'vertical',
				'justifyContent' => 'stretch',
			],
		],
		'<div class="wp-block-group has-border-color has-background" style="border-color:' . $accent . ';border-style:solid;border-width:1px;background-color:var(--newspack-rolling-coverage-accent-tint);margin-top:var(--wp--preset--spacing--50);padding-top:var(--wp--preset--spacing--40);padding-right:var(--wp--preset--spacing--40);padding-bottom:var(--wp--preset--spacing--40);padding-left:var(--wp--preset--spacing--40)">',
		array_merge( [ $label ], $tables ),
		'</div>'
	);

	return serialize_block( $card );
};
?>
<?php echo Page_Patterns::side_columns_open(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block markup. ?><!-- wp:column {"verticalAlignment":"top","width":"33.33%"} -->
<div class="wp-block-column is-vertically-aligned-top" style="flex-basis:33.33%">
<?php
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block markup from serialize_block().
echo Page_Patterns::scoreboard(
	[
		'stacked'    => true,
		'score_size' => [ 'xxx-large', 'huge', 'xx-large' ],
		'gap'        => '30',
		'attributes' => [
			'style' => [
				'color'   => [ 'background' => 'var(--wp--preset--color--base-2, var(--newspack-theme-color-bg-light, #f7f7f7))' ],
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

<?php echo $newspack_rc_lineups(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block markup with escaped text. ?></div>
<!-- /wp:column -->

<!-- wp:column {"verticalAlignment":"top","width":"66.66%"} -->
<div class="wp-block-column is-vertically-aligned-top" style="flex-basis:66.66%"><?php echo Page_Patterns::follow(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block markup from serialize_block(). ?>

<?php echo Page_Patterns::feed( 'minute', [ 'archivedNoticeShow' => false ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block markup from serialize_block(). ?></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->
