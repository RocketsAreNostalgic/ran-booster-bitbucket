<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider;

require_once __DIR__ . '/BitbucketCredentialValidatorWordPressFunctions.php';
require_once __DIR__ . '/BitbucketCredentialValidationSecretsStub.php';

use PHPUnit\Framework\TestCase;
use RAN\Booster\Bitbucket\BitbucketApiClient;
use RAN\Booster\Bitbucket\BitbucketApiException;
use RAN\Booster\Bitbucket\BitbucketCredential;
use RAN\Booster\Bitbucket\BitbucketCredentialLoader;
use RAN\Booster\Bitbucket\BitbucketCredentialValidationTransportError;

final class BitbucketApiClientTest extends TestCase {

	private const EMAIL = 'deploy@example.test';
	private const TOKEN = 'bitbucket-api-client-token-canary';

	protected function setUp(): void {
		parent::setUp();

		\RAN\Booster\Bitbucket\bitbucket_credential_validation_http_reset(
			$this->response( 200, '{"values":[]}' )
		);
	}

	public function testAnonymousRepositoryRequestUsesTheBoundedTransportWithoutAuthorization(): void {
		$response = ( new BitbucketApiClient() )->get(
			'https://api.bitbucket.org/2.0/repositories/rockets-are-nostalgic?pagelen=10'
		);
		$requests = \RAN\Booster\Bitbucket\bitbucket_credential_validation_http_requests();

		self::assertSame( 200, $response->getStatus() );
		self::assertSame( '{"values":[]}', $response->getBody() );
		self::assertCount( 1, $requests );
		self::assertArrayNotHasKey( 'Authorization', $requests[0]['arguments']['headers'] );
		self::assertSame( 0, $requests[0]['arguments']['redirection'] );
		self::assertSame( 262144, $requests[0]['arguments']['limit_response_size'] );
		self::assertTrue( $requests[0]['arguments']['reject_unsafe_urls'] );
	}

	public function testOpaqueRepositoryPaginationUrlPassesThroughTheSamePolicyAndCredential(): void {
		$url        = 'https://api.bitbucket.org/2.0/repositories/rockets-are-nostalgic/repo.name?after=opaque%3Acursor&pagelen=10';
		$credential = $this->credential();

		( new BitbucketApiClient() )->get( $url, $credential );

		$requests = \RAN\Booster\Bitbucket\bitbucket_credential_validation_http_requests();

		self::assertCount( 1, $requests );
		self::assertSame( $url, $requests[0]['url'] );
		self::assertSame(
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Assert the HTTP Basic wire value required by Bitbucket.
			'Basic ' . base64_encode( self::EMAIL . ':' . self::TOKEN ),
			$requests[0]['arguments']['headers']['Authorization']
		);
	}

	public function testRejectsNonRepositoryAndHostileUrlsBeforeSendingCredentials(): void {
		$urls = array(
			'http://api.bitbucket.org/2.0/repositories/workspace',
			'https://api.bitbucket.org./2.0/repositories/workspace',
			'https://api.bitbucket.org.evil.test/2.0/repositories/workspace',
			'https://api.bitbucket.org@evil.test/2.0/repositories/workspace',
			'https://user:pass@api.bitbucket.org/2.0/repositories/workspace',
			'https://api.bitbucket.org:443/2.0/repositories/workspace',
			'https://api.bitbucket.org/2.0/repositories/workspace#fragment',
			'https://api.bitbucket.org/2.0/repositories/workspace?access_token=secret',
			'https://api.bitbucket.org/2.0/repositories/workspace?%61pi_token=secret',
			'https://api.bitbucket.org/2.0/repositories/workspace?token%5Bvalue%5D=secret',
			'https://api.bitbucket.org/2.0/repositories/workspace?email=deploy%40example.test',
			'https://api.bitbucket.org/2.0/repositories/workspace?authorization=secret',
			'https://api.bitbucket.org/2.0/repositories/workspace%5Coutside',
			'https://api.bitbucket.org/2.0/repositories/workspace\\outside',
			'https://api.bitbucket.org/2.0/repositories/workspace/%2e%2e/users',
			'https://api.bitbucket.org/2.0/repositories/workspace/%252e%252e/users',
			'https://api.bitbucket.org/2.0/repositories/workspace%2f..%2fusers',
			'https://api.bitbucket.org/2.0/repositories/workspace/../users',
			'https://api.bitbucket.org/2.0/repositories/workspace/./users',
			'https://api.bitbucket.org/2.0/repositories/workspace/.//users',
			'https://api.bitbucket.org/2.0/repositories/workspace//users',
			'https://api.bitbucket.org/2.0/repositories/workspace/users/',
			'https://api.bitbucket.org/2.0/repositories/workspace%00evil',
			'https://api.bitbucket.org/2.0/repositories/workspace%1Fevil',
			'https://api.bitbucket.org/2.0/users/workspace',
			'https://api.bitbucket.org/2.0/workspaces/workspace',
			'https://api.bitbucket.org/2.0/repositories',
			'https://api.bitbucket.org/1.0/repositories/workspace',
			"https://api.bitbucket.org/2.0/repositories/workspace\n",
		);

		foreach ( $urls as $url ) {
			try {
				( new BitbucketApiClient() )->get( $url, $this->credential() );
				self::fail( 'Expected the hostile Bitbucket URL to be rejected: ' . $url );
			} catch ( BitbucketApiException $exception ) {
				self::assertSame( BitbucketApiException::INVALID_URL, $exception->getReason(), $url );
				self::assertSame( 'The Bitbucket API request URL is not allowed.', $exception->getMessage(), $url );
				self::assertStringNotContainsString( self::TOKEN, $exception->getMessage(), $url );
			}
		}

		self::assertSame( array(), \RAN\Booster\Bitbucket\bitbucket_credential_validation_http_requests() );
	}

