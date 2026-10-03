const MAIN_TRC = 2.4;
const S_RCO = 0.2126729;
const S_GCO = 0.7151522;
const S_BCO = 0.072175;
const NORM_BG = 0.56;
const NORM_TXT = 0.57;
const REV_TXT = 0.62;
const REV_BG = 0.65;
const BLK_THRS = 0.022;
const BLK_CLMP = 1.414;
const SCALE_BOW = 1.14;
const SCALE_WOB = 1.14;
const LO_BOW_OFF = 0.027;
const LO_WOB_OFF = 0.027;
const DELTA_Y_MIN = 0.0005;
const LO_CLIP = 0.1;

/**
 * Normalizes a hex color to lowercase `#rrggbb`, dropping any alpha.
 * Anything else returns '', matching Apca::normalize().
 *
 * @param {string} color Color such as `#abc`, `#AABBCC` or `#aabbccdd`.
 * @return {string} Lowercase `#rrggbb`, or ''.
 */
function normalizeColor( color: string ): string {
	const value = String( color ).trim().toLowerCase();
	const match = /^#([0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/.exec( value );

	if ( ! match ) {
		return '';
	}

	let hex = match[ 1 ];

	if ( hex.length <= 4 ) {
		hex = hex[ 0 ] + hex[ 0 ] + hex[ 1 ] + hex[ 1 ] + hex[ 2 ] + hex[ 2 ];
	}

	return '#' + hex.slice( 0, 6 );
}

/**
 * Screen luminance of a `#rrggbb` color.
 *
 * @param {string} color Normalized color.
 * @return {number} Luminance.
 */
function luminance( color: string ): number {
	const red = parseInt( color.slice( 1, 3 ), 16 ) / 255;
	const green = parseInt( color.slice( 3, 5 ), 16 ) / 255;
	const blue = parseInt( color.slice( 5, 7 ), 16 ) / 255;

	return (
		S_RCO * Math.pow( red, MAIN_TRC ) +
		S_GCO * Math.pow( green, MAIN_TRC ) +
		S_BCO * Math.pow( blue, MAIN_TRC )
	);
}

/**
 * Lifts very dark luminance values, as APCA specifies.
 *
 * @param {number} y Luminance.
 * @return {number} Clamped luminance.
 */
function softClamp( y: number ): number {
	return y < BLK_THRS ? y + Math.pow( BLK_THRS - y, BLK_CLMP ) : y;
}

/**
 * APCA lightness contrast of text on a background.
 *
 * @param {string} text       Normalized `#rrggbb` text color.
 * @param {string} background Normalized `#rrggbb` background color.
 * @return {number} Lc: positive for dark text on light, negative for light on dark.
 */
function lc( text: string, background: string ): number {
	const yTxt = softClamp( luminance( text ) );
	const yBg = softClamp( luminance( background ) );

	if ( Math.abs( yBg - yTxt ) < DELTA_Y_MIN ) {
		return 0;
	}

	let out: number;

	if ( yBg > yTxt ) {
		const sapc =
			( Math.pow( yBg, NORM_BG ) - Math.pow( yTxt, NORM_TXT ) ) *
			SCALE_BOW;
		out = sapc < LO_CLIP ? 0 : sapc - LO_BOW_OFF;
	} else {
		const sapc =
			( Math.pow( yBg, REV_BG ) - Math.pow( yTxt, REV_TXT ) ) * SCALE_WOB;
		out = sapc > -LO_CLIP ? 0 : sapc + LO_WOB_OFF;
	}

	return out * 100;
}

/**
 * Black or white, whichever contrasts more with the background.
 *
 * @param {string} background Normalized `#rrggbb` background.
 * @return {string} `#000000` or `#ffffff`; black on a tie.
 */
function textColor( background: string ): string {
	const black = Math.abs( lc( '#000000', background ) );
	const white = Math.abs( lc( '#ffffff', background ) );

	return white > black ? '#ffffff' : '#000000';
}

export { normalizeColor, textColor };
