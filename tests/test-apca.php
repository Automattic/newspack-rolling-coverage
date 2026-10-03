<?php
/**
 * Tests for the APCA helper.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Apca;

/**
 * Color sanitizing and APCA contrast.
 */
class Test_Apca extends WP_UnitTestCase {

	/**
	 * Contrast matches the published APCA values.
	 */
	public function test_lc_matches_reference_values() {
		$this->assertEqualsWithDelta( 106.04, Apca::lc( '#000000', '#ffffff' ), 0.01 );
		$this->assertEqualsWithDelta( -107.88, Apca::lc( '#ffffff', '#000000' ), 0.01 );
		$this->assertEqualsWithDelta( 63.06, Apca::lc( '#888888', '#ffffff' ), 0.01 );
		$this->assertSame( 0.0, Apca::lc( '#777777', '#777777' ) );
	}

	/**
	 * Hex forms normalize; everything else is rejected.
	 */
	public function test_normalize_accepts_hex_forms_and_rejects_anything_else() {
		$this->assertSame( '#aabbcc', Apca::normalize( '#abc' ) );
		$this->assertSame( '#aabbcc', Apca::normalize( '#AABBCC' ) );
		$this->assertSame( '#aabbcc', Apca::normalize( '  #aBc8 ' ) );
		$this->assertSame( '#112233', Apca::normalize( '#11223344' ) );

		foreach ( [ '', 'red', 'url(x)', '#12345', '#fff;}', '#ggg', '123456', "#fff\n;x", 'rgb(0,0,0)' ] as $junk ) {
			$this->assertSame( '', Apca::normalize( $junk ), $junk );
		}
	}

	/**
	 * Text is whichever of black and white contrasts more.
	 */
	public function test_text_color_picks_whichever_of_black_and_white_contrasts_more() {
		$this->assertSame( '#000000', Apca::text_color( '#ffffff' ) );
		$this->assertSame( '#ffffff', Apca::text_color( '#000000' ) );
		$this->assertSame( '#ffffff', Apca::text_color( '#2271b1' ) );
		$this->assertSame( '#000000', Apca::text_color( '#ffd700' ) );
	}
}
