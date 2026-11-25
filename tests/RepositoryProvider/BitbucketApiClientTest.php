<?php

declare(strict_types=1);

namespace RAN\Booster\Bitbucket\Tests\RepositoryProvider;

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

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Preserve the PHPUnit lifecycle override signature.
	protected function setUp(): void {
		parent::setUp();

		\RAN\Booster\Bitbucket\bitbucket_credential_validation_http_reset(
			$this->response( 200, '{"values":[]}' )
		);
	}

	public function test_anonymous_repository_request_uses_the_bounded_transport_without_authorization(): void {
		$response = ( new BitbucketApiClient() )->get(
			'https://api.bitbucket.org/2.0/repositories/rockets-are-nostalgic?pagelen=10'
		);
		$requests = \RAN\Booster\Bitbucket\bitbucket_credential_validation_http_requests();

		self::assertSame( 200, $response->get_status() );
		self::assertSame( '{"values":[]}', $response->get_body() );
		self::assertCount( 1, $requests );
		self::assertArrayNotHasKey( 'Authorization', $requests[0]['arguments']['headers'] );
		self::assertSame( 0, $requests[0]['arguments']['redirection'] );
		self::assertSame( 262144, $requests[0]['arguments']['limit_response_size'] );
		self::assertTrue( $requests[0]['arguments']['reject_unsafe_urls'] );
	}

	public function test_opaque_repository_pagination_url_passes_through_the_same_policy_and_credential(): void {
		$url        = 'https://api.bitbucket.org/2.0/repositories/rockets-are-nostalgic/repo.name?after=opaque%3Acursor&pagelen=10';
		$credential = $this->credential();

		( new BitbucketApiClient() )->get( url: $url, credential: $credential, timeout: 2.5, response_size: 17 );

		$requests = \RAN\Booster\Bitbucket\bitbucket_credential_validation_http_requests();

		self::assertCount( 1, $requests );
		self::assertSame( $url, $requests[0]['url'] );
		self::assertSame( 2.5, $requests[0]['arguments']['timeout'] );
		self::assertSame( 17, $requests[0]['arguments']['limit_response_size'] );
		self::assertSame(
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Assert the HTTP Basic wire value required by Bitbucket.
			'Basic ' . base64_encode( self::EMAIL . ':' . self::TOKEN ),
			$requests[0]['arguments']['headers']['Authorization']
		);
	}

	public function test_rejects_non_repository_and_hostile_urls_before_sending_credentials(): void {
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
				self::assertSame( BitbucketApiException::INVALID_URL, $exception->get_reason(), $url );
				self::assertSame( 'The Bitbucket API request URL is not allowed.', $exception->getMessage(), $url );
				self::assertStringNotContainsString( self::TOKEN, $exception->getMessage(), $url );
			}
		}

		self::assertSame( array(), \RAN\Booster\Bitbucket\bitbucket_credential_validation_http_requests() );
	}

	public function test_rejects_widened_credential_parameters_case_insensitively_and_unlisted_endpoint_parameters(): void {
		$credential_parameters = array(
			'access_token',
			'api_key',
			'api_token',
			'api-token',
			'apikey',
			'app_password',
			'app-password',
			'auth',
			'authorization',
			'bearer',
			'email',
			'jwt',
			'key',
			'password',
			'secret',
			'sig',
			'signature',
			'token',
			'username',
		);

		$urls = array();
		foreach ( $credential_parameters as $parameter ) {
			$mixed   = ucfirst( $parameter );
			$upper   = strtoupper( $parameter );
			$encoded = '%' . strtoupper( bin2hex( $parameter[0] ) ) . substr( $parameter, 1 );
			$urls[]  = 'https://api.bitbucket.org/2.0/repositories/workspace?' . $parameter . '=secret';
			$urls[]  = 'https://api.bitbucket.org/2.0/repositories/workspace?pagelen=100&' . $mixed . '=secret';
			$urls[]  = 'https://api.bitbucket.org/2.0/repositories/workspace?after=cursor&' . $upper . '=secret';
			$urls[]  = 'https://api.bitbucket.org/2.0/repositories/workspace?' . $encoded . '=secret';
			$urls[]  = 'https://api.bitbucket.org/2.0/repositories/workspace?' . $mixed . '%5B0%5D=secret';
			$urls[]  = 'https://api.bitbucket.org/2.0/repositories/workspace/repo/commit/0123456789abcdef0123456789abcdef01234567?fields=hash&' . $parameter . '=secret';
		}

		$urls = array_merge(
			$urls,
			array(
				'https://api.bitbucket.org/2.0/repositories/workspace?unexpected=1',
				'https://api.bitbucket.org/2.0/repositories/workspace?PageLen=10',
				'https://api.bitbucket.org/2.0/repositories/workspace?%66ields=uuid',
				'https://api.bitbucket.org/2.0/repositories/workspace?fields%5B0%5D=uuid',
				'https://api.bitbucket.org/2.0/repositories/workspace/repo/commit/0123456789abcdef0123456789abcdef01234567?after=cursor',
				'https://api.bitbucket.org/2.0/repositories/workspace/repo/refs/branches/main?pagelen=10',
				'https://api.bitbucket.org/2.0/repositories/workspace/repo/refs/tags/v1.0.0?page=2',
				'https://api.bitbucket.org/2.0/repositories/workspace/repo/unknown?fields=uuid',
			)
		);

		$client     = new BitbucketApiClient();
		$credential = $this->credential();

		foreach ( $urls as $url ) {
			try {
				$client->get( $url, $credential );
				self::fail( 'Expected the blocked Bitbucket query parameter URL to be rejected: ' . $url );
			} catch ( BitbucketApiException $exception ) {
				self::assertSame( BitbucketApiException::INVALID_URL, $exception->get_reason(), $url );
				self::assertSame( 'The Bitbucket API request URL is not allowed.', $exception->getMessage(), $url );
				self::assertStringNotContainsString( self::TOKEN, $exception->getMessage(), $url );
			}
		}

		self::assertSame( array(), \RAN\Booster\Bitbucket\bitbucket_credential_validation_http_requests() );
	}

	public function test_allows_known_query_parameters_for_each_bitbucket_endpoint(): void {
		$urls = array(
			'https://api.bitbucket.org/2.0/repositories/workspace?pagelen=100&fields=values.uuid%2Cnext&after=opaque%3Acursor&page=2',
			'https://api.bitbucket.org/2.0/repositories/workspace/repo?fields=uuid%2Cfull_name',
			'https://api.bitbucket.org/2.0/repositories/workspace/repo/commit/0123456789abcdef0123456789abcdef01234567?fields=hash',
			'https://api.bitbucket.org/2.0/repositories/workspace/repo/refs/branches/feature/test?fields=name',
			'https://api.bitbucket.org/2.0/repositories/workspace/repo/refs/tags/v1.0.0?fields=name',
		);

		$client = new BitbucketApiClient();
		foreach ( $urls as $url ) {
			\RAN\Booster\Bitbucket\bitbucket_credential_validation_http_reset(
				$this->response( 200, '{"values":[]}' )
			);
			$response = $client->get( $url );
			self::assertSame( 200, $response->get_status(), $url );
			self::assertCount( 1, \RAN\Booster\Bitbucket\bitbucket_credential_validation_http_requests(), $url );
		}
	}

	public function test_transport_and_malformed_responses_become_fixed_safe_exceptions(): void {
		$fixtures = array(
			array(
				'response' => new BitbucketCredentialValidationTransportError( '' ),
				'reason'   => BitbucketApiException::TRANSPORT_ERROR,
				'message'  => 'Bitbucket could not be reached.',
			),
			array(
				'response' => new BitbucketCredentialValidationTransportError( 'http_request_not_executed' ),
				'reason'   => BitbucketApiException::BLOCKED_BY_SITE,
				'message'  => 'This site blocked the Bitbucket API request.',
			),
			array(
				'response' => new BitbucketCredentialValidationTransportError( 'local_policy_canary' ),
				'reason'   => BitbucketApiException::TRANSPORT_ERROR,
				'message'  => 'Bitbucket could not be reached.',
			),
			array(
				'response' => new BitbucketCredentialValidationTransportError( 'http_failure' ),
				'reason'   => BitbucketApiException::TRANSPORT_ERROR,
				'message'  => 'Bitbucket could not be reached.',
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

		foreach ( array(
			null,
			false,
			42,
			self::TOKEN,
			new \stdClass(),
			array(),
			array(
				'response' => 42,
				'body'     => '',
			),
			array(
				'response' => array( 'code' => '200' ),
				'body'     => '',
			),
		) as $malformed ) {
			$fixtures[] = array(
				'response' => $malformed,
				'reason'   => BitbucketApiException::INVALID_RESPONSE,
				'message'  => 'Bitbucket returned an invalid API response.',
			);
		}

		foreach ( $fixtures as $fixture ) {
			\RAN\Booster\Bitbucket\bitbucket_credential_validation_http_reset( $fixture['response'] );

			try {
				( new BitbucketApiClient() )->get(
					'https://api.bitbucket.org/2.0/repositories/rockets-are-nostalgic',
					$this->credential()
				);
				self::fail( 'Expected a safe Bitbucket API exception.' );
			} catch ( BitbucketApiException $exception ) {
				self::assertSame( $fixture['reason'], $exception->get_reason() );
				self::assertSame( $fixture['message'], $exception->getMessage() );
				self::assertStringNotContainsString( self::TOKEN, $exception->getMessage() );
			}
		}
	}

	public function test_credential_exposes_only_workspace_and_authorization_application(): void {
		$credential = $this->credential();
		$reflection = new \ReflectionClass( $credential );
		$methods    = array_map(
			static fn ( \ReflectionMethod $method ): string => $method->getName(),
			$reflection->getMethods( \ReflectionMethod::IS_PUBLIC )
		);

		self::assertSame( 'rockets-are-nostalgic', $credential->get_workspace() );
		self::assertContains( 'get_workspace', $methods );
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

		return ( new BitbucketCredentialLoader( new BitbucketProviderCredentialStore( $secrets ) ) )->load( credential_id: 'profile' );
	}

	/** @return array<string, mixed> */
	private function response( int $status, string $body ): array {
		return array(
			'response' => array( 'code' => $status ),
			'body'     => $body,
		);
	}
}
