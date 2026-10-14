<?php
/**
 * Tests for the accent color set on the page background.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Readable_Accent;

/**
 * Accents take the primary color when it reads well on the background,
 * else the secondary color, else the text color.
 */
class Test_Readable_Accent extends WP_UnitTestCase {

	const PRIMARY   = 'var(--wp--preset--color--accent)';
	const SECONDARY = 'var(--wp--preset--color--accent-2)';

	/**
	 * A readable primary color wins, even when the secondary contrasts more.
	 */
	public function test_readable_primary_wins() {
		$this->assertSame(
			self::PRIMARY,
			Readable_Accent::pick(
				[
					self::PRIMARY   => '#003da5',
					self::SECONDARY => '#000000',
				],
				'#ffffff'
			)
		);
	}

	/**
	 * A pale primary color gives way to a readable secondary color.
	 */
	public function test_pale_primary_falls_back_to_secondary() {
		$this->assertSame(
			self::SECONDARY,
			Readable_Accent::pick(
				[
					self::PRIMARY   => '#ffcc00',
					self::SECONDARY => '#2055b0',
				],
				'#ffffff'
			)
		);
	}

	/**
	 * With neither color readable, the label takes the text color.
	 */
	public function test_neither_readable_uses_text_color() {
		$this->assertSame(
			'currentcolor',
			Readable_Accent::pick(
				[
					self::PRIMARY   => '#ffcc00',
					self::SECONDARY => '#f5b7c5',
				],
				'#ffffff'
			)
		);
	}

	/**
	 * On a dark background, a light color reads well and a dark one doesn't.
	 */
	public function test_dark_background() {
		$this->assertSame(
			self::SECONDARY,
			Readable_Accent::pick(
				[
					self::PRIMARY   => '#1a1a1a',
					self::SECONDARY => '#c8d6ee',
				],
				'#111111'
			)
		);
	}

	/**
	 * A color that isn't a hex value can't be weighed, so the stylesheet's
	 * own fallback stays.
	 */
	public function test_unmeasurable_color_keeps_fallback() {
		$this->assertSame( '', Readable_Accent::pick( [ self::PRIMARY => 'rgb(0, 61, 165)' ], '#ffffff' ) );
		$this->assertSame( '', Readable_Accent::pick( [ self::PRIMARY => '#003da5' ], 'var(--base)' ) );
	}
}
