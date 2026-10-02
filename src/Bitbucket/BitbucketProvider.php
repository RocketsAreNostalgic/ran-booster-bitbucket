<?php

declare(strict_types=1);

namespace RAN\Booster\Bitbucket;

use RAN\RepositoryProvider\Admin\CredentialFieldMetadata;
use RAN\RepositoryProvider\Admin\CredentialKindMetadata;
use RAN\RepositoryProvider\Admin\ProviderAdminMetadata;
use RAN\RepositoryProvider\Admin\ProviderNavigationPlacement;
use RAN\RepositoryProvider\Admin\ProviderSetupMetadata;
use RAN\RepositoryProvider\Admin\WebhookScopeMetadata;
use RAN\RepositoryProvider\ArchiveRequest;
use RAN\RepositoryProvider\CredentialValidationResult;
use RAN\RepositoryProvider\CredentialValidator;
use RAN\RepositoryProvider\CredentialedPublicRepositoryBrowser;
use RAN\RepositoryProvider\PreparedArchive;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderCredentialPolicy;
use RAN\RepositoryProvider\ProviderCredentialPolicySupplier;
use RAN\RepositoryProvider\ProviderDiagnosticResult;
use RAN\RepositoryProvider\ProviderDiagnostics;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\ProviderWebhookPolicy;
use RAN\RepositoryProvider\PublicRepositoryBrowseMetadata;
use RAN\RepositoryProvider\RepositoryBrowseRequest;
use RAN\RepositoryProvider\RepositoryBrowseResult;
use RAN\RepositoryProvider\RepositoryDescriptor;
use RAN\RepositoryProvider\RepositoryLookupRequest;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\RepositoryProvider\RepositoryWebhookSettingsLink;
use RAN\RepositoryProvider\WebhookEnvelope;
use RAN\RepositoryProvider\WebhookNormalizer;
use RAN\RepositoryProvider\WebhookRequest;
use RuntimeException;

final readonly class BitbucketProvider implements RepositoryProvider, CredentialValidator, CredentialedPublicRepositoryBrowser, WebhookNormalizer, ProviderCredentialPolicySupplier, RepositoryWebhookSettingsLink {

	private ProviderMetadata $metadata;
	private BitbucketDiagnostics $diagnostics;
	private BitbucketCredentialPolicy $credential_policy;

	public function __construct(
		private BitbucketCredentialValidator $credential_validator,
		private BitbucketRepositoryBrowser $browser,
		private BitbucketArchivePreparer $archives,
		private BitbucketWebhookNormalizer $webhooks
	) {
		$this->diagnostics       = new BitbucketDiagnostics( $credential_validator, $browser );
		$this->credential_policy = new BitbucketCredentialPolicy();
		$this->metadata          = new ProviderMetadata(
			ProviderCode::parse( 'bb' ),
			'Bitbucket',
			'https://bitbucket.org/',
			'Workspace',
			new ProviderAdminMetadata(
				array(
					new CredentialKindMetadata(
						'api-token',
						'Bitbucket API token',
						'API token',
						'Paste the API token',
						array(
							new CredentialFieldMetadata(
								'workspace',
								'Workspace',
								'text',
								true,
								'workspace-slug',
								'The Bitbucket workspace that the token can access.'
							),
							new CredentialFieldMetadata(
								'email',
								'Atlassian account email',
								'email',
								true,
								'name@example.com',
								'The Atlassian account email used with the API token.'
							),
						)
					),
				),
				array(
					new WebhookScopeMetadata(
						'owner',
						'Bitbucket workspace',
						true,
						'Workspace',
						'workspace-slug',
						'Use this secret for repositories in one workspace.',
						// Owner scopes must resolve to a configured managed package target.
						true
					),
					new WebhookScopeMetadata(
						'repository',
						'Bitbucket repository',
						true,
						'Repository',
						'workspace/repository',
						'Use this secret only for one repository.'
					),
				),
				new ProviderSetupMetadata(
					'Public repositories need no token. A saved Bitbucket Cloud API token may be selected as the default identity for public repository lookup across workspaces; use a dedicated, expiring token with only Repositories: Read (read:repository:bitbucket) for that purpose. For private repositories and deployments, save the token with the Atlassian account email and intended workspace: Booster continues to enforce that workspace boundary. Do not add Webhooks, Pull requests, Projects, Pipelines, Runners, Issues, SSH keys or any Write, Admin or Delete permission: Booster only reads repository data and archives, and does not create provider webhooks. App passwords and workspace, project or repository access tokens are not supported. Choose an expiry that suits your policy and replace the token before it expires.',
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
					'Repository settings → Webhooks → Add webhook',
					'Repository push',
					'https://support.atlassian.com/bitbucket-cloud/docs/manage-webhooks/',
					'https://support.atlassian.com/bitbucket-cloud/docs/troubleshoot-webhooks/'
				),
				new ProviderNavigationPlacement(
					ProviderNavigationPlacement::GIT_HOST,
					// Place Bitbucket after GitHub among ordinary Git host providers.
					200
				),
				'workspace/repository'
			)
		);
	}

	public function get_metadata(): ProviderMetadata {
		return $this->metadata;
	}

	public function get_provider_diagnostics(): ProviderDiagnostics {
		return $this->diagnostics;
	}

	public function get_credential_policy(): ProviderCredentialPolicy {
		return $this->credential_policy;
	}

	public function get_webhook_policy(): ProviderWebhookPolicy {
		return $this->webhooks->get_webhook_policy();
	}

	public function diagnose_webhook_readiness(): ProviderDiagnosticResult {
		return $this->webhooks->diagnose_webhook_readiness();
	}

	public function validate_credential( string $credential_id ): CredentialValidationResult {
		return $this->credential_validator->validate_credential( $credential_id );
	}

	public function browse_repositories( RepositoryBrowseRequest $request ): RepositoryBrowseResult {
		return $this->browser->browse( $request );
	}

	public function get_public_repository_browse_metadata(): PublicRepositoryBrowseMetadata {
		return new PublicRepositoryBrowseMetadata( true );
	}

	public function resolve_repository( RepositoryLookupRequest $request ): RepositoryDescriptor {
		return $this->browser->repository(
			$request->locator,
			$request->credential_id,
			public_only: $request->public_only
		);
	}

	public function prepare_archive( ArchiveRequest $request ): PreparedArchive {
		return $this->archives->prepare_archive( $request );
	}

	public function normalize_webhook( WebhookRequest $request ): WebhookEnvelope {
		return $this->webhooks->normalize_webhook( $request );
	}

	public function repository_webhook_settings_url( string $locator ): string {
		$coordinates = BitbucketRepositoryCoordinates::from_full_name( $locator );

		return 'https://bitbucket.org/'
			. rawurlencode( $coordinates->get_workspace() )
			. '/'
			. rawurlencode( $coordinates->get_repository_slug() )
			. '/admin/webhooks';
	}
}
