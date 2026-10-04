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

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Preserve the PHPUnit lifecycle override signature.
	protected function setUp(): void {
		parent::setUp();

		\RAN\Booster\Bitbucket\bitbucket_credential_validation_http_reset(
			$this->response( 200, '{"pagelen":1,"values":[]}' )
		);
	}

	public function test_provider_delegates_validation_using_an_exact_scoped_basic_auth_request(): void {
		$secrets  = new BitbucketCredentialValidationSecretsStub( array( 'profile' => $this->credential() ) );
		$store    = new BitbucketProviderCredentialStore( $secrets );
		$loader   = new BitbucketCredentialLoader( $store );
		$api      = new BitbucketApiClient();
		$provider = new BitbucketProvider(
			credential_validator: new BitbucketCredentialValidator( $loader, $api ),
			browser: new BitbucketRepositoryBrowser( $loader, $api ),
			archives: new BitbucketArchivePreparer( $loader, $api ),
			webhooks: new BitbucketWebhookNormalizer( $store )
		);
		$result   = $provider->validate_credential( 'profile' );
		$requests = \RAN\Booster\Bitbucket\bitbucket_credential_validation_http_requests();

		self::assertTrue( $result->is_valid() );
		self::assertNull( $result->get_display_message() );
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

	public function test_validator_forwards_named_request_budgets(): void {
		$result   = $this->validator( $this->secrets() )->validate_credential( credential_id: 'profile', timeout: 3.5, response_size: 12345 );
		$requests = \RAN\Booster\Bitbucket\bitbucket_credential_validation_http_requests();

		self::assertTrue( $result->is_valid() );
		self::assertCount( 1, $requests );
		self::assertSame( 3.5, $requests[0]['arguments']['timeout'] );
		self::assertSame( 12345, $requests[0]['arguments']['limit_response_size'] );
	}

	public function test_missing_explicit_credential_never_falls_back_or_makes_arequest(): void {
		$secrets   = new BitbucketCredentialValidationSecretsStub( array() );
		$validator = $this->validator( $secrets );

		$blank   = $validator->validate_credential( ' ' );
		$missing = $validator->validate_credential( 'missing-profile' );

		self::assertFalse( $blank->is_valid() );
		self::assertFalse( $missing->is_valid() );
		self::assertSame( array( array( 'bb', 'missing-profile' ) ), $secrets->lookups );
		self::assertSame( array(), \RAN\Booster\Bitbucket\bitbucket_credential_validation_http_requests() );
		self::assertSame(
			'The repository provider rejected this credential.',
			$missing->get_display_message()
		);
	}

	public function test_malformed_stored_credential_exception_becomes_afixed_safe_result(): void {
		$secrets = new SecretsFile(
			sys_get_temp_dir() . '/ran-booster-validator-missing-' . bin2hex( random_bytes( 8 ) ) . '.php',
			array(
				'RAN_BOOSTER_BITBUCKET_WORKSPACE' => 'rockets-are-nostalgic',
				'RAN_BOOSTER_BITBUCKET_EMAIL'     => 'credential-material-canary@example.test:smuggled',
				'RAN_BOOSTER_BITBUCKET_TOKEN'     => self::TOKEN,
			)
		);

		$result = $this->validator( $secrets )->validate_credential( 'constant' );

		self::assertFalse( $result->is_valid() );
		self::assertSame(
			'The repository provider rejected this credential.',
			$result->get_display_message()
		);
		$this->assert_message_does_not_contain_credential_or_response_material( $result->get_display_message() );
		self::assertStringNotContainsString( 'credential-material-canary', (string) $result->get_display_message() );
		self::assertSame( array(), \RAN\Booster\Bitbucket\bitbucket_credential_validation_http_requests() );
	}

	public function test_programming_errors_from_credential_storage_are_not_hidden(): void {
		$secrets = new class( null, array() ) extends SecretsFile {
			/** @return array<string, mixed>|null */
			public function credential_material( ProviderCode|string $provider, ?string $id = null ): ?array {
				throw new \LogicException( 'programming-error-canary' );
			}
		};

		$this->expectException( \LogicException::class );
		$this->expectExceptionMessage( 'programming-error-canary' );

		$this->validator( $secrets )->validate_credential( 'profile' );
	}

	public function test_malformed_credential_records_fail_before_any_request(): void {
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
			$result    = $validator->validate_credential( 'profile' );

			self::assertFalse( $result->is_valid(), $name );
			self::assertSame(
				'The repository provider rejected this credential.',
				$result->get_display_message(),
				$name
			);
			self::assertSame(
				array(),
				\RAN\Booster\Bitbucket\bitbucket_credential_validation_http_requests(),
				$name
			);
		}
	}

	public function test_transport_and_status_failures_use_only_fixed_safe_messages(): void {
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
			$result = $this->validator( $this->secrets() )->validate_credential( 'profile' );

			self::assertFalse( $result->is_valid(), (string) $name );
			self::assertSame( $fixture['message'], $result->get_display_message(), (string) $name );
			$this->assert_message_does_not_contain_credential_or_response_material( $result->get_display_message(), (string) $name );
		}
	}

	public function test_malformed_http_responses_fail_safely(): void {
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
			$result = $this->validator( $this->secrets() )->validate_credential( 'profile' );

			self::assertFalse( $result->is_valid() );
			self::assertSame(
				'The repository provider returned an invalid credential-validation response.',
				$result->get_display_message()
			);
			$this->assert_message_does_not_contain_credential_or_response_material( $result->get_display_message() );
		}
	}

	public function test_successful_status_requires_arepository_list_json_shape(): void {
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
			$result = $this->validator( $this->secrets() )->validate_credential( 'profile' );

			self::assertFalse( $result->is_valid(), $body );
			self::assertSame(
				'The repository provider returned an invalid credential-validation response.',
				$result->get_display_message(),
				$body
			);
			$this->assert_message_does_not_contain_credential_or_response_material( $result->get_display_message(), $body );
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

	private function assert_message_does_not_contain_credential_or_response_material( ?string $message, string $context = '' ): void {
		self::assertNotNull( $message, $context );

		self::assertStringNotContainsString( self::TOKEN, $message, $context );
		self::assertStringNotContainsString( self::EMAIL, $message, $context );
		self::assertStringNotContainsString( self::RESPONSE_CANARY, $message, $context );
	}
}
