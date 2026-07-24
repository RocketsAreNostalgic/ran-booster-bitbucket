<?php

declare(strict_types=1);

namespace Tests\Admin;

// Direct local filesystem operations are used to exercise the sidecar-backed presenter fixture.
// phpcs:disable WordPress.WP.AlternativeFunctions

use LogicException;
use PHPUnit\Framework\TestCase;
use RAN\Admin\ProviderSettingsPresenter;
use RAN\Deployment\DeploymentPolicy;
use RAN\ManagedRepository;
use RAN\Package;
use RAN\RepositoryProvider\Admin\CredentialFieldMetadata;
use RAN\RepositoryProvider\Admin\CredentialKindMetadata;
use RAN\RepositoryProvider\Admin\ProviderAdminMetadata;
use RAN\RepositoryProvider\Admin\ProviderSetupMetadata;
use RAN\RepositoryProvider\Admin\WebhookScopeMetadata;
use RAN\RepositoryProvider\ArchiveRequest;
use RAN\RepositoryProvider\CredentialedPublicRepositoryBrowser;
use RAN\RepositoryProvider\CredentialValidationResult;
use RAN\RepositoryProvider\CredentialValidator;
use RAN\RepositoryProvider\Bitbucket\BitbucketCredentialPolicy;
use RAN\RepositoryProvider\Bitbucket\BitbucketWebhookPolicy;
use RAN\RepositoryProvider\GitHubCredentialPolicy;
use RAN\RepositoryProvider\GitHubWebhookPolicy;
use RAN\RepositoryProvider\PreparedArchive;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderCredentialPolicy;
use RAN\RepositoryProvider\ProviderCredentialPolicySupplier;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\PublicRepositoryBrowseMetadata;
use RAN\RepositoryProvider\ProviderSecretPolicyCatalog;
use RAN\RepositoryProvider\ProviderWebhookPolicy;
use RAN\RepositoryProvider\RepositoryBrowseRequest;
use RAN\RepositoryProvider\RepositoryBrowser;
use RAN\RepositoryProvider\RepositoryDescriptor;
use RAN\RepositoryProvider\RepositoryLookupRequest;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\RepositoryProvider\RepositoryWebhookSettingsLink;
use RAN\RepositoryProvider\WebhookEnvelope;
use RAN\RepositoryProvider\WebhookNormalizer;
use RAN\RepositoryProvider\WebhookRequest;
use RAN\Secrets\SecretsFile;
use RAN\Storage\CredentialUsageReader;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;
use Tests\Secrets\SecretsFileTestFactory;
use Tests\Support\CredentialUsageDatabase;
use Tests\Support\InMemoryPublicRepositoryLookupProfileStore;

final class ProviderSettingsPresenterTest extends TestCase {

	private const GITHUB_CONSTANT_TOKEN    = 'canary-github-constant-token';
	private const BITBUCKET_CONSTANT_TOKEN = 'canary-bitbucket-constant-token';
	private const WEBHOOK_CONSTANT_SECRET  = 'canary-webhook-constant-secret-0001';
	private const GITHUB_FILE_TOKEN        = 'canary-github-file-token';
	private const BITBUCKET_FILE_TOKEN     = 'canary-bitbucket-file-token';
	private const GITHUB_FILE_WEBHOOK      = 'canary-github-file-webhook-0000001';
	private const BITBUCKET_FILE_WEBHOOK   = 'canary-bitbucket-file-webhook-001';

	private string $directory;
	private string $path;
	private SecretsFile $secrets;
	private ProviderSettingsPresenter $presenter;
	private CredentialUsageDatabase $usageDatabase;
	private InMemoryPublicRepositoryLookupProfileStore $publicLookupProfiles;