	public function testTransportAndMalformedResponsesBecomeFixedSafeExceptions(): void {
		$fixtures = array(
			array(
				'response' => new BitbucketCredentialValidationTransportError( 'http_request_not_executed' ),
				'reason'   => BitbucketApiException::INVALID_RESPONSE,
				'message'  => 'Bitbucket returned an invalid API response.',
			),
			array(
				'response' => new BitbucketCredentialValidationTransportError( 'local_policy_canary' ),
				'reason'   => BitbucketApiException::INVALID_RESPONSE,
				'message'  => 'Bitbucket returned an invalid API response.',
			),
			array(
				'response' => new BitbucketCredentialValidationTransportError( 'http_failure' ),
				'reason'   => BitbucketApiException::INVALID_RESPONSE,
				'message'  => 'Bitbucket returned an invalid API response.',
			),
			array(
				'response' => new BitbucketCredentialValidationTransportError(),
				'reason'   => BitbucketApiException::TRANSPORT_ERROR,
				'message'  => 'Bitbucket could not be reached.',
			),
			array(
				'response' => array(
					'response' => array( 'code' => 200 ),
					'body'     => array( self::TOKEN ),
				),
				'reason'   => BitbucketApiException::INVALID_RESPONSE,
				'message'  => 'Bitbucket returned an invalid API response.',
			),
		);

		foreach ( $fixtures as $fixture ) {
			\RAN\Booster\Bitbucket\bitbucket_credential_validation_http_reset( $fixture['response'] );

			try {
				( new BitbucketApiClient() )->get(
					'https://api.bitbucket.org/2.0/repositories/rockets-are-nostalgic',
					$this->credential()
				);
				self::fail( 'Expected a safe Bitbucket API exception.' );
			} catch ( BitbucketApiException $exception ) {
				self::assertSame( $fixture['reason'], $exception->getReason() );
				self::assertSame( $fixture['message'], $exception->getMessage() );
				self::assertStringNotContainsString( self::TOKEN, $exception->getMessage() );
			}
		}
	}

	public function testCredentialExposesOnlyWorkspaceAndAuthorizationApplication(): void {
		$credential = $this->credential();
		$reflection = new \ReflectionClass( $credential );
		$methods    = array_map(
			static fn ( \ReflectionMethod $method ): string => $method->getName(),
			$reflection->getMethods( \ReflectionMethod::IS_PUBLIC )
		);

		self::assertSame( 'rockets-are-nostalgic', $credential->getWorkspace() );
		self::assertContains( 'getWorkspace', $methods );
		self::assertContains( 'authorize', $methods );
		self::assertNotContains( 'getToken', $methods );
		self::assertNotContains( 'getEmail', $methods );
		self::assertNotContains( '__toString', $methods );

		foreach ( $reflection->getProperties() as $property ) {
			$value = $property->getValue( $credential );

			if ( is_string( $value ) ) {
				self::assertStringNotContainsString( self::TOKEN, $value );
			}
		}
	}

	private function credential(): BitbucketCredential {
		$secrets = new BitbucketCredentialValidationSecretsStub(
			array(
				'profile' => array(
					'provider'      => 'bb',
					'kind'          => 'api-token',
					'configuration' => array(
						'workspace' => 'rockets-are-nostalgic',
						'email'     => self::EMAIL,
					),
					'secret'        => self::TOKEN,
				),
			)
		);

		return ( new BitbucketCredentialLoader( new BitbucketProviderCredentialStore( $secrets ) ) )->load( 'profile' );
	}

	/** @return array<string, mixed> */
	private function response( int $status, string $body ): array {
		return array(
			'response' => array( 'code' => $status ),
			'body'     => $body,
		);
	}
}
