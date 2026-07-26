<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider;

require_once __DIR__ . '/BitbucketCredentialValidatorWordPressFunctions.php';
require_once __DIR__ . '/BitbucketCredentialValidationSecretsStub.php';

use PHPUnit\Framework\TestCase;
use RAN\Booster\Bitbucket\BitbucketApiClient;
use RAN\Booster\Bitbucket\BitbucketArchivePreparer;
use RAN\Booster\Bitbucket\BitbucketCredentialLoader;
use RAN\Booster\Bitbucket\BitbucketCredentialValidationTransportError;
use RAN\Booster\Bitbucket\BitbucketCredentialValidator;
use RAN\Booster\Bitbucket\BitbucketProvider;
use RAN\Booster\Bitbucket\BitbucketRepositoryBrowser;
use RAN\Booster\Bitbucket\BitbucketWebhookNormalizer;
use RAN\RepositoryProvider\ProviderCode;
use RAN\Secrets\SecretsFile;

final class BitbucketCredentialValidatorTest extends TestCase {

	private const EMAIL           = 'deploy@example.test';
	private const TOKEN           = 'bitbucket-validation-token-canary';
	private const RESPONSE_CANARY = 'bitbucket-response-body-canary';

	protected function setUp(): void {
		parent::setUp();

		\RAN\Booster\Bitbucket\bitbucket_credential_validation_http_reset(
			$this->response( 200, '{"pagelen":1,"values":[]}' )
		);
	}

	public function testProviderDelegatesValidationUsingAnExactScopedBasicAuthRequest(): void {
		$secrets  = new BitbucketCredentialValidationSecretsStub( array( 'profile' => $this->credential() ) );
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
		$result   = $provider->validateCredential( 'profile' );
		$requests = \RAN\Booster\Bitbucket\bitbucket_credential_validation_http_requests();

		self::assertTrue( $result->isValid() );
		self::assertNull( $result->getDisplayMessage() );
		self::assertSame( array( array( 'bb', 'profile' ) ), $secrets->lookups );
		self::assertCount( 1, $requests );
		self::assertSame(
			'https://api.bitbucket.org/2.0/repositories/rockets-are-nostalgic?pagelen=1',
			$requests[0]['url']
		);
		self::assertSame( 15, $requests[0]['arguments']['timeout'] );
		self::assertSame( 0, $requests[0]['arguments']['redirection'] );
		self::assertSame( 262144, $requests[0]['arguments']['limit_response_size'] );
		self::assertTrue( $requests[0]['arguments']['reject_unsafe_urls'] );
		self::assertSame( 'application/json', $requests[0]['arguments']['headers']['Accept'] );
		self::assertSame( 'RAN-Booster', $requests[0]['arguments']['headers']['User-Agent'] );
		self::assertSame(
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Assert the HTTP Basic wire value required by Bitbucket.
			'Basic ' . base64_encode( self::EMAIL . ':' . self::TOKEN ),
			$requests[0]['arguments']['headers']['Authorization']
		);
		self::assertStringNotContainsString( self::EMAIL, $requests[0]['url'] );
		self::assertStringNotContainsString( self::TOKEN, $requests[0]['url'] );
	}

	public function testMissingExplicitCredentialNeverFallsBackOrMakesARequest(): void {
		$secrets   = new BitbucketCredentialValidationSecretsStub( array() );
		$validator = $this->validator( $secrets );

		$blank   = $validator->validateCredential( ' ' );
		$missing = $validator->validateCredential( 'missing-profile' );

		self::assertFalse( $blank->isValid() );
		self::assertFalse( $missing->isValid() );
		self::assertSame( array( array( 'bb', 'missing-profile' ) ), $secrets->lookups );
		self::assertSame( array(), \RAN\Booster\Bitbucket\bitbucket_credential_validation_http_requests() );
		self::assertSame(
			'The repository provider rejected this credential.',
			$missing->getDisplayMessage()
		);
	}

