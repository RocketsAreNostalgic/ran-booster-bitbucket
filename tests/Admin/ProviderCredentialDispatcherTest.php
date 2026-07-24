<?php

declare(strict_types=1);

namespace Tests\Admin;

require_once __DIR__ . '/../Support/ProviderCredentialDispatcherWordPressFunctions.php';
require_once __DIR__ . '/../Support/WPError.php';

// Direct local filesystem operations exercise the sidecar-backed dispatcher fixture.
// phpcs:disable WordPress.WP.AlternativeFunctions

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Admin\CredentialExpiryObservationStore;
use RAN\Admin\ManagedPackageWebhookAuthorityResolver;
use RAN\Admin\PackageEditProviderGuard;
use RAN\AbstractPackage;
use RAN\Dashboard;
use RAN\Dispatcher;
use RAN\GitHub\RepositoryBrowser as GitHubRepositoryBrowser;
use RAN\ManagedRepository;
use RAN\RepositoryProvider\Bitbucket\BitbucketApiClient;
use RAN\RepositoryProvider\Bitbucket\BitbucketArchivePreparer;
use RAN\RepositoryProvider\Bitbucket\BitbucketCredentialLoader;
use RAN\RepositoryProvider\Bitbucket\BitbucketCredentialValidator;
use RAN\RepositoryProvider\Bitbucket\BitbucketProvider;
use RAN\RepositoryProvider\Bitbucket\BitbucketRepositoryBrowser;
use RAN\RepositoryProvider\Bitbucket\BitbucketWebhookNormalizer;
use RAN\RepositoryProvider\CredentialExpiryReport;
use RAN\RepositoryProvider\CredentialValidationResult;
use RAN\RepositoryProvider\GitHubProvider;
use RAN\RepositoryProvider\GitHubWebhookNormalizer;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\ProviderSecretPolicyCatalog;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\Secrets\EncryptedSecretsEnvelopeCodec;
use RAN\Secrets\SecretsFile;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;
use RAN\Storage\CredentialUsageReader;
use Tests\Support\CredentialUsageDatabase;
use Tests\Support\InMemoryCredentialExpiryObservationStore;
use Tests\Support\InMemoryPublicRepositoryLookupProfileStore;
use Tests\RepositoryProvider\Support\ShippedSecretPolicyCatalog;
use Tests\Secrets\SecretsFileTestFactory;
use WP_Error;

final class ProviderCredentialDispatcherTest extends TestCase {

	private const GITHUB_CLASSIC_TOKEN = 'dispatcher-github-classic-canary';
	private const GITHUB_FINE_TOKEN    = 'dispatcher-github-fine-canary';
	private const BITBUCKET_TOKEN      = 'dispatcher-bitbucket-token-canary';
	private const GITHUB_WEBHOOK       = 'dispatcher-github-webhook-canary';
	private const BITBUCKET_WEBHOOK    = 'dispatcher-bitbucket-webhook-canary';

	private string $directory;
	private string $path;
	private SecretsFile $secrets;
	private ProviderRegistry $providers;
	private InMemoryPublicRepositoryLookupProfileStore $publicLookupProfiles;
	private InMemoryCredentialExpiryObservationStore $expiryObservations;

	/** @var list<mixed> */
	private array $messages = array();

	protected function setUp(): void {
		parent::setUp();

		$this->directory = sys_get_temp_dir() . '/ran-booster-dispatcher-' . bin2hex( random_bytes( 8 ) );
		$this->path      = $this->directory . '/secrets.json';

		self::assertTrue( mkdir( $this->directory, 0700 ) );

		$secretPolicies             = new ProviderSecretPolicyCatalog();
		$this->secrets              = SecretsFileTestFactory::create( $this->path, array(), $secretPolicies );
		$bitbucketStore             = new \Tests\RepositoryProvider\BitbucketProviderCredentialStore( $this->secrets );
		$bitbucketLoader            = new BitbucketCredentialLoader( $bitbucketStore );
		$bitbucketApi               = new BitbucketApiClient();
		$this->providers            = new ProviderRegistry(
			array(
				new GitHubProvider(
					$this->secrets,
					new GitHubRepositoryBrowser( $this->secrets ),
					new GitHubWebhookNormalizer( $this->secrets )
				),
				new BitbucketProvider(
					new BitbucketCredentialValidator(
						$bitbucketLoader,
						$bitbucketApi
					),
					new BitbucketRepositoryBrowser( $bitbucketLoader, $bitbucketApi ),
					new BitbucketArchivePreparer( $bitbucketLoader, $bitbucketApi ),
					new BitbucketWebhookNormalizer( $bitbucketStore )
				),
			),
			$secretPolicies
		);
		$this->messages             = array();
		$this->publicLookupProfiles = new InMemoryPublicRepositoryLookupProfileStore();
		$this->expiryObservations   = new InMemoryCredentialExpiryObservationStore();
		$_POST                      = array();
		$GLOBALS['ran_booster_test_capability_checks'] = array();
		$GLOBALS['ran_booster_test_nonce_checks']      = array();
		$GLOBALS['ran_booster_test_capabilities']      = array();
		$GLOBALS['ran_booster_test_nonce_valid']       = true;
	}

	protected function tearDown(): void {
		$_POST = array();
		unset(
			$GLOBALS['ran_booster_test_capability_checks'],
			$GLOBALS['ran_booster_test_nonce_checks'],
			$GLOBALS['ran_booster_test_capabilities'],
			$GLOBALS['ran_booster_test_nonce_valid']
		);

		foreach ( array( $this->path, $this->path . '.lock' ) as $path ) {
			if ( is_file( $path ) || is_link( $path ) ) {
				unlink( $path );
			}
		}

		if ( is_dir( $this->directory ) ) {
			rmdir( $this->directory );
		}

		parent::tearDown();
	}

