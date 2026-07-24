<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RAN\GitHub\RepositoryBrowser as GitHubRepositoryBrowser;
use RAN\RepositoryProvider\Admin\CredentialFieldMetadata;
use RAN\RepositoryProvider\Admin\CredentialKindMetadata;
use RAN\RepositoryProvider\Admin\ProviderAdminMetadata;
use RAN\RepositoryProvider\Admin\ProviderSetupMetadata;
use RAN\RepositoryProvider\Admin\WebhookScopeMetadata;
use RAN\RepositoryProvider\Bitbucket\BitbucketArchivePreparer;
use RAN\RepositoryProvider\Bitbucket\BitbucketProvider;
use RAN\RepositoryProvider\Bitbucket\BitbucketApiClient;
use RAN\RepositoryProvider\Bitbucket\BitbucketCredentialLoader;
use RAN\RepositoryProvider\Bitbucket\BitbucketCredentialValidator;
use RAN\RepositoryProvider\Bitbucket\BitbucketRepositoryBrowser;
use RAN\RepositoryProvider\Bitbucket\BitbucketWebhookNormalizer;
use RAN\RepositoryProvider\CredentialValidator;
use RAN\RepositoryProvider\GitHubProvider;
use RAN\RepositoryProvider\GitHubWebhookNormalizer;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\RepositoryBrowser;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\RepositoryProvider\RepositoryWebhookSettingsLink;
use RAN\RepositoryProvider\WebhookNormalizer;
use RAN\Secrets\SecretsFile;

final class ProviderAdminMetadataTest extends TestCase {

	public function testProviderMetadataKeepsAdminDataOutOfItsIdentityArray(): void {
		$admin    = new ProviderAdminMetadata( array(), array() );
		$metadata = new ProviderMetadata(
			ProviderCode::parse( 'gh' ),
			'GitHub',
			'https://github.com/',
			'Owner',
			$admin
		);

		self::assertSame( $admin, $metadata->admin );
		self::assertSame(
			array(
				'code'                => 'gh',
				'label'               => 'GitHub',
				'repository_url_base' => 'https://github.com/',
				'owner_label'         => 'Owner',
			),
			$metadata->toArray()
		);
	}

	public function testProviderMetadataAdminDataIsOptional(): void {
		$metadata = new ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub', 'https://github.com/', 'Owner' );

		self::assertNull( $metadata->admin );
	}

	public function testGitHubAdvertisesClassicAndFineGrainedAdminConfiguration(): void {
		$provider = $this->githubProvider();
		$admin    = $provider->getMetadata()->admin;

		self::assertInstanceOf( RepositoryWebhookSettingsLink::class, $provider );
		self::assertSame(
			'https://github.com/RocketsAreNostalgic/ran-booster/settings/hooks',
			$provider->repositoryWebhookSettingsUrl( 'RocketsAreNostalgic/ran-booster' )
		);
		self::assertNotNull( $admin );
		self::assertSame( array( 'classic', 'fine-grained' ), $this->credentialKindCodes( $admin ) );
		self::assertSame( array(), $admin->getCredentialKind( 'classic' )->fields );

		$fineGrainedFields = $admin->getCredentialKind( 'fine-grained' )->fields;

		self::assertCount( 1, $fineGrainedFields );
		self::assertSame( 'owner', $fineGrainedFields[0]->key );
		self::assertSame( 'text', $fineGrainedFields[0]->type );
		self::assertTrue( $fineGrainedFields[0]->required );
		self::assertSame( array( 'global', 'owner', 'repository' ), $this->webhookScopeCodes( $admin ) );
		self::assertFalse( $admin->getWebhookScope( 'global' )->requiresTarget );
		self::assertTrue( $admin->getWebhookScope( 'owner' )->requiresTarget );

		$setup = $admin->setup;

		self::assertNotNull( $setup );
		self::assertStringContainsString( 'Contents to Read-only', $setup->credentialSummary );
		self::assertStringContainsString( 'fine-grained', $setup->credentialSummary );
		self::assertStringContainsString( 'classic personal access token needs the repo scope and no other scope', $setup->credentialSummary );
		self::assertStringContainsString( 'admin:repo_hook', $setup->credentialSummary );
		self::assertStringContainsString( 'organisation approval', $setup->credentialSummary );
		self::assertSame( 'Repository Settings → Webhooks → Add webhook', $setup->webhookLocation );
		self::assertSame( 'Just the push event', $setup->webhookEvent );
		self::assertSame(
			array(
				'https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/managing-your-personal-access-tokens',
				'https://docs.github.com/en/rest/authentication/permissions-required-for-fine-grained-personal-access-tokens',
				'https://docs.github.com/en/organizations/managing-programmatic-access-to-your-organization/setting-a-personal-access-token-policy-for-your-organization',
			),
			array_column( $setup->credentialLinks, 'url' )
		);
		self::assertStringStartsWith( 'https://docs.github.com/', $setup->webhookDocumentationUrl );
		self::assertStringStartsWith( 'https://docs.github.com/', $setup->deliveryDocumentationUrl );
	}

