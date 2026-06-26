<?php
/**
 * Slack request signature verifier.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

defined( 'ABSPATH' ) || exit;

/**
 * Verifies Slack-signed requests using the HMAC-SHA256 signing scheme.
 */
class Slack_Signature_Verifier {

	const MAX_TIMESTAMP_AGE = 300;

	/**
	 * Signing secret used for verification.
	 *
	 * @var string
	 */
	private $signing_secret;

	/**
	 * Constructor.
	 *
	 * @param string|null $signing_secret Optional signing secret; falls back to Slack_Config.
	 */
	public function __construct( ?string $signing_secret = null ) {
		if ( null === $signing_secret ) {
			$signing_secret = Slack_Config::get_signing_secret();
		}

		$this->signing_secret = (string) $signing_secret;
	}

	/**
	 * Verify the signature of an incoming Slack request.
	 *
	 * @param string      $raw_body   Raw request body.
	 * @param string|null $signature   X-Slack-Signature header.
	 * @param int|null    $timestamp   X-Slack-Request-Timestamp header.
	 * @return array ['valid'=>bool, 'reason'=>string]
	 */
	public function verify( string $raw_body, ?string $signature, ?int $timestamp ): array {
		if ( null === $signature || '' === $signature ) {

			/**
			 * Fires when a Slack webhook request fails signature verification.
			 *
			 * @param string $type The failure reason code.
			 * @param array  $data Optional context for the event.
			 */
			do_action( 'rolling_coverage_slack_security_event', 'missing_signature', [] );

			return [
				'valid'  => false,
				'reason' => 'missing_signature',
			];
		}

		if ( '' === $this->signing_secret ) {

			/** This action is documented above. */
			do_action( 'rolling_coverage_slack_security_event', 'empty_secret', [] );

			return [
				'valid'  => false,
				'reason' => 'configuration_error',
			];
		}

		if ( null === $timestamp ) {

			/** This action is documented above. */
			do_action( 'rolling_coverage_slack_security_event', 'missing_timestamp', [] );

			return [
				'valid'  => false,
				'reason' => 'missing_timestamp',
			];
		}

		$delta = abs( time() - $timestamp );

		if ( $delta > self::MAX_TIMESTAMP_AGE ) {

			/** This action is documented above. */
			do_action( 'rolling_coverage_slack_security_event', 'replay_attack_attempt', [ 'timestamp_delta' => $delta ] );

			return [
				'valid'  => false,
				'reason' => 'expired_timestamp',
			];
		}

		if ( 0 !== strpos( $signature, 'v0=' ) ) {

			/** This action is documented above. */
			do_action( 'rolling_coverage_slack_security_event', 'invalid_signature_format', [ 'signature_prefix' => substr( $signature, 0, 10 ) ] );

			return [
				'valid'  => false,
				'reason' => 'invalid_signature_format',
			];
		}

		$expected = $this->generate_signature( (string) $timestamp, $raw_body );

		if ( ! hash_equals( $expected, $signature ) ) {

			/** This action is documented above. */
			do_action(
				'rolling_coverage_slack_security_event',
				'signature_mismatch',
				[
					'timestamp'   => $timestamp,
					'body_length' => strlen( $raw_body ),
				]
			);

			return [
				'valid'  => false,
				'reason' => 'invalid_signature',
			];
		}

		return [
			'valid'  => true,
			'reason' => '',
		];
	}

	/**
	 * Generate the expected Slack signature for a given timestamp and body.
	 *
	 * @param string $timestamp Slack request timestamp.
	 * @param string $raw_body  Raw request body.
	 * @return string Computed 'v0=...' signature.
	 */
	public function generate_signature( string $timestamp, string $raw_body ): string {
		return 'v0=' . hash_hmac( 'sha256', "v0:{$timestamp}:{$raw_body}", $this->signing_secret );
	}
}
