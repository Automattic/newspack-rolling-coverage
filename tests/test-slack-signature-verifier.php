<?php
/**
 * Tests for Slack request signature verification.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Slack_Config;
use Newspack_Rolling_Coverage\Slack_Signature_Verifier;

/**
 * The signature check is the only thing standing between the public webhook
 * routes and entry creation, so every way of failing it is pinned down here.
 */
class Test_Slack_Signature_Verifier extends Rolling_Coverage_TestCase {

	/**
	 * A signing secret in the format Slack issues.
	 */
	const SIGNING_SECRET = '0123456789abcdef0123456789abcdef';

	/**
	 * A request body as Slack would send it.
	 */
	const REQUEST_BODY = '{"type":"event_callback","event":{"type":"message"}}';

	/**
	 * Security events fired during the test, as [ code, context ] pairs.
	 *
	 * @var array
	 */
	private $security_events = [];

	/**
	 * Record the security events the verifier reports.
	 */
	public function set_up() {
		parent::set_up();
		$this->security_events = [];
		add_action( 'rolling_coverage_slack_security_event', [ $this, 'record_security_event' ], 10, 2 );
	}

	/**
	 * Collect a security event.
	 *
	 * @param string $code    Failure reason code.
	 * @param array  $context Event context.
	 */
	public function record_security_event( $code, $context ) {
		$this->security_events[] = [ $code, $context ];
	}

	/**
	 * Sign a body the way Slack does.
	 *
	 * @param int    $timestamp Request timestamp.
	 * @param string $body      Request body.
	 * @param string $secret    Signing secret.
	 * @return string The `v0=` signature.
	 */
	private static function sign( $timestamp, $body, $secret = self::SIGNING_SECRET ) {
		return 'v0=' . hash_hmac( 'sha256', "v0:{$timestamp}:{$body}", $secret );
	}

	/**
	 * Assert a verification result is a rejection with the expected reason
	 * and that the matching security event was reported.
	 *
	 * @param array  $result         Result of verify().
	 * @param string $expected_reason Reason code returned to the caller.
	 * @param string $expected_event  Code reported to the security event hook.
	 */
	private function assert_rejected( array $result, $expected_reason, $expected_event ) {
		$this->assertFalse( $result['valid'], 'The request should be rejected.' );
		$this->assertSame( $expected_reason, $result['reason'], 'The rejection should carry the expected reason.' );
		$this->assertSame( [ $expected_event ], wp_list_pluck( $this->security_events, 0 ), 'The rejection should be reported as a security event.' );
	}

	/**
	 * A request signed with the configured secret is accepted.
	 */
	public function test_accepts_a_request_signed_with_the_configured_secret() {
		$timestamp = time();
		$verifier  = new Slack_Signature_Verifier( self::SIGNING_SECRET );

		$result = $verifier->verify( self::REQUEST_BODY, self::sign( $timestamp, self::REQUEST_BODY ), $timestamp );

		$this->assertTrue( $result['valid'], 'A correctly signed request should be accepted.' );
		$this->assertSame( [], $this->security_events, 'An accepted request should not report a security event.' );
	}

	/**
	 * Without an injected secret the verifier uses the one saved in settings.
	 */
	public function test_falls_back_to_the_stored_signing_secret() {
		Slack_Config::set_signing_secret( self::SIGNING_SECRET );
		$timestamp = time();
		$verifier  = new Slack_Signature_Verifier();

		$result = $verifier->verify( self::REQUEST_BODY, self::sign( $timestamp, self::REQUEST_BODY ), $timestamp );

		$this->assertTrue( $result['valid'], 'The stored signing secret should be used when none is injected.' );
	}

	/**
	 * A signature does not carry over to a different body.
	 */
	public function test_rejects_a_body_that_was_changed_after_signing() {
		$timestamp = time();
		$verifier  = new Slack_Signature_Verifier( self::SIGNING_SECRET );
		$signature = self::sign( $timestamp, self::REQUEST_BODY );

		$result = $verifier->verify( self::REQUEST_BODY . ' ', $signature, $timestamp );

		$this->assert_rejected( $result, 'invalid_signature', 'signature_mismatch' );
	}

