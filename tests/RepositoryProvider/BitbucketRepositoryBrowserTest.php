<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider;

require_once __DIR__ . '/BitbucketCredentialValidatorWordPressFunctions.php';
require_once __DIR__ . '/BitbucketRepositoryBrowserSecretsStub.php';

use PHPUnit\Framework\TestCase;
use RAN\Booster\Bitbucket\BitbucketApiClient;
use RAN\Booster\Bitbucket\BitbucketArchivePreparer;
use RAN\Booster\Bitbucket\BitbucketCredentialLoader;
use RAN\Booster\Bitbucket\BitbucketCredentialValidationTransportError;
use RAN\Booster\Bitbucket\BitbucketRepositoryBrowser;
use RAN\Booster\Bitbucket\BitbucketWebhookNormalizer;
use RAN\Booster\Bitbucket\BitbucketProvider;
use RAN\Booster\Bitbucket\BitbucketCredentialValidator;
use RAN\RepositoryProvider\CredentialedPublicRepositoryBrowser;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\RepositoryBrowseRequest;
use RAN\RepositoryProvider\RepositoryBrowseResult;
use RuntimeException;

final class BitbucketRepositoryBrowserTest extends TestCase {

	private const TOKEN_CANARY    = 'bitbucket-browser-token-canary';
	private const RESPONSE_CANARY = 'bitbucket-browser-response-canary';

	protected function setUp(): void {
		parent::setUp();

		$this->queue( array() );
	}

	public function testAnonymousWorkspaceBrowseUsesExactUrlWithoutAuthAndFiltersPrivateRows(): void {
		$this->queue(
			array(
				$this->response(
					200,
					$this->json(
						array(
							'values' => array(
								$this->item( '{public}', 'acme/public-plugin', false ),
								$this->item( '{private}', 'acme/private-plugin', true ),
								$this->item( '{other}', 'other/wrong-workspace', false ),
							),
						)
					)
				),
			)
		);

		$repositories = $this->publicRepositories( $this->browser(), 'acme' );
		$requests     = $this->requests();

		self::assertSame( array( 'acme/public-plugin' ), array_column( $this->rows( $repositories ), 'locator' ) );
		self::assertSame(
			'https://api.bitbucket.org/2.0/repositories/acme?pagelen=100&fields=values.uuid%2Cvalues.full_name%2Cvalues.is_private%2Cvalues.mainbranch.name%2Cnext',
			$requests[0]['url']
		);
		self::assertArrayNotHasKey( 'Authorization', $requests[0]['arguments']['headers'] );
		self::assertNull( $repositories[0]->credentialId );
	}

	public function testProviderSupportsAGlobalCredentialedPublicBrowseDefault(): void {
		$secrets  = $this->secrets();
		$store    = new BitbucketProviderCredentialStore( $secrets );
		$loader   = new BitbucketCredentialLoader( $store );
		$api      = new BitbucketApiClient();
		$provider = new BitbucketProvider(
			new BitbucketCredentialValidator( $loader, $api ),
			new BitbucketRepositoryBrowser( $loader, $api ),
			new BitbucketArchivePreparer( $loader, $api ),
			new BitbucketWebhookNormalizer( $store ),
			new BitbucketLoggingStub()
		);

		self::assertInstanceOf( CredentialedPublicRepositoryBrowser::class, $provider );
		self::assertTrue( $provider->getPublicRepositoryBrowseMetadata()->supportsProviderDefaultProfile );
	}

