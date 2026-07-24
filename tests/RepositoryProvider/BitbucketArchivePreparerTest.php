<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider;

require_once __DIR__ . '/BitbucketCredentialValidatorWordPressFunctions.php';
require_once __DIR__ . '/BitbucketRepositoryBrowserSecretsStub.php';
require_once dirname( __DIR__, 3 ) . '/ran-booster/tests/RepositoryProvider/AuthenticatedPreparedArchiveWordPressFunctions.php';

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
				$this->response( 200, $this->branchBody( 'main', strtoupper( self::COMMIT ), 'ACME/EXAMPLE', self::REPOSITORY_UUID ) ),
			)
		);
		$secrets = $this->secrets( array( 'profile' => $this->credential() ) );

		$archive  = $this->preparer( $secrets )->prepareArchive( $this->request( 'main', true, 'profile' ) );
		$requests = $this->requests();

		self::assertSame(
			'https://api.bitbucket.org/2.0/repositories/acme/example/refs/branches/main?fields=name%2Ctarget.hash%2Ctarget.repository.uuid%2Ctarget.repository.full_name',
			$requests[0]['url']
		);
		self::assertSame( $this->basicAuthorization(), $requests[0]['arguments']['headers']['Authorization'] );
		self::assertSame( 'https://bitbucket.org/acme/example/get/' . self::COMMIT . '.zip', $archive->getUrl() );
		self::assertStringNotContainsString( self::TOKEN, $archive->getUrl() );
		self::assertStringNotContainsString( self::EMAIL, $archive->getUrl() );
		self::assertCount( 1, $this->archiveFilters() );
		self::assertCount( 1, $this->archiveActions() );
	}

	public function testManualFullCommitWithoutExpectedBranchUsesCommitVerification(): void {
		$this->queue(
			array(
				$this->response( 200, $this->commitBody( self::COMMIT, 'acme/example', self::REPOSITORY_UUID ) ),
			)
		);
		$archive  = $this->preparer( $this->secrets( array( 'profile' => $this->credential() ) ) )
			->prepareArchive( $this->request( strtoupper( self::COMMIT ), true, 'profile' ) );
		$requests = $this->requests();

		self::assertCount( 1, $requests );
		self::assertSame(
			'https://api.bitbucket.org/2.0/repositories/acme/example/commit/' . self::COMMIT . '?fields=hash%2Crepository.uuid%2Crepository.full_name',
			$requests[0]['url']
		);
		self::assertSame( $this->basicAuthorization(), $requests[0]['arguments']['headers']['Authorization'] );
		self::assertSame( 'https://bitbucket.org/acme/example/get/' . self::COMMIT . '.zip', $archive->getUrl() );
		self::assertCount( 1, $this->archiveFilters() );
		self::assertCount( 1, $this->archiveActions() );
	}

	public function testWebhookCommitChecksTheConfiguredBranchHeadBeforePreparingArchiveAuthentication(): void {
		$branch = 'release/candidate';
		$this->queue(
			array(
				$this->response(
					200,
					$this->branchBody( $branch, strtoupper( self::COMMIT ), 'ACME/EXAMPLE', self::REPOSITORY_UUID )
				),
			)
		);
		$archive  = $this->preparer( $this->secrets( array( 'profile' => $this->credential() ) ) )
			->prepareArchive( $this->request( strtoupper( self::COMMIT ), true, 'profile', 'acme/example', $branch ) );
		$requests = $this->requests();

		self::assertCount( 1, $requests );
		self::assertSame(
			'https://api.bitbucket.org/2.0/repositories/acme/example/refs/branches/release/candidate?fields=name%2Ctarget.hash%2Ctarget.repository.uuid%2Ctarget.repository.full_name',
			$requests[0]['url']
		);
		self::assertSame( $this->basicAuthorization(), $requests[0]['arguments']['headers']['Authorization'] );
		self::assertSame( 'https://bitbucket.org/acme/example/get/' . self::COMMIT . '.zip', $archive->getUrl() );
		self::assertCount( 1, $this->archiveFilters() );
		self::assertCount( 1, $this->archiveActions() );
	}

	public function testPublicWebhookCommitChecksTheConfiguredBranchAnonymously(): void {
		$this->queue(
			array(
				$this->response(
					200,
					$this->branchBody( 'main', self::COMMIT, 'acme/example', self::REPOSITORY_UUID )
				),
			)
		);
		$archive  = $this->preparer()->prepareArchive(
			$this->request( self::COMMIT, false, null, 'acme/example', 'main' )
		);
		$requests = $this->requests();

		self::assertCount( 1, $requests );
		self::assertSame(
			'https://api.bitbucket.org/2.0/repositories/acme/example/refs/branches/main?fields=name%2Ctarget.hash%2Ctarget.repository.uuid%2Ctarget.repository.full_name',
			$requests[0]['url']
		);
		self::assertArrayNotHasKey( 'Authorization', $requests[0]['arguments']['headers'] );
		self::assertSame( 'https://bitbucket.org/acme/example/get/' . self::COMMIT . '.zip', $archive->getUrl() );
		$this->assertNoArchiveHooks();
	}

	public function testAutomaticArchiveRechecksTheBranchImmediatelyBeforeMutation(): void {
		$this->queue(
			array(
				$this->response( 200, $this->branchBody( 'main', self::COMMIT, 'acme/example', self::REPOSITORY_UUID ) ),
				$this->response( 200, $this->branchBody( 'main', self::OTHER_COMMIT, 'acme/example', self::REPOSITORY_UUID ) ),
			)
		);
		$archive = $this->preparer()->prepareArchive(
			$this->request( self::COMMIT, false, null, 'acme/example', 'main' )
		);

		try {
			$archive->verifyCurrentHead();
			self::fail( 'The second Bitbucket head check must reject a branch that moved before mutation.' );
		} catch ( \RAN\RepositoryProvider\StaleDeployment $exception ) {
			self::assertSame( 409, $exception->getCode() );
		}

		self::assertCount( 2, $this->requests() );
	}

	public function testExpectedBranchRejectsNonCommitRefBeforeHttpOrArchiveAuthentication(): void {
		try {
			$this->preparer( $this->secrets( array( 'profile' => $this->credential() ) ) )
				->prepareArchive( $this->request( 'main', true, 'profile', 'acme/example', 'main' ) );
			self::fail( 'An expected branch must be paired with an immutable commit.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( 400, $exception->getCode() );
			self::assertSame( 'The Bitbucket deployment event does not contain a valid commit.', $exception->getMessage() );
		}

		self::assertSame( array(), $this->requests() );
		$this->assertNoArchiveHooks();
	}

	public function testStaleOrMismatchedWebhookBranchResponsesFailBeforeArchiveAuthentication(): void {
		$fixtures = array(
			'stale head'          => array(
				$this->branchBody( 'main', self::OTHER_COMMIT, 'acme/example', self::REPOSITORY_UUID ),
				409,
				'The Bitbucket deployment event is stale because the configured branch has moved.',
			),
			'branch mismatch'     => array(
				$this->branchBody( 'other', self::COMMIT, 'acme/example', self::REPOSITORY_UUID ),
				502,
				null,
			),
			'repository mismatch' => array(
				$this->branchBody( 'main', self::COMMIT, 'other/example', self::REPOSITORY_UUID ),
				502,
				null,
			),
			'uuid mismatch'       => array(
				$this->branchBody( 'main', self::COMMIT, 'acme/example', '{other-uuid}' ),
				502,
				null,
			),
		);

		foreach ( $fixtures as $name => [$body, $code, $expectedMessage] ) {
			$context = (string) $name;
			$this->resetHarness();
			$this->queue( array( $this->response( 200, $body ) ) );

			try {
				$this->preparer( $this->secrets( array( 'profile' => $this->credential() ) ) )
					->prepareArchive( $this->request( self::COMMIT, true, 'profile', 'acme/example', 'main' ) );
				self::fail( 'Expected the webhook branch guard to reject: ' . $context );
			} catch ( RuntimeException $exception ) {
				self::assertSame( $code, $exception->getCode(), $context );
				if ( null !== $expectedMessage ) {
					self::assertSame( $expectedMessage, $exception->getMessage(), $context );
				}
				$this->assertSafeFailure( $exception, $context );
			}

			self::assertCount( 1, $this->requests(), $context );
			self::assertSame(
				'https://api.bitbucket.org/2.0/repositories/acme/example/refs/branches/main?fields=name%2Ctarget.hash%2Ctarget.repository.uuid%2Ctarget.repository.full_name',
				$this->requests()[0]['url'],
				$context
			);
			self::assertSame( $this->basicAuthorization(), $this->requests()[0]['arguments']['headers']['Authorization'], $context );
			$this->assertNoArchiveHooks( $context );
		}
	}

	public function testInvalidProviderRepositoryRefCredentialAndWorkspaceFailBeforeHttpOrHooks(): void {
		$fixtures = array(
			'repository traversal' => fn (): ArchiveRequest => $this->request( self::COMMIT, false, null, 'acme/../example' ),
			'ref traversal'        => fn (): ArchiveRequest => $this->request( '../main', false ),
			'ref repeated slash'   => fn (): ArchiveRequest => $this->request( 'feature//candidate', false ),
			'ref control'          => fn (): ArchiveRequest => $this->request( "main\n", false ),
			'missing credential'   => fn (): ArchiveRequest => $this->request( self::COMMIT, true, 'missing' ),
			'implicit credential'  => fn (): ArchiveRequest => $this->request( self::COMMIT, true ),
		);

		foreach ( $fixtures as $name => $requestFactory ) {
			$this->resetHarness();

			try {
				$this->preparer()->prepareArchive( $requestFactory() );
				self::fail( 'Expected invalid archive input to fail: ' . $name );
			} catch ( \Throwable $exception ) {
				$this->assertSafeFailure( $exception, $name );
			}

			self::assertSame( array(), $this->requests(), $name );
			$this->assertNoArchiveHooks( $name );
		}

		$this->resetHarness();
		$secrets = $this->secrets( array( 'other' => $this->credential( 'other' ) ) );

		try {
			$this->preparer( $secrets )->prepareArchive( $this->request( self::COMMIT, true, 'other' ) );
			self::fail( 'Expected a credential for another workspace to fail.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSafeFailure( $exception, 'workspace mismatch' );
		}

		self::assertSame( array(), $this->requests() );
		$this->assertNoArchiveHooks();
	}

	public function testBranchStatusTransportAndMalformedResponsesFailSafelyWithoutArchiveHooks(): void {
		$fixtures = array(
			'transport'           => array( new BitbucketCredentialValidationTransportError(), 0, true ),
			'blocked transport'   => array( new BitbucketCredentialValidationTransportError( 'http_request_not_executed' ), 502, false ),
			'local policy error'  => array( new BitbucketCredentialValidationTransportError( 'local_policy_canary' ), 502, false ),
			'no transport'        => array( new BitbucketCredentialValidationTransportError( 'http_failure' ), 502, false ),
			'400'                 => array( $this->response( 400, $this->errorBody() ), 400, false ),
			'401'                 => array( $this->response( 401, $this->errorBody() ), 401, false ),
			'403'                 => array( $this->response( 403, $this->errorBody() ), 403, false ),
			'404'                 => array( $this->response( 404, $this->errorBody() ), 404, false ),
			'410'                 => array( $this->response( 410, $this->errorBody() ), 410, false ),
			'429'                 => array( $this->response( 429, $this->errorBody() ), 429, false ),
			'503'                 => array( $this->response( 503, $this->errorBody() ), 0, true ),
			'502'                 => array( $this->response( 502, $this->errorBody() ), 0, true ),
			'504'                 => array( $this->response( 504, $this->errorBody() ), 0, true ),
			'500'                 => array( $this->response( 500, $this->errorBody() ), 502, false ),
			'501'                 => array( $this->response( 501, $this->errorBody() ), 502, false ),
			'505'                 => array( $this->response( 505, $this->errorBody() ), 502, false ),
			'not json'            => array( $this->response( 200, self::RESPONSE_CANARY ), 502, false ),
			'missing target'      => array( $this->response( 200, '{"name":"main"}' ), 502, false ),
			'branch mismatch'     => array( $this->response( 200, $this->branchBody( 'other', self::COMMIT, 'acme/example', self::REPOSITORY_UUID ) ), 502, false ),
			'invalid hash'        => array( $this->response( 200, $this->branchBody( 'main', 'not-a-hash', 'acme/example', self::REPOSITORY_UUID ) ), 502, false ),
			'repository mismatch' => array( $this->response( 200, $this->branchBody( 'main', self::COMMIT, 'other/example', self::REPOSITORY_UUID ) ), 502, false ),
			'uuid mismatch'       => array( $this->response( 200, $this->branchBody( 'main', self::COMMIT, 'acme/example', '{other-uuid}' ) ), 502, false ),
		);

		foreach ( $fixtures as $name => [$response, $code, $retryable] ) {
			$context = (string) $name;
			$this->resetHarness();
			$this->queue( '404' === $context ? array( $response, $response ) : array( $response ) );

			try {
				$this->preparer( $this->secrets( array( 'profile' => $this->credential() ) ) )
					->prepareArchive( $this->request( 'main', true, 'profile' ) );
				self::fail( 'Expected branch resolution failure: ' . $context );
			} catch ( RuntimeException $exception ) {
				self::assertSame( $retryable ? 0 : $code, $exception->getCode(), $context );
				$this->assertSafeFailure( $exception, $context );
			}

			self::assertCount( '404' === $context ? 2 : 1, $this->requests(), $context );
			self::assertStringNotContainsString( self::TOKEN, $this->requests()[0]['url'], $context );
			self::assertStringNotContainsString( self::EMAIL, $this->requests()[0]['url'], $context );
			$this->assertNoArchiveHooks( $context );
		}
	}

	public function testDirectCommitStatusTransportAndIdentityFailuresAreSafeAndHookFree(): void {
		$fixtures = array(
			'transport'           => array( new BitbucketCredentialValidationTransportError(), 0, true ),
			'blocked transport'   => array( new BitbucketCredentialValidationTransportError( 'http_request_not_executed' ), 502, false ),
			'local policy error'  => array( new BitbucketCredentialValidationTransportError( 'local_policy_canary' ), 502, false ),
			'no transport'        => array( new BitbucketCredentialValidationTransportError( 'http_failure' ), 502, false ),
			'400'                 => array( $this->response( 400, $this->errorBody() ), 400, false ),
			'401'                 => array( $this->response( 401, $this->errorBody() ), 401, false ),
			'403'                 => array( $this->response( 403, $this->errorBody() ), 403, false ),
			'404'                 => array( $this->response( 404, $this->errorBody() ), 404, false ),
			'410'                 => array( $this->response( 410, $this->errorBody() ), 410, false ),
			'429'                 => array( $this->response( 429, $this->errorBody() ), 429, false ),
			'503'                 => array( $this->response( 503, $this->errorBody() ), 0, true ),
			'502'                 => array( $this->response( 502, $this->errorBody() ), 0, true ),
			'504'                 => array( $this->response( 504, $this->errorBody() ), 0, true ),
			'500'                 => array( $this->response( 500, $this->errorBody() ), 502, false ),
			'501'                 => array( $this->response( 501, $this->errorBody() ), 502, false ),
			'505'                 => array( $this->response( 505, $this->errorBody() ), 502, false ),
			'not json'            => array( $this->response( 200, self::RESPONSE_CANARY ), 502, false ),
			'missing repository'  => array( $this->response( 200, $this->json( array( 'hash' => self::COMMIT ) ) ), 502, false ),
			'hash mismatch'       => array( $this->response( 200, $this->commitBody( self::OTHER_COMMIT, 'acme/example', self::REPOSITORY_UUID ) ), 502, false ),
			'repository mismatch' => array( $this->response( 200, $this->commitBody( self::COMMIT, 'other/example', self::REPOSITORY_UUID ) ), 502, false ),
			'uuid mismatch'       => array( $this->response( 200, $this->commitBody( self::COMMIT, 'acme/example', '{other-uuid}' ) ), 502, false ),
		);

		foreach ( $fixtures as $name => [$response, $code, $retryable] ) {
			$context = (string) $name;
			$this->resetHarness();
			$this->queue( array( $response ) );

			try {
				$this->preparer( $this->secrets( array( 'profile' => $this->credential() ) ) )
					->prepareArchive( $this->request( self::COMMIT, true, 'profile' ) );
				self::fail( 'Expected direct commit verification failure: ' . $context );
			} catch ( RuntimeException $exception ) {
				self::assertSame( $retryable ? 0 : $code, $exception->getCode(), $context );
				$this->assertSafeFailure( $exception, $context );
			}

			self::assertCount( 1, $this->requests(), $context );
			self::assertSame(
				'https://api.bitbucket.org/2.0/repositories/acme/example/commit/' . self::COMMIT . '?fields=hash%2Crepository.uuid%2Crepository.full_name',
				$this->requests()[0]['url'],
				$context
			);
			self::assertSame( $this->basicAuthorization(), $this->requests()[0]['arguments']['headers']['Authorization'], $context );
			self::assertStringNotContainsString( self::TOKEN, $this->requests()[0]['url'], $context );
			self::assertStringNotContainsString( self::EMAIL, $this->requests()[0]['url'], $context );
			$this->assertNoArchiveHooks( $context );
		}
	}

	public function testPrivateAuthenticationIsOneShotAndBoundToTheExactImmutableArchive(): void {
		$archive  = $this->privateImmutableArchive();
		$callback = $this->archiveFilters()[0]['callback'];
		$url      = $archive->getUrl();
		$hostile  = array(
			'https://example.test/archive.zip',
			'https://bitbucket.org.evil.test/acme/example/get/' . self::COMMIT . '.zip',
			'https://bitbucket.org/acme/other/get/' . self::COMMIT . '.zip',
			'https://bitbucket.org/acme/example/get/' . self::OTHER_COMMIT . '.zip',
			'http://bitbucket.org/acme/example/get/' . self::COMMIT . '.zip',
			$url . '?download=1',
			$url . '#fragment',
		);

		foreach ( $hostile as $candidate ) {
			$arguments = $callback( array( 'headers' => array( 'Existing' => 'value' ) ), $candidate );

			self::assertSame( array( 'Existing' => 'value' ), $arguments['headers'], $candidate );
			self::assertCount( 1, $this->archiveFilters(), $candidate );
		}

		$arguments = $callback( array( 'headers' => array( 'Existing' => 'value' ) ), $url );

		self::assertSame( 'value', $arguments['headers']['Existing'] );
		self::assertSame( $this->basicAuthorization(), $arguments['headers']['Authorization'] );
		self::assertSame( array(), $this->archiveFilters() );
		self::assertCount( 1, $this->archiveActions() );

		try {
			$callback( array( 'headers' => array() ), $url );
			self::fail( 'Expected consumed archive authentication to remain unavailable.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSafeFailure( $exception );
		}
	}

	public function testRedirectScrubberOnlyRemovesAuthInheritedFromTheExactArchiveOrigin(): void {
		$archive          = $this->privateImmutableArchive();
		$requestCallback  = $this->archiveFilters()[0]['callback'];
		$redirectCallback = $this->archiveActions()[0]['callback'];
		$url              = $archive->getUrl();
		$arguments        = $requestCallback( array( 'headers' => array() ), $url );
		$location         = 'https://bbuseruploads.example.test/signed/archive.zip';
		$unrelatedHeaders = $arguments['headers'];
		$archiveHeaders   = array(
			'authorization' => $arguments['headers']['Authorization'],
			'Existing'      => 'value',
		);

		call_user_func_array(
			$redirectCallback,
			array( &$location, &$unrelatedHeaders, null, array(), (object) array( 'url' => 'https://example.test/' ) )
		);
		self::assertArrayHasKey( 'Authorization', $unrelatedHeaders );

		call_user_func_array(
			$redirectCallback,
			array( &$location, &$archiveHeaders, null, array(), (object) array( 'url' => $url ) )
		);
		self::assertArrayNotHasKey( 'authorization', $archiveHeaders );
		self::assertSame( 'value', $archiveHeaders['Existing'] );
		self::assertStringNotContainsString( self::TOKEN, $location );

		$archive->cleanup();
		$this->assertNoArchiveHooks();
	}

	public function testCleanupIsIdempotentBeforeAndAfterAuthentication(): void {
		$cancelled = $this->privateImmutableArchive();
		$callback  = $this->archiveFilters()[0]['callback'];

		$cancelled->cleanup();
		$cancelled->cleanup();
		$this->assertNoArchiveHooks();

		try {
			$callback( array( 'headers' => array() ), $cancelled->getUrl() );
			self::fail( 'Expected cleaned archive authentication to remain unavailable.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSafeFailure( $exception );
		}

		$consumed = $this->privateImmutableArchive();
		$this->archiveFilters()[0]['callback']( array( 'headers' => array() ), $consumed->getUrl() );
		$consumed->cleanup();
		$consumed->cleanup();
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
