<?php

declare(strict_types=1);

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Isolated PHPUnit namespace matches the test autoload contract.
namespace Tests\RepositoryProvider;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Booster\Bitbucket\BitbucketWebhookNormalizer;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderDiagnosticResult;
use RAN\RepositoryProvider\SignedWebhookVerification;
use RAN\RepositoryProvider\WebhookEnvelope;
use RAN\RepositoryProvider\WebhookRejected;
use RAN\RepositoryProvider\WebhookRequest;
use RAN\Secrets\SecretsFile;

final class BitbucketWebhookNormalizerTest extends TestCase {

	private const OWNER_SECRET     = 'owner-bitbucket-webhook-secret';
	private const OTHER_SECRET     = 'other-bitbucket-webhook-secret';
	private const BODY_CANARY      = 'body-canary-private-value';
	private const REPOSITORY       = 'RocketsAreNostalgic/ran.booster';
	private const REPOSITORY_ID    = '{673a6070-3421-46c9-9d48-90745f7bfe8e}';
	private const COMMIT_A         = '0123456789abcdef0123456789abcdef01234567';
	private const COMMIT_B         = '89abcdef0123456789abcdef0123456789abcdef';
	private const ZERO_COMMIT      = '0000000000000000000000000000000000000000';
	private const DELIVERY_ID      = '{request-uuid-kept-verbatim}';
	private const MAX_BODY_BYTES   = 262144;
	private const RETAINED_HEADERS = array( 'x-event-key', 'x-request-uuid', 'x-hub-signature' );

	public function test_multi_change_push_produces_exact_ordered_neutral_events_sharing_the_raw_delivery_id(): void {
		$payload                    = $this->valid_push_payload();
		$payload['push']['changes'] = array(
			$this->branch_change(
				'main',
				strtoupper( self::COMMIT_A ),
				array(
					'created'   => true,
					'truncated' => true,
					'commits'   => array( array( 'hash' => '0123456789ab' ) ),
				)
			),
			$this->branch_change(
				'release/alpha',
				self::COMMIT_B,
				array(
					'forced'    => true,
					'truncated' => true,
					'commits'   => array( array( 'hash' => '89abcdef0123' ) ),
				)
			),
		);
		$body                       = $this->encode( $payload );
		$events                     = $this->normalizer()->normalize_webhook( $this->request( $body ) )->get_events();

		self::assertSame(
			array(
				array(
					'provider'               => 'bb',
					'repository'             => self::REPOSITORY,
					'provider_repository_id' => self::REPOSITORY_ID,
					'branch'                 => 'main',
					'commit'                 => self::COMMIT_A,
					'delivery_id'            => self::DELIVERY_ID,
				),
				array(
					'provider'               => 'bb',
					'repository'             => self::REPOSITORY,
					'provider_repository_id' => self::REPOSITORY_ID,
					'branch'                 => 'release/alpha',
					'commit'                 => self::COMMIT_B,
					'delivery_id'            => self::DELIVERY_ID,
				),
			),
			array_map( static fn ( object $event ): array => $event->to_array(), $events )
		);
	}

	public function test_push_changes_are_bounded_before_event_normalization(): void {
		$payload                    = $this->valid_push_payload();
		$payload['push']['changes'] = array_fill( 0, 33, $this->branch_change( 'main', self::COMMIT_A ) );

		$this->assert_rejected(
			400,
			fn (): WebhookEnvelope => $this->normalizer()->normalize_webhook( $this->request( $this->encode( $payload ) ) )
		);
	}

	public function test_official_atlassian_hmac_vector_is_accepted(): void {
		$secret     = "It's a Secret to Everybody";
		$body       = 'Hello World!';
		$signature  = 'sha256=a4771c39fbe90f317c7824e83ddef3caae9cb3d976c214ace1f2937e133263c9';
		$normalizer = $this->normalizer(
			array( $this->profile( $secret, 'owner', 'RocketsAreNostalgic' ) )
		);
		$request    = ( new WebhookRequest(
			ProviderCode::parse( 'bb' ),
			$body,
			array(
				'X-Event-Key'     => 'repo:updated',
				'X-Hub-Signature' => $signature,
			),
			self::RETAINED_HEADERS
		) )->with_verification( $this->verification( 'owner', 'RocketsAreNostalgic' ) );

		self::assertTrue( $normalizer->normalize_webhook( $request )->is_ignored() );
	}

