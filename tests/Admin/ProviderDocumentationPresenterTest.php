<?php

declare(strict_types=1);

namespace Tests\Admin;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use RAN\Admin\ProviderDocumentationPresenter;
use RAN\GitHub\RepositoryBrowser as GitHubRepositoryBrowser;
use RAN\RepositoryProvider\Admin\ProviderAdminMetadata;
use RAN\RepositoryProvider\Admin\ProviderSetupMetadata;
use RAN\RepositoryProvider\ArchiveRequest;
use RAN\RepositoryProvider\Bitbucket\BitbucketApiClient;
use RAN\RepositoryProvider\Bitbucket\BitbucketArchivePreparer;
use RAN\RepositoryProvider\Bitbucket\BitbucketCredentialLoader;
use RAN\RepositoryProvider\Bitbucket\BitbucketCredentialValidator;
use RAN\RepositoryProvider\Bitbucket\BitbucketProvider;
use RAN\RepositoryProvider\Bitbucket\BitbucketRepositoryBrowser;
use RAN\RepositoryProvider\Bitbucket\BitbucketWebhookNormalizer;
use RAN\RepositoryProvider\CredentialValidationResult;
use RAN\RepositoryProvider\CredentialValidator;
use RAN\RepositoryProvider\GitHubProvider;
use RAN\RepositoryProvider\GitHubWebhookNormalizer;
use RAN\RepositoryProvider\GitHubCredentialPolicy;
use RAN\RepositoryProvider\GitHubWebhookPolicy;
use RAN\RepositoryProvider\PreparedArchive;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\ProviderCredentialPolicy;
use RAN\RepositoryProvider\ProviderCredentialPolicySupplier;
use RAN\RepositoryProvider\ProviderWebhookPolicy;
use RAN\RepositoryProvider\RepositoryBrowseRequest;
use RAN\RepositoryProvider\RepositoryBrowser;
use RAN\RepositoryProvider\RepositoryDescriptor;
use RAN\RepositoryProvider\RepositoryLookupRequest;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\RepositoryProvider\WebhookEnvelope;
use RAN\RepositoryProvider\WebhookNormalizer;
use RAN\RepositoryProvider\WebhookRequest;
use RAN\Secrets\SecretsFile;

final class ProviderDocumentationPresenterTest extends TestCase {

	public function testBuildSerializesCompleteGuidanceFromShippedProvidersInRegistryOrder(): void {
		$presenter     = new ProviderDocumentationPresenter( $this->shippedProviderRegistry() );
		$documentation = $presenter->build();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- The presenter contract remains testable without WordPress runtime.
		$serialized = json_encode( $documentation, JSON_THROW_ON_ERROR );

		self::assertSame( array( 'gh', 'bb' ), array_column( $documentation, 'code' ) );
		self::assertSame( array( 'GitHub', 'Bitbucket' ), array_column( $documentation, 'label' ) );
		self::assertSame( array( true, true ), array_column( $documentation, 'setup_available' ) );
		self::assertSame(
			array(
				array(
					'label' => 'Create and manage GitHub personal access tokens',
					'url'   => 'https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/managing-your-personal-access-tokens',
				),
				array(
					'label' => 'Required fine-grained token permissions',
					'url'   => 'https://docs.github.com/en/rest/authentication/permissions-required-for-fine-grained-personal-access-tokens',
				),
				array(
					'label' => 'Organisation token policies and approval',
					'url'   => 'https://docs.github.com/en/organizations/managing-programmatic-access-to-your-organization/setting-a-personal-access-token-policy-for-your-organization',
				),
			),
			$documentation[0]['credentials']['links']
		);
		self::assertSame(
			array(
				'location'                   => 'Repository Settings → Webhooks → Add webhook',
				'event'                      => 'Just the push event',
				'documentation_url'          => 'https://docs.github.com/en/webhooks/using-webhooks/creating-webhooks#creating-a-repository-webhook',
				'delivery_documentation_url' => 'https://docs.github.com/en/webhooks/testing-and-troubleshooting-webhooks/viewing-webhook-deliveries',
			),
			$documentation[0]['webhook']
		);
		self::assertSame(
			array(
				array(
					'label' => 'Create a Bitbucket Cloud API token',
					'url'   => 'https://support.atlassian.com/bitbucket-cloud/docs/create-an-api-token/',
				),
				array(
					'label' => 'Bitbucket API token permissions',
					'url'   => 'https://support.atlassian.com/bitbucket-cloud/docs/api-token-permissions/',
				),
				array(
					'label' => 'Use API tokens with Bitbucket Cloud',
					'url'   => 'https://support.atlassian.com/bitbucket-cloud/docs/using-api-tokens/',
				),
			),
			$documentation[1]['credentials']['links']
		);
		self::assertSame(
			array(
				'location'                   => 'Repository settings → Webhooks → Add webhook',
				'event'                      => 'Repository push',
				'documentation_url'          => 'https://support.atlassian.com/bitbucket-cloud/docs/manage-webhooks/',
				'delivery_documentation_url' => 'https://support.atlassian.com/bitbucket-cloud/docs/troubleshoot-webhooks/',
			),
			$documentation[1]['webhook']
		);
		self::assertStringContainsString( 'Contents to Read-only', $documentation[0]['credentials']['summary'] );
		self::assertStringContainsString( 'read:repository:bitbucket', $documentation[1]['credentials']['summary'] );
		self::assertStringContainsString( 'admin:repo_hook', $documentation[0]['credentials']['summary'] );
		self::assertStringContainsString( 'Do not add Webhooks', $documentation[1]['credentials']['summary'] );
		self::assertStringNotContainsString( 'canary-github-secret', $serialized );
		self::assertStringNotContainsString( 'canary-bitbucket-secret', $serialized );
		self::assertStringNotContainsString( 'canary-webhook-secret', $serialized );
		self::assertStringNotContainsString( 'missing-provider-documentation-secrets.php', $serialized );
	}