	public function testSelectedPublicLookupCredentialAuthenticatesAcrossWorkspacesAndKeepsResultsCredentialFree(): void {
		$next    = 'https://api.bitbucket.org/2.0/repositories/acme?pagelen=100&after=opaque%3Acursor';
		$secrets = $this->secrets(
			array( 'public_lookup' => $this->credential( 'another-workspace', self::TOKEN_CANARY ) )
		);
		$this->queue(
			array(
				$this->response(
					200,
					$this->json(
						array(
							'values' => array(
								$this->item( '{one}', 'acme/one' ),
								$this->item( '{private}', 'acme/private', true ),
							),
							'next'   => $next,
						)
					)
				),
				$this->response(
					200,
					$this->json( array( 'values' => array( $this->item( '{two}', 'acme/two' ) ) ) )
				),
			)
		);

		$result   = $this->browser( $secrets )->browse(
			RepositoryBrowseRequest::publicOwner( 'acme', 'public_lookup' )
		);
		$requests = $this->requests();

		self::assertSame( array( 'acme/one', 'acme/two' ), array_column( $this->rows( $result->repositories ), 'locator' ) );
		self::assertSame( array( null, null ), array_column( $this->rows( $result->repositories ), 'credential_id' ) );
		self::assertSame( array( array( 'bb', 'public_lookup' ) ), $secrets->materialLookups );
		self::assertCount( 2, $requests );

		foreach ( $requests as $request ) {
			self::assertSame(
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Assert Bitbucket's required Basic wire value.
				'Basic ' . base64_encode( 'deploy@example.test:' . self::TOKEN_CANARY ),
				$request['arguments']['headers']['Authorization']
			);
			self::assertSame( 0, $request['arguments']['redirection'] );
			self::assertStringNotContainsString( self::TOKEN_CANARY, $request['url'] );
		}
	}