	public function test_verified_whitespace_and_unicode_body_bytes_are_parsed_untouched(): void {
		$payload          = $this->valid_push_payload();
		$payload['actor'] = array( 'display_name' => self::BODY_CANARY . ' José 🚀' );
		$body             = $this->encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
		$signature        = $this->signature( $body );
		$request          = $this->request_with_signature( $body, $signature );

		self::assertTrue( $this->normalizer()->normalize_webhook( $request )->has_events() );
	}

	#[DataProvider( 'invalid_signature_provider' )]
	public function test_missing_invalid_uppercase_and_unsupported_signatures_are_rejected( ?string $signature ): void {
		$body    = $this->encode( $this->valid_push_payload() );
		$headers = array(
			'X-Event-Key'    => 'repo:push',
			'X-Request-UUID' => self::DELIVERY_ID,
		);

		if ( null !== $signature ) {
			$headers['X-Hub-Signature'] = $signature;
		}

		$this->assert_rejected(
			401,
			fn (): WebhookEnvelope => $this->normalizer()->normalize_webhook(
				new WebhookRequest( ProviderCode::parse( 'bb' ), $body, $headers, self::RETAINED_HEADERS )
			)
		);
	}

	/**
	 * @return iterable<string, array{?string}>
	 */
	public static function invalid_signature_provider(): iterable {
		yield 'missing' => array( null );
		yield 'wrong digest' => array( 'sha256=' . str_repeat( 'a', 64 ) );
		yield 'uppercase digest' => array( 'sha256=' . str_repeat( 'A', 64 ) );
		yield 'uppercase algorithm' => array( 'SHA256=' . str_repeat( 'a', 64 ) );
		yield 'unsupported algorithm' => array( 'sha1=' . str_repeat( 'a', 40 ) );
		yield 'invalid encoding' => array( 'sha256=not-hex' );
	}

	public function test_missing_processor_verification_is_rejected_without_leaks(): void {
		$normalizer = $this->normalizer( array() );

		$this->assert_rejected(
			401,
			static fn (): WebhookEnvelope => $normalizer->normalize_webhook(
				new WebhookRequest(
					ProviderCode::parse( 'bb' ),
					'{"private":"' . self::BODY_CANARY . '"}',
					array(
						'X-Event-Key'     => 'repo:updated',
						'X-Hub-Signature' => 'sha256=invalid',
					),
					self::RETAINED_HEADERS
				)
			)
		);
	}

	public function test_oversized_body_is_rejected_before_hmac_while_the_exact_limit_is_accepted(): void {
		$oversized = str_repeat( 'x', self::MAX_BODY_BYTES + 1 );

		$this->assert_rejected(
			413,
			fn (): WebhookEnvelope => $this->normalizer()->normalize_webhook(
				new WebhookRequest(
					ProviderCode::parse( 'bb' ),
					$oversized,
					array( 'X-Hub-Signature' => 'sha256=' . str_repeat( '0', 64 ) ),
					self::RETAINED_HEADERS
				)
			)
		);

		$base = $this->encode( $this->valid_push_payload() );
		$body = $base . str_repeat( ' ', self::MAX_BODY_BYTES - strlen( $base ) );

		self::assertSame( self::MAX_BODY_BYTES, strlen( $body ) );
		self::assertTrue( $this->normalizer()->normalize_webhook( $this->request( $body ) )->has_events() );
	}

	public function test_provider_mismatch_is_rejected(): void {
		$body    = $this->encode( $this->valid_push_payload() );
		$request = new WebhookRequest(
			ProviderCode::parse( 'gh' ),
			$body,
			array(
				'X-Event-Key'     => 'repo:push',
				'X-Request-UUID'  => self::DELIVERY_ID,
				'X-Hub-Signature' => $this->signature( $body ),
			),
			self::RETAINED_HEADERS
		);

		$this->assert_rejected(
			400,
			fn (): WebhookEnvelope => $this->normalizer()->normalize_webhook( $request )
		);
	}