	protected function setUp(): void {
		$this->directory = sys_get_temp_dir() . '/ran-booster-provider-presenter-' . bin2hex( random_bytes( 8 ) );
		$this->path      = $this->directory . '/secrets.json';

		self::assertTrue( mkdir( $this->directory, 0700 ) );

		$secretPolicies = new ProviderSecretPolicyCatalog();
		$this->secrets  = SecretsFileTestFactory::create(
			$this->path,
			array(
				'RAN_BOOSTER_GITHUB_TOKEN'             => self::GITHUB_CONSTANT_TOKEN,
				'RAN_BOOSTER_GITHUB_WEBHOOK_SECRET'    => self::WEBHOOK_CONSTANT_SECRET,
				'RAN_BOOSTER_BITBUCKET_WEBHOOK_SECRET' => self::WEBHOOK_CONSTANT_SECRET,
				'RAN_BOOSTER_BITBUCKET_WORKSPACE'      => 'rockets-are-nostalgic',
				'RAN_BOOSTER_BITBUCKET_EMAIL'          => 'deploy@example.test',
				'RAN_BOOSTER_BITBUCKET_TOKEN'          => self::BITBUCKET_CONSTANT_TOKEN,
			),
			$secretPolicies
		);
		$providers      = $this->providerRegistry( $secretPolicies );

		$this->secrets->saveCredential(
			'gh',
			'shared_profile',
			array(
				'label'         => 'GitHub file profile',
				'kind'          => 'fine-grained',
				'configuration' => array( 'owner' => 'RocketsAreNostalgic' ),
			),
			self::GITHUB_FILE_TOKEN
		);
		$this->secrets->saveCredential(
			'bb',
			'shared_profile',
			array(
				'label'         => 'Bitbucket file profile',
				'kind'          => 'api-token',
				'configuration' => array(
					'workspace' => 'protestsandsuffragettes',
					'email'     => 'automation@example.test',
				),
			),
			self::BITBUCKET_FILE_TOKEN
		);
		$this->secrets->saveWebhook(
			'gh',
			'gh_webhook',
			array(
				'label'  => 'GitHub file webhook',
				'scope'  => 'owner',
				'target' => 'RocketsAreNostalgic',
			),
			self::GITHUB_FILE_WEBHOOK
		);
		$this->secrets->saveWebhook(
			'bb',
			'bb_webhook',
			array(
				'label'  => 'Bitbucket file webhook',
				'scope'  => 'workspace',
				'target' => 'protestsandsuffragettes',
			),
			self::BITBUCKET_FILE_WEBHOOK
		);

		$this->usageDatabase        = new CredentialUsageDatabase();
		$this->publicLookupProfiles = new InMemoryPublicRepositoryLookupProfileStore();
		$this->presenter            = new ProviderSettingsPresenter(
			$providers,
			$this->secrets,
			new CredentialUsageReader( $this->usageDatabase, 'wp_ran_booster_packages' ),
			$this->publicLookupProfiles
		);
	}

	protected function tearDown(): void {
		foreach ( array( $this->path, $this->path . '.lock' ) as $path ) {
			if ( is_file( $path ) || is_link( $path ) ) {
				unlink( $path );
			}
		}

		if ( is_dir( $this->directory ) ) {
			rmdir( $this->directory );
		}
	}

	public function testBuildDefaultsToTheFirstProviderAndPreservesRegistryOrder(): void {
		$payload = $this->presenter->build( null );

		self::assertSame( 'gh', $payload['selected_provider'] );
		self::assertSame(
			array(
				array(
					'code'   => 'gh',
					'label'  => 'GitHub',
					'active' => true,
				),
				array(
					'code'   => 'bb',
					'label'  => 'Bitbucket',
					'active' => false,
				),
			),
			$payload['providers']
		);
		self::assertSame( 'gh', $payload['provider']['code'] );
		self::assertSame( 'GitHub', $payload['provider']['label'] );
		self::assertSame( 'Owner', $payload['provider']['owner_label'] );
		self::assertSame(
			array(
				'browse'                                 => true,
				'credentialed_public_browse'             => true,
				'provider_default_public_lookup_profile' => true,
				'archive'                                => true,
				'webhooks'                               => true,
				'package'                                => true,
				'credentials'                            => false,
			),
			$payload['provider']['capabilities']
		);
		self::assertArrayNotHasKey( 'secrets_path', $payload );
		self::assertStringNotContainsString( $this->path, json_encode( $payload, JSON_THROW_ON_ERROR ) );
	}

	public function testBuildSelectsBitbucketAndFallsBackForUnknownProviders(): void {
		$bitbucket = $this->presenter->build( 'bb' );

		self::assertSame( 'bb', $bitbucket['selected_provider'] );
		self::assertSame( array( false, true ), array_column( $bitbucket['providers'], 'active' ) );
		self::assertSame(
			array(
				'browse'                                 => true,
				'credentialed_public_browse'             => true,
				'provider_default_public_lookup_profile' => true,
				'archive'                                => true,
				'webhooks'                               => true,
				'package'                                => true,
				'credentials'                            => true,
			),
			$bitbucket['provider']['capabilities']
		);

		self::assertSame( 'gh', $this->presenter->build( 'unknown' )['selected_provider'] );
	}

