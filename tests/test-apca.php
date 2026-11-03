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
	 * Text is black or white by APCA, held to the WCAG 2 AA floor.
	 *
	 * @dataProvider text_color_cases
	 *
	 * @param string $background Background.
	 * @param string $expected   Expected text color.
	 */
	public function test_text_color_picks_by_apca_held_to_the_aa_floor( $background, $expected ) {
		$this->assertSame( $expected, Apca::text_color( $background ) );
	}

	/**
	 * Backgrounds and their expected text colors.
	 *
	 * @return array
	 */
	public function text_color_cases() {
		return [
			'black'      => [ '#000000', '#ffffff' ],
			'white'      => [ '#ffffff', '#000000' ],
			'light gray' => [ '#f0f0f0', '#000000' ],
			'yellow'     => [ '#ffcc00', '#000000' ],
			'gold'       => [ '#ffd700', '#000000' ],
			'mid green'  => [ '#178f15', '#000000' ],
			'blue'       => [ '#3366cc', '#ffffff' ],
			'wp blue'    => [ '#2271b1', '#ffffff' ],
			'red'        => [ '#dd3333', '#ffffff' ],
			'orange'     => [ '#e65100', '#000000' ],
			'shorthand'  => [ Apca::normalize( '#FC0' ), '#000000' ],
			'with alpha' => [ Apca::normalize( '#E6510080' ), '#000000' ],
		];
	}
}