	public function test_signed_unrelated_event_is_ignored_without_delivery_or_json_parsing(): void {
		$body    = 'not-json-' . self::BODY_CANARY;
		$request = ( new WebhookRequest(
			ProviderCode::parse( 'bb' ),
			$body,
			array(
				'X-Event-Key'     => 'repo:updated',
				'X-Hub-Signature' => $this->signature( $body ),
			),
			self::RETAINED_HEADERS
		) )->with_verification( $this->verification( 'owner', 'RocketsAreNostalgic' ) );

		self::assertTrue( $this->normalizer()->normalize_webhook( $request )->is_ignored() );
	}

	#[DataProvider( 'invalid_event_provider' )]
	public function test_event_key_is_required_and_bounded( ?string $event ): void {
		$body    = $this->encode( $this->valid_push_payload() );
		$headers = array( 'X-Hub-Signature' => $this->signature( $body ) );

		if ( null !== $event ) {
			$headers['X-Event-Key'] = $event;
		}

		$this->assert_rejected(
			400,
			fn (): WebhookEnvelope => $this->normalizer()->normalize_webhook(
				new WebhookRequest( ProviderCode::parse( 'bb' ), $body, $headers, self::RETAINED_HEADERS )
			)
		);
	}

	/**
	 * @return iterable<string, array{?string}>
	 */
	public static function invalid_event_provider(): iterable {
		yield 'missing' => array( null );
		yield 'embedded space' => array( 'repo: push' );
		yield 'control byte' => array( "repo:\npush" );
		yield 'too long' => array( str_repeat( 'e', 129 ) );
	}

	#[DataProvider( 'invalid_delivery_provider' )]
	public function test_push_delivery_id_is_required_and_bounded( ?string $delivery ): void {
		$body    = $this->encode( $this->valid_push_payload() );
		$headers = array(
			'X-Event-Key'     => 'repo:push',
			'X-Hub-Signature' => $this->signature( $body ),
		);

		if ( null !== $delivery ) {
			$headers['X-Request-UUID'] = $delivery;
		}

		$this->assert_rejected(
			400,
			fn (): WebhookEnvelope => $this->normalizer()->normalize_webhook(
				new WebhookRequest( ProviderCode::parse( 'bb' ), $body, $headers, self::RETAINED_HEADERS )
			)
		);
	}

	/**
	 * @return iterable<string, array{?string}>
	 */
	public static function invalid_delivery_provider(): iterable {
		yield 'missing' => array( null );
		yield 'embedded space' => array( 'request uuid' );
		yield 'control byte' => array( "request\x7f" );
		yield 'too long' => array( str_repeat( 'd', 129 ) );
	}

	public function test_trimmed_boundary_headers_are_accepted_and_the_delivery_id_is_preserved(): void {
		$body     = $this->encode( $this->valid_push_payload() );
		$event    = 'repo:push';
		$delivery = str_repeat( 'd', 128 );
		$request  = ( new WebhookRequest(
			ProviderCode::parse( 'bb' ),
			$body,
			array(
				'X-Event-Key'     => " \t{$event}\t ",
				'X-Request-UUID'  => " \t{$delivery}\t ",
				'X-Hub-Signature' => $this->signature( $body ),
			),
			self::RETAINED_HEADERS
		) )->with_verification( $this->verification( 'owner', 'RocketsAreNostalgic' ) );

		$normalized = $this->normalizer()->normalize_webhook( $request )->get_events()[0];

		self::assertSame( $delivery, $normalized->delivery_id );
	}

	public function test_maximum_length_event_key_is_accepted_before_an_unrelated_event_is_ignored(): void {
		$body    = 'not-json-and-not-needed';
		$event   = str_repeat( 'e', 128 );
		$request = ( new WebhookRequest(
			ProviderCode::parse( 'bb' ),
			$body,
			array(
				'X-Event-Key'     => $event,
				'X-Hub-Signature' => $this->signature( $body ),
			),
			self::RETAINED_HEADERS
		) )->with_verification( $this->verification( 'owner', 'RocketsAreNostalgic' ) );

		self::assertTrue( $this->normalizer()->normalize_webhook( $request )->is_ignored() );
	}

