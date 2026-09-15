<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider;

require_once __DIR__ . '/BitbucketCredentialValidatorWordPressFunctions.php';
require_once __DIR__ . '/BitbucketRepositoryBrowserSecretsStub.php';
require_once __DIR__ . '/AuthenticatedPreparedArchiveWordPressFunctions.php';

use PHPUnit\Framework\TestCase;
use RAN\RepositoryProvider\AuthenticatedPreparedArchive;
use RAN\RepositoryProvider\ArchiveRequest;
use RAN\Booster\Bitbucket\BitbucketApiClient;
use RAN\Booster\Bitbucket\BitbucketArchivePreparer;
use RAN\Booster\Bitbucket\BitbucketCredentialLoader;
use RAN\Booster\Bitbucket\BitbucketCredentialValidationTransportError;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\RepositoryReference;
use RuntimeException;

final class BitbucketArchivePreparerTest extends TestCase {

	private const EMAIL           = 'deploy@example.test';
	private const TOKEN           = 'bitbucket-archive-token-canary';
	private const RESPONSE_CANARY = 'bitbucket-archive-response-canary';
	private const COMMIT          = '0123456789abcdef0123456789abcdef01234567';
	private const OTHER_COMMIT    = '89abcdef0123456789abcdef0123456789abcdef';
	private const REPOSITORY_UUID = '{repository-uuid}';

	protected function setUp(): void {
		parent::setUp();

		$this->resetHarness();
	}

	protected function tearDown(): void {
		$this->resetHarness();

		parent::tearDown();
	}

	public function testPublicImmutableCommitIsVerifiedAnonymouslyWithoutCredentialOrHooks(): void {
		$this->queue(
			array(
				$this->response( 200, $this->commitBody( self::COMMIT, 'acme/example', self::REPOSITORY_UUID ) ),
			)
		);
		$secrets  = $this->secrets( array( 'profile' => $this->credential() ) );
		$archive  = $this->preparer( $secrets )->prepareArchive(
			$this->request( strtoupper( self::COMMIT ), false, 'profile' )
		);
		$requests = $this->requests();

		self::assertSame(
			'https://bitbucket.org/acme/example/get/' . self::COMMIT . '.zip',
			$archive->getUrl()
		);
		self::assertCount( 1, $requests );
		self::assertSame(
			'https://api.bitbucket.org/2.0/repositories/acme/example/commit/' . self::COMMIT . '?fields=hash%2Crepository.uuid%2Crepository.full_name',
			$requests[0]['url']
		);
		self::assertArrayNotHasKey( 'Authorization', $requests[0]['arguments']['headers'] );
		self::assertSame( array(), $secrets->materialLookups );
		$this->assertNoArchiveHooks();

		$archive->cleanup();
		$archive->cleanup();
		$this->assertNoArchiveHooks();
	}

	public function testPublicSlashBranchResolvesAnImmutableRepositoryBoundCommitWithoutAuth(): void {
		$this->queue(
			array(
				$this->response(
					200,
					$this->branchBody( 'feature/candidate', self::COMMIT, 'acme/example', self::REPOSITORY_UUID )
				),
			)
		);

		$archive  = $this->preparer()->prepareArchive( $this->request( 'feature/candidate', false ) );
		$requests = $this->requests();

		self::assertSame(
			'https://api.bitbucket.org/2.0/repositories/acme/example/refs/branches/feature/candidate?fields=name%2Ctarget.hash%2Ctarget.repository.uuid%2Ctarget.repository.full_name',
			$requests[0]['url']
		);
		self::assertArrayNotHasKey( 'Authorization', $requests[0]['arguments']['headers'] );
		self::assertSame( 'https://bitbucket.org/acme/example/get/' . self::COMMIT . '.zip', $archive->getUrl() );
		self::assertSame( self::COMMIT, $archive->getResolvedRef() );
		$this->assertNoArchiveHooks();
	}