	public function testMissingPublicLookupCredentialFailsBeforeHttp(): void {
		try {
			$this->browser()->browse(
				RepositoryBrowseRequest::publicOwner( 'acme', 'missing_profile' )
			);
			self::fail( 'An unavailable public lookup credential must fail closed.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( 400, $exception->getCode() );
			$this->assertSafeMessage( $exception->getMessage() );
		}

		self::assertSame( array(), $this->requests() );
	}

	public function testRejectedPublicLookupCredentialNeverRetriesAnonymously(): void {
		$secrets = $this->secrets(
			array( 'public_lookup' => $this->credential( 'acme', self::TOKEN_CANARY ) )
		);

		foreach ( array( 401, 403, 429 ) as $status ) {
			$this->queue(
				array( $this->response( $status, '{"error":"' . self::RESPONSE_CANARY . '"}' ) )
			);

			try {
				$this->browser( $secrets )->browse(
					RepositoryBrowseRequest::publicOwner( 'acme', 'public_lookup' )
				);
				self::fail( 'A rejected public lookup credential must not retry anonymously.' );
			} catch ( RuntimeException $exception ) {
				self::assertSame( $status, $exception->getCode() );
				$this->assertSafeMessage( $exception->getMessage() );
			}

			$requests = $this->requests();
			self::assertCount( 1, $requests );
			self::assertArrayHasKey( 'Authorization', $requests[0]['arguments']['headers'] );
		}
	}

	public function testLaterPublicLookupAuthorizationFailureDoesNotReturnPartialRows(): void {
		$next    = 'https://api.bitbucket.org/2.0/repositories/acme?pagelen=100&after=next';
		$secrets = $this->secrets(
			array( 'public_lookup' => $this->credential( 'acme', self::TOKEN_CANARY ) )
		);

		foreach ( array( 401, 403, 429 ) as $status ) {
			$this->queue(
				array(
					$this->response(
						200,
						$this->json(
							array(
								'values' => array( $this->item( '{one}', 'acme/one' ) ),
								'next'   => $next,
							)
						)
					),
					$this->response( $status, '{"error":"' . self::RESPONSE_CANARY . '"}' ),
				)
			);

			try {
				$this->browser( $secrets )->browse(
					RepositoryBrowseRequest::publicOwner( 'acme', 'public_lookup' )
				);
				self::fail( 'A later authenticated public lookup failure must not return partial rows.' );
			} catch ( RuntimeException $exception ) {
				self::assertSame( $status, $exception->getCode() );
				$this->assertSafeMessage( $exception->getMessage() );
			}

			$requests = $this->requests();
			self::assertCount( 2, $requests );
			foreach ( $requests as $request ) {
				self::assertArrayHasKey( 'Authorization', $request['arguments']['headers'] );
			}
		}
	}

	public function testSelectedCredentialUsesItsWorkspaceBasicAuthAndIdentity(): void {
		$secrets = $this->secrets( array( 'primary' => $this->credential( 'acme', self::TOKEN_CANARY ) ) );
		$this->queue(
			array(
				$this->response( 200, $this->json( array( 'values' => array( $this->item( '{private}', 'acme/private-plugin', true ) ) ) ) ),
			)
		);

		$repositories = $this->accessibleRepositories( $this->browser( $secrets ), 'primary' );
		$requests     = $this->requests();

		self::assertSame( 'primary', $repositories[0]->credentialId );
		self::assertTrue( $repositories[0]->private );
		self::assertSame( array( array( 'bb', 'primary' ) ), $secrets->materialLookups );
		self::assertSame(
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Assert Bitbucket's required Basic wire value.
			'Basic ' . base64_encode( 'deploy@example.test:' . self::TOKEN_CANARY ),
			$requests[0]['arguments']['headers']['Authorization']
		);
		self::assertStringNotContainsString( self::TOKEN_CANARY, $requests[0]['url'] );
	}

	public function testLiteralAllCredentialIdLoadsOnlyThatProfile(): void {
		$secrets = $this->secrets(
			array(
				'all'   => $this->credential( 'selected', self::TOKEN_CANARY ),
				'other' => $this->credential( 'other', 'other-token' ),
			)
		);
		$this->queue(
			array(
				$this->response(
					200,
					$this->json( array( 'values' => array( $this->item( '{selected}', 'selected/repository' ) ) ) )
				),
			)
		);

		$repositories = $this->accessibleRepositories( $this->browser( $secrets ), 'all' );

		self::assertSame( array( 'selected/repository' ), array_column( $this->rows( $repositories ), 'locator' ) );
		self::assertSame( array( 'all' ), array_column( $this->rows( $repositories ), 'credential_id' ) );
		self::assertSame( array( array( 'bb', 'all' ) ), $secrets->materialLookups );
		self::assertSame( array(), $secrets->profileLookups );
		self::assertCount( 1, $this->requests() );
	}

	public function testExactLookupSupportsAnonymousPublicAndSelectedPrivateRepositories(): void {
		$secrets = $this->secrets( array( 'primary' => $this->credential( 'acme', self::TOKEN_CANARY ) ) );
		$this->queue(
			array(
				$this->response( 200, $this->json( $this->item( '{public}', 'acme/public-plugin', false ) ) ),
				$this->response( 200, $this->json( $this->item( '{private}', 'acme/private-plugin', true ) ) ),
			)
		);

		$browser  = $this->browser( $secrets );
		$public   = $browser->repository( '  acme/public-plugin  ' );
		$private  = $browser->repository( 'acme/private-plugin', 'primary' );
		$requests = $this->requests();

		self::assertFalse( $public->private );
		self::assertNull( $public->credentialId );
		self::assertTrue( $private->private );
		self::assertSame( 'primary', $private->credentialId );
		self::assertSame(
			'https://api.bitbucket.org/2.0/repositories/acme/public-plugin?fields=uuid%2Cfull_name%2Cis_private%2Cmainbranch.name',
			$requests[0]['url']
		);
		self::assertArrayNotHasKey( 'Authorization', $requests[0]['arguments']['headers'] );
		self::assertArrayHasKey( 'Authorization', $requests[1]['arguments']['headers'] );
	}

	public function testExactLookupRejectsWorkspaceMismatchMalformedRowsAndEmptyBranches(): void {
		$fixtures = array(
			array( $this->item( '{mismatch}', 'other/repository', false, '' ), 502 ),
			array( array( 'uuid' => '{missing-fields}' ), 502 ),
			array( $this->item( '{empty-branch}', 'acme/repository', false, '' ), 400 ),
		);

		foreach ( $fixtures as [$fixture, $expectedStatus] ) {
			$this->queue( array( $this->response( 200, $this->json( $fixture ) ) ) );

			try {
				$this->browser()->repository( 'acme/repository' );
				self::fail( 'Expected an invalid exact Bitbucket repository response.' );
			} catch ( RuntimeException $exception ) {
				self::assertSame( $expectedStatus, $exception->getCode() );
				$this->assertSafeMessage( $exception->getMessage() );
			}
		}
	}

	public function testExactPrivateLookupRejectsACredentialFromAnotherWorkspaceBeforeHttp(): void {
		$secrets = $this->secrets( array( 'other' => $this->credential( 'other', self::TOKEN_CANARY ) ) );

		try {
			$this->browser( $secrets )->repository( 'acme/repository', 'other' );
			self::fail( 'Expected a differently scoped Bitbucket credential to be rejected.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( 400, $exception->getCode() );
			$this->assertSafeMessage( $exception->getMessage() );
		}

		self::assertSame( array(), $this->requests() );
	}

	public function testExactPublicLookupUsesACredentialFromAnotherWorkspace(): void {
		$secrets = $this->secrets( array( 'public_lookup' => $this->credential( 'other', self::TOKEN_CANARY ) ) );
		$this->queue(
			array(
				$this->response( 200, $this->json( $this->item( '{public}', 'acme/repository', false ) ) ),
			)
		);

		$repository = $this->browser( $secrets )->repository(
			'acme/repository',
			'public_lookup',
			publicOnly: true
		);

		self::assertFalse( $repository->private );
		self::assertSame( 'public_lookup', $repository->credentialId );
		self::assertArrayHasKey( 'Authorization', $this->requests()[0]['arguments']['headers'] );
	}

	public function testExactPublicLookupRejectsAPrivateRepositoryAfterAuthenticatedVerification(): void {
		$secrets = $this->secrets( array( 'public_lookup' => $this->credential( 'other', self::TOKEN_CANARY ) ) );
		$this->queue(
			array(
				$this->response( 200, $this->json( $this->item( '{private}', 'acme/repository', true ) ) ),
			)
		);

		try {
			$this->browser( $secrets )->repository(
				'acme/repository',
				'public_lookup',
				publicOnly: true
			);
			self::fail( 'Public-only exact verification must reject private repositories.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( 400, $exception->getCode() );
			$this->assertSafeMessage( $exception->getMessage() );
		}

		self::assertCount( 1, $this->requests() );
		self::assertArrayHasKey( 'Authorization', $this->requests()[0]['arguments']['headers'] );
	}

	public function testPaginationFollowsOnlyOpaqueNextUrlsForTheSameCollection(): void {
		$next = 'https://api.bitbucket.org/2.0/repositories/acme?pagelen=100&after=opaque%3Acursor';
		$this->queue(
			array(
				$this->response(
					200,
					$this->json(
						array(
							'values' => array( $this->item( '{one}', 'acme/one' ) ),
							'next'   => $next,
						)
					)
				),
				$this->response( 200, $this->json( array( 'values' => array( $this->item( '{two}', 'acme/two' ) ) ) ) ),
			)
		);

		$repositories = $this->publicRepositories( $this->browser(), 'acme' );

		self::assertSame( array( 'acme/one', 'acme/two' ), array_column( $this->rows( $repositories ), 'locator' ) );
		self::assertSame( $next, $this->requests()[1]['url'] );
	}

	public function testLaterRateLimitReturnsEarlierRepositoriesAsPartial(): void {
		$next = 'https://api.bitbucket.org/2.0/repositories/acme?pagelen=100&after=next';
		$this->queue(
			array(
				$this->response(
					200,
					$this->json(
						array(
							'values' => array( $this->item( '{one}', 'acme/one' ) ),
							'next'   => $next,
						)
					)
				),
				$this->response( 429, '{"error":"' . self::RESPONSE_CANARY . '"}' ),
			)
		);

		$result = $this->browser()->browse( RepositoryBrowseRequest::publicOwner( 'acme' ) );

		self::assertTrue( $result->isPartial() );
		self::assertSame( RepositoryBrowseResult::RATE_LIMIT, $result->partialReason );
		self::assertSame( array( 'acme/one' ), array_column( $this->rows( $result->repositories ), 'locator' ) );
		self::assertCount( 2, $this->requests() );
	}

	public function testPaginationStopsAfterFiveCallsAndReturnsAnExplicitPartialResult(): void {
		$responses = array();
		for ( $page = 1; $page <= RepositoryBrowseRequest::MAX_REMOTE_CALLS; ++$page ) {
			$responses[] = $this->response(
				200,
				$this->json(
					array(
						'values' => 1 === $page ? array( $this->item( '{one}', 'acme/one' ) ) : array(),
						'next'   => 'https://api.bitbucket.org/2.0/repositories/acme?pagelen=100&after=page-' . $page,
					)
				)
			);
		}
		$this->queue( $responses );

		$result = $this->browser()->browse( RepositoryBrowseRequest::publicOwner( 'acme' ) );

		self::assertTrue( $result->isPartial() );
		self::assertSame( RepositoryBrowseResult::LIMIT, $result->partialReason );
		self::assertCount( 1, $result->repositories );
		self::assertCount( RepositoryBrowseRequest::MAX_REMOTE_CALLS, $this->requests() );
	}

	public function testPaginationRejectsOtherCollectionsLoopsAndThePageCap(): void {
		$hostileNext = 'https://api.bitbucket.org/2.0/repositories/other?pagelen=100&after=cursor';
		$this->queue(
			array(
				$this->response(
					200,
					$this->json(
						array(
							'values' => array(),
							'next'   => $hostileNext,
						)
					)
				),
			)
		);
		$this->expectBrowseFailureAfterRequests( 1 );

		$initial = 'https://api.bitbucket.org/2.0/repositories/acme?pagelen=100&fields=values.uuid%2Cvalues.full_name%2Cvalues.is_private%2Cvalues.mainbranch.name%2Cnext';
		$this->queue(
			array(
				$this->response(
					200,
					$this->json(
						array(
							'values' => array(),
							'next'   => $initial,
						)
					)
				),
			)
		);
		$this->expectBrowseFailureAfterRequests( 1 );

		$responses = array();
		for ( $page = 1; $page <= 10; ++$page ) {
			$responses[] = $this->response(
				200,
				$this->json(
					array(
						'values' => array(),
						'next'   => 'https://api.bitbucket.org/2.0/repositories/acme?pagelen=100&after=page-' . $page,
					)
				)
			);
		}
		$this->queue( $responses );
		$this->expectBrowseFailureAfterRequests( 5, 503 );
	}

	public function testMalformedListsFailAndMalformedRowsAreSkipped(): void {
		foreach ( array( '', 'not-json', '{}', '{"values":"invalid"}' ) as $body ) {
			$this->queue( array( $this->response( 200, $body ) ) );

			try {
				$this->publicRepositories( $this->browser(), 'acme' );
				self::fail( 'Expected a malformed Bitbucket list to fail.' );
			} catch ( RuntimeException $exception ) {
				self::assertSame( 422, $exception->getCode() );
				$this->assertSafeMessage( $exception->getMessage() );
			}
		}

		$this->queue(
			array(
				$this->response(
					200,
					$this->json(
						array(
							'values' => array(
								array( 'uuid' => '{missing}' ),
								$this->item( '{blank}', 'acme/blank', false, '' ),
								$this->item( '{valid}', 'acme/valid' ),
							),
						)
					)
				),
			)
		);

		self::assertSame(
			array( 'acme/valid' ),
			array_column( $this->rows( $this->publicRepositories( $this->browser(), 'acme' ) ), 'locator' )
		);
	}

	public function testTransportAndStatusesUseFixedRedactedErrors(): void {
		$fixtures = array(
			array( new BitbucketCredentialValidationTransportError(), 504 ),
			array( $this->response( 401, '{"error":"' . self::RESPONSE_CANARY . '"}' ), 401 ),
			array( $this->response( 403, '{"error":"' . self::RESPONSE_CANARY . '"}' ), 403 ),
			array( $this->response( 404, '{"error":"' . self::RESPONSE_CANARY . '"}' ), 404 ),
			array( $this->response( 410, '{"error":"' . self::RESPONSE_CANARY . '"}' ), 410 ),
			array( $this->response( 429, '{"error":"' . self::RESPONSE_CANARY . '"}' ), 429 ),
			array( $this->response( 500, '{"error":"' . self::RESPONSE_CANARY . '"}' ), 502 ),
		);

		foreach ( $fixtures as [$response, $code] ) {
			$this->queue( array( $response ) );

			try {
				$this->publicRepositories( $this->browser(), 'acme' );
				self::fail( 'Expected a safe Bitbucket request failure.' );
			} catch ( RuntimeException $exception ) {
				self::assertSame( $code, $exception->getCode() );
				$this->assertSafeMessage( $exception->getMessage() );
			}
		}
	}

	private function browser( ?BitbucketRepositoryBrowserSecretsStub $secrets = null ): BitbucketRepositoryBrowser {
		return new BitbucketRepositoryBrowser(
			new BitbucketCredentialLoader( new BitbucketProviderCredentialStore( $secrets ?? $this->secrets() ) ),
			new BitbucketApiClient()
		);
	}

	/** @return list<\RAN\RepositoryProvider\RepositoryDescriptor> */
	private function publicRepositories( BitbucketRepositoryBrowser $browser, string $workspace ): array {
		return $browser->browse( RepositoryBrowseRequest::publicOwner( $workspace ) )->repositories;
	}

	/** @return list<\RAN\RepositoryProvider\RepositoryDescriptor> */
	private function accessibleRepositories( BitbucketRepositoryBrowser $browser, string $credentialId ): array {
		return $browser->browse( RepositoryBrowseRequest::accessible( $credentialId ) )->repositories;
	}

	/** @param array<string, array<string, mixed>> $materials */
	private function secrets( array $materials = array() ): BitbucketRepositoryBrowserSecretsStub {
		return new BitbucketRepositoryBrowserSecretsStub( $materials );
	}

	/** @return array<string, mixed> */
	private function credential( string $workspace, string $token ): array {
		return array(
			'id'            => strtolower( $workspace ),
			'provider'      => 'bb',
			'label'         => $workspace,
			'kind'          => 'api-token',
			'configuration' => array(
				'workspace' => $workspace,
				'email'     => 'deploy@example.test',
			),
			'secret'        => $token,
			'source'        => 'file',
			'immutable'     => false,
			'configured'    => true,
		);
	}

	/** @return array<string, mixed> */
	private function item( string $uuid, string $fullName, bool $private = false, string $branch = 'main' ): array {
		return array(
			'uuid'       => $uuid,
			'full_name'  => $fullName,
			'is_private' => $private,
			'mainbranch' => array( 'name' => $branch ),
		);
	}

	/** @return array<string, mixed> */
	private function response( int $status, string $body ): array {
		return array(
			'response' => array( 'code' => $status ),
			'body'     => $body,
		);
	}

	private function json( mixed $value ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Isolated JSON fixture encoding outside WordPress runtime.
		$json = json_encode( $value );

		self::assertIsString( $json );

		return $json;
	}

	/** @param list<mixed> $responses */
	private function queue( array $responses ): void {
		\RAN\Booster\Bitbucket\bitbucket_repository_http_queue( $responses );
	}

	/** @return list<array{url: string, arguments: array<string, mixed>}> */
	private function requests(): array {
		return \RAN\Booster\Bitbucket\bitbucket_credential_validation_http_requests();
	}

	/** @param list<\RAN\RepositoryProvider\RepositoryDescriptor> $repositories */
	private function rows( array $repositories ): array {
		return array_map( static fn ( $repository ): array => $repository->toArray(), $repositories );
	}

	private function assertSafeMessage( string $message ): void {
		self::assertNotSame( '', trim( $message ) );
		self::assertStringNotContainsString( self::TOKEN_CANARY, $message );
		self::assertStringNotContainsString( self::RESPONSE_CANARY, $message );
	}

	private function expectBrowseFailureAfterRequests( int $requestCount, int $status = 422 ): void {
		try {
			$this->publicRepositories( $this->browser(), 'acme' );
			self::fail( 'Expected unsafe Bitbucket pagination to fail.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( $status, $exception->getCode() );
			$this->assertSafeMessage( $exception->getMessage() );
		}

		self::assertCount( $requestCount, $this->requests() );
	}
}
