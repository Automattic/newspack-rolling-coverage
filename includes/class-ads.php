<?php
/**
 * Newspack Ads integration: registers the rolling coverage feed placement
 * and renders its ads across the GAM and Broadstreet providers.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

/**
 * External dependencies.
 */
use Newspack_Ads\Placements;
use Newspack_Ads\Providers;
use Newspack_Ads\Providers\GAM_Model;
use Newspack_Ads\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Integrates rolling coverage entries with Newspack Ads placements.
 */
class Ads {

	// Placement key used for each ad position in the feed.
	const PLACEMENT_KEY = 'rolling_coverage_entry';

	// Maximum number of ads shown across the initial feed and its load-more backlog.
	const INITIAL_AD_CAP = 3;

	/**
	 * Initialize hooks.
	 * 
	 * @return void
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_placement' ], 9 );
	}

	/**
	 * Registers the rolling coverage feed placement in Newspack Ads.
	 *
	 * The hook name lets the placement UI assign an ad unit, even though feed
	 * ads are rendered directly at computed entry positions.
	 *
	 * @return void
	 */
	public static function register_placement() {
		if ( ! self::is_available() ) {
			return;
		}

		Placements::register_placement(
			self::PLACEMENT_KEY,
			[
				'name'        => __( 'Rolling Coverage: Entry', 'newspack-rolling-coverage' ),
				'description' => __( 'Appears at the configured interval in the rolling coverage feed.', 'newspack-rolling-coverage' ),
				'show_ui'     => true,
				'hook_name'   => self::PLACEMENT_KEY . '_ad',
			]
		);
	}

	/**
	 * Checks whether Newspack Ads is active.
	 *
	 * @return bool Whether the Newspack_Ads\Placements class exists.
	 */
	public static function is_available() {
		return class_exists( Placements::class );
	}

	/**
	 * Checks whether the rolling coverage feed placement has been enabled in
	 * Newspack Ads settings.
	 *
	 * @return bool Whether the placement is enabled.
	 */
	public static function is_placement_enabled() {
		return ! empty( self::get_placement_data()['enabled'] );
	}

	/**
	 * Renders one instance of the rolling coverage feed placement.
	 *
	 * Delegates the ad code itself to Providers::render_placement_ad_code(),
	 * which dispatches to whichever provider (GAM, Broadstreet) is assigned
	 * and checks that provider is active before rendering anything.
	 *
	 * @return array{html: string, slots: array[]} Ad HTML and its slot data.
	 */
	public static function render_placement() {
		if ( ! self::is_available() || ! self::can_display() ) {
			return self::empty_result();
		}

		$placement_data = self::get_placement_data();
		$ad_unit_id     = $placement_data['ad_unit'] ?? '';
		$provider_id    = $placement_data['provider'] ?? Providers::get_default_provider();

		if ( ! $ad_unit_id ) {
			return self::empty_result();
		}

		$unique_id = self::PLACEMENT_KEY . '-' . wp_generate_uuid4();

		ob_start();
		Providers::render_placement_ad_code(
			$ad_unit_id,
			$provider_id,
			self::PLACEMENT_KEY,
			'',
			array_merge( $placement_data, [ 'id' => $unique_id ] )
		);
		$ad_code = ob_get_clean();

		if ( ! $ad_code ) {
			return self::empty_result();
		}

		$classnames = [ 'newspack_global_ad', self::PLACEMENT_KEY ];
		if ( class_exists( Settings::class ) && Settings::get_setting( 'fixed_height', 'active' ) ) {
			$classnames[] = 'fixed-height';
		}

		$html = sprintf(
			'<div class="%s">%s</div>',
			esc_attr( implode( ' ', $classnames ) ),
			$ad_code
		);

		$slots = class_exists( GAM_Model::class ) && isset( GAM_Model::$slots[ $unique_id ] )
			? self::build_slots_response( [ $unique_id ] )
			: [];

		return [
			'html'  => $html,
			'slots' => $slots,
		];
	}