	public function testManualTagFallsBackFromBranchAndResolvesAnImmutableCommit(): void {
		$this->queue(
			array(
				$this->response( 404, $this->errorBody() ),
				$this->response( 200, $this->branchBody( 'v1.2.3', self::COMMIT, 'acme/example', self::REPOSITORY_UUID ) ),
			)
		);

		$archive  = $this->preparer()->prepareArchive( $this->request( 'v1.2.3', false ) );
		$requests = $this->requests();

		self::assertCount( 2, $requests );
		self::assertStringContainsString( '/refs/branches/v1.2.3?', $requests[0]['url'] );
		self::assertStringContainsString( '/refs/tags/v1.2.3?', $requests[1]['url'] );
		self::assertSame( self::COMMIT, $archive->getResolvedRef() );
		self::assertStringEndsWith( '/' . self::COMMIT . '.zip', $archive->getUrl() );
	}

	public function testPrivateBranchResolutionUsesExactBasicAuthThenPreparesImmutableArchiveAuth(): void {
		$this->queue(
			array(
				$this->response( 200, $this->branchBody( 'main', self::COMMIT, 'acme/example', self::REPOSITORY_UUID ) ),
			)
		);
		$archive  = $this->preparer( $this->secrets( array( 'profile' => $this->credential() ) ) )
			->prepareArchive( $this->request( 'main', true, 'profile' ) );
		$requests = $this->requests();

		self::assertCount( 1, $requests );
		self::assertSame( $this->basicAuthorization(), $requests[0]['arguments']['headers']['Authorization'] ?? null );
		self::assertSame( self::COMMIT, $archive->getResolvedRef() );
		self::assertStringNotContainsString( self::TOKEN, $archive->getUrl() );
		self::assertStringNotContainsString( self::EMAIL, $archive->getUrl() );
		self::assertCount( 1, $this->archiveFilters() );
		self::assertCount( 1, $this->archiveActions() );

		$archive->cleanup();
		$this->assertNoArchiveHooks();
	}

	public function testPrivateCommitResolutionUsesExactBasicAuthThenPreparesImmutableArchiveAuth(): void {
		$this->queue(
			array(
				$this->response( 200, $this->commitBody( self::COMMIT, 'acme/example', self::REPOSITORY_UUID ) ),
			)
		);
		$archive  = $this->preparer( $this->secrets( array( 'profile' => $this->credential() ) ) )
			->prepareArchive( $this->request( self::COMMIT, true, 'profile' ) );
		$requests = $this->requests();

		self::assertCount( 1, $requests );
		self::assertSame( $this->basicAuthorization(), $requests[0]['arguments']['headers']['Authorization'] ?? null );
		self::assertSame( self::COMMIT, $archive->getResolvedRef() );
		self::assertCount( 1, $this->archiveFilters() );
		self::assertCount( 1, $this->archiveActions() );

		$archive->cleanup();
		$this->assertNoArchiveHooks();
	}

