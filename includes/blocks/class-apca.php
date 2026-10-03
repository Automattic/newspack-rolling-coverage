<?php
/**
 * APCA contrast helpers.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

defined( 'ABSPATH' ) || exit;

/**
 * Color sanitizing and APCA (0.0.98G-4g) contrast, used to pick readable text
 * for a custom badge background.
 */
class Apca {

	const MAIN_TRC    = 2.4;
	const S_RCO       = 0.2126729;
	const S_GCO       = 0.7151522;
	const S_BCO       = 0.0721750;
	const NORM_BG     = 0.56;
	const NORM_TXT    = 0.57;
	const REV_TXT     = 0.62;
	const REV_BG      = 0.65;
	const BLK_THRS    = 0.022;
	const BLK_CLMP    = 1.414;
	const SCALE_BOW   = 1.14;
	const SCALE_WOB   = 1.14;
	const LO_BOW_OFF  = 0.027;
	const LO_WOB_OFF  = 0.027;
	const DELTA_Y_MIN = 0.0005;
	const LO_CLIP     = 0.1;

	/**
	 * Normalizes a hex color to lowercase `#rrggbb`, dropping any alpha.
	 * Anything else returns '', so the result is safe for inline CSS.
	 *
	 * @param string $color Color such as `#abc`, `#AABBCC` or `#aabbccdd`.
	 * @return string Lowercase `#rrggbb`, or ''.
	 */
	public static function normalize( string $color ): string {
		$color = strtolower( trim( $color ) );

		if ( ! preg_match( '/^#([0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/D', $color, $matches ) ) {
			return '';
		}

		$hex = $matches[1];

		if ( strlen( $hex ) <= 4 ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		return '#' . substr( $hex, 0, 6 );
	}

	/**
	 * Black or white, whichever contrasts more with the background.
	 *
	 * @param string $background Normalized `#rrggbb` background.
	 * @return string `#000000` or `#ffffff`; black on a tie.
	 */
	public static function text_color( string $background ): string {
		$black = abs( self::lc( '#000000', $background ) );
		$white = abs( self::lc( '#ffffff', $background ) );

		return $white > $black ? '#ffffff' : '#000000';
	}

	/**
	 * APCA lightness contrast of text on a background.
	 *
	 * @param string $text       Normalized `#rrggbb` text color.
	 * @param string $background Normalized `#rrggbb` background color.
	 * @return float Lc: positive for dark text on light, negative for light on dark.
	 */
	public static function lc( string $text, string $background ): float {
		$y_txt = self::soft_clamp( self::luminance( $text ) );
		$y_bg  = self::soft_clamp( self::luminance( $background ) );

		if ( abs( $y_bg - $y_txt ) < self::DELTA_Y_MIN ) {
			return 0.0;
		}

		if ( $y_bg > $y_txt ) {
			$sapc = ( pow( $y_bg, self::NORM_BG ) - pow( $y_txt, self::NORM_TXT ) ) * self::SCALE_BOW;
			$out  = $sapc < self::LO_CLIP ? 0.0 : $sapc - self::LO_BOW_OFF;
		} else {
			$sapc = ( pow( $y_bg, self::REV_BG ) - pow( $y_txt, self::REV_TXT ) ) * self::SCALE_WOB;
			$out  = $sapc > -self::LO_CLIP ? 0.0 : $sapc + self::LO_WOB_OFF;
		}

		return $out * 100;
	}

	/**
	 * Screen luminance of a `#rrggbb` color.
	 *
	 * @param string $color Normalized color.
	 * @return float
	 */
	private static function luminance( string $color ): float {
		$red   = hexdec( substr( $color, 1, 2 ) ) / 255;
		$green = hexdec( substr( $color, 3, 2 ) ) / 255;
		$blue  = hexdec( substr( $color, 5, 2 ) ) / 255;

		return self::S_RCO * pow( $red, self::MAIN_TRC )
			+ self::S_GCO * pow( $green, self::MAIN_TRC )
			+ self::S_BCO * pow( $blue, self::MAIN_TRC );
	}

	/**
	 * Lifts very dark luminance values, as APCA specifies.
	 *
	 * @param float $y Luminance.
	 * @return float
	 */
	private static function soft_clamp( float $y ): float {
		return $y < self::BLK_THRS ? $y + pow( self::BLK_THRS - $y, self::BLK_CLMP ) : $y;
	}
}