	public function testBuildProvidesSafeFallbacksForMissingAdminAndSetupMetadata(): void {
		$metadataOnly = new ProviderMetadata(
			ProviderCode::parse( 'gh' ),
			'GitHub',
			'https://github.com/',
			'Owner'
		);
		$missingSetup = new ProviderMetadata(
			ProviderCode::parse( 'bb' ),
			'Bitbucket',
			'https://bitbucket.org/',
			'Workspace',
			new ProviderAdminMetadata( array(), array() )
		);
		$presenter    = new ProviderDocumentationPresenter(
			new ProviderRegistry(
				array(
					$this->provider( $metadataOnly ),
					$this->provider( $missingSetup ),
				)
			)
		);

		self::assertSame(
			array(
				array(
					'code'            => 'gh',
					'label'           => 'GitHub',
					'setup_available' => false,
					'credentials'     => array(
						'summary' => 'GitHub setup guidance is not available yet.',
						'links'   => array(),
					),
					'webhook'         => null,
				),
				array(
					'code'            => 'bb',
					'label'           => 'Bitbucket',
					'setup_available' => false,
					'credentials'     => array(
						'summary' => 'Bitbucket setup guidance is not available yet.',
						'links'   => array(),
					),
					'webhook'         => null,
				),
			),
			$presenter->build()
		);
	}

	public function testBuildCallsOnlyMetadataOnProvidersWithOperationalCapabilities(): void {
		$provider  = new readonly class( $this->githubMetadata() ) implements RepositoryProvider, ProviderCredentialPolicySupplier, CredentialValidator, RepositoryBrowser, WebhookNormalizer {

			use \Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

			public function __construct( private ProviderMetadata $metadata ) {
			}

			public function getMetadata(): ProviderMetadata {
				return $this->metadata;
			}

			public function validateCredential( string $credentialId ): CredentialValidationResult {
				throw new LogicException( 'Credential validation must not run while presenting documentation.' );
			}

			public function getCredentialPolicy(): ProviderCredentialPolicy {
				return new GitHubCredentialPolicy();
			}

			public function getWebhookPolicy(): ProviderWebhookPolicy {
				return new GitHubWebhookPolicy();
			}

			public function diagnoseWebhookReadiness(): \RAN\RepositoryProvider\ProviderDiagnosticResult {
				throw new LogicException( 'Webhook readiness must not run while presenting documentation.' );
			}

			public function browseRepositories( RepositoryBrowseRequest $request ): \RAN\RepositoryProvider\RepositoryBrowseResult {
				throw new LogicException( 'Repository browsing must not run while presenting documentation.' );
			}

			public function resolveRepository( RepositoryLookupRequest $request ): RepositoryDescriptor {
				throw new LogicException( 'Repository resolution must not run while presenting documentation.' );
			}

			public function prepareArchive( ArchiveRequest $request ): PreparedArchive {
				throw new LogicException( 'Archive preparation must not run while presenting documentation.' );
			}

			public function normalizeWebhook( WebhookRequest $request ): WebhookEnvelope {
				throw new LogicException( 'Webhook normalization must not run while presenting documentation.' );
			}

			public function credentialProfiles(): never {
				throw new LogicException( 'Credential profiles must not load while presenting documentation.' );
			}

			public function webhookProfiles(): never {
				throw new LogicException( 'Webhook profiles must not load while presenting documentation.' );
			}
		};
		$presenter = new ProviderDocumentationPresenter( new ProviderRegistry( array( $provider ) ) );

		self::assertSame( 'gh', $presenter->build()[0]['code'] );
	}