	/**
	 * A signature made with another secret is rejected.
	 */
	public function test_rejects_a_signature_made_with_a_different_secret() {
		$timestamp = time();
		$verifier  = new Slack_Signature_Verifier( self::SIGNING_SECRET );
		$signature = self::sign( $timestamp, self::REQUEST_BODY, 'ffffffffffffffffffffffffffffffff' );

		$result = $verifier->verify( self::REQUEST_BODY, $signature, $timestamp );

		$this->assert_rejected( $result, 'invalid_signature', 'signature_mismatch' );
	}

	/**
	 * With no secret configured the verifier fails closed.
	 *
	 * An HMAC keyed with the empty string is trivial to compute, so a request
	 * signed that way must not pass just because the site has no secret yet.
	 */
	public function test_rejects_a_signature_keyed_with_the_empty_string_when_no_secret_is_configured() {
		$timestamp = time();
		$verifier  = new Slack_Signature_Verifier( '' );
		$signature = self::sign( $timestamp, self::REQUEST_BODY, '' );

		$result = $verifier->verify( self::REQUEST_BODY, $signature, $timestamp );

		$this->assert_rejected( $result, 'configuration_error', 'empty_secret' );
	}

	/**
	 * A captured request cannot be replayed once it is older than the window,
	 * even though its signature is still correct for its own timestamp.
	 */
	public function test_rejects_a_correctly_signed_request_outside_the_replay_window() {
		$stale_timestamp = time() - Slack_Signature_Verifier::MAX_TIMESTAMP_AGE - 5;
		$verifier        = new Slack_Signature_Verifier( self::SIGNING_SECRET );

		$result = $verifier->verify( self::REQUEST_BODY, self::sign( $stale_timestamp, self::REQUEST_BODY ), $stale_timestamp );

		$this->assert_rejected( $result, 'expired_timestamp', 'replay_attack_attempt' );
	}

	/**
	 * The replay window applies to timestamps in the future as well.
	 */
	public function test_rejects_a_correctly_signed_request_dated_in_the_future() {
		$future_timestamp = time() + Slack_Signature_Verifier::MAX_TIMESTAMP_AGE + 5;
		$verifier         = new Slack_Signature_Verifier( self::SIGNING_SECRET );

		$result = $verifier->verify( self::REQUEST_BODY, self::sign( $future_timestamp, self::REQUEST_BODY ), $future_timestamp );

		$this->assert_rejected( $result, 'expired_timestamp', 'replay_attack_attempt' );
	}

	/**
	 * A request inside the window is accepted, so ordinary delivery delay
	 * does not drop messages.
	 */
	public function test_accepts_a_delayed_request_inside_the_replay_window() {
		$delayed_timestamp = time() - Slack_Signature_Verifier::MAX_TIMESTAMP_AGE + 5;
		$verifier          = new Slack_Signature_Verifier( self::SIGNING_SECRET );

		$result = $verifier->verify( self::REQUEST_BODY, self::sign( $delayed_timestamp, self::REQUEST_BODY ), $delayed_timestamp );

		$this->assertTrue( $result['valid'], 'A request inside the replay window should be accepted.' );
	}

	/**
	 * Only the `v0` signing scheme is accepted.
	 */
	public function test_rejects_a_correct_hash_under_an_unknown_scheme_version() {
		$timestamp = time();
		$verifier  = new Slack_Signature_Verifier( self::SIGNING_SECRET );
		$signature = 'v1=' . substr( self::sign( $timestamp, self::REQUEST_BODY ), 3 );

		$result = $verifier->verify( self::REQUEST_BODY, $signature, $timestamp );

		$this->assert_rejected( $result, 'invalid_signature_format', 'invalid_signature_format' );
	}

	/**
	 * A request with no signature header is rejected.
	 */
	public function test_rejects_a_request_without_a_signature() {
		$verifier = new Slack_Signature_Verifier( self::SIGNING_SECRET );

		$this->assert_rejected( $verifier->verify( self::REQUEST_BODY, null, time() ), 'missing_signature', 'missing_signature' );
	}

	/**
	 * A request with no timestamp header is rejected.
	 */
	public function test_rejects_a_request_without_a_timestamp() {
		$timestamp = time();
		$verifier  = new Slack_Signature_Verifier( self::SIGNING_SECRET );

		$result = $verifier->verify( self::REQUEST_BODY, self::sign( $timestamp, self::REQUEST_BODY ), null );

		$this->assert_rejected( $result, 'missing_timestamp', 'missing_timestamp' );
	}
}