	public function testBuildExposesExplicitProviderDefaultsWithoutInferringAProfile(): void {
		$anonymous = $this->presenter->build( 'gh' );

		self::assertSame(
			array(
				'configured_id' => '',
				'stale'         => false,
			),
			$anonymous['public_lookup_profile']
		);
		self::assertSame( array( false, false ), array_column( $anonymous['credential_profiles'], 'public_lookup_default' ) );

		$this->publicLookupProfiles->set( 'gh', 'constant' );
		$immutable = $this->presenter->build( 'gh' );
		self::assertSame( array( true, false ), array_column( $immutable['credential_profiles'], 'public_lookup_default' ) );

		$this->publicLookupProfiles->set( 'gh', 'shared_profile' );
		$configured = $this->presenter->build( 'gh' );

		self::assertSame( 'shared_profile', $configured['public_lookup_profile']['configured_id'] );
		self::assertFalse( $configured['public_lookup_profile']['stale'] );
		self::assertSame( array( false, true ), array_column( $configured['credential_profiles'], 'public_lookup_default' ) );
		$packageGitHub = $this->presenter->buildPackageForm()['providers'][0];
		self::assertSame( 'shared_profile', $packageGitHub['public_lookup']['configured_id'] );
		self::assertSame( 'GitHub file profile', $packageGitHub['public_lookup']['configured_label'] );
		self::assertFalse( $packageGitHub['public_lookup']['stale'] );

		$bitbucket = $this->presenter->build( 'bb' );
		self::assertSame(
			array(
				'configured_id' => '',
				'stale'         => false,
			),
			$bitbucket['public_lookup_profile']
		);
	}

	public function testUnreadableSidecarKeepsProviderAndPackageDisplaysBootableWithoutChangingStorage(): void {
		self::assertNotFalse( file_put_contents( $this->path, 'broken-sidecar-canary' ) );
		self::assertTrue( chmod( $this->path, 0600 ) );
		$bytes = (string) file_get_contents( $this->path );

		$provider = $this->presenter->build( 'gh' );
		$packages = $this->presenter->buildPackageForm();

		self::assertTrue( $provider['secrets_storage_unavailable'] );
		self::assertSame( array(), $provider['credential_profiles'] );
		self::assertSame( array(), $provider['webhook_profiles'] );
		self::assertNull( $provider['public_lookup_profile'] );
		self::assertSame(
			array( array(), array() ),
			array_column( $packages['providers'], 'credential_profiles' )
		);
		self::assertSame( $bytes, file_get_contents( $this->path ) );
	}

	public function testBuildPreservesAVisibleStaleDefaultWithoutAnonymousFallback(): void {
		$this->publicLookupProfiles->set( 'gh', 'missing_profile' );

		$payload = $this->presenter->build( 'gh' );

		self::assertSame( 'missing_profile', $payload['public_lookup_profile']['configured_id'] );
		self::assertTrue( $payload['public_lookup_profile']['stale'] );
		self::assertSame( array( false, false ), array_column( $payload['credential_profiles'], 'public_lookup_default' ) );
	}

	public function testBuildSerializesProviderOwnedCredentialAndWebhookMetadata(): void {
		$github    = $this->presenter->build( 'gh' )['provider'];
		$bitbucket = $this->presenter->build( 'bb' )['provider'];

		self::assertSame( array( 'classic', 'fine-grained' ), array_column( $github['credential_kinds'], 'code' ) );
		self::assertSame( array(), $github['credential_kinds'][0]['fields'] );
		self::assertSame(
			array(
				'key'         => 'owner',
				'label'       => 'Resource owner',
				'type'        => 'text',
				'required'    => true,
				'placeholder' => 'RocketsAreNostalgic',
				'description' => 'GitHub user or organization that can use this token.',
			),
			$github['credential_kinds'][1]['fields'][0]
		);
		self::assertSame( array( 'global', 'owner', 'repository' ), array_column( $github['webhook_scopes'], 'code' ) );
		self::assertSame(
			array(
				'location'                   => 'Repository Settings → Webhooks → Add webhook',
				'event'                      => 'Just the push event',
				'documentation_url'          => 'https://docs.github.com/webhooks',
				'delivery_documentation_url' => 'https://docs.github.com/webhooks/testing',
			),
			$github['webhook_setup']
		);

		self::assertSame( array( 'api-token' ), array_column( $bitbucket['credential_kinds'], 'code' ) );
		self::assertSame( array( 'workspace', 'email' ), array_column( $bitbucket['credential_kinds'][0]['fields'], 'key' ) );
		self::assertSame( array( 'global', 'workspace', 'repository' ), array_column( $bitbucket['webhook_scopes'], 'code' ) );
		self::assertTrue( $bitbucket['webhook_scopes'][1]['requires_target'] );
		self::assertSame( 'Workspace', $bitbucket['webhook_scopes'][1]['target_label'] );
		self::assertSame( 'Repository push', $bitbucket['webhook_setup']['event'] );
	}