	/**
	 * The empty result returned when there's nothing to display.
	 *
	 * @return array{html: string, slots: array[]}
	 */
	private static function empty_result() {
		return [
			'html'  => '',
			'slots' => [],
		];
	}

	/**
	 * Checks whether the placement can display an ad.
	 *
	 * @return bool Whether the placement can currently display an ad.
	 */
	private static function can_display() {
		if ( ! self::is_available() ) {
			return false;
		}

		return \newspack_ads_should_show_ads()
			&& Placements::can_display_ad_unit( self::PLACEMENT_KEY );
	}

	/**
	 * The placement's stored config: enabled state, assigned ad unit, provider.
	 *
	 * @return array Placement data, or an empty array if not registered.
	 */
	private static function get_placement_data() {
		if ( ! self::is_available() ) {
			return [];
		}

		$placements = Placements::get_placements();

		return $placements[ self::PLACEMENT_KEY ]['data'] ?? [];
	}

	/**
	 * Builds GPT slot data for dynamically rendered GAM ads.
	 *
	 * @param string[] $slot_keys Unique IDs of slots to serialize, as stored
	 *                             in GAM_Model::$slots.
	 * @return array[] Array of ad slot data arrays ready for JSON encoding.
	 */
	private static function build_slots_response( array $slot_keys ) {
		$parent_network_code = GAM_Model::get_parent_network_code();
		$network_code        = GAM_Model::get_active_network_code();
		$network_part        = $parent_network_code
			? $parent_network_code . ',' . $network_code
			: $network_code;

		$fixed_height = Settings::get_settings( 'fixed_height' );

		$slots = [];

		foreach ( $slot_keys as $unique_id ) {
			if ( ! isset( GAM_Model::$slots[ $unique_id ] ) ) {
				continue;
			}

			$ad_unit    = GAM_Model::$slots[ $unique_id ];
			$path_parts = [ $network_part ];

			foreach ( $ad_unit['path'] ?? [] as $parent ) {
				$path_parts[] = $parent['code'];
			}

			$path_parts[] = $ad_unit['code'];

			$sizes = $ad_unit['sizes'] ?? [];

			$slots[] = [
				'containerId'     => 'div-gpt-ad-' . $unique_id . '-0',
				'path'            => '/' . implode( '/', $path_parts ),
				'sizes'           => $sizes,
				'fluid'           => ! empty( $ad_unit['fluid'] ),
				'targeting'       => GAM_Model::get_ad_targeting( $ad_unit ),
				'sizeMap'         => GAM_Model::get_ad_unit_size_map( $ad_unit ),
				'boundsSelectors' => apply_filters(
					'newspack_ads_gam_bounds_selectors',
					[ '.wp-block-column', '.entry-content', '.sidebar', '.widget-area' ],
					$ad_unit,
					$sizes
				),
				'boundsBleed'     => (int) apply_filters( 'newspack_ads_gam_bounds_bleed', 40, $ad_unit, $sizes ),
				'fixedHeight'     => [
					'active'       => ! empty( $fixed_height['active'] ),
					'useMaxHeight' => ! empty( $fixed_height['use_max_height'] ),
					'maxHeight'    => (int) ( $fixed_height['max_height'] ?? 100 ),
				],
			];
		}

		return $slots;
	}

	/**
	 * Checks whether an ad belongs after the entry at the given 1-indexed position,
	 * given the requested interval and the initial feed's ad cap.
	 *
	 * @param int $position     1-indexed entry position in the feed.
	 * @param int $ads_interval Show an ad after every N entries.
	 * @return bool Whether an ad belongs after this entry.
	 */
	public static function is_capped_ad_position( int $position, int $ads_interval ) {
		$is_on_interval = 0 === $position % $ads_interval;
		$is_within_cap  = $position <= $ads_interval * self::INITIAL_AD_CAP;

		return $is_on_interval && $is_within_cap;
	}
}