	public function testBitbucketAdvertisesApiTokenConfigurationAndRepositoryDiscovery(): void {
		$secrets  = new SecretsFile( sys_get_temp_dir() . '/ran-booster-missing-secrets.php', array() );
		$store    = new BitbucketProviderCredentialStore( $secrets );
		$loader   = new BitbucketCredentialLoader( $store );
		$api      = new BitbucketApiClient();
		$provider = new BitbucketProvider(
			new BitbucketCredentialValidator( $loader, $api ),
			new BitbucketRepositoryBrowser( $loader, $api ),
			new BitbucketArchivePreparer( $loader, $api ),
			new BitbucketWebhookNormalizer( $store )
		);
		$admin    = $provider->getMetadata()->admin;

		self::assertInstanceOf( RepositoryProvider::class, $provider );
		self::assertInstanceOf( RepositoryBrowser::class, $provider );
		self::assertInstanceOf( WebhookNormalizer::class, $provider );
		self::assertInstanceOf( CredentialValidator::class, $provider );
		self::assertInstanceOf( RepositoryWebhookSettingsLink::class, $provider );
		self::assertSame(
			'https://bitbucket.org/rocketsarenostalgic/ran-booster/admin/webhooks',
			$provider->repositoryWebhookSettingsUrl( 'rocketsarenostalgic/ran-booster' )
		);
		self::assertTrue( $provider->getMetadata()->code->equals( 'bb' ) );
		self::assertNotNull( $admin );
		self::assertSame( array( 'api-token' ), $this->credentialKindCodes( $admin ) );

		$fields = $admin->getCredentialKind( 'api-token' )->fields;

		self::assertSame( array( 'workspace', 'email' ), array_map( static fn ( CredentialFieldMetadata $field ): string => $field->key, $fields ) );
		self::assertSame( 'email', $fields[1]->type );
		self::assertSame( array( 'global', 'workspace', 'repository' ), $this->webhookScopeCodes( $admin ) );

		$setup = $admin->setup;

		self::assertNotNull( $setup );
		self::assertStringContainsString( 'read:repository:bitbucket', $setup->credentialSummary );
		self::assertStringContainsString( 'default identity for public repository lookup across workspaces', $setup->credentialSummary );
		self::assertStringContainsString( 'continues to enforce that workspace boundary', $setup->credentialSummary );
		self::assertStringContainsString( 'Do not add Webhooks', $setup->credentialSummary );
		self::assertStringContainsString( 'workspace, project or repository access tokens are not supported', $setup->credentialSummary );
		self::assertStringContainsString( 'Atlassian account email', $setup->credentialSummary );
		self::assertStringContainsString( 'App passwords and workspace, project or repository access tokens are not supported', $setup->credentialSummary );
		self::assertSame( 'Repository settings → Webhooks → Add webhook', $setup->webhookLocation );
		self::assertSame( 'Repository push', $setup->webhookEvent );
		self::assertSame(
			array(
				'https://support.atlassian.com/bitbucket-cloud/docs/create-an-api-token/',
				'https://support.atlassian.com/bitbucket-cloud/docs/api-token-permissions/',
				'https://support.atlassian.com/bitbucket-cloud/docs/using-api-tokens/',
			),
			array_column( $setup->credentialLinks, 'url' )
		);
		self::assertStringStartsWith( 'https://support.atlassian.com/', $setup->webhookDocumentationUrl );
		self::assertSame(
			'https://support.atlassian.com/bitbucket-cloud/docs/troubleshoot-webhooks/',
			$setup->deliveryDocumentationUrl
		);
	}