	public function test_malformed_json_fails_closed_without_body_or_secret_canaries(): void {
		$body = '{"private":"' . self::BODY_CANARY . '"';

		$this->assert_rejected(
			400,
			fn (): WebhookEnvelope => $this->normalizer()->normalize_webhook( $this->request( $body ) )
		);
	}

	#[DataProvider( 'malformed_payload_provider' )]
	public function test_malformed_repository_uuid_push_and_changes_fail_closed( array $payload ): void {
		$body = $this->encode( $payload );

		$this->assert_rejected(
			400,
			fn (): WebhookEnvelope => $this->normalizer()->normalize_webhook( $this->request( $body ) )
		);
	}

	/**
	 * @return iterable<string, array{array<string, mixed>}>
	 */
	public static function malformed_payload_provider(): iterable {
		$valid = self::static_valid_push_payload();

		$payload = $valid;
		unset( $payload['repository'] );
		yield 'missing repository' => array( $payload );

		$payload               = $valid;
		$payload['repository'] = 'repository';
		yield 'non-array repository' => array( $payload );

		$payload = $valid;
		unset( $payload['repository']['full_name'] );
		yield 'missing repository full name' => array( $payload );

		$payload                            = $valid;
		$payload['repository']['full_name'] = 'workspace/../repository';
		yield 'invalid repository full name' => array( $payload );

		$payload = $valid;
		unset( $payload['repository']['uuid'] );
		yield 'missing repository uuid' => array( $payload );

		$payload                       = $valid;
		$payload['repository']['uuid'] = '';
		yield 'empty repository uuid' => array( $payload );

		$payload                       = $valid;
		$payload['repository']['uuid'] = '   ';
		yield 'blank repository uuid' => array( $payload );

		$payload                       = $valid;
		$payload['repository']['uuid'] = 1234;
		yield 'non-string repository uuid' => array( $payload );

		$payload = $valid;
		unset( $payload['push'] );
		yield 'missing push' => array( $payload );

		$payload         = $valid;
		$payload['push'] = 'push';
		yield 'non-array push' => array( $payload );

		$payload = $valid;
		unset( $payload['push']['changes'] );
		yield 'missing changes' => array( $payload );

		$payload                    = $valid;
		$payload['push']['changes'] = array( 'not' => 'a-list' );
		yield 'non-list changes' => array( $payload );

		$payload                    = $valid;
		$payload['push']['changes'] = array();
		yield 'empty changes' => array( $payload );
	}

	#[DataProvider( 'malformed_branch_change_provider' )]
	public function test_malformed_branch_entries_fail_the_whole_delivery_closed( mixed $change ): void {
		$payload                    = $this->valid_push_payload();
		$payload['push']['changes'] = array(
			$this->branch_change( 'first', self::COMMIT_A ),
			$change,
		);
		$body                       = $this->encode( $payload );

		$this->assert_rejected(
			400,
			fn (): WebhookEnvelope => $this->normalizer()->normalize_webhook( $this->request( $body ) )
		);
	}

	/**
	 * @return iterable<string, array{mixed}>
	 */
	public static function malformed_branch_change_provider(): iterable {
		$valid = self::static_branch_change( 'main', self::COMMIT_A );

		yield 'non-array change' => array( 'change' );

		$change = $valid;
		unset( $change['new'] );
		yield 'missing new field' => array( $change );

		$change           = $valid;
		$change['closed'] = 'false';
		yield 'non-boolean closed field' => array( $change );

		$change        = $valid;
		$change['new'] = 'branch';
		yield 'non-array new branch' => array( $change );

		$change = $valid;
		unset( $change['new']['type'] );
		yield 'missing new type' => array( $change );

		$change                = $valid;
		$change['new']['type'] = array( 'branch' );
		yield 'non-string new type' => array( $change );

		$change = $valid;
		unset( $change['new']['name'] );
		yield 'missing branch name' => array( $change );

		$change                = $valid;
		$change['new']['name'] = '';
		yield 'empty branch name' => array( $change );

		$change                = $valid;
		$change['new']['name'] = "main\x00private";
		yield 'branch name control byte' => array( $change );

		$change                = $valid;
		$change['new']['name'] = str_repeat( 'b', 256 );
		yield 'branch name too long' => array( $change );

		$change = $valid;
		unset( $change['new']['target'] );
		yield 'missing branch target' => array( $change );

		$change                  = $valid;
		$change['new']['target'] = 'target';
		yield 'non-array branch target' => array( $change );

		$change = $valid;
		unset( $change['new']['target']['hash'] );
		yield 'missing target hash' => array( $change );

		$change                          = $valid;
		$change['new']['target']['hash'] = '0123456789ab';
		yield 'truncated target hash' => array( $change );

		$change                          = $valid;
		$change['new']['target']['hash'] = str_repeat( 'g', 40 );
		yield 'non-hex target hash' => array( $change );
	}

