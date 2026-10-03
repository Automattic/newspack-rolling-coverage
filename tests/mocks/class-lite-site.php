<?php
/**
 * Stand-in for the Lite Site plugin.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Lite_Site;

/**
 * The one method Rolling Coverage calls. Like the real plugin's, it renders
 * blocks inside the lite content filter, drops comments and scripts, and
 * keeps only the elements and attributes on its allowlist, which plugins
 * extend through `newspack_lite_site_allowed_html`. The default list is Lite
 * Site's.
 */
class Lite_Site {

	/**
	 * Elements and attributes lite pages keep by default.
	 *
	 * @var array
	 */
	const ALLOWED_HTML = [
		'p'          => [ 'class' => true ],
		'h1'         => [],
		'h2'         => [],
		'h3'         => [],
		'h4'         => [],
		'h5'         => [],
		'h6'         => [],
		'ul'         => [],
		'ol'         => [],
		'li'         => [],
		'blockquote' => [],
		'strong'     => [],
		'em'         => [],
		'b'          => [],
		'i'          => [],
		'a'          => [
			'href'  => true,
			'title' => true,
		],
		'br'         => [],
		'div'        => [
			'class'        => true,
			'data-src'     => true,
			'data-srcset'  => true,
			'data-alt'     => true,
			'data-caption' => true,
		],
		'button'     => [
			'class' => true,
			'type'  => true,
		],
	];

	/**
	 * Clean post content for a lite page.
	 *
	 * Blocks render inside the content filter, at priority 9, as the real
	 * plugin hooks them, so blocks in content cleaned along the way render
	 * for a lite page too. The hook is added on first use, as the core test
	 * case restores hooks after each test.
	 *
	 * @param string $content Post content.
	 * @return string Cleaned HTML.
	 */
	public static function clean_content( $content ) {
		if ( false === has_filter( 'newspack_lite_site_post_content', 'do_blocks' ) ) {
			add_filter( 'newspack_lite_site_post_content', 'do_blocks', 9 );
		}

		$content = apply_filters( 'newspack_lite_site_post_content', $content );
		$content = preg_replace( '/<!--.*?-->/s', '', $content );
		$content = preg_replace( '/<script.*?>.*?<\/script>/is', '', $content );

		return wp_kses( $content, apply_filters( 'newspack_lite_site_allowed_html', self::ALLOWED_HTML ) );
	}
}
