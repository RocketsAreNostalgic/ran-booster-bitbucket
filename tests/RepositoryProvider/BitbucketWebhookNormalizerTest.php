<?php

declare(strict_types=1);

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

	private const OWNER_SECRET    = 'owner-bitbucket-webhook-secret';
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

	public function testMultiChangePushProducesExactOrderedNeutralEventsSharingTheRawDeliveryId(): void {
		$payload                    = $this->validPushPayload();
		$payload['push']['changes'] = array(
			$this->branchChange(
				'main',
				strtoupper( self::COMMIT_A ),
				array(
					'created'   => true,
					'truncated' => true,
					'commits'   => array( array( 'hash' => '0123456789ab' ) ),
				)
			),
			$this->branchChange(
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
		$events                     = $this->normalizer()->normalizeWebhook( $this->request( $body ) )->getEvents();

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
			array_map( static fn ( object $event ): array => $event->toArray(), $events )
		);
	}

	public function testPushChangesAreBoundedBeforeEventNormalization(): void {
		$payload                    = $this->validPushPayload();
		$payload['push']['changes'] = array_fill( 0, 33, $this->branchChange( 'main', self::COMMIT_A ) );

		$this->assertRejected(
			400,
			fn (): WebhookEnvelope => $this->normalizer()->normalizeWebhook( $this->request( $this->encode( $payload ) ) )
		);
	}

	public function testOfficialAtlassianHmacVectorIsAccepted(): void {
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
		) )->withVerification( $this->verification( 'owner', 'RocketsAreNostalgic' ) );

		self::assertTrue( $normalizer->normalizeWebhook( $request )->isIgnored() );
	}

	public function testVerifiedWhitespaceAndUnicodeBodyBytesAreParsedUntouched(): void {
		$payload          = $this->validPushPayload();
		$payload['actor'] = array( 'display_name' => self::BODY_CANARY . ' José 🚀' );
		$body             = $this->encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
		$signature        = $this->signature( $body );
		$request          = $this->requestWithSignature( $body, $signature );

		self::assertTrue( $this->normalizer()->normalizeWebhook( $request )->hasEvents() );
	}

	#[DataProvider( 'invalidSignatureProvider' )]
	public function testMissingInvalidUppercaseAndUnsupportedSignaturesAreRejected( ?string $signature ): void {
		$body    = $this->encode( $this->validPushPayload() );
		$headers = array(
			'X-Event-Key'    => 'repo:push',
			'X-Request-UUID' => self::DELIVERY_ID,
		);

		if ( null !== $signature ) {
			$headers['X-Hub-Signature'] = $signature;
		}

		$this->assertRejected(
			401,
			fn (): WebhookEnvelope => $this->normalizer()->normalizeWebhook(
				new WebhookRequest( ProviderCode::parse( 'bb' ), $body, $headers, self::RETAINED_HEADERS )
			)
		);
	}

	/**
	 * @return iterable<string, array{?string}>
	 */
	public static function invalidSignatureProvider(): iterable {
		yield 'missing' => array( null );
		yield 'wrong digest' => array( 'sha256=' . str_repeat( 'a', 64 ) );
		yield 'uppercase digest' => array( 'sha256=' . str_repeat( 'A', 64 ) );
		yield 'uppercase algorithm' => array( 'SHA256=' . str_repeat( 'a', 64 ) );
		yield 'unsupported algorithm' => array( 'sha1=' . str_repeat( 'a', 40 ) );
		yield 'invalid encoding' => array( 'sha256=not-hex' );
	}

	public function testMissingProcessorVerificationIsRejectedWithoutLeaks(): void {
		$normalizer = $this->normalizer( array() );

		$this->assertRejected(
			401,
			static fn (): WebhookEnvelope => $normalizer->normalizeWebhook(
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

	public function testOversizedBodyIsRejectedBeforeHmacWhileTheExactLimitIsAccepted(): void {
		$oversized = str_repeat( 'x', self::MAX_BODY_BYTES + 1 );

		$this->assertRejected(
			413,
			fn (): WebhookEnvelope => $this->normalizer()->normalizeWebhook(
				new WebhookRequest(
					ProviderCode::parse( 'bb' ),
					$oversized,
					array( 'X-Hub-Signature' => 'sha256=' . str_repeat( '0', 64 ) ),
					self::RETAINED_HEADERS
				)
			)
		);

		$base = $this->encode( $this->validPushPayload() );
		$body = $base . str_repeat( ' ', self::MAX_BODY_BYTES - strlen( $base ) );

		self::assertSame( self::MAX_BODY_BYTES, strlen( $body ) );
		self::assertTrue( $this->normalizer()->normalizeWebhook( $this->request( $body ) )->hasEvents() );
	}

	public function testProviderMismatchIsRejected(): void {
		$body    = $this->encode( $this->validPushPayload() );
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

		$this->assertRejected(
			400,
			fn (): WebhookEnvelope => $this->normalizer()->normalizeWebhook( $request )
		);
	}

	public function testSignedUnrelatedEventIsIgnoredWithoutDeliveryOrJsonParsing(): void {
		$body    = 'not-json-' . self::BODY_CANARY;
		$request = ( new WebhookRequest(
			ProviderCode::parse( 'bb' ),
			$body,
			array(
				'X-Event-Key'     => 'repo:updated',
				'X-Hub-Signature' => $this->signature( $body ),
			),
			self::RETAINED_HEADERS
		) )->withVerification( $this->verification( 'owner', 'RocketsAreNostalgic' ) );

		self::assertTrue( $this->normalizer()->normalizeWebhook( $request )->isIgnored() );
	}

	#[DataProvider( 'invalidEventProvider' )]
	public function testEventKeyIsRequiredAndBounded( ?string $event ): void {
		$body    = $this->encode( $this->validPushPayload() );
		$headers = array( 'X-Hub-Signature' => $this->signature( $body ) );

		if ( null !== $event ) {
			$headers['X-Event-Key'] = $event;
		}

		$this->assertRejected(
			400,
			fn (): WebhookEnvelope => $this->normalizer()->normalizeWebhook(
				new WebhookRequest( ProviderCode::parse( 'bb' ), $body, $headers, self::RETAINED_HEADERS )
			)
		);
	}

	/**
	 * @return iterable<string, array{?string}>
	 */
	public static function invalidEventProvider(): iterable {
		yield 'missing' => array( null );
		yield 'embedded space' => array( 'repo: push' );
		yield 'control byte' => array( "repo:\npush" );
		yield 'too long' => array( str_repeat( 'e', 129 ) );
	}

	#[DataProvider( 'invalidDeliveryProvider' )]
	public function testPushDeliveryIdIsRequiredAndBounded( ?string $delivery ): void {
		$body    = $this->encode( $this->validPushPayload() );
		$headers = array(
			'X-Event-Key'     => 'repo:push',
			'X-Hub-Signature' => $this->signature( $body ),
		);

		if ( null !== $delivery ) {
			$headers['X-Request-UUID'] = $delivery;
		}

		$this->assertRejected(
			400,
			fn (): WebhookEnvelope => $this->normalizer()->normalizeWebhook(
				new WebhookRequest( ProviderCode::parse( 'bb' ), $body, $headers, self::RETAINED_HEADERS )
			)
		);
	}

	/**
	 * @return iterable<string, array{?string}>
	 */
	public static function invalidDeliveryProvider(): iterable {
		yield 'missing' => array( null );
		yield 'embedded space' => array( 'request uuid' );
		yield 'control byte' => array( "request\x7f" );
		yield 'too long' => array( str_repeat( 'd', 129 ) );
	}

	public function testTrimmedBoundaryHeadersAreAcceptedAndTheDeliveryIdIsPreserved(): void {
		$body     = $this->encode( $this->validPushPayload() );
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
		) )->withVerification( $this->verification( 'owner', 'RocketsAreNostalgic' ) );

		$normalized = $this->normalizer()->normalizeWebhook( $request )->getEvents()[0];

		self::assertSame( $delivery, $normalized->deliveryId );
	}

	public function testMaximumLengthEventKeyIsAcceptedBeforeAnUnrelatedEventIsIgnored(): void {
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
		) )->withVerification( $this->verification( 'owner', 'RocketsAreNostalgic' ) );

		self::assertTrue( $this->normalizer()->normalizeWebhook( $request )->isIgnored() );
	}

	public function testMalformedJsonFailsClosedWithoutBodyOrSecretCanaries(): void {
		$body = '{"private":"' . self::BODY_CANARY . '"';

		$this->assertRejected(
			400,
			fn (): WebhookEnvelope => $this->normalizer()->normalizeWebhook( $this->request( $body ) )
		);
	}

	#[DataProvider( 'malformedPayloadProvider' )]
	public function testMalformedRepositoryUuidPushAndChangesFailClosed( array $payload ): void {
		$body = $this->encode( $payload );

		$this->assertRejected(
			400,
			fn (): WebhookEnvelope => $this->normalizer()->normalizeWebhook( $this->request( $body ) )
		);
	}

	/**
	 * @return iterable<string, array{array<string, mixed>}>
	 */
	public static function malformedPayloadProvider(): iterable {
		$valid = self::staticValidPushPayload();

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

	#[DataProvider( 'malformedBranchChangeProvider' )]
	public function testMalformedBranchEntriesFailTheWholeDeliveryClosed( mixed $change ): void {
		$payload                    = $this->validPushPayload();
		$payload['push']['changes'] = array(
			$this->branchChange( 'first', self::COMMIT_A ),
			$change,
		);
		$body                       = $this->encode( $payload );

		$this->assertRejected(
			400,
			fn (): WebhookEnvelope => $this->normalizer()->normalizeWebhook( $this->request( $body ) )
		);
	}

	/**
	 * @return iterable<string, array{mixed}>
	 */
	public static function malformedBranchChangeProvider(): iterable {
		$valid = self::staticBranchChange( 'main', self::COMMIT_A );

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

	public function testTagsDeletionsClosedBranchesAndZeroHashesAreIgnoredPerChange(): void {
		$payload                    = $this->validPushPayload();
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
				'new'    => self::staticBranchReference( 'closed', self::COMMIT_A ),
			),
			$this->branchChange( 'zero', self::ZERO_COMMIT ),
			$this->branchChange( 'deploy', self::COMMIT_B ),
		);
		$body                       = $this->encode( $payload );
		$events                     = $this->normalizer()->normalizeWebhook( $this->request( $body ) )->getEvents();

		self::assertCount( 1, $events );
		self::assertSame( 'deploy', $events[0]->branch );
		self::assertSame( self::COMMIT_B, $events[0]->commit );
	}

	public function testAllFilteredChangesReturnAnIgnoredEnvelope(): void {
		$payload                    = $this->validPushPayload();
		$payload['push']['changes'] = array(
			array(
				'closed' => true,
				'new'    => null,
			),
			array(
				'closed' => false,
				'new'    => array( 'type' => 'tag' ),
			),
			$this->branchChange( 'zero', self::ZERO_COMMIT ),
		);
		$body                       = $this->encode( $payload );
		$envelope                   = $this->normalizer()->normalizeWebhook( $this->request( $body ) );

		self::assertTrue( $envelope->isIgnored() );
		self::assertSame( array(), $envelope->getEvents() );
	}

	#[DataProvider( 'authorizedScopeProvider' )]
	public function testMatchedProfilesAuthorizeOnlyTheirConfiguredScope( string $scope, string $target ): void {
		$body       = $this->encode( $this->validPushPayload() );
		$normalizer = $this->normalizer( array( $this->profile( self::OWNER_SECRET, $scope, $target ) ) );

		self::assertTrue( $normalizer->normalizeWebhook( $this->verifiedRequest( $body, $scope, $target ) )->hasEvents() );
	}

	/**
	 * @return iterable<string, array{string, string}>
	 */
	public static function authorizedScopeProvider(): iterable {
		yield 'owner' => array( 'owner', 'RocketsAreNostalgic' );
		yield 'workspace case insensitive' => array( 'owner', 'rocketsarenostalgic' );
		yield 'repository case insensitive' => array( 'repository', 'rocketsarenostalgic/RAN.BOOSTER' );
	}

	#[DataProvider( 'unauthorizedScopeProvider' )]
	public function testMatchedSecretOutsideItsConfiguredScopeIsRejected( string $scope, string $target ): void {
		$body       = $this->encode( $this->validPushPayload() );
		$normalizer = $this->normalizer( array( $this->profile( self::OWNER_SECRET, $scope, $target ) ) );

		$this->assertRejected(
			401,
			fn (): WebhookEnvelope => $normalizer->normalizeWebhook( $this->verifiedRequest( $body, $scope, $target ) )
		);
	}

	/**
	 * @return iterable<string, array{string, string}>
	 */
	public static function unauthorizedScopeProvider(): iterable {
		yield 'different workspace' => array( 'owner', 'ProtestsAndSuffragettes' );
		yield 'different repository' => array( 'repository', 'RocketsAreNostalgic/other-plugin' );
		yield 'unknown scope' => array( 'project', 'RocketsAreNostalgic' );
	}

	public function testASecondRequestCannotReuseTheFirstRequestsMatchedProfile(): void {
		$profiles   = array(
			$this->profile( self::OWNER_SECRET, 'owner', 'RocketsAreNostalgic' ),
			$this->profile( self::OTHER_SECRET, 'owner', 'ProtestsAndSuffragettes' ),
		);
		$normalizer = $this->normalizer( $profiles );
		$body       = $this->encode( $this->validPushPayload() );

		self::assertTrue( $normalizer->normalizeWebhook( $this->request( $body ) )->hasEvents() );

		$this->assertRejected(
			401,
			fn (): WebhookEnvelope => $normalizer->normalizeWebhook(
				$this->verifiedRequest( $body, 'owner', 'ProtestsAndSuffergettes', self::OTHER_SECRET )
			)
		);
	}

	public function testWebhookReadinessReportsMissingConfigurationWithoutReadingDeliveryState(): void {
		$result = $this->normalizer( array() )->diagnoseWebhookReadiness();

		self::assertSame( ProviderDiagnosticResult::NOT_CONFIGURED, $result->status );
		self::assertSame( 'bb.webhook.not_configured', $result->code );
	}

	public function testWebhookReadinessReportsConfiguredButDeliveryUnverifiedWithoutSecrets(): void {
		$result = $this->normalizer()->diagnoseWebhookReadiness();
		$output = implode( ' ', $result->toArray() );

		self::assertSame( ProviderDiagnosticResult::WARNING, $result->status );
		self::assertSame( 'bb.webhook.delivery_unverified', $result->code );
		self::assertStringNotContainsString( self::OWNER_SECRET, $output );
		self::assertStringNotContainsString( self::OTHER_SECRET, $output );
	}

	public function testWebhookReadinessSafelyReportsUnreadableConfiguration(): void {
		$secrets = new class() extends SecretsFile {
			public function __construct() {
				parent::__construct( '/unused/test-secrets.php', array() );
			}

			public function webhookProfiles( ProviderCode|string $provider ): array {
				throw new \RuntimeException( 'bitbucket-webhook-secret-canary' );
			}
		};
		$result  = ( new BitbucketWebhookNormalizer( new BitbucketProviderCredentialStore( $secrets ) ) )->diagnoseWebhookReadiness();
		$output  = implode( ' ', $result->toArray() );

		self::assertSame( ProviderDiagnosticResult::FAILED, $result->status );
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
			public function webhookMaterials( ProviderCode|string $provider ): array {
				try {
					$provider = $provider instanceof ProviderCode ? $provider : ProviderCode::parse( $provider );
				} catch ( \RAN\RepositoryProvider\InvalidProviderCode ) {
					return array();
				}

				return $provider->equals( ProviderCode::parse( 'bb' ) ) ? $this->profiles : array();
			}
		};

		return new BitbucketWebhookNormalizer( new BitbucketProviderCredentialStore( $secrets ) );
	}

	private function request(
		string $body,
		string $event = 'repo:push',
		string $deliveryId = self::DELIVERY_ID,
		string $secret = self::OWNER_SECRET
	): WebhookRequest {
		return $this->requestWithSignature( $body, $this->signature( $body, $secret ), $event, $deliveryId );
	}

	private function requestWithSignature(
		string $body,
		string $signature,
		string $event = 'repo:push',
		string $deliveryId = self::DELIVERY_ID
	): WebhookRequest {
		return ( new WebhookRequest(
			ProviderCode::parse( 'bb' ),
			$body,
			array(
				'X-Event-Key'     => $event,
				'X-Request-UUID'  => $deliveryId,
				'X-Hub-Signature' => $signature,
			),
			self::RETAINED_HEADERS
		) )->withVerification( $this->verification( 'owner', 'RocketsAreNostalgic' ) );
	}

	private function verifiedRequest( string $body, string $scope, string $target, string $secret = self::OWNER_SECRET ): WebhookRequest {
		return $this->request( $body, 'repo:push', self::DELIVERY_ID, $secret )
			->withVerification( $this->verification( $scope, $target ) );
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
	private function validPushPayload(): array {
		return self::staticValidPushPayload();
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function staticValidPushPayload(): array {
		return array(
			'repository' => array(
				'full_name' => self::REPOSITORY,
				'uuid'      => self::REPOSITORY_ID,
			),
			'push'       => array(
				'changes' => array( self::staticBranchChange( 'main', self::COMMIT_A ) ),
			),
		);
	}

	/**
	 * @param array<string, mixed> $extra Additional Bitbucket change fields.
	 * @return array<string, mixed>
	 */
	private function branchChange( string $branch, string $commit, array $extra = array() ): array {
		return self::staticBranchChange( $branch, $commit, $extra );
	}

	/**
	 * @param array<string, mixed> $extra Additional Bitbucket change fields.
	 * @return array<string, mixed>
	 */
	private static function staticBranchChange( string $branch, string $commit, array $extra = array() ): array {
		return array_merge(
			array(
				'closed' => false,
				'new'    => self::staticBranchReference( $branch, $commit ),
			),
			$extra
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function staticBranchReference( string $branch, string $commit ): array {
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
	private function assertRejected( int $statusCode, callable $operation ): void {
		try {
			$operation();
			self::fail( 'Webhook request should have been rejected.' );
		} catch ( WebhookRejected $exception ) {
			self::assertSame( $statusCode, $exception->getStatusCode() );
			self::assertNotSame( '', $exception->getMessage() );
			self::assertStringNotContainsString( self::OWNER_SECRET, $exception->getMessage() );
			self::assertStringNotContainsString( self::OTHER_SECRET, $exception->getMessage() );
			self::assertStringNotContainsString( self::BODY_CANARY, $exception->getMessage() );
		}
	}
}