	public function test_tags_deletions_closed_branches_and_zero_hashes_are_ignored_per_change(): void {
		$payload                    = $this->valid_push_payload();
		$payload['push']['changes'] = array(
			array(
				'closed' => false,
				'new'    => array( 'type' => 'tag' ),
			),
			array(
				'closed' => true,
				'new'    => null,
			),
			array(
				'closed' => true,
				'new'    => self::static_branch_reference( 'closed', self::COMMIT_A ),
			),
			$this->branch_change( 'zero', self::ZERO_COMMIT ),
			$this->branch_change( 'deploy', self::COMMIT_B ),
		);
		$body                       = $this->encode( $payload );
		$events                     = $this->normalizer()->normalize_webhook( $this->request( $body ) )->get_events();

		self::assertCount( 1, $events );
		self::assertSame( 'deploy', $events[0]->branch );
		self::assertSame( self::COMMIT_B, $events[0]->commit );
	}

	public function test_all_filtered_changes_return_an_ignored_envelope(): void {
		$payload                    = $this->valid_push_payload();
		$payload['push']['changes'] = array(
			array(
				'closed' => true,
				'new'    => null,
			),
			array(
				'closed' => false,
				'new'    => array( 'type' => 'tag' ),
			),
			$this->branch_change( 'zero', self::ZERO_COMMIT ),
		);
		$body                       = $this->encode( $payload );
		$envelope                   = $this->normalizer()->normalize_webhook( $this->request( $body ) );

		self::assertTrue( $envelope->is_ignored() );
		self::assertSame( array(), $envelope->get_events() );
	}

	#[DataProvider( 'authorized_scope_provider' )]
	public function test_matched_profiles_authorize_only_their_configured_scope( string $scope, string $target ): void {
		$body       = $this->encode( $this->valid_push_payload() );
		$normalizer = $this->normalizer( array( $this->profile( self::OWNER_SECRET, $scope, $target ) ) );

		self::assertTrue( $normalizer->normalize_webhook( $this->verified_request( $body, $scope, $target ) )->has_events() );
	}

	/**
	 * @return iterable<string, array{string, string}>
	 */
	public static function authorized_scope_provider(): iterable {
		yield 'owner' => array( 'owner', 'RocketsAreNostalgic' );
		yield 'workspace case insensitive' => array( 'owner', 'rocketsarenostalgic' );
		yield 'repository case insensitive' => array( 'repository', 'rocketsarenostalgic/RAN.BOOSTER' );
	}

	#[DataProvider( 'unauthorized_scope_provider' )]
	public function test_matched_secret_outside_its_configured_scope_is_rejected( string $scope, string $target ): void {
		$body       = $this->encode( $this->valid_push_payload() );
		$normalizer = $this->normalizer( array( $this->profile( self::OWNER_SECRET, $scope, $target ) ) );

		$this->assert_rejected(
			401,
			fn (): WebhookEnvelope => $normalizer->normalize_webhook( $this->verified_request( $body, $scope, $target ) )
		);
	}

	/**
	 * @return iterable<string, array{string, string}>
	 */
	public static function unauthorized_scope_provider(): iterable {
		yield 'different workspace' => array( 'owner', 'ProtestsAndSuffragettes' );
		yield 'different repository' => array( 'repository', 'RocketsAreNostalgic/other-plugin' );
		yield 'unknown scope' => array( 'project', 'RocketsAreNostalgic' );
	}