	public function testBuildKeepsProfilesIsolatedAndExposesOnlyMutationSafeState(): void {
		$github    = $this->presenter->build( 'gh' );
		$bitbucket = $this->presenter->build( 'bb' );

		self::assertSame( array( 'constant', 'shared_profile' ), array_column( $github['credential_profiles'], 'id' ) );
		self::assertSame( array( 'gh', 'gh' ), array_column( $github['credential_profiles'], 'provider' ) );
		self::assertTrue( $github['credential_profiles'][0]['immutable'] );
		self::assertFalse( $github['credential_profiles'][1]['immutable'] );
		self::assertFalse( $github['credential_profiles'][0]['editable'] );
		self::assertTrue( $github['credential_profiles'][1]['editable'] );
		self::assertSame( 'constant', $github['credential_profiles'][0]['source'] );
		self::assertSame( 'file', $github['credential_profiles'][1]['source'] );

		self::assertSame( array( 'constant', 'shared_profile' ), array_column( $bitbucket['credential_profiles'], 'id' ) );
		self::assertSame( array( 'bb', 'bb' ), array_column( $bitbucket['credential_profiles'], 'provider' ) );
		self::assertTrue( $bitbucket['credential_profiles'][0]['immutable'] );
		self::assertFalse( $bitbucket['credential_profiles'][1]['immutable'] );
		self::assertFalse( $bitbucket['credential_profiles'][0]['editable'] );
		self::assertTrue( $bitbucket['credential_profiles'][1]['editable'] );

		self::assertSame( array( 'constant', 'gh_webhook' ), array_column( $github['webhook_profiles'], 'id' ) );
		self::assertSame( array( 'gh', 'gh' ), array_column( $github['webhook_profiles'], 'provider' ) );
		self::assertSame( array( 'constant', 'bb_webhook' ), array_column( $bitbucket['webhook_profiles'], 'id' ) );
		self::assertSame( array( 'bb', 'bb' ), array_column( $bitbucket['webhook_profiles'], 'provider' ) );
	}

	public function testProviderSettingsExposeExactBoundedUsageWithoutQueryingPackageForms(): void {
		$this->usageDatabase->count = '2';
		$this->usageDatabase->rows  = array(
			(object) array(
				'type'    => '1',
				'package' => 'missing/plugin.php',
			),
			(object) array(
				'type'    => '2',
				'package' => 'missing-theme',
			),
		);

		$profiles = $this->presenter->build( 'gh' )['credential_profiles'];
		$profile  = $profiles[1];

		self::assertTrue( $profile['usage']['available'] );
		self::assertSame( 2, $profile['usage']['total'] );
		self::assertSame( array( 'plugin', 'theme' ), array_column( $profile['usage']['packages'], 'type' ) );
		self::assertSame( array( null, null ), array_column( $profile['usage']['packages'], 'edit_url' ) );
		self::assertNotEmpty( $this->usageDatabase->prepared );

		$this->usageDatabase->prepared = array();
		$this->presenter->buildPackageForm( 'gh' );
		self::assertSame( array(), $this->usageDatabase->prepared );
	}

	public function testProviderSettingsRenderUsageUnavailableInsteadOfInventingZeroOrLeakingErrors(): void {
		$this->usageDatabase->last_error = 'presenter-secret-canary';

		$json = json_encode( $this->presenter->build( 'gh' ), JSON_THROW_ON_ERROR );

		self::assertStringContainsString( '"available":false', $json );
		self::assertStringContainsString( '"total":null', $json );
		self::assertStringNotContainsString( 'presenter-secret-canary', $json );
	}

	public function testBuildListsManagedRepositoryTargetsWithStableIdsAndPolicies(): void {
		$githubPackage = $this->createStub( Package::class );
		$githubPackage->method( 'getProviderCode' )->willReturn( 'gh' );
		$githubPackage->method( 'getProviderRepositoryId' )->willReturn( 'repository-42' );
		$githubPackage->method( 'getRepository' )->willReturn(
			new ManagedRepository( 'gh', 'RocketsAreNostalgic/example', 'repository-42', 'main' )
		);
		$githubPackage->method( 'getDeploymentPolicy' )->willReturn( DeploymentPolicy::AUTOMATIC );

		$bitbucketPackage = $this->createStub( Package::class );
		$bitbucketPackage->method( 'getProviderCode' )->willReturn( 'bb' );
		$bitbucketPackage->method( 'getProviderRepositoryId' )->willReturn( 'bitbucket-81' );
		$bitbucketPackage->method( 'getRepository' )->willReturn(
			new ManagedRepository( 'bb', 'workspace/example', 'bitbucket-81', 'main' )
		);
		$bitbucketPackage->method( 'getDeploymentPolicy' )->willReturn( DeploymentPolicy::MANUAL );

		$unsafeLinkPackage = $this->createStub( Package::class );
		$unsafeLinkPackage->method( 'getProviderCode' )->willReturn( 'gh' );
		$unsafeLinkPackage->method( 'getProviderRepositoryId' )->willReturn( 'repository-unsafe' );
		$unsafeLinkPackage->method( 'getRepository' )->willReturn(
			new ManagedRepository( 'gh', 'unsafe/example', 'repository-unsafe', 'main' )
		);
		$unsafeLinkPackage->method( 'getDeploymentPolicy' )->willReturn( DeploymentPolicy::MANUAL );

		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'allDeploymentPlugins' )->willReturn( array( $githubPackage, $unsafeLinkPackage ) );
		$themes = $this->createStub( ThemeRepository::class );
		$themes->method( 'allDeploymentThemes' )->willReturn( array( $bitbucketPackage ) );