	public function testProviderSetupRejectsUnlabelledOrInsecureGuidanceLinks(): void {
		$this->expectException( InvalidArgumentException::class );

		new ProviderSetupMetadata(
			'Credential summary',
			array(
				array(
					'label' => 'Credential help',
					'url'   => 'http://example.test/help',
				),
			),
			'Repository settings',
			'Push',
			'https://example.test/webhooks',
			'https://example.test/deliveries'
		);
	}

	public function testProviderSetupAllowsPublicHttpsDocumentationQueriesAndFragments(): void {
		$setup = new ProviderSetupMetadata(
			'Credential summary',
			array(
				array(
					'label' => 'Credential help',
					'url'   => 'https://example.test/help?provider=fixture#token',
				),
			),
			'Repository settings',
			'Push',
			'https://example.test/webhooks?provider=fixture#setup',
			'https://example.test/deliveries?provider=fixture#testing'
		);

		self::assertSame( 'https://example.test/help?provider=fixture#token', $setup->credentialLinks[0]['url'] );
		self::assertSame( 'https://example.test/webhooks?provider=fixture#setup', $setup->webhookDocumentationUrl );
		self::assertSame( 'https://example.test/deliveries?provider=fixture#testing', $setup->deliveryDocumentationUrl );
	}

	public function testDisplayMetadataRejectsUnsafeTextAtConstruction(): void {
		$invalidValues = array(
			static fn (): ProviderMetadata => new ProviderMetadata( ProviderCode::parse( 'gh' ), "GitHub\nunsafe", 'https://github.com/', 'Owner' ),
			static fn (): ProviderMetadata => new ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub', 'https://github.com/', str_repeat( 'o', 161 ) ),
			static fn (): CredentialFieldMetadata => new CredentialFieldMetadata( 'owner', "Owner\tname" ),
			static fn (): CredentialFieldMetadata => new CredentialFieldMetadata( 'owner', 'Owner', 'text', false, str_repeat( 'p', 501 ) ),
			static fn (): CredentialFieldMetadata => new CredentialFieldMetadata( 'owner', 'Owner', 'text', false, '', "Description\nunsafe" ),
			static fn (): CredentialKindMetadata => new CredentialKindMetadata( 'token', str_repeat( 'k', 161 ), 'Token' ),
			static fn (): CredentialKindMetadata => new CredentialKindMetadata( 'token', 'Token', "Secret\rlabel" ),
			static fn (): CredentialKindMetadata => new CredentialKindMetadata( 'token', 'Token', 'Secret', str_repeat( 'p', 501 ) ),
			static fn (): WebhookScopeMetadata => new WebhookScopeMetadata( 'global', "Global\nunsafe", false ),
			static fn (): WebhookScopeMetadata => new WebhookScopeMetadata( 'owner', 'Owner', true, 'Owner', '', str_repeat( 'd', 501 ) ),
			static fn (): ProviderSetupMetadata => new ProviderSetupMetadata(
				str_repeat( 's', 2001 ),
				array(
					array(
						'label' => 'Help',
						'url'   => 'https://example.test/help',
					),
				),
				'Repository settings',
				'Push',
				'https://example.test/webhooks',
				'https://example.test/deliveries'
			),
			static fn (): ProviderSetupMetadata => new ProviderSetupMetadata(
				'Credential summary',
				array(
					array(
						'label' => "Help\nunsafe",
						'url'   => 'https://example.test/help',
					),
				),
				'Repository settings',
				'Push',
				'https://example.test/webhooks',
				'https://example.test/deliveries'
			),
			static fn (): ProviderSetupMetadata => new ProviderSetupMetadata(
				'Credential summary',
				array(
					array(
						'label' => 'Help',
						'url'   => 'https://example.test/help',
					),
				),
				"Repository\nsettings",
				'Push',
				'https://example.test/webhooks',
				'https://example.test/deliveries'
			),
		);

		foreach ( $invalidValues as $factory ) {
			try {
				$factory();
				self::fail( 'Unsafe provider display metadata was accepted.' );
			} catch ( InvalidArgumentException $exception ) {
				self::assertNotSame( '', $exception->getMessage() );
			}
		}
	}