	public function testSavesGitHubClassicCredentialFromProviderMetadata(): void {
		$this->dispatch(
			array(
				'action'        => 'save-access-profile',
				'provider'      => 'gh',
				'id'            => 'github_classic',
				'label'         => 'Cross-organization access',
				'kind'          => 'classic',
				'configuration' => array( 'owner' => 'must-be-ignored' ),
				'secret'        => self::GITHUB_CLASSIC_TOKEN,
			)
		);

		$material = $this->secrets->credentialMaterial( ProviderCode::parse( 'gh' ), 'github_classic' );

		self::assertIsArray( $material );
		self::assertSame( 'classic', $material['kind'] );
		self::assertSame( array( 'owner' => '' ), $material['configuration'] );
		self::assertSame( self::GITHUB_CLASSIC_TOKEN, $material['secret'] );
		self::assertSame( array( 'Repository access token saved.' ), $this->messages );
	}

	public function testSavesOptionalManualExpiryForEitherProvider(): void {
		foreach (
			array(
				'gh' => array(
					'kind'          => 'classic',
					'configuration' => array( 'owner' => '' ),
					'secret'        => self::GITHUB_CLASSIC_TOKEN,
				),
				'bb' => array(
					'kind'          => 'api-token',
					'configuration' => array(
						'workspace' => 'workspace',
						'email'     => 'deploy@example.test',
					),
					'secret'        => self::BITBUCKET_TOKEN,
				),
			) as $provider => $credential
		) {
			$this->dispatch(
				array(
					'action'     => 'save-access-profile',
					'provider'   => $provider,
					'id'         => $provider . '_expires',
					'label'      => 'Expiring credential',
					'expires_on' => '2026-12-31',
				) + $credential
			);

			self::assertSame(
				array( 'manual_expires_on' => '2026-12-31' ),
				$this->expiryObservations->get( $provider, $provider . '_expires' )
			);
		}
	}

	public function testLabelOnlyEditRetainsExpiryAndSecretReplacementClearsIt(): void {
		$this->secrets->saveCredential(
			'gh',
			'edit_expiry',
			array(
				'label'         => 'Original',
				'kind'          => 'classic',
				'configuration' => array( 'owner' => '' ),
			),
			self::GITHUB_CLASSIC_TOKEN
		);
		$this->expiryObservations->setManualExpiry( 'gh', 'edit_expiry', '2026-12-31' );
		$this->expiryObservations->recordProviderExpiry(
			'gh',
			'edit_expiry',
			CredentialExpiryReport::known( '2027-01-01T00:00:00Z' ),
			'2026-07-23T12:00:00Z'
		);

		$this->dispatch(
			array(
				'action'        => 'save-access-profile',
				'provider'      => 'gh',
				'id'            => 'edit_expiry',
				'label'         => 'Label only',
				'kind'          => 'classic',
				'configuration' => array( 'owner' => '' ),
				'secret'        => '',
			)
		);

		self::assertSame( '2027-01-01T00:00:00Z', $this->expiryObservations->get( 'gh', 'edit_expiry' )['provider_expires_at'] );

		$this->messages = array();
		$this->dispatch(
			array(
				'action'        => 'save-access-profile',
				'provider'      => 'gh',
				'id'            => 'edit_expiry',
				'label'         => 'Replacement',
				'kind'          => 'classic',
				'configuration' => array( 'owner' => '' ),
				'secret'        => self::GITHUB_FINE_TOKEN,
			)
		);

		self::assertSame( array(), $this->expiryObservations->get( 'gh', 'edit_expiry' ) );
		self::assertSame(
			array( 'Repository access token replaced. Validate it to refresh provider expiry information.' ),
			$this->messages
		);
	}

	public function testRunsTroubleshootingOnlyAfterAllCapabilitiesAndDedicatedNonce(): void {
		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::once() )
			->method( 'postRunTroubleshooting' )
			->with(
				array(
					'provider'      => 'gh',
					'credential_id' => 'private-profile',
					'repository'    => ' RocketsAreNostalgic/ran-booster ',
				)
			);

		$this->dispatch(
			array(
				'action'        => 'run-troubleshooting',
				'provider'      => 'gh',
				'credential_id' => ' private-profile ',
				'repository'    => ' RocketsAreNostalgic/ran-booster ',
			),
			null,
			null,
			$dashboard
		);