		$presenter = new ProviderSettingsPresenter(
			$this->providerRegistry( new ProviderSecretPolicyCatalog() ),
			$this->secrets,
			new CredentialUsageReader( $this->usageDatabase, 'wp_ran_booster_packages' ),
			$this->publicLookupProfiles,
			null,
			null,
			$plugins,
			$themes
		);

		self::assertSame(
			array(
				'available'    => true,
				'repositories' => array(
					array(
						'target'               => 'RocketsAreNostalgic/example',
						'repository_id'        => 'repository-42',
						'package_count'        => 1,
						'automatic_count'      => 1,
						'webhook_settings_url' => 'https://github.com/RocketsAreNostalgic/example/settings/hooks',
					),
					array(
						'target'               => 'unsafe/example',
						'repository_id'        => 'repository-unsafe',
						'package_count'        => 1,
						'automatic_count'      => 0,
						'webhook_settings_url' => null,
					),
				),
			),
			$presenter->build( 'gh' )['managed_webhook_repositories']
		);

		self::assertSame(
			'https://bitbucket.org/workspace/example/admin/webhooks',
			$presenter->build( 'bb' )['managed_webhook_repositories']['repositories'][0]['webhook_settings_url']
		);
	}

	public function testBuildReflectsCredentialAndWebhookProfilesSavedAfterAnEarlierBuild(): void {
		$before = $this->presenter->build( 'bb' );

		self::assertNotContains( 'same_response_credential', array_column( $before['credential_profiles'], 'id' ) );
		self::assertNotContains( 'same_response_webhook', array_column( $before['webhook_profiles'], 'id' ) );

		$this->secrets->saveCredential(
			'bb',
			'same_response_credential',
			array(
				'label'         => 'Same-response credential',
				'kind'          => 'api-token',
				'configuration' => array(
					'workspace' => 'rocketsarenostalgic',
					'email'     => 'same-response@example.test',
				),
			),
			'same-response-credential-canary'
		);

		$afterCredential = $this->presenter->build( 'bb' );
		$credentialIds   = array_column( $afterCredential['credential_profiles'], 'id' );

		self::assertContains( 'same_response_credential', $credentialIds );
		self::assertSame(
			'Same-response credential',
			$afterCredential['credential_profiles'][ array_search( 'same_response_credential', $credentialIds, true ) ]['label']
		);

		$this->secrets->saveWebhook(
			'bb',
			'same_response_webhook',
			array(
				'label'  => 'Same-response webhook',
				'scope'  => 'workspace',
				'target' => 'rocketsarenostalgic',
			),
			'same-response-webhook-canary-00001'
		);

		$afterWebhook = $this->presenter->build( 'bb' );
		$webhookIds   = array_column( $afterWebhook['webhook_profiles'], 'id' );

		self::assertContains( 'same_response_webhook', $webhookIds );
		self::assertSame(
			'Same-response webhook',
			$afterWebhook['webhook_profiles'][ array_search( 'same_response_webhook', $webhookIds, true ) ]['label']
		);
	}

	public function testBuildNeverIncludesSecretMaterial(): void {
		$json = json_encode(
			array(
				$this->presenter->build( 'gh' ),
				$this->presenter->build( 'bb' ),
			),
			JSON_THROW_ON_ERROR
		);

		self::assertStringNotContainsString( self::GITHUB_CONSTANT_TOKEN, $json );
		self::assertStringNotContainsString( self::BITBUCKET_CONSTANT_TOKEN, $json );
		self::assertStringNotContainsString( self::WEBHOOK_CONSTANT_SECRET, $json );
		self::assertStringNotContainsString( self::GITHUB_FILE_TOKEN, $json );
		self::assertStringNotContainsString( self::BITBUCKET_FILE_TOKEN, $json );
		self::assertStringNotContainsString( self::GITHUB_FILE_WEBHOOK, $json );
		self::assertStringNotContainsString( self::BITBUCKET_FILE_WEBHOOK, $json );
	}

	public function testBuildPackageFormPreservesProviderOrderAndCapabilities(): void {
		$payload = $this->presenter->buildPackageForm();

		self::assertSame( 'gh', $payload['default_provider'] );
		self::assertSame( array( 'gh', 'bb' ), array_column( $payload['providers'], 'code' ) );
		self::assertSame(
			array(
				'code'                                   => 'gh',
				'label'                                  => 'GitHub',
				'owner_label'                            => 'Owner',
				'repository_url_base'                    => 'https://github.com/',
				'available'                              => true,
				'browse'                                 => true,
				'credentialed_public_browse'             => true,
				'provider_default_public_lookup_profile' => true,
				'deploy'                                 => true,
				'webhooks'                               => true,
				'default_credential_id'                  => 'constant',
				'public_lookup'                          => array(
					'supports_default' => true,
					'configured_id'    => '',
					'configured_label' => '',
					'stale'            => false,
				),
			),
			array_diff_key( $payload['providers'][0], array( 'credential_profiles' => true ) )
		);
		self::assertSame(
			array(
				'code'                                   => 'bb',
				'label'                                  => 'Bitbucket',
				'owner_label'                            => 'Workspace',
				'repository_url_base'                    => 'https://bitbucket.org/',
				'available'                              => true,
				'browse'                                 => true,
				'credentialed_public_browse'             => true,
				'provider_default_public_lookup_profile' => true,
				'deploy'                                 => true,
				'webhooks'                               => true,
				'default_credential_id'                  => 'constant',
				'public_lookup'                          => array(
					'supports_default' => true,
					'configured_id'    => '',
					'configured_label' => '',
					'stale'            => false,
				),
			),
			array_diff_key( $payload['providers'][1], array( 'credential_profiles' => true ) )
		);
	}

	public function testBuildPackageFormAcceptsAnAvailableRequestedDefaultProviderAndRejectsUnknownProviders(): void {
		self::assertSame( 'bb', $this->presenter->buildPackageForm( 'bb' )['default_provider'] );
		self::assertSame( 'gh', $this->presenter->buildPackageForm( 'unknown' )['default_provider'] );
	}

	public function testExistingPackageFormRepresentsAnUnavailableStoredProviderWithoutFallback(): void {
		$presenter = new ProviderSettingsPresenter(
			new ProviderRegistry(),
			$this->secrets,
			new CredentialUsageReader( $this->usageDatabase, 'wp_ran_booster_packages' )
		);
		$payload   = $presenter->buildExistingPackageForm( 'temporarily-offline' );

		self::assertSame( 'temporarily-offline', $payload['default_provider'] );
		self::assertCount( 1, $payload['providers'] );
		self::assertSame( 'temporarily-offline', $payload['providers'][0]['code'] );
		self::assertSame( 'temporarily-offline', $payload['providers'][0]['label'] );
		self::assertFalse( $payload['providers'][0]['available'] );
		self::assertFalse( $payload['providers'][0]['browse'] );
		self::assertFalse( $payload['providers'][0]['credentialed_public_browse'] );
		self::assertFalse( $payload['providers'][0]['provider_default_public_lookup_profile'] );
		self::assertFalse( $payload['providers'][0]['deploy'] );
		self::assertFalse( $payload['providers'][0]['webhooks'] );
	}

	public function testRegisteringTheSameStoredProviderRestoresItsPackageControls(): void {
		$payload = $this->presenter->buildExistingPackageForm( 'gh' );
		$github  = $payload['providers'][0];

		self::assertSame( 'gh', $payload['default_provider'] );
		self::assertSame( 'gh', $github['code'] );
		self::assertTrue( $github['available'] );
		self::assertTrue( $github['deploy'] );
		self::assertTrue( $github['browse'] );
		self::assertTrue( $github['webhooks'] );
	}

	public function testBuildPackageFormKeepsSameCredentialIdsProviderScoped(): void {
		$providers = array_column( $this->presenter->buildPackageForm()['providers'], null, 'code' );
		$github    = $providers['gh']['credential_profiles'];
		$bitbucket = $providers['bb']['credential_profiles'];

		self::assertSame( array( 'constant', 'shared_profile' ), array_column( $github, 'id' ) );
		self::assertSame( array( 'constant', 'shared_profile' ), array_column( $bitbucket, 'id' ) );

		self::assertSame( 'GitHub file profile', $github[1]['label'] );
		self::assertSame( 'fine-grained', $github[1]['kind'] );
		self::assertSame( 'Fine-grained token', $github[1]['kind_label'] );
		self::assertSame( 'Resource owner: RocketsAreNostalgic', $github[1]['detail'] );
		self::assertSame( 'file', $github[1]['source'] );

		self::assertSame( 'Bitbucket file profile', $bitbucket[1]['label'] );
		self::assertSame( 'api-token', $bitbucket[1]['kind'] );
		self::assertSame( 'API token', $bitbucket[1]['kind_label'] );
		self::assertSame(
			'Workspace: protestsandsuffragettes · Atlassian account email: automation@example.test',
			$bitbucket[1]['detail']
		);
		self::assertSame( 'file', $bitbucket[1]['source'] );
	}

	public function testBuildPackageFormNeverIncludesSecretMaterial(): void {
		$json = json_encode( $this->presenter->buildPackageForm(), JSON_THROW_ON_ERROR );

		self::assertStringNotContainsString( self::GITHUB_CONSTANT_TOKEN, $json );
		self::assertStringNotContainsString( self::BITBUCKET_CONSTANT_TOKEN, $json );
		self::assertStringNotContainsString( self::WEBHOOK_CONSTANT_SECRET, $json );
		self::assertStringNotContainsString( self::GITHUB_FILE_TOKEN, $json );
		self::assertStringNotContainsString( self::BITBUCKET_FILE_TOKEN, $json );
		self::assertStringNotContainsString( self::GITHUB_FILE_WEBHOOK, $json );
		self::assertStringNotContainsString( self::BITBUCKET_FILE_WEBHOOK, $json );
	}

	public function testBuildPackageListExposesOnlyConfiguredCredentialIdentities(): void {
		$providers = array_column( $this->presenter->buildPackageList(), null, 'code' );

		self::assertSame( 'constant', $providers['gh']['default_credential_id'] );
		self::assertSame( array( 'constant', 'shared_profile' ), array_column( $providers['gh']['credentials'], 'id' ) );
		self::assertSame( array( 'Deployment configuration', 'GitHub file profile' ), array_column( $providers['gh']['credentials'], 'label' ) );
		self::assertSame( array( 'constant', 'file' ), array_column( $providers['gh']['credentials'], 'source' ) );

		$json = json_encode( $providers, JSON_THROW_ON_ERROR );
		self::assertStringNotContainsString( self::GITHUB_CONSTANT_TOKEN, $json );
		self::assertStringNotContainsString( self::GITHUB_FILE_TOKEN, $json );
		self::assertStringNotContainsString( self::BITBUCKET_CONSTANT_TOKEN, $json );
		self::assertStringNotContainsString( self::BITBUCKET_FILE_TOKEN, $json );
	}

	private function providerRegistry( ProviderSecretPolicyCatalog $secretPolicies ): ProviderRegistry {
		return new ProviderRegistry(
			array(
				$this->githubProvider(),
				$this->bitbucketProvider(),
			),
			$secretPolicies
		);
	}

	private function githubProvider(): RepositoryProvider {
		return new class() implements RepositoryProvider, ProviderCredentialPolicySupplier, CredentialedPublicRepositoryBrowser, WebhookNormalizer, RepositoryWebhookSettingsLink {

			use \Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

			public function getMetadata(): ProviderMetadata {
				return new ProviderMetadata(
					ProviderCode::parse( 'gh' ),
					'GitHub',
					'https://github.com/',
					'Owner',
					new ProviderAdminMetadata(
						array(
							new CredentialKindMetadata( 'classic', 'Classic token', 'Personal access token' ),
							new CredentialKindMetadata(
								'fine-grained',
								'Fine-grained token',
								'Personal access token',
								'Leave blank to retain the saved token.',
								array(
									new CredentialFieldMetadata(
										'owner',
										'Resource owner',
										'text',
										true,
										'RocketsAreNostalgic',
										'GitHub user or organization that can use this token.'
									),
								)
							),
						),
						array(
							new WebhookScopeMetadata( 'global', 'All repositories', false ),
							new WebhookScopeMetadata( 'owner', 'Owner', true, 'Owner' ),
							new WebhookScopeMetadata( 'repository', 'Repository', true, 'Repository' ),
						),
						new ProviderSetupMetadata(
							'GitHub credential guidance.',
							array(
								array(
									'label' => 'GitHub credentials',
									'url'   => 'https://docs.github.com/authentication',
								),
							),
							'Repository Settings → Webhooks → Add webhook',
							'Just the push event',
							'https://docs.github.com/webhooks',
							'https://docs.github.com/webhooks/testing'
						)
					)
				);
			}

			public function getPublicRepositoryBrowseMetadata(): PublicRepositoryBrowseMetadata {
				return new PublicRepositoryBrowseMetadata( true );
			}

			public function browseRepositories( RepositoryBrowseRequest $request ): \RAN\RepositoryProvider\RepositoryBrowseResult {
				return new \RAN\RepositoryProvider\RepositoryBrowseResult( array() );
			}

			public function getCredentialPolicy(): ProviderCredentialPolicy {
				return new GitHubCredentialPolicy();
			}

			public function getWebhookPolicy(): ProviderWebhookPolicy {
				return new GitHubWebhookPolicy();
			}

			public function diagnoseWebhookReadiness(): \RAN\RepositoryProvider\ProviderDiagnosticResult {
				return new \RAN\RepositoryProvider\ProviderDiagnosticResult(
					\RAN\RepositoryProvider\ProviderDiagnosticResult::WARNING,
					'test.webhook.delivery_unverified',
					'Test webhook delivery is not verified.',
					'Use a provider test delivery.'
				);
			}

			public function resolveRepository( RepositoryLookupRequest $request ): RepositoryDescriptor {
				throw new LogicException( 'Not exercised by presenter tests.' );
			}

			public function prepareArchive( ArchiveRequest $request ): PreparedArchive {
				throw new LogicException( 'Not exercised by presenter tests.' );
			}

			public function normalizeWebhook( WebhookRequest $request ): WebhookEnvelope {
				throw new LogicException( 'Not exercised by presenter tests.' );
			}

			public function repositoryWebhookSettingsUrl( string $locator ): string {
				if ( 'unsafe/example' === $locator ) {
					return 'https://github.com/unsafe/example/settings/hooks?secret=canary';
				}

				return 'https://github.com/' . $locator . '/settings/hooks';
			}
		};
	}

	private function bitbucketProvider(): RepositoryProvider {
		return new class() implements RepositoryProvider, ProviderCredentialPolicySupplier, CredentialValidator, CredentialedPublicRepositoryBrowser, WebhookNormalizer, RepositoryWebhookSettingsLink {

			use \Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

			public function getMetadata(): ProviderMetadata {
				return new ProviderMetadata(
					ProviderCode::parse( 'bb' ),
					'Bitbucket',
					'https://bitbucket.org/',
					'Workspace',
					new ProviderAdminMetadata(
						array(
							new CredentialKindMetadata(
								'api-token',
								'API token',
								'API token',
								'Leave blank to retain the saved token.',
								array(
									new CredentialFieldMetadata( 'workspace', 'Workspace', 'text', true ),
									new CredentialFieldMetadata( 'email', 'Atlassian account email', 'email', true ),
								)
							),
						),
						array(
							new WebhookScopeMetadata( 'global', 'All repositories', false ),
							new WebhookScopeMetadata( 'workspace', 'Workspace', true, 'Workspace' ),
							new WebhookScopeMetadata( 'repository', 'Repository', true, 'Repository' ),
						),
						new ProviderSetupMetadata(
							'Bitbucket credential guidance.',
							array(
								array(
									'label' => 'Bitbucket credentials',
									'url'   => 'https://support.atlassian.com/bitbucket-cloud/docs',
								),
							),
							'Repository settings → Webhooks → Add webhook',
							'Repository push',
							'https://support.atlassian.com/bitbucket-cloud/docs/manage-webhooks/',
							'https://support.atlassian.com/bitbucket-cloud/docs/troubleshoot-webhooks/'
						)
					)
				);
			}

			public function validateCredential( string $credentialId ): CredentialValidationResult {
				return CredentialValidationResult::valid();
			}

			public function getCredentialPolicy(): ProviderCredentialPolicy {
				return new BitbucketCredentialPolicy();
			}

			public function getWebhookPolicy(): ProviderWebhookPolicy {
				return new BitbucketWebhookPolicy();
			}

			public function diagnoseWebhookReadiness(): \RAN\RepositoryProvider\ProviderDiagnosticResult {
				return new \RAN\RepositoryProvider\ProviderDiagnosticResult(
					\RAN\RepositoryProvider\ProviderDiagnosticResult::WARNING,
					'test.webhook.delivery_unverified',
					'Test webhook delivery is not verified.',
					'Use a provider test delivery.'
				);
			}

			public function browseRepositories( RepositoryBrowseRequest $request ): \RAN\RepositoryProvider\RepositoryBrowseResult {
				return new \RAN\RepositoryProvider\RepositoryBrowseResult( array() );
			}

			public function getPublicRepositoryBrowseMetadata(): PublicRepositoryBrowseMetadata {
				return new PublicRepositoryBrowseMetadata( true );
			}

			public function resolveRepository( RepositoryLookupRequest $request ): RepositoryDescriptor {
				throw new LogicException( 'Not exercised by presenter tests.' );
			}

			public function prepareArchive( ArchiveRequest $request ): PreparedArchive {
				throw new LogicException( 'Not exercised by presenter tests.' );
			}

			public function normalizeWebhook( WebhookRequest $request ): WebhookEnvelope {
				throw new LogicException( 'Not exercised by presenter tests.' );
			}

			public function repositoryWebhookSettingsUrl( string $locator ): string {
				return 'https://bitbucket.org/' . $locator . '/admin/webhooks';
			}
		};
	}
}