	public function testAdminMetadataRejectsOversizedIdentifiersWithFixedErrors(): void {
		$canary     = 'malicious-provider-metadata-canary';
		$identifier = str_repeat( 'a', 65 ) . $canary;
		$factories  = array(
			static fn (): CredentialFieldMetadata => new CredentialFieldMetadata( $identifier, 'Owner' ),
			static fn (): CredentialKindMetadata => new CredentialKindMetadata( $identifier, 'Token', 'Secret' ),
			static fn (): WebhookScopeMetadata => new WebhookScopeMetadata( $identifier, 'Global', false ),
		);

		foreach ( $factories as $factory ) {
			$exception = $this->captureInvalidMetadata( $factory );

			self::assertSame( 'Provider admin identifiers must be bounded lowercase identifiers.', $exception->getMessage() );
			self::assertStringNotContainsString( $canary, $exception->getMessage() );
		}
	}

	public function testAdminMetadataRejectsRawEdgeControlsWithoutDisclosingValues(): void {
		$canary = 'malicious-provider-metadata-canary';

		$textException = $this->captureInvalidMetadata(
			static fn (): CredentialFieldMetadata => new CredentialFieldMetadata( 'owner', "\n{$canary}" )
		);
		self::assertSame( 'Provider admin text must be bounded, non-empty single-line text.', $textException->getMessage() );
		self::assertStringNotContainsString( $canary, $textException->getMessage() );

		$optionalException = $this->captureInvalidMetadata(
			static fn (): CredentialFieldMetadata => new CredentialFieldMetadata( 'owner', 'Owner', 'text', false, "{$canary}\t" )
		);
		self::assertSame( 'Provider admin text must be bounded single-line text.', $optionalException->getMessage() );
		self::assertStringNotContainsString( $canary, $optionalException->getMessage() );

		$urlException = $this->captureInvalidMetadata(
			static fn (): ProviderSetupMetadata => new ProviderSetupMetadata(
				'Credential summary',
				array(
					array(
						'label' => 'Help',
						'url'   => "\thttps://example.test/{$canary}",
					),
				),
				'Repository settings',
				'Push',
				'https://example.test/webhooks',
				'https://example.test/deliveries'
			)
		);
		self::assertSame( 'Provider documentation URLs must be bounded public HTTPS URLs without credentials.', $urlException->getMessage() );
		self::assertStringNotContainsString( $canary, $urlException->getMessage() );

		$repositoryException = $this->captureInvalidMetadata(
			static fn (): ProviderMetadata => new ProviderMetadata(
				ProviderCode::parse( 'gh' ),
				'GitHub',
				"https://example.test/{$canary}\n",
				'Owner'
			)
		);
		self::assertSame( 'Repository provider must have an HTTPS repository URL base.', $repositoryException->getMessage() );
		self::assertStringNotContainsString( $canary, $repositoryException->getMessage() );
	}

	public function testDuplicateMetadataCodesAreRejected(): void {
		$this->expectException( InvalidArgumentException::class );

		new ProviderAdminMetadata(
			array(
				new CredentialKindMetadata( 'token', 'Token', 'Secret' ),
				new CredentialKindMetadata( 'token', 'Duplicate', 'Secret' ),
			),
			array()
		);
	}

	public function testWebhookTargetsAreRequiredWhenDeclared(): void {
		$this->expectException( InvalidArgumentException::class );

		new WebhookScopeMetadata( 'owner', 'Owner', true );
	}

	/**
	 * @return list<string>
	 */
	private function credentialKindCodes( ProviderAdminMetadata $admin ): array {
		return array_map(
			static fn ( CredentialKindMetadata $kind ): string => $kind->code,
			$admin->credentialKinds
		);
	}

	private function githubProvider(): GitHubProvider {
		$secrets = new SecretsFile( null, array() );

		return new GitHubProvider( $secrets, new GitHubRepositoryBrowser( $secrets ), new GitHubWebhookNormalizer( $secrets ) );
	}

	private function captureInvalidMetadata( callable $factory ): InvalidArgumentException {
		try {
			$factory();
			self::fail( 'Unsafe provider metadata was accepted.' );
		} catch ( InvalidArgumentException $exception ) {
			return $exception;
		}
	}

	/**
	 * @return list<string>
	 */
	private function webhookScopeCodes( ProviderAdminMetadata $admin ): array {
		return array_map(
			static fn ( WebhookScopeMetadata $scope ): string => $scope->code,
			$admin->webhookScopes
		);
	}
}