	public function testDocumentationRejectsCredentialBearingUrlsFromDisplayMetadata(): void {
		$this->expectException( InvalidArgumentException::class );

		new ProviderSetupMetadata(
			'Credential guidance.',
			array(
				array(
					'label' => 'Unsafe guidance',
					'url'   => 'https://user:password@example.test/guidance',
				),
			),
			'Repository settings',
			'Push event',
			'https://example.test/webhooks',
			'https://example.test/deliveries'
		);
	}

	public function testDocumentationRejectsControlCharactersFromDisplayMetadata(): void {
		$this->expectException( InvalidArgumentException::class );

		new ProviderSetupMetadata(
			"Credential guidance.\nUnexpected detail.",
			array(
				array(
					'label' => 'Credential guidance',
					'url'   => 'https://example.test/guidance',
				),
			),
			'Repository settings',
			'Push event',
			'https://example.test/webhooks',
			'https://example.test/deliveries'
		);
	}

	private function githubMetadata(): ProviderMetadata {
		return new ProviderMetadata(
			ProviderCode::parse( 'gh' ),
			'GitHub',
			'https://github.com/',
			'Owner',
			new ProviderAdminMetadata(
				array(),
				array(),
				new ProviderSetupMetadata(
					'Public repositories need no token. Private repositories need Contents: Read.',
					array(
						array(
							'label' => 'GitHub personal access token guidance',
							'url'   => 'https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/managing-your-personal-access-tokens',
						),
					),
					'Repository Settings → Webhooks → Add webhook',
					'Just the push event',
					'https://docs.github.com/en/webhooks/using-webhooks/creating-webhooks',
					'https://docs.github.com/en/webhooks/testing-and-troubleshooting-webhooks/viewing-webhook-deliveries'
				)
			)
		);
	}

	private function provider( ProviderMetadata $metadata ): RepositoryProvider {
		return new readonly class( $metadata ) implements RepositoryProvider {

			use \Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

			public function __construct( private ProviderMetadata $metadata ) {
			}

			public function getMetadata(): ProviderMetadata {
				return $this->metadata;
			}
		};
	}

	private function shippedProviderRegistry(): ProviderRegistry {
		$secrets         = new SecretsFile(
			__DIR__ . '/missing-provider-documentation-secrets.php',
			array(
				'RAN_BOOSTER_GITHUB_TOKEN'          => 'canary-github-secret',
				'RAN_BOOSTER_GITHUB_WEBHOOK_SECRET' => 'canary-webhook-secret-00000000000',
				'RAN_BOOSTER_BITBUCKET_WORKSPACE'   => 'example-workspace',
				'RAN_BOOSTER_BITBUCKET_EMAIL'       => 'deploy@example.test',
				'RAN_BOOSTER_BITBUCKET_TOKEN'       => 'canary-bitbucket-secret',
			)
		);
		$bitbucketApi    = new BitbucketApiClient();
		$bitbucketStore  = new \Tests\RepositoryProvider\BitbucketProviderCredentialStore( $secrets );
		$bitbucketLoader = new BitbucketCredentialLoader( $bitbucketStore );

		return new ProviderRegistry(
			array(
				new GitHubProvider(
					$secrets,
					new GitHubRepositoryBrowser( $secrets ),
					new GitHubWebhookNormalizer( $secrets )
				),
				new BitbucketProvider(
					new BitbucketCredentialValidator( $bitbucketLoader, $bitbucketApi ),
					new BitbucketRepositoryBrowser( $bitbucketLoader, $bitbucketApi ),
					new BitbucketArchivePreparer( $bitbucketLoader, $bitbucketApi ),
					new BitbucketWebhookNormalizer( $bitbucketStore )
				),
			)
		);
	}
}
