<?php
/**
 * A stand-in for Newspack's Block_Visibility, for tests that run without
 * Newspack: it hides any block carrying a `zzHiddenFromPublic` attribute.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack;

/**
 * Stand-in for \Newspack\Block_Visibility.
 */
class Block_Visibility {

	/**
	 * Marks this class as the test stand-in.
	 */
	const IS_TEST_STUB = true;

	/**
	 * Content without the blocks hidden from the public.
	 *
	 * @param string $content Serialized block content.
	 * @return string
	 */
	public static function strip_blocks_hidden_from_public( $content ) {
		return serialize_blocks( self::strip( parse_blocks( $content ) ) );
	}

	/**
	 * Parsed blocks without the hidden ones, at any depth.
	 *
	 * @param array $blocks Parsed blocks.
	 * @return array
	 */
	private static function strip( array $blocks ): array {
		$kept = [];

		foreach ( $blocks as $block ) {
			if ( ! empty( $block['attrs']['zzHiddenFromPublic'] ) ) {
				continue;
			}

			$inner_blocks  = [];
			$inner_content = [];
			$index         = 0;

			foreach ( $block['innerContent'] ?? [] as $chunk ) {
				if ( is_string( $chunk ) ) {
					$inner_content[] = $chunk;
					continue;
				}

				$inner = $block['innerBlocks'][ $index++ ] ?? null;

				if ( is_array( $inner ) && empty( $inner['attrs']['zzHiddenFromPublic'] ) ) {
					$inner_blocks[]  = self::strip( [ $inner ] )[0];
					$inner_content[] = null;
				}
			}

			$block['innerBlocks']  = $inner_blocks;
			$block['innerContent'] = $inner_content;
			$kept[]                = $block;
		}

		return $kept;
	}
}