	public function test_a_second_request_cannot_reuse_the_first_requests_matched_profile(): void {
		$profiles   = array(
			$this->profile( self::OWNER_SECRET, 'owner', 'RocketsAreNostalgic' ),
			$this->profile( self::OTHER_SECRET, 'owner', 'ProtestsAndSuffragettes' ),
		);
		$normalizer = $this->normalizer( $profiles );
		$body       = $this->encode( $this->valid_push_payload() );

		self::assertTrue( $normalizer->normalize_webhook( $this->request( $body ) )->has_events() );

		$this->assert_rejected(
			401,
			fn (): WebhookEnvelope => $normalizer->normalize_webhook(
				$this->verified_request( $body, 'owner', 'ProtestsAndSuffergettes', self::OTHER_SECRET )
			)
		);
	}

	public function test_webhook_readiness_reports_missing_configuration_without_reading_delivery_state(): void {
		$result = $this->normalizer( array() )->diagnose_webhook_readiness();

		self::assertSame( ProviderDiagnosticResult::NOT_CONFIGURED, $result->status );
		self::assertSame( 'bb.webhook.not_configured', $result->code );
	}

	public function test_webhook_readiness_reports_configured_but_delivery_unverified_without_secrets(): void {
		$result = $this->normalizer()->diagnose_webhook_readiness();
		$output = implode( ' ', $result->to_array() );

		self::assertSame( ProviderDiagnosticResult::WARNING, $result->status );
		self::assertSame( 'bb.webhook.delivery_unverified', $result->code );
		self::assertStringContainsString( 'does not prove the remote hook or a matching delivery', $output );
		self::assertStringContainsString( 'Provider request ID in Booster Activity', $output );
		self::assertStringNotContainsString( self::OWNER_SECRET, $output );
		self::assertStringNotContainsString( self::OTHER_SECRET, $output );
	}

	public function test_webhook_readiness_safely_reports_unreadable_configuration(): void {
		$secrets = new class() extends SecretsFile {
			public int $reads = 0;

			public function __construct() {
				parent::__construct( '/unused/test-secrets.php', array() );
			}

			public function webhook_materials( ProviderCode|string $provider ): array {
				++$this->reads;
				throw new \RuntimeException( 'bitbucket-webhook-secret-canary' );
			}
		};
		$result  = ( new BitbucketWebhookNormalizer( new BitbucketProviderCredentialStore( $secrets ) ) )->diagnose_webhook_readiness();
		$output  = implode( ' ', $result->to_array() );

		self::assertSame( ProviderDiagnosticResult::FAILED, $result->status );
		self::assertSame( 1, $secrets->reads, 'The current Core override must throw; an undefined adapter method must not satisfy this test.' );
		self::assertSame( 'bb.webhook.configuration_unavailable', $result->code );
		self::assertStringNotContainsString( 'bitbucket-webhook-secret-canary', $output );
	}

	/**
	 * @param list<array<string, mixed>>|null $profiles Secret profiles.
	 */
	private function normalizer( ?array $profiles = null ): BitbucketWebhookNormalizer {
		$profiles ??= array( $this->profile( self::OWNER_SECRET, 'owner', 'RocketsAreNostalgic' ) );

		$secrets = new class( $profiles ) extends SecretsFile {

			/**
			 * @param list<array<string, mixed>> $profiles Secret profiles.
			 */
			public function __construct( private array $profiles ) {
				parent::__construct( '/unused/test-secrets.php', array() );
			}

			/**
			 * @return list<array<string, mixed>>
			 */
			public function webhook_materials( ProviderCode|string $provider ): array {
				try {
					$provider = $provider instanceof ProviderCode ? $provider : ProviderCode::parse( $provider );
				} catch ( \RAN\RepositoryProvider\InvalidProviderCode ) {
					return array();
				}

				return $provider->equals( ProviderCode::parse( 'bb' ) ) ? $this->profiles : array();
			}
		};

		return new BitbucketWebhookNormalizer( webhook_profiles: new BitbucketProviderCredentialStore( $secrets ) );
	}

	private function request(
		string $body,
		string $event = 'repo:push',
		string $delivery_id = self::DELIVERY_ID,
		string $secret = self::OWNER_SECRET
	): WebhookRequest {
		return $this->request_with_signature( $body, $this->signature( $body, $secret ), $event, $delivery_id );
	}

