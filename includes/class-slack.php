<?php
/**
 * Slack integration orchestrator.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

defined( 'ABSPATH' ) || exit;

/**
 * Slack integration orchestrator. Wires the per-adapter dependencies and registers the admin and webhook routes.
 */
class Slack {

	const REST_NAMESPACE = 'rolling-coverage/v1';

	/**
	 * Cached webhook controller instance.
	 *
	 * @var Slack_Webhook_Controller|null
	 */
	private static $webhook_controller = null;

	/**
	 * Initialize the Slack integration.
	 */
	public static function init(): void {
		self::register_hooks();
		self::register_conditional_hooks();
	}

	/**
	 * Register always-on admin REST routes.
	 */
	public static function register_hooks(): void {
		add_action( 'rest_api_init', [ self::get_webhook_controller(), 'register_admin_routes' ] );
	}

	/**
	 * Register webhook routes and filters only when Slack is configured.
	 */
	public static function register_conditional_hooks(): void {
		if ( ! Slack_Config::is_configured() ) {
			return;
		}

		add_action( 'rest_api_init', [ self::get_webhook_controller(), 'register_webhook_routes' ] );
		add_action( 'delete_' . Taxonomy::TAXONOMY_SLUG, [ Slack_Config::class, 'on_term_deleted' ], 10, 1 );
	}

	/**
	 * Lazily instantiate the webhook controller with all dependencies.
	 *
	 * @return Slack_Webhook_Controller
	 */
	private static function get_webhook_controller(): Slack_Webhook_Controller {
		if ( null !== self::$webhook_controller ) {
			return self::$webhook_controller;
		}

		// Validates Slack request HMAC signatures → permission_callback for webhook routes.
		$signature_verifier = new Slack_Signature_Verifier( Slack_Config::get_signing_secret() );
		// Outbound Slack API calls: auth.test, conversations.info, users.info, chat.postMessage.
		$api_client = new Slack_API_Client();
		// Maps Slack authors to display names + resolves the bot WP user.
		$author_resolver = new Slack_Author_Resolver();
		// Converts Slack mrkdwn/markdown to entry HTML.
		$content_processor = new Slack_Content_Processor();

		self::$webhook_controller = new Slack_Webhook_Controller(
			$api_client,
			$signature_verifier,
			$author_resolver,
			$content_processor
		);

		return self::$webhook_controller;
	}
}