	public function testMalformedStoredCredentialExceptionBecomesAFixedSafeResult(): void {
		$secrets = new SecretsFile(
			sys_get_temp_dir() . '/ran-booster-validator-missing-' . bin2hex( random_bytes( 8 ) ) . '.php',
			array(
				'RAN_BOOSTER_BITBUCKET_WORKSPACE' => 'rockets-are-nostalgic',
				'RAN_BOOSTER_BITBUCKET_EMAIL'     => 'credential-material-canary@example.test:smuggled',
				'RAN_BOOSTER_BITBUCKET_TOKEN'     => self::TOKEN,
			)
		);

		$result = $this->validator( $secrets )->validateCredential( 'constant' );

		self::assertFalse( $result->isValid() );
		self::assertSame(
			'The repository provider rejected this credential.',
			$result->getDisplayMessage()
		);
		$this->assertMessageDoesNotContainCredentialOrResponseMaterial( $result->getDisplayMessage() );
		self::assertStringNotContainsString( 'credential-material-canary', (string) $result->getDisplayMessage() );
		self::assertSame( array(), \RAN\Booster\Bitbucket\bitbucket_credential_validation_http_requests() );
	}

	public function testProgrammingErrorsFromCredentialStorageAreNotHidden(): void {
		$secrets = new class( null, array() ) extends SecretsFile {
			/** @return array<string, mixed>|null */
			public function credentialMaterial( ProviderCode|string $provider, ?string $id = null ): ?array {
				throw new \LogicException( 'programming-error-canary' );
			}
		};

		$this->expectException( \LogicException::class );
		$this->expectExceptionMessage( 'programming-error-canary' );

		$this->validator( $secrets )->validateCredential( 'profile' );
	}

	public function testMalformedCredentialRecordsFailBeforeAnyRequest(): void {
		$fixtures = array(
			'wrong provider'   => array_replace( $this->credential(), array( 'provider' => 'gh' ) ),
			'wrong kind'       => array_replace( $this->credential(), array( 'kind' => 'app-password' ) ),
			'bad workspace'    => $this->credential( 'invalid workspace', self::EMAIL, self::TOKEN ),
			'bad email'        => $this->credential( 'rockets-are-nostalgic', 'not-an-email', self::TOKEN ),
			'colon in email'   => $this->credential( 'rockets-are-nostalgic', 'deploy@example.test:smuggled', self::TOKEN ),
			'control in email' => $this->credential( 'rockets-are-nostalgic', "deploy@example.test\r", self::TOKEN ),
			'blank token'      => $this->credential( 'rockets-are-nostalgic', self::EMAIL, '   ' ),
			'control in token' => $this->credential( 'rockets-are-nostalgic', self::EMAIL, "token\nvalue" ),
		);

		foreach ( $fixtures as $name => $credential ) {
			\RAN\Booster\Bitbucket\bitbucket_credential_validation_http_reset(
				$this->response( 200, '{"values":[]}' )
			);
			$validator = $this->validator(
				new BitbucketCredentialValidationSecretsStub( array( 'profile' => $credential ) )
			);
			$result    = $validator->validateCredential( 'profile' );

			self::assertFalse( $result->isValid(), $name );
			self::assertSame(
				'The repository provider rejected this credential.',
				$result->getDisplayMessage(),
				$name
			);
			self::assertSame(
				array(),
				\RAN\Booster\Bitbucket\bitbucket_credential_validation_http_requests(),
				$name
			);
		}
	}

	public function testTransportAndStatusFailuresUseOnlyFixedSafeMessages(): void {
		$fixtures = array(
			'transport' => array(
				'response' => new BitbucketCredentialValidationTransportError(),
				'message'  => 'The repository provider could not validate this credential. Try again later.',
			),
			'401'       => array(
				'response' => $this->response( 401, '{"error":"' . self::TOKEN . '"}' ),
				'message'  => 'The repository provider rejected this credential.',
			),
			'403'       => array(
				'response' => $this->response( 403, '{"error":"' . self::TOKEN . '"}' ),
				'message'  => 'The repository provider rejected this credential.',
			),
			'404'       => array(
				'response' => $this->response( 404, '{"error":"' . self::TOKEN . '"}' ),
				'message'  => 'The repository provider rejected this credential.',
			),
			'429'       => array(
				'response' => $this->response( 429, '{"error":"' . self::TOKEN . '"}' ),
				'message'  => 'The repository provider rate-limited credential validation. Try again later.',
			),
			'500'       => array(
				'response' => $this->response( 500, '{"error":"' . self::TOKEN . '"}' ),
				'message'  => 'The repository provider could not validate this credential. Try again later.',
			),
		);

		foreach ( $fixtures as $name => $fixture ) {
			\RAN\Booster\Bitbucket\bitbucket_credential_validation_http_reset( $fixture['response'] );
			$result = $this->validator( $this->secrets() )->validateCredential( 'profile' );

			self::assertFalse( $result->isValid(), (string) $name );
			self::assertSame( $fixture['message'], $result->getDisplayMessage(), (string) $name );
			$this->assertMessageDoesNotContainCredentialOrResponseMaterial( $result->getDisplayMessage(), (string) $name );
		}
	}