		self::assertSame(
			array( 'manage_options', 'install_plugins', 'update_plugins', 'install_themes', 'update_themes' ),
			$GLOBALS['ran_booster_test_capability_checks']
		);
		self::assertSame( array( 'ran-booster-run-troubleshooting' ), $GLOBALS['ran_booster_test_nonce_checks'] );
	}

	public function testMalformedTroubleshootingFieldsAreRejectedAfterAuthorizationWithoutCallingService(): void {
		foreach (
			array(
				array( 'provider' => array( 'gh' ) ),
				array(
					'provider'      => 'gh',
					'credential_id' => array( 'profile' ),
				),
				array(
					'provider'   => 'gh',
					'repository' => array( 'owner/repository' ),
				),
				array(
					'provider'      => 'gh',
					'credential_id' => str_repeat( 'a', 129 ),
				),
				array(
					'provider'   => 'gh',
					'repository' => str_repeat( 'a', 513 ),
				),
			) as $fields
		) {
			$dashboard = $this->createMock( Dashboard::class );
			$dashboard->expects( self::never() )->method( 'postRunTroubleshooting' );
			$GLOBALS['ran_booster_test_capability_checks'] = array();
			$GLOBALS['ran_booster_test_nonce_checks']      = array();

			$this->dispatch( array_merge( array( 'action' => 'run-troubleshooting' ), $fields ), null, null, $dashboard );

			self::assertCount( 5, $GLOBALS['ran_booster_test_capability_checks'] );
			self::assertSame( array( 'ran-booster-run-troubleshooting' ), $GLOBALS['ran_booster_test_nonce_checks'] );
		}
	}

	/** @return list<array{string, list<string>}> */
	public static function deniedTroubleshootingCapabilityProvider(): array {
		$capabilities = array( 'manage_options', 'install_plugins', 'update_plugins', 'install_themes', 'update_themes' );

		return array_map(
			static fn( string $capability, int $index ): array => array( $capability, array_slice( $capabilities, 0, $index + 1 ) ),
			$capabilities,
			array_keys( $capabilities )
		);
	}

	#[DataProvider( 'deniedTroubleshootingCapabilityProvider' )]
	public function testEveryTroubleshootingCapabilityIsRequiredBeforeNonceOrService( string $denied, array $expectedChecks ): void {
		$GLOBALS['ran_booster_test_capabilities'][ $denied ] = false;
		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::never() )->method( 'postRunTroubleshooting' );

		try {
			$this->dispatch(
				array(
					'action'   => 'run-troubleshooting',
					'provider' => 'gh',
				),
				null,
				null,
				$dashboard
			);
			self::fail( 'A denied troubleshooting capability must stop the request.' );
		} catch ( \RuntimeException ) {
			self::assertSame( $expectedChecks, $GLOBALS['ran_booster_test_capability_checks'] );
			self::assertSame( array(), $GLOBALS['ran_booster_test_nonce_checks'] );
		}
	}

	public function testInvalidTroubleshootingNonceStopsBeforeInputParsingOrService(): void {
		$GLOBALS['ran_booster_test_nonce_valid'] = false;
		$dashboard                               = $this->createMock( Dashboard::class );
		$dashboard->expects( self::never() )->method( 'postRunTroubleshooting' );

		try {
			$this->dispatch(
				array(
					'action'        => 'run-troubleshooting',
					'provider'      => array( 'input-canary' ),
					'credential_id' => array( 'credential-canary' ),
				),
				null,
				null,
				$dashboard
			);
			self::fail( 'An invalid troubleshooting nonce must stop the request.' );
		} catch ( \RuntimeException ) {
			self::assertCount( 5, $GLOBALS['ran_booster_test_capability_checks'] );
			self::assertSame( array( 'ran-booster-run-troubleshooting' ), $GLOBALS['ran_booster_test_nonce_checks'] );
		}
	}

	public function testNonArrayTroubleshootingPayloadIsIgnoredWithoutAuthorizationOrServiceWork(): void {
		$_POST['ran_booster'] = 'not-an-array';
		$dashboard            = $this->createMock( Dashboard::class );
		$dashboard->expects( self::never() )->method( 'postRunTroubleshooting' );

		$this->dispatcher( $dashboard )->dispatchPostRequests();

		self::assertSame( array(), $GLOBALS['ran_booster_test_capability_checks'] );
		self::assertSame( array(), $GLOBALS['ran_booster_test_nonce_checks'] );
	}

	public function testSavesGitHubFineGrainedCredentialAndAllowlistsConfiguration(): void {
		$this->dispatch(
			array(
				'action'        => 'save-access-profile',
				'provider'      => 'gh',
				'id'            => 'github_fine',
				'label'         => 'RAN repositories',
				'kind'          => 'fine-grained',
				'configuration' => array(
					'owner'    => ' RocketsAreNostalgic ',
					'injected' => 'must-not-be-persisted',
				),
				'secret'        => self::GITHUB_FINE_TOKEN,
			)
		);

		$material = $this->secrets->credentialMaterial( ProviderCode::parse( 'gh' ), 'github_fine' );

		self::assertIsArray( $material );
		self::assertSame( 'fine-grained', $material['kind'] );
		self::assertSame( array( 'owner' => 'RocketsAreNostalgic' ), $material['configuration'] );
		self::assertSame( self::GITHUB_FINE_TOKEN, $material['secret'] );
		self::assertArrayNotHasKey( 'injected', $material['configuration'] );
	}

	public function testSavesBitbucketApiTokenMetadata(): void {
		$this->dispatch(
			array(
				'action'        => 'save-access-profile',
				'provider'      => 'bb',
				'id'            => 'bitbucket_api',
				'label'         => 'Bitbucket deployment',
				'kind'          => 'api-token',
				'configuration' => array(
					'workspace' => ' rockets-are-nostalgic ',
					'email'     => ' deploy@example.test ',
					'injected'  => 'must-not-be-persisted',
				),
				'secret'        => self::BITBUCKET_TOKEN,
			)
		);

		$material = $this->secrets->credentialMaterial( ProviderCode::parse( 'bb' ), 'bitbucket_api' );

		self::assertIsArray( $material );
		self::assertSame( 'api-token', $material['kind'] );
		self::assertSame(
			array(
				'workspace' => 'rockets-are-nostalgic',
				'email'     => 'deploy@example.test',
			),
			$material['configuration']
		);
		self::assertSame( self::BITBUCKET_TOKEN, $material['secret'] );
	}

	public function testRejectsInvalidBitbucketApiTokenRequestsWithoutPersistingOrDisclosingSecrets(): void {
		$fixtures = array(
			'missing workspace' => array(
				'configuration' => array(
					'email' => 'deploy@example.test',
				),
				'expected'      => 'Complete every required credential field.',
			),
			'invalid email'     => array(
				'configuration' => array(
					'workspace' => 'rockets-are-nostalgic',
					'email'     => 'invalid-email',
				),
				'expected'      => 'Enter a valid account email address.',
			),
			'invalid workspace' => array(
				'configuration' => array(
					'workspace' => 'invalid workspace!',
					'email'     => 'deploy@example.test',
				),
				'expected'      => 'Booster could not complete the credential request.',
			),
			'missing secret'    => array(
				'configuration' => array(
					'workspace' => 'rockets-are-nostalgic',
					'email'     => 'deploy@example.test',
				),
				'id'            => '',
				'secret'        => '',
				'expected'      => 'Enter the credential secret.',
			),
		);

		foreach ( $fixtures as $name => $fixture ) {
			$canary         = 'dispatcher-invalid-bitbucket-' . str_replace( ' ', '-', $name ) . '-canary';
			$this->messages = array();
			$this->dispatch(
				array(
					'action'        => 'save-access-profile',
					'provider'      => 'bb',
					'id'            => $fixture['id'] ?? 'invalid_bitbucket',
					'label'         => 'Invalid Bitbucket fixture',
					'kind'          => 'api-token',
					'configuration' => $fixture['configuration'],
					'secret'        => $fixture['secret'] ?? $canary,
				)
			);

			self::assertCount( 1, $this->messages, $name );
			self::assertInstanceOf( WP_Error::class, $this->messages[0], $name );
			self::assertSame( $fixture['expected'], $this->messages[0]->get_error_message(), $name );
			self::assertStringNotContainsString( $canary, $this->messages[0]->get_error_message(), $name );
			self::assertNull( $this->secrets->credentialMaterial( ProviderCode::parse( 'bb' ), 'invalid_bitbucket' ), $name );
			self::assertFileDoesNotExist( $this->path, $name );
		}
	}

	public function testValidatesAConfiguredCredentialWithoutChangingTheSidecar(): void {
		$this->secrets->saveCredential(
			ProviderCode::parse( 'bb' ),
			'validation_profile',
			array(
				'label'         => 'Validation fixture',
				'kind'          => 'api-token',
				'configuration' => array(
					'workspace' => 'rockets-are-nostalgic',
					'email'     => 'deploy@example.test',
				),
			),
			self::BITBUCKET_TOKEN
		);

		$before    = file_get_contents( $this->path );
		$validator = new CredentialValidationProvider( CredentialValidationResult::valid() );
		$providers = new ProviderRegistry( array( $validator ) );

		$this->dispatch(
			array(
				'action'   => 'validate-access-profile',
				'provider' => 'bb',
				'id'       => 'validation_profile',
			),
			null,
			$providers
		);

		self::assertSame( array( 'validation_profile' ), $validator->validatedIds );
		self::assertSame( array( 'Repository credential validated successfully.' ), $this->messages );
		self::assertSame( $before, file_get_contents( $this->path ) );
	}

	public function testSuccessfulValidationStoresProviderExpiryAndFailedValidationRetainsIt(): void {
		$this->secrets->saveCredential(
			'bb',
			'validation_expiry',
			array(
				'label'         => 'Validation expiry',
				'kind'          => 'api-token',
				'configuration' => array(
					'workspace' => 'workspace',
					'email'     => 'deploy@example.test',
				),
			),
			self::BITBUCKET_TOKEN
		);
		$knownExpiry = CredentialExpiryReport::known( '2026-09-01T00:00:00Z' );

		$this->dispatch(
			array(
				'action'   => 'validate-access-profile',
				'provider' => 'bb',
				'id'       => 'validation_expiry',
			),
			null,
			new ProviderRegistry(
				array( new CredentialValidationProvider( CredentialValidationResult::valid( $knownExpiry ) ) )
			)
		);

		$record = $this->expiryObservations->get( 'bb', 'validation_expiry' );
		self::assertSame( '2026-09-01T00:00:00Z', $record['provider_expires_at'] );
		self::assertArrayHasKey( 'provider_checked_at', $record );

		$this->messages = array();
		$this->dispatch(
			array(
				'action'   => 'validate-access-profile',
				'provider' => 'bb',
				'id'       => 'validation_expiry',
			),
			null,
			new ProviderRegistry(
				array( new CredentialValidationProvider( CredentialValidationResult::invalid() ) )
			)
		);

		self::assertSame( $record, $this->expiryObservations->get( 'bb', 'validation_expiry' ) );
		self::assertInstanceOf( WP_Error::class, $this->messages[0] );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function untrustedValidationDisplayMessages(): array {
		return array(
			'canary'    => array( 'provider-validation-display-canary' ),
			'header'    => array( 'Authorization: Bearer provider-header-display-canary' ),
			'multiline' => array( "Validation failed\r\nSet-Cookie: provider-multiline-display-canary=1" ),
			'oversize'  => array( str_repeat( 'provider-oversize-display-canary-', 256 ) ),
		);
	}

	#[DataProvider( 'untrustedValidationDisplayMessages' )]
	public function testMapsValidatorFailureToACoreOwnedMessageWithoutChangingTheSidecar( string $untrustedMessage ): void {
		$this->secrets->saveCredential(
			ProviderCode::parse( 'bb' ),
			'validation_profile',
			array(
				'label'         => 'Validation fixture',
				'kind'          => 'api-token',
				'configuration' => array(
					'workspace' => 'rockets-are-nostalgic',
					'email'     => 'deploy@example.test',
				),
			),
			self::BITBUCKET_TOKEN
		);

		$factory   = new \ReflectionMethod( CredentialValidationResult::class, 'invalid' );
		$result    = $factory->invokeArgs( null, array( $untrustedMessage ) );
		$before    = file_get_contents( $this->path );
		$validator = new CredentialValidationProvider( $result );

		self::assertSame( 0, $factory->getNumberOfParameters() );

		$this->dispatch(
			array(
				'action'   => 'validate-access-profile',
				'provider' => 'bb',
				'id'       => 'validation_profile',
			),
			null,
			new ProviderRegistry( array( $validator ) )
		);

		self::assertCount( 1, $this->messages );
		self::assertInstanceOf( WP_Error::class, $this->messages[0] );
		self::assertSame( 'The repository provider rejected this credential.', $this->messages[0]->get_error_message() );
		self::assertStringNotContainsString( 'canary', $this->messages[0]->get_error_message() );
		self::assertStringNotContainsString( $untrustedMessage, $this->messages[0]->get_error_message() );
		self::assertStringNotContainsString( self::BITBUCKET_TOKEN, $this->messages[0]->get_error_message() );
		self::assertSame( $before, file_get_contents( $this->path ) );
	}

	public function testRejectsCredentialValidationForAnUnsupportedProviderWithoutChangingTheSidecar(): void {
		$this->secrets->saveCredential(
			ProviderCode::parse( 'gh' ),
			'github_validation',
			array(
				'label'         => 'GitHub validation fixture',
				'kind'          => 'classic',
				'configuration' => array( 'owner' => '' ),
			),
			self::GITHUB_CLASSIC_TOKEN
		);

		$before   = file_get_contents( $this->path );
		$provider = new class() implements RepositoryProvider {
			use \Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

			public function getMetadata(): ProviderMetadata {
				return new ProviderMetadata(
					ProviderCode::parse( 'fixture' ),
					'Fixture',
					'https://example.test/',
					'Owner'
				);
			}
		};

		$this->dispatch(
			array(
				'action'   => 'validate-access-profile',
				'provider' => 'fixture',
				'id'       => 'github_validation',
			),
			null,
			new ProviderRegistry( array( $provider ) )
		);

		self::assertCount( 1, $this->messages );
		self::assertInstanceOf( WP_Error::class, $this->messages[0] );
		self::assertSame(
			'Credential validation is unavailable for this repository provider.',
			$this->messages[0]->get_error_message()
		);
		self::assertSame( $before, file_get_contents( $this->path ) );
	}

	public function testWebhookSaveAndDeleteRemainScopedToTheSelectedProvider(): void {
		$this->dispatch(
			array(
				'action'   => 'save-webhook-profile',
				'provider' => 'gh',
				'id'       => 'shared_webhook',
				'label'    => 'GitHub webhook',
				'scope'    => 'owner',
				'target'   => 'RocketsAreNostalgic',
				'secret'   => self::GITHUB_WEBHOOK,
			)
		);
		$this->dispatch(
			array(
				'action'   => 'save-webhook-profile',
				'provider' => 'bb',
				'id'       => 'shared_webhook',
				'label'    => 'Bitbucket webhook',
				'scope'    => 'workspace',
				'target'   => 'rockets-are-nostalgic',
				'secret'   => self::BITBUCKET_WEBHOOK,
			)
		);

		self::assertSame(
			self::GITHUB_WEBHOOK,
			$this->secrets->webhookMaterials( ProviderCode::parse( 'gh' ) )['shared_webhook']['secret']
		);
		self::assertSame(
			self::BITBUCKET_WEBHOOK,
			$this->secrets->webhookMaterials( ProviderCode::parse( 'bb' ) )['shared_webhook']['secret']
		);

		$this->dispatch(
			array(
				'action'   => 'delete-webhook-profile',
				'provider' => 'bb',
				'id'       => 'shared_webhook',
			)
		);

		self::assertArrayHasKey( 'shared_webhook', $this->secrets->webhookMaterials( ProviderCode::parse( 'gh' ) ) );
		self::assertArrayNotHasKey( 'shared_webhook', $this->secrets->webhookMaterials( ProviderCode::parse( 'bb' ) ) );
		self::assertSame( 'Push-to-Deploy secret removed.', $this->messages[2] );
	}

	public function testRepositoryWebhookScopeStoresTheManagedStableAuthorityIdentity(): void {
		$package = new class() extends AbstractPackage {
			public function getIdentifier(): mixed {
				return 'example/example.php';
			}
		};

		$repository = new ManagedRepository( 'gh', 'RocketsAreNostalgic/example', 'github-repository-42', 'main' );
		$package->setRepository( $repository );

		$this->dispatch(
			array(
				'action'   => 'save-webhook-profile',
				'provider' => 'gh',
				'id'       => 'repository_webhook',
				'label'    => 'Example repository webhook',
				'scope'    => 'repository',
				'target'   => 'rocketsarenostalgic/example',
				'secret'   => self::GITHUB_WEBHOOK,
			),
			null,
			null,
			null,
			$this->webhookAuthorities( array( $package ) )
		);

		self::assertSame(
			'github-repository-42',
			$this->secrets->webhookMaterials( ProviderCode::parse( 'gh' ) )['repository_webhook']['authority_id']
		);
	}

	public function testUnknownProviderProducesASafeActionableError(): void {
		$this->dispatch(
			array(
				'action'   => 'save-access-profile',
				'provider' => 'unknown-provider',
				'label'    => 'Unknown',
				'kind'     => 'classic',
				'secret'   => 'unknown-provider-secret-canary',
			)
		);

		self::assertCount( 1, $this->messages );
		self::assertInstanceOf( WP_Error::class, $this->messages[0] );
		self::assertSame( 'Choose a supported repository provider.', $this->messages[0]->get_error_message() );
		self::assertStringNotContainsString( 'unknown-provider-secret-canary', $this->messages[0]->get_error_message() );
	}

	public function testSetsReplacesAndClearsProviderPublicLookupProfiles(): void {
		foreach ( array( 'github_classic', 'github_fine' ) as $id ) {
			$this->secrets->saveCredential(
				'gh',
				$id,
				array(
					'label'         => $id,
					'kind'          => 'github_fine' === $id ? 'fine-grained' : 'classic',
					'configuration' => array( 'owner' => 'github_fine' === $id ? 'RocketsAreNostalgic' : '' ),
				),
				'github_fine' === $id ? self::GITHUB_FINE_TOKEN : self::GITHUB_CLASSIC_TOKEN
			);
		}
		$this->secrets->saveCredential(
			'bb',
			'bitbucket_profile',
			array(
				'label'         => 'Bitbucket public lookup',
				'kind'          => 'api-token',
				'configuration' => array(
					'workspace' => 'workspace',
					'email'     => 'test@example.test',
				),
			),
			self::BITBUCKET_TOKEN
		);

		foreach (
			array(
				array( 'gh', 'github_classic' ),
				array( 'gh', 'github_fine' ),
				array( 'gh', '' ),
				array( 'bb', 'bitbucket_profile' ),
				array( 'bb', '' ),
			) as [$provider, $profileId]
		) {
			$this->messages = array();
			$this->dispatch(
				array(
					'action'     => 'save-public-lookup-profile',
					'provider'   => $provider,
					'profile_id' => $profileId,
				)
			);

			self::assertSame( '' === $profileId ? null : $profileId, $this->publicLookupProfiles->get( $provider ) );
		}

		self::assertSame( array( 'ran-booster-save-public-lookup-profile' ), array_unique( $GLOBALS['ran_booster_test_nonce_checks'] ) );
		self::assertSame( array( 'Public repository lookup will use anonymous access.' ), $this->messages );
	}

	public function testRejectsMissingAndWrongProviderPublicLookupProfiles(): void {
		$this->secrets->saveCredential(
			'bb',
			'bitbucket_profile',
			array(
				'label'         => 'Bitbucket',
				'kind'          => 'api-token',
				'configuration' => array(
					'workspace' => 'workspace',
					'email'     => 'test@example.test',
				),
			),
			self::BITBUCKET_TOKEN
		);

		foreach (
			array(
				array(
					'provider'   => 'gh',
					'profile_id' => 'missing_profile',
				),
				array(
					'provider'   => 'gh',
					'profile_id' => 'bitbucket_profile',
				),
			) as $request
		) {
			$this->messages = array();
			$this->dispatch( array( 'action' => 'save-public-lookup-profile' ) + $request );

			self::assertNull( $this->publicLookupProfiles->get( $request['provider'] ) );
			self::assertInstanceOf( WP_Error::class, $this->messages[0] );
		}
	}

	public function testDeletingTheConfiguredPublicLookupProfileClearsThePreferenceAfterVerifiedDeletion(): void {
		$this->secrets->saveCredential(
			'gh',
			'public_lookup',
			array(
				'label'         => 'Public lookup',
				'kind'          => 'classic',
				'configuration' => array( 'owner' => '' ),
			),
			self::GITHUB_CLASSIC_TOKEN
		);
		$this->publicLookupProfiles->set( 'gh', 'public_lookup' );

		$this->dispatch(
			array(
				'action'   => 'delete-access-profile',
				'provider' => 'gh',
				'id'       => 'public_lookup',
			),
			null,
			null,
			null,
			null,
			new CredentialUsageReader( new CredentialUsageDatabase(), 'wp_ran_booster_packages' )
		);

		self::assertNull( $this->publicLookupProfiles->get( 'gh' ) );
		self::assertNull( $this->secrets->credentialMaterial( 'gh', 'public_lookup' ) );
		self::assertSame(
			array( 'Repository access token removed. Public repository lookup now uses anonymous access.' ),
			$this->messages
		);
	}

	public function testDeletesAnUnusedFileCredentialOnlyAfterCheckedAbsenceReadback(): void {
		$this->secrets->saveCredential(
			'gh',
			'delete_me',
			array(
				'label'         => 'Delete me',
				'kind'          => 'classic',
				'configuration' => array( 'owner' => '' ),
			),
			self::GITHUB_CLASSIC_TOKEN
		);
		$this->secrets->saveCredential(
			'bb',
			'delete_me',
			array(
				'label'         => 'Keep me',
				'kind'          => 'api-token',
				'configuration' => array(
					'workspace' => 'workspace',
					'email'     => 'test@example.test',
				),
			),
			self::BITBUCKET_TOKEN
		);
		$database = new CredentialUsageDatabase();
		$this->expiryObservations->setManualExpiry( 'gh', 'delete_me', '2026-12-31' );

		$this->dispatch(
			array(
				'action'   => 'delete-access-profile',
				'provider' => 'gh',
				'id'       => 'delete_me',
			),
			null,
			null,
			null,
			null,
			new CredentialUsageReader( $database, 'wp_ran_booster_packages' )
		);

		self::assertNull( $this->secrets->credentialMaterial( 'gh', 'delete_me' ) );
		self::assertNotNull( $this->secrets->credentialMaterial( 'bb', 'delete_me' ) );
		self::assertSame( array(), $this->expiryObservations->get( 'gh', 'delete_me' ) );
		self::assertSame( array( 'Repository access token removed.' ), $this->messages );
		self::assertSame( array( 'wp_ran_booster_packages', 'gh', 'delete_me' ), $database->prepared[0]['arguments'] );
	}

	public function testManagedPackageReferenceIncludingAMissingPackageBlocksDeletion(): void {
		$this->secrets->saveCredential(
			'gh',
			'in_use',
			array(
				'label'         => 'In use',
				'kind'          => 'classic',
				'configuration' => array( 'owner' => '' ),
			),
			self::GITHUB_CLASSIC_TOKEN
		);
		$database        = new CredentialUsageDatabase();
		$database->count = '3';
		$database->rows  = array(
			(object) array(
				'type'    => '1',
				'package' => 'installed/plugin.php',
			),
			(object) array(
				'type'    => '2',
				'package' => 'installed-theme',
			),
			(object) array(
				'type'    => '1',
				'package' => 'missing/plugin.php',
			),
		);

		$this->dispatch(
			array(
				'action'   => 'delete-access-profile',
				'provider' => 'gh',
				'id'       => 'in_use',
			),
			null,
			null,
			null,
			null,
			new CredentialUsageReader( $database, 'wp_ran_booster_packages' )
		);

		self::assertNotNull( $this->secrets->credentialMaterial( 'gh', 'in_use' ) );
		self::assertInstanceOf( WP_Error::class, $this->messages[0] );
		self::assertSame( 'This repository access token is used by 3 managed packages. Assign another credential before deleting it.', $this->messages[0]->get_error_message() );
	}

	public function testMalformedUsageResultBlocksDeletion(): void {
		$this->secrets->saveCredential(
			'gh',
			'malformed_usage',
			array(
				'label'         => 'Malformed usage',
				'kind'          => 'classic',
				'configuration' => array( 'owner' => '' ),
			),
			self::GITHUB_CLASSIC_TOKEN
		);
		$database        = new CredentialUsageDatabase();
		$database->count = null;

		$this->dispatch(
			array(
				'action'   => 'delete-access-profile',
				'provider' => 'gh',
				'id'       => 'malformed_usage',
			),
			null,
			null,
			null,
			null,
			new CredentialUsageReader( $database, 'wp_ran_booster_packages' )
		);

		self::assertInstanceOf( WP_Error::class, $this->messages[0] );
		self::assertNotNull( $this->secrets->credentialMaterial( 'gh', 'malformed_usage' ) );
	}

	public function testUsageQueryFailureBlocksDeletionAndRedactsDatabaseErrors(): void {
		$this->secrets->saveCredential(
			'gh',
			'query_failure',
			array(
				'label'         => 'Query failure',
				'kind'          => 'classic',
				'configuration' => array( 'owner' => '' ),
			),
			self::GITHUB_CLASSIC_TOKEN
		);
		$database             = new CredentialUsageDatabase();
		$database->last_error = 'dispatcher-usage-secret-canary';

		$this->dispatch(
			array(
				'action'   => 'delete-access-profile',
				'provider' => 'gh',
				'id'       => 'query_failure',
			),
			null,
			null,
			null,
			null,
			new CredentialUsageReader( $database, 'wp_ran_booster_packages' )
		);

		self::assertNotNull( $this->secrets->credentialMaterial( 'gh', 'query_failure' ) );
		self::assertInstanceOf( WP_Error::class, $this->messages[0] );
		self::assertSame( 'Booster could not complete the credential request.', $this->messages[0]->get_error_message() );
		self::assertStringNotContainsString( 'dispatcher-usage-secret-canary', $this->messages[0]->get_error_message() );
	}

	public function testForgedConstantMissingAndWrongProviderProfilesFailBeforeUsageQuery(): void {
		$this->secrets->saveCredential(
			'gh',
			'exact_profile',
			array(
				'label'         => 'Exact profile',
				'kind'          => 'classic',
				'configuration' => array( 'owner' => '' ),
			),
			self::GITHUB_CLASSIC_TOKEN
		);
		$this->secrets->saveCredential(
			'bb',
			'wrong_provider',
			array(
				'label'         => 'Wrong provider',
				'kind'          => 'api-token',
				'configuration' => array(
					'workspace' => 'workspace',
					'email'     => 'test@example.test',
				),
			),
			self::BITBUCKET_TOKEN
		);
		$database = new CredentialUsageDatabase();

		foreach ( array( 'exact_profile<script>', 'constant', 'missing_profile', 'wrong_provider' ) as $id ) {
			$this->messages = array();
			$this->dispatch(
				array(
					'action'   => 'delete-access-profile',
					'provider' => 'gh',
					'id'       => $id,
				),
				null,
				null,
				null,
				null,
				new CredentialUsageReader( $database, 'wp_ran_booster_packages' )
			);
			self::assertInstanceOf( WP_Error::class, $this->messages[0] );
		}

		self::assertSame( array(), $database->prepared );
		self::assertNotNull( $this->secrets->credentialMaterial( 'gh', 'exact_profile' ) );
		self::assertNotNull( $this->secrets->credentialMaterial( 'bb', 'wrong_provider' ) );
	}

	public function testFalseDeleteAndPositiveReadbackCannotReportSuccess(): void {
		foreach ( array( false, true ) as $pretendDeleted ) {
			$secretPolicies = ShippedSecretPolicyCatalog::create();
			$secrets        = new class( $this->path, array(), $secretPolicies, $pretendDeleted ) extends SecretsFile {
				public function __construct( string $path, array $constants, ProviderSecretPolicyCatalog $policies, private bool $pretendDeleted ) {
					parent::__construct(
						$path,
						$constants,
						$policies,
						SecretsFileTestFactory::keyStore( $path ),
						new EncryptedSecretsEnvelopeCodec()
					);
				}

				public function deleteCredential( ProviderCode|string $provider, string $id ): bool {
					unset( $provider, $id );

					return $this->pretendDeleted;
				}
			};
			$secrets->saveCredential(
				'gh',
				'delete_failure',
				array(
					'label'         => 'Delete failure',
					'kind'          => 'classic',
					'configuration' => array( 'owner' => '' ),
				),
				self::GITHUB_CLASSIC_TOKEN
			);
			$this->publicLookupProfiles->set( 'gh', 'delete_failure' );
			$this->messages = array();

			$this->dispatch(
				array(
					'action'   => 'delete-access-profile',
					'provider' => 'gh',
					'id'       => 'delete_failure',
				),
				$secrets,
				null,
				null,
				null,
				new CredentialUsageReader( new CredentialUsageDatabase(), 'wp_ran_booster_packages' )
			);

			self::assertInstanceOf( WP_Error::class, $this->messages[0] );
			self::assertSame( 'Booster could not verify that the repository credential was removed.', $this->messages[0]->get_error_message() );
			self::assertNotNull( $secrets->credentialMaterial( 'gh', 'delete_failure' ) );
			self::assertSame( 'delete_failure', $this->publicLookupProfiles->get( 'gh' ) );
		}
	}

	public function testDeploymentConstantCredentialAndWebhookProfilesAreImmutableWithoutUsageQueries(): void {
		$secrets  = SecretsFileTestFactory::create(
			$this->path,
			array(
				'RAN_BOOSTER_GITHUB_TOKEN'          => self::GITHUB_CLASSIC_TOKEN,
				'RAN_BOOSTER_GITHUB_WEBHOOK_SECRET' => self::GITHUB_WEBHOOK,
			),
			ShippedSecretPolicyCatalog::create()
		);
		$database = new CredentialUsageDatabase();

		$this->dispatch(
			array(
				'action'   => 'delete-access-profile',
				'provider' => 'gh',
				'id'       => 'constant',
			),
			$secrets,
			null,
			null,
			null,
			new CredentialUsageReader( $database, 'wp_ran_booster_packages' )
		);
		self::assertInstanceOf( WP_Error::class, $this->messages[0] );
		self::assertArrayHasKey( 'constant', $secrets->credentialProfiles( 'gh' ) );
		self::assertSame( array(), $database->prepared );

		$this->messages = array();
		$this->dispatch(
			array(
				'action'   => 'delete-webhook-profile',
				'provider' => 'gh',
				'id'       => 'constant',
			),
			$secrets
		);
		self::assertInstanceOf( WP_Error::class, $this->messages[0] );
		self::assertArrayHasKey( 'constant', $secrets->webhookProfiles( 'gh' ) );
	}

	public function testWebhookMissingAndUncheckedDeletionCannotReportSuccess(): void {
		$this->dispatch(
			array(
				'action'   => 'delete-webhook-profile',
				'provider' => 'gh',
				'id'       => 'missing_webhook',
			)
		);
		self::assertInstanceOf( WP_Error::class, $this->messages[0] );

		foreach ( array( false, true ) as $pretendDeleted ) {
			$secrets = new class( $this->path, array(), ShippedSecretPolicyCatalog::create(), $pretendDeleted ) extends SecretsFile {
				public function __construct( string $path, array $constants, ProviderSecretPolicyCatalog $policies, private bool $pretendDeleted ) {
					parent::__construct(
						$path,
						$constants,
						$policies,
						SecretsFileTestFactory::keyStore( $path ),
						new EncryptedSecretsEnvelopeCodec()
					);
				}

				public function deleteWebhook( ProviderCode|string $provider, string $id ): bool {
					unset( $provider, $id );

					return $this->pretendDeleted;
				}
			};
			$secrets->saveWebhook(
				'gh',
				'webhook_failure',
				array(
					'label'  => 'Webhook failure',
					'scope'  => 'global',
					'target' => '',
				),
				self::GITHUB_WEBHOOK
			);
			$this->messages = array();

			$this->dispatch(
				array(
					'action'   => 'delete-webhook-profile',
					'provider' => 'gh',
					'id'       => 'webhook_failure',
				),
				$secrets
			);

			self::assertInstanceOf( WP_Error::class, $this->messages[0] );
			self::assertSame( 'Booster could not verify that the Push-to-Deploy secret was removed.', $this->messages[0]->get_error_message() );
			self::assertArrayHasKey( 'webhook_failure', $secrets->webhookProfiles( 'gh' ) );
		}
	}

	/**
	 * @return array<string, array{string, mixed}>
	 */
	public static function invalidCredentialProviders(): array {
		$cases = array();

		foreach ( array( 'save-access-profile', 'validate-access-profile', 'delete-access-profile', 'save-webhook-profile', 'delete-webhook-profile' ) as $action ) {
			foreach (
				array(
					'missing'      => null,
					'non-string'   => array( 'gh' ),
					'invalid-code' => 'GitHub!',
					'unknown-code' => 'unknown-provider',
				) as $label => $provider
			) {
				$cases[ $action . '-' . $label ] = array( $action, $provider );
			}
		}

		return $cases;
	}

	#[DataProvider( 'invalidCredentialProviders' )]
	public function testInvalidCredentialProviderFailsBeforeTheUsageQueryOrSecretStore( string $action, mixed $provider ): void {
		$wpdb    = new class() {
			public int $queries = 0;

			public function prepare( string $query, mixed ...$arguments ): string {
				++$this->queries;

				return $query;
			}

			public function get_var( string $query ): int {
				++$this->queries;

				return 0;
			}
		};
		$hadWpdb = array_key_exists( 'wpdb', $GLOBALS );
		$oldWpdb = $GLOBALS['wpdb'] ?? null;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Focused assertion proves malformed provider input cannot reach a package-usage query.
		$GLOBALS['wpdb'] = $wpdb;
		$request         = array(
			'action' => $action,
			'id'     => 'shared-credential',
		);
		if ( null !== $provider ) {
			$request['provider'] = $provider;
		}

		try {
			$this->dispatch( $request );
		} finally {
			if ( $hadWpdb ) {
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the prior test environment.
				$GLOBALS['wpdb'] = $oldWpdb;
			} else {
				unset( $GLOBALS['wpdb'] );
			}
		}

		self::assertCount( 1, $this->messages );
		self::assertInstanceOf( WP_Error::class, $this->messages[0] );
		self::assertSame( 'Choose a supported repository provider.', $this->messages[0]->get_error_message() );
		self::assertSame( 0, $wpdb->queries );
		self::assertFileDoesNotExist( $this->path );
	}

	public function testUnexpectedCredentialFailureNeverExposesSecretMaterialInDashboardMessages(): void {
		$canary  = 'dispatcher-exception-secret-canary';
		$secrets = new ExplodingCredentialSecretsFile( $this->path, array(), $canary );

		$this->dispatch(
			array(
				'action'        => 'save-access-profile',
				'provider'      => 'gh',
				'id'            => 'will_fail',
				'label'         => 'Failure fixture',
				'kind'          => 'classic',
				'configuration' => array(),
				'secret'        => $canary,
			),
			$secrets
		);

		self::assertCount( 1, $this->messages );
		self::assertInstanceOf( WP_Error::class, $this->messages[0] );
		self::assertSame( 'Booster could not complete the credential request.', $this->messages[0]->get_error_message() );
		self::assertStringNotContainsString( $canary, $this->messages[0]->get_error_message() );
	}

	/**
	 * @param array<string, mixed> $request
	 */
	private function dispatch(
		array $request,
		?SecretsFile $secrets = null,
		?ProviderRegistry $providers = null,
		?Dashboard $dashboard = null,
		?ManagedPackageWebhookAuthorityResolver $webhookAuthorities = null,
		?CredentialUsageReader $credentialUsage = null,
		?InMemoryPublicRepositoryLookupProfileStore $publicLookupProfiles = null,
		?CredentialExpiryObservationStore $expiryObservations = null
	): void {
		$_POST['ran_booster'] = $request;
		$this->dispatcher( $dashboard, $secrets, $providers, $webhookAuthorities, $credentialUsage, $publicLookupProfiles, $expiryObservations )->dispatchPostRequests();
	}

	private function dispatcher(
		?Dashboard $dashboard = null,
		?SecretsFile $secrets = null,
		?ProviderRegistry $providers = null,
		?ManagedPackageWebhookAuthorityResolver $webhookAuthorities = null,
		?CredentialUsageReader $credentialUsage = null,
		?InMemoryPublicRepositoryLookupProfileStore $publicLookupProfiles = null,
		?CredentialExpiryObservationStore $expiryObservations = null
	): Dispatcher {
		$providers = $providers ?? $this->providers;
		$plugins   = $this->createStub( PluginRepository::class );
		$themes    = $this->createStub( ThemeRepository::class );

		return new Dispatcher(
			$dashboard ?? $this->dashboard(),
			$providers,
			$secrets ?? $this->secrets,
			new \RAN\Admin\PackageRepositoryRequestResolver( $providers ),
			$webhookAuthorities ?? $this->emptyWebhookAuthorities(),
			new PackageEditProviderGuard( $plugins, $themes, $providers ),
			null,
			$credentialUsage,
			null,
			$publicLookupProfiles ?? $this->publicLookupProfiles,
			null,
			$expiryObservations ?? $this->expiryObservations
		);
	}

	private function emptyWebhookAuthorities(): ManagedPackageWebhookAuthorityResolver {
		return $this->webhookAuthorities( array() );
	}

	/**
	 * @param list<\RAN\Package> $plugins
	 * @param list<\RAN\Package> $themes
	 */
	private function webhookAuthorities( array $plugins, array $themes = array() ): ManagedPackageWebhookAuthorityResolver {
		return new ManagedPackageWebhookAuthorityResolver(
			new class( $plugins ) extends PluginRepository {
				public function __construct( private readonly array $packages ) {
				}

				public function allDeploymentPlugins(): array {
					return $this->packages;
				}
			},
			new class( $themes ) extends ThemeRepository {
				public function __construct( private readonly array $packages ) {
				}

				public function allDeploymentThemes(): array {
					return $this->packages;
				}
			}
		);
	}

	private function dashboard(): Dashboard {
		$dashboard = $this->createStub( Dashboard::class );
		$dashboard->method( 'addMessage' )->willReturnCallback(
			function ( mixed $message ): void {
				$this->messages[] = $message;
			}
		);
		$dashboard->method( 'addFailureMessage' )->willReturnCallback(
			function ( mixed $message ): void {
				$this->messages[] = $message;
			}
		);

		return $dashboard;
	}
}