	public function testPrivateRepositoryWithoutCredentialFailsBeforeTransportOrHooks(): void {
		try {
			$this->preparer()->prepareArchive( $this->request( 'main', true ) );
			self::fail( 'Expected private repository preparation to fail.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSafeFailure( $exception );
		}

		self::assertSame( array(), $this->requests() );
		$this->assertNoArchiveHooks();
	}

	public function testPrivateRepositoryWithUnknownCredentialFailsBeforeTransportOrHooks(): void {
		try {
			$this->preparer()->prepareArchive( $this->request( 'main', true, 'missing' ) );
			self::fail( 'Expected private repository preparation to fail.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSafeFailure( $exception );
		}

		self::assertSame( array(), $this->requests() );
		$this->assertNoArchiveHooks();
	}

	public function testPrivateRepositoryWithWrongWorkspaceCredentialFailsBeforeTransportOrHooks(): void {
		try {
			$this->preparer( $this->secrets( array( 'profile' => $this->credential( 'other' ) ) ) )
				->prepareArchive( $this->request( 'main', true, 'profile' ) );
			self::fail( 'Expected workspace mismatch to fail.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSafeFailure( $exception );
		}

		self::assertSame( array(), $this->requests() );
		$this->assertNoArchiveHooks();
	}

	public function testPrivateResolutionTransportFailureFailsWithoutHooksOrLeaks(): void {
		\RAN\Booster\Bitbucket\bitbucket_credential_validation_transport_failure( self::RESPONSE_CANARY );

		try {
			$this->preparer( $this->secrets( array( 'profile' => $this->credential() ) ) )
				->prepareArchive( $this->request( 'main', true, 'profile' ) );
			self::fail( 'Expected transport failure.' );
		} catch ( BitbucketCredentialValidationTransportError $exception ) {
			$this->assertSafeFailure( $exception );
		}

		$this->assertNoArchiveHooks();
	}

	public function testPrivateResolutionUpstreamErrorFailsWithoutHooksOrLeaks(): void {
		$this->queue( array( $this->response( 500, $this->errorBody() ) ) );

		try {
			$this->preparer( $this->secrets( array( 'profile' => $this->credential() ) ) )
				->prepareArchive( $this->request( 'main', true, 'profile' ) );
			self::fail( 'Expected upstream failure.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSafeFailure( $exception );
		}

		$this->assertNoArchiveHooks();
	}

	public function testPrivateResolutionNotFoundFailsWithoutHooksOrLeaks(): void {
		$this->queue( array( $this->response( 404, $this->errorBody() ) ) );

		try {
			$this->preparer( $this->secrets( array( 'profile' => $this->credential() ) ) )
				->prepareArchive( $this->request( 'missing', true, 'profile' ) );
			self::fail( 'Expected not-found failure.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSafeFailure( $exception );
		}

		$this->assertNoArchiveHooks();
	}

	public function testPrivateResolutionRateLimitFailsWithoutHooksOrLeaks(): void {
		$this->queue( array( $this->response( 429, $this->errorBody() ) ) );

		try {
			$this->preparer( $this->secrets( array( 'profile' => $this->credential() ) )
			)->prepareArchive( $this->request( 'main', true, 'profile' ) );
			self::fail( 'Expected rate-limit failure.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSafeFailure( $exception );
		}

		$this->assertNoArchiveHooks();
	}

	public function testPrivateResolutionMalformedJsonFailsWithoutHooksOrLeaks(): void {
		$this->queue( array( $this->response( 200, '{"hash":' ) ) );

		try {
			$this->preparer( $this->secrets( array( 'profile' => $this->credential() ) )
			)->prepareArchive( $this->request( 'main', true, 'profile' ) );
			self::fail( 'Expected malformed response failure.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSafeFailure( $exception );
		}

		$this->assertNoArchiveHooks();
	}

	public function testPrivateResolutionMismatchedRepositoryFailsWithoutHooksOrLeaks(): void {
		$this->queue( array( $this->response( 200, $this->branchBody( 'main', self::COMMIT, 'other/example', self::REPOSITORY_UUID ) ) ) );

		try {
			$this->preparer( $this->secrets( array( 'profile' => $this->credential() ) )
			)->prepareArchive( $this->request( 'main', true, 'profile' ) );
			self::fail( 'Expected repository mismatch.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSafeFailure( $exception );
		}

		$this->assertNoArchiveHooks();
	}

	public function testPrivateResolutionMismatchedRepositoryUuidFailsWithoutHooksOrLeaks(): void {
		$this->queue( array( $this->response( 200, $this->branchBody( 'main', self::COMMIT, 'acme/example', '{other-repository-uuid}' ) ) ) );

		try {
			$this->preparer( $this->secrets( array( 'profile' => $this->credential() ) )
			)->prepareArchive( $this->request( 'main', true, 'profile' ) );
			self::fail( 'Expected repository UUID mismatch.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSafeFailure( $exception );
		}

		$this->assertNoArchiveHooks();
	}

	public function testPrivateImmutableArchiveRegistersOneExactAuthorizationHook(): void {
		$archive = $this->privateImmutableArchive();
		$filters = $this->archiveFilters();
		$actions = $this->archiveActions();

		self::assertCount( 1, $filters );
		self::assertSame( 'http_request_args', $filters[0]['hook'] );
		self::assertSame( 10, $filters[0]['priority'] );
		self::assertSame( 2, $filters[0]['accepted_args'] );
		self::assertCount( 1, $actions );
		self::assertSame( AuthenticatedPreparedArchive::REDIRECT_HOOK, $actions[0]['hook'] );
		self::assertSame( 10, $actions[0]['priority'] );
		self::assertSame( 5, $actions[0]['accepted_args'] );

		$archive->cleanup();
		$this->assertNoArchiveHooks();
	}

	public function testPrivateArchiveAuthorizesOnlyItsExactUrl(): void {
		$archive = $this->privateImmutableArchive();
		$filters = $this->archiveFilters();
		self::assertCount( 1, $filters );
		$callback = $filters[0]['callback'];

		$authorized = $callback(
			array(
				'headers' => array( 'Existing' => 'value' ),
			),
			$archive->getUrl()
		);
		self::assertSame( $this->basicAuthorization(), $authorized['headers']['Authorization'] ?? null );
		self::assertSame( 'value', $authorized['headers']['Existing'] ?? null );

		$other = $callback(
			array(
				'headers' => array( 'Existing' => 'value' ),
			),
			'https://bitbucket.org/acme/example/get/' . self::OTHER_COMMIT . '.zip'
		);
		self::assertArrayNotHasKey( 'Authorization', $other['headers'] );
		self::assertSame( 'value', $other['headers']['Existing'] ?? null );

		$archive->cleanup();
		$this->assertNoArchiveHooks();
	}

	public function testPrivateArchivePreservesCaseVariantHeadersWithoutAddingDuplicateAuthorization(): void {
		$archive = $this->privateImmutableArchive();
		$filters = $this->archiveFilters();
		$callback = $filters[0]['callback'];

		$authorized = $callback(
			array(
				'headers' => array(
					'authorization' => 'Existing authorization',
					'Existing'      => 'value',
				),
			),
			$archive->getUrl()
		);
		self::assertSame( 'Existing authorization', $authorized['headers']['authorization'] ?? null );
		self::assertArrayNotHasKey( 'Authorization', $authorized['headers'] );

		$archive->cleanup();
		$this->assertNoArchiveHooks();
	}

	public function testPrivateArchiveRedirectRemovesAllAuthorizationCaseVariants(): void {
		$archive = $this->privateImmutableArchive();
		$actions = $this->archiveActions();
		self::assertCount( 1, $actions );
		$callback = $actions[0]['callback'];
		$http = new class() {
			/** @var array<string, mixed> */
			public array $requests = array();
			/** @param array<string, mixed> $arguments */
			public function request( string $location, array $arguments ): string {
				$this->requests[] = array( 'location' => $location, 'arguments' => $arguments );

				return 'redirected';
			}
		};
		$arguments = array(
			'headers' => array(
				'authorization' => 'Existing authorization',
				'Authorization' => 'Injected authorization',
				'Existing'      => 'value',
			),
		);

		$result = $callback( 'ignored-response', $arguments, $archive->getUrl(), 'https://downloads.example.test/archive.zip', $http );
		self::assertSame( 'redirected', $result );
		self::assertCount( 1, $http->requests );
		$redirectArguments = $http->requests[0]['arguments'];
		self::assertArrayNotHasKey( 'authorization', $redirectArguments['headers'] );
		self::assertArrayNotHasKey( 'Authorization', $redirectArguments['headers'] );
		self::assertSame( 'value', $redirectArguments['headers']['Existing'] ?? null );
		self::assertSame( 4, $redirectArguments['redirection'] ?? null );

		$archive->cleanup();
		$this->assertNoArchiveHooks();
	}

	public function testPrivateArchiveConsumesAuthorizationAfterExactRequest(): void {
		$archive = $this->privateImmutableArchive();
		$filters = $this->archiveFilters();
		$callback = $filters[0]['callback'];

		$authorized = $callback( array( 'headers' => array() ), $archive->getUrl() );
		self::assertSame( $this->basicAuthorization(), $authorized['headers']['Authorization'] ?? null );
		$this->assertNoArchiveHooks();

		$second = $callback( array( 'headers' => array() ), $archive->getUrl() );
		self::assertArrayNotHasKey( 'Authorization', $second['headers'] );
	}

	public function testPrivateArchiveCleanupIsIdempotent(): void {
		$archive = $this->privateImmutableArchive();
		self::assertCount( 1, $this->archiveFilters() );
		self::assertCount( 1, $this->archiveActions() );

		$archive->cleanup();
		$this->assertNoArchiveHooks();
		$archive->cleanup();
		$this->assertNoArchiveHooks();
	}

	public function testPrivateArchiveConsumptionCleanupIsIdempotent(): void {
		$archive = $this->privateImmutableArchive();
		$callback = $this->archiveFilters()[0]['callback'];
		$callback( array( 'headers' => array() ), $archive->getUrl() );
		$this->assertNoArchiveHooks();

		$archive->cleanup();
		$this->assertNoArchiveHooks();
	}

	public function testPrivateArchiveWrapsRedirectFailureWithoutLeaking(): void {
		$archive = $this->privateImmutableArchive();
		$actions = $this->archiveActions();
		$callback = $actions[0]['callback'];
		$http = new class() {
			public function request( string $location, array $arguments ): never {
				unset( $location, $arguments );
				throw new RuntimeException( BitbucketArchivePreparerTest::RESPONSE_CANARY );
			}
		};

		try {
			$callback( 'ignored-response', array( 'headers' => array() ), $archive->getUrl(), 'https://downloads.example.test/archive.zip', $http );
			self::fail( 'Expected redirect failure.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSafeFailure( $exception );
		}

		$this->assertNoArchiveHooks();
	}

	public function testPrivateArchiveVerificationFailureCleansHooksBeforeThrowing(): void {
		$archive = $this->privateImmutableArchive();
		$this->queue( array( $this->response( 200, $this->branchBody( 'main', self::OTHER_COMMIT, 'acme/example', self::REPOSITORY_UUID ) ) ) );

		try {
			$archive->verifyCurrentHead();
			self::fail( 'Expected stale archive verification failure.' );
		} catch ( \RAN\RepositoryProvider\StaleDeployment $exception ) {
			$this->assertSafeFailure( $exception );
		}

		$this->assertNoArchiveHooks();
	}

	public function testPrivateArchiveVerifierKeepsHooksWhenCurrentHeadStillMatches(): void {
		$archive = $this->privateImmutableArchive();
		$this->queue( array( $this->response( 200, $this->branchBody( 'main', self::COMMIT, 'acme/example', self::REPOSITORY_UUID ) ) ) );

		$archive->verifyCurrentHead();
		self::assertCount( 1, $this->archiveFilters() );
		self::assertCount( 1, $this->archiveActions() );

		$archive->cleanup();
		$this->assertNoArchiveHooks();
	}

	public function testPrivateArchiveVerificationCanBeRepeatedBeforeConsumption(): void {
		$archive = $this->privateImmutableArchive();
		$this->queue(
			array(
				$this->response( 200, $this->branchBody( 'main', self::COMMIT, 'acme/example', self::REPOSITORY_UUID ) ),
				$this->response( 200, $this->branchBody( 'main', self::COMMIT, 'acme/example', self::REPOSITORY_UUID ) ),
			)
		);

		$archive->verifyCurrentHead();
		$archive->verifyCurrentHead();
		self::assertCount( 1, $this->archiveFilters() );
		self::assertCount( 1, $this->archiveActions() );

		$archive->cleanup();
		$this->assertNoArchiveHooks();
	}

	public function testPrivateArchiveConsumedHandleStillExposesResolvedRefAndCleansIdempotently(): void {
		$archive = $this->privateImmutableArchive();
		$callback = $this->archiveFilters()[0]['callback'];
		$callback( array( 'headers' => array() ), $archive->getUrl() );
		self::assertSame( self::COMMIT, $archive->getResolvedRef() );

		$archive->cleanup();
		$archive->cleanup();
		$this->assertNoArchiveHooks();
	}

	public function testPrivateArchiveExposesOnlyItsImmutableResolvedRef(): void {
		$archive = $this->privateImmutableArchive();

		self::assertSame( self::COMMIT, $archive->getResolvedRef() );
		self::assertStringNotContainsString( self::TOKEN, $archive->getUrl() );
		self::assertStringNotContainsString( self::EMAIL, $archive->getUrl() );
		$archive->cleanup();

		$this->assertNoArchiveHooks();
	}

	private function privateImmutableArchive(): AuthenticatedPreparedArchive {
		$this->queue(
			array(
				$this->response( 200, $this->commitBody( self::COMMIT, 'acme/example', self::REPOSITORY_UUID ) ),
			)
		);
		$archive = $this->preparer( $this->secrets( array( 'profile' => $this->credential() ) ) )
			->prepareArchive( $this->request( self::COMMIT, true, 'profile' ) );

		self::assertInstanceOf( AuthenticatedPreparedArchive::class, $archive );

		return $archive;
	}

	private function preparer( ?BitbucketRepositoryBrowserSecretsStub $secrets = null ): BitbucketArchivePreparer {
		return new BitbucketArchivePreparer(
			new BitbucketCredentialLoader( new BitbucketProviderCredentialStore( $secrets ?? $this->secrets() ) ),
			new BitbucketApiClient()
		);
	}

	private function request(
		string $ref,
		bool $private,
		?string $credentialId = null,
		string $fullName = 'acme/example',
		?string $expectedBranch = null
	): ArchiveRequest {
		return new ArchiveRequest(
			new RepositoryReference( $fullName, self::REPOSITORY_UUID, $private, $credentialId ),
			$ref,
			$expectedBranch
		);
	}

	/** @param array<string, array<string, mixed>> $materials */
	private function secrets( array $materials = array() ): BitbucketRepositoryBrowserSecretsStub {
		return new BitbucketRepositoryBrowserSecretsStub( $materials );
	}

	/** @return array<string, mixed> */
	private function credential( string $workspace = 'acme' ): array {
		return array(
			'id'            => 'profile',
			'provider'      => 'bb',
			'label'         => 'Archive deployment',
			'kind'          => 'api-token',
			'configuration' => array(
				'workspace' => $workspace,
				'email'     => self::EMAIL,
			),
			'secret'        => self::TOKEN,
			'source'        => 'file',
			'immutable'     => false,
			'configured'    => true,
		);
	}

	private function basicAuthorization(): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Assert Bitbucket's required Basic wire value.
		return 'Basic ' . base64_encode( self::EMAIL . ':' . self::TOKEN );
	}

	private function branchBody( string $name, string $hash, string $fullName, string $uuid ): string {
		return $this->json(
			array(
				'name'   => $name,
				'target' => array(
					'hash'       => $hash,
					'repository' => array(
						'full_name' => $fullName,
						'uuid'      => $uuid,
					),
				),
			)
		);
	}

	private function commitBody( string $hash, string $fullName, string $uuid ): string {
		return $this->json(
			array(
				'hash'       => $hash,
				'repository' => array(
					'full_name' => $fullName,
					'uuid'      => $uuid,
				),
			)
		);
	}

	private function errorBody(): string {
		return '{"error":"' . self::RESPONSE_CANARY . '"}';
	}

	private function json( mixed $value ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Isolated JSON fixture encoding outside WordPress runtime.
		$json = json_encode( $value );

		self::assertIsString( $json );

		return $json;
	}

	/** @return array<string, mixed> */
	private function response( int $status, string $body ): array {
		return array(
			'response' => array( 'code' => $status ),
			'body'     => $body,
		);
	}

	/** @param list<mixed> $responses */
	private function queue( array $responses ): void {
		\RAN\Booster\Bitbucket\bitbucket_repository_http_queue( $responses );
	}

	/** @return list<array{url: string, arguments: array<string, mixed>}> */
	private function requests(): array {
		return \RAN\Booster\Bitbucket\bitbucket_credential_validation_http_requests();
	}

	/** @return list<array{callback: callable, priority: int, accepted_args: int}> */
	private function archiveFilters(): array {
		return \RAN\RepositoryProvider\authenticated_archive_filters( 'http_request_args' );
	}

	/** @return list<array{callback: callable, priority: int, accepted_args: int}> */
	private function archiveActions(): array {
		return \RAN\RepositoryProvider\authenticated_archive_actions( AuthenticatedPreparedArchive::REDIRECT_HOOK );
	}

	private function assertNoArchiveHooks( string $context = '' ): void {
		self::assertSame( array(), $this->archiveFilters(), $context );
		self::assertSame( array(), $this->archiveActions(), $context );
	}

	private function assertSafeFailure( \Throwable $exception, string $context = '' ): void {
		self::assertNotSame( '', trim( $exception->getMessage() ), $context );
		self::assertStringNotContainsString( self::TOKEN, $exception->getMessage(), $context );
		self::assertStringNotContainsString( self::EMAIL, $exception->getMessage(), $context );
		self::assertStringNotContainsString( self::RESPONSE_CANARY, $exception->getMessage(), $context );
	}

	private function resetHarness(): void {
		\RAN\RepositoryProvider\authenticated_archive_hooks_reset();
		$this->queue( array() );
	}
}