	public function testMalformedHttpResponsesFailSafely(): void {
		$responses = array(
			null,
			false,
			123,
			'malformed-' . self::RESPONSE_CANARY,
			array(),
			array(
				'response' => 'malformed-' . self::RESPONSE_CANARY,
				'body'     => self::RESPONSE_CANARY,
			),
			array(
				'response' => array( 'code' => '200' ),
				'body'     => self::RESPONSE_CANARY,
			),
			array(
				'response' => array( 'code' => 200 ),
				'body'     => array( self::RESPONSE_CANARY ),
			),
		);

		foreach ( $responses as $response ) {
			\RAN\Booster\Bitbucket\bitbucket_credential_validation_http_reset( $response );
			$result = $this->validator( $this->secrets() )->validateCredential( 'profile' );

			self::assertFalse( $result->isValid() );
			self::assertSame(
				'The repository provider returned an invalid credential-validation response.',
				$result->getDisplayMessage()
			);
			$this->assertMessageDoesNotContainCredentialOrResponseMaterial( $result->getDisplayMessage() );
		}
	}

	public function testSuccessfulStatusRequiresARepositoryListJsonShape(): void {
		$fixtures = array(
			'',
			'not-json-' . self::RESPONSE_CANARY,
			'[]',
			'{"values":"' . self::RESPONSE_CANARY . '"}',
			'{"values":{"0":{"slug":"' . self::RESPONSE_CANARY . '"}}}',
			'{"pagelen":1,"error":"' . self::RESPONSE_CANARY . '"}',
		);

		foreach ( $fixtures as $body ) {
			\RAN\Booster\Bitbucket\bitbucket_credential_validation_http_reset( $this->response( 200, $body ) );
			$result = $this->validator( $this->secrets() )->validateCredential( 'profile' );

			self::assertFalse( $result->isValid(), $body );
			self::assertSame(
				'The repository provider returned an invalid credential-validation response.',
				$result->getDisplayMessage(),
				$body
			);
			$this->assertMessageDoesNotContainCredentialOrResponseMaterial( $result->getDisplayMessage(), $body );
		}
	}

	/** @return array<string, mixed> */
	private function credential(
		string $workspace = 'rockets-are-nostalgic',
		string $email = self::EMAIL,
		string $token = self::TOKEN
	): array {
		return array(
			'provider'      => 'bb',
			'kind'          => 'api-token',
			'configuration' => array(
				'workspace' => $workspace,
				'email'     => $email,
			),
			'secret'        => $token,
		);
	}

	private function secrets(): BitbucketCredentialValidationSecretsStub {
		return new BitbucketCredentialValidationSecretsStub( array( 'profile' => $this->credential() ) );
	}

	private function validator( SecretsFile $secrets ): BitbucketCredentialValidator {
		return new BitbucketCredentialValidator(
			new BitbucketCredentialLoader( new BitbucketProviderCredentialStore( $secrets ) ),
			new BitbucketApiClient()
		);
	}

	/** @return array<string, mixed> */
	private function response( int $status, string $body ): array {
		return array(
			'response' => array( 'code' => $status ),
			'body'     => $body,
		);
	}

	private function assertMessageDoesNotContainCredentialOrResponseMaterial( ?string $message, string $context = '' ): void {
		self::assertNotNull( $message, $context );

		self::assertStringNotContainsString( self::TOKEN, $message, $context );
		self::assertStringNotContainsString( self::EMAIL, $message, $context );
		self::assertStringNotContainsString( self::RESPONSE_CANARY, $message, $context );
	}
}
