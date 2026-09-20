<?php
/**
 * Registers Rolling Coverage key takeaways as a feature with the AI plugin.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

defined( 'ABSPATH' ) || exit;

/*
 * The base class ships with the WordPress AI plugin (WordPress\AI). When that
 * plugin is inactive the class is unavailable, so the feature is declared
 * conditionally. AI_Service::register_feature_class() only references this
 * class behind a class_exists() check, so the conditional declaration is safe.
 */
if ( ! class_exists( '\WordPress\AI\Abstracts\Abstract_Feature' ) ) {
	return;
}

/**
 * Exposes key takeaways generation to the AI plugin.
 *
 * Registering as an AI plugin feature gives Rolling Coverage the same
 * per-feature Provider/Model picker every other AI feature has
 * (Settings → AI → Developer Tools), stored under the option
 * `wpai_feature_{id}_field_developer` and read by AI_Service.
 */
class Key_Takeaways_Feature extends \WordPress\AI\Abstracts\Abstract_Feature {

	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return AI_Service::FEATURE_ID;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return [
			'label'       => __( 'Rolling Coverage Key Takeaways', 'newspack-rolling-coverage' ),
			'description' => __( 'Generates key takeaways from the published entries of a rolling coverage.', 'newspack-rolling-coverage' ),
			'category'    => 'other',
		];
	}

	/**
	 * {@inheritDoc}
	 *
	 * No hooks are registered: the feature exists solely so the AI plugin
	 * can render its per-feature Provider/Model picker for key takeaways.
	 */
	public function register(): void {}
}