	private function request_with_signature(
		string $body,
		string $signature,
		string $event = 'repo:push',
		string $delivery_id = self::DELIVERY_ID
	): WebhookRequest {
		return ( new WebhookRequest(
			ProviderCode::parse( 'bb' ),
			$body,
			array(
				'X-Event-Key'     => $event,
				'X-Request-UUID'  => $delivery_id,
				'X-Hub-Signature' => $signature,
			),
			self::RETAINED_HEADERS
		) )->with_verification( $this->verification( 'owner', 'RocketsAreNostalgic' ) );
	}

	private function verified_request( string $body, string $scope, string $target, string $secret = self::OWNER_SECRET ): WebhookRequest {
		return $this->request( $body, 'repo:push', self::DELIVERY_ID, $secret )
			->with_verification( $this->verification( $scope, $target ) );
	}

	private function verification( string $scope, string $target ): SignedWebhookVerification {
		return new SignedWebhookVerification(
			ProviderCode::parse( 'bb' ),
			array(
				array(
					'id'           => 'test-profile',
					'scope'        => $scope,
					'target'       => $target,
					'authority_id' => 'repository' === $scope && 0 === strcasecmp( $target, self::REPOSITORY )
						? self::REPOSITORY_ID
						: ( 'repository' === $scope ? 'different-repository-id' : '' ),
				),
			)
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function valid_push_payload(): array {
		return self::static_valid_push_payload();
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function static_valid_push_payload(): array {
		return array(
			'repository' => array(
				'full_name' => self::REPOSITORY,
				'uuid'      => self::REPOSITORY_ID,
			),
			'push'       => array(
				'changes' => array( self::static_branch_change( 'main', self::COMMIT_A ) ),
			),
		);
	}

	/**
	 * @param array<string, mixed> $extra Additional Bitbucket change fields.
	 * @return array<string, mixed>
	 */
	private function branch_change( string $branch, string $commit, array $extra = array() ): array {
		return self::static_branch_change( $branch, $commit, $extra );
	}

	/**
	 * @param array<string, mixed> $extra Additional Bitbucket change fields.
	 * @return array<string, mixed>
	 */
	private static function static_branch_change( string $branch, string $commit, array $extra = array() ): array {
		return array_merge(
			array(
				'closed' => false,
				'new'    => self::static_branch_reference( $branch, $commit ),
			),
			$extra
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function static_branch_reference( string $branch, string $commit ): array {
		return array(
			'type'   => 'branch',
			'name'   => $branch,
			'target' => array(
				'type' => 'commit',
				'hash' => $commit,
			),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function profile( string $secret, string $scope, string $target ): array {
		return array(
			'id'        => 'test-profile-' . hash( 'sha256', $secret . $scope . $target ),
			'label'     => 'Test Bitbucket profile',
			'scope'     => $scope,
			'target'    => $target,
			'secret'    => $secret,
			'source'    => 'test',
			'immutable' => false,
		);
	}

	private function signature( string $body, string $secret = self::OWNER_SECRET ): string {
		return 'sha256=' . hash_hmac( 'sha256', $body, $secret );
	}

	/**
	 * @param array<string, mixed> $payload JSON payload.
	 */
	private function encode( array $payload, int $flags = 0 ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded by focused unit tests.
		$json = json_encode( $payload, JSON_THROW_ON_ERROR | $flags );

		self::assertIsString( $json );

		return $json;
	}

	/**
	 * @param callable(): WebhookEnvelope $operation Normalizer operation expected to reject.
	 */
	private function assert_rejected( int $status_code, callable $operation ): void {
		try {
			$operation();
			self::fail( 'Webhook request should have been rejected.' );
		} catch ( WebhookRejected $exception ) {
			self::assertSame( $status_code, $exception->get_status_code() );
			self::assertNotSame( '', $exception->getMessage() );
			self::assertStringNotContainsString( self::OWNER_SECRET, $exception->getMessage() );
			self::assertStringNotContainsString( self::OTHER_SECRET, $exception->getMessage() );
			self::assertStringNotContainsString( self::BODY_CANARY, $exception->getMessage() );
		}
	}
}
