<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider;

use RAN\RepositoryProvider\ProviderCredentialStore;
use RAN\Secrets\SecretsFile;

/** Test-only adapter that gives legacy sidecar fixtures the public bb boundary. */
final readonly class BitbucketProviderCredentialStore implements ProviderCredentialStore {

	public function __construct( private SecretsFile $secrets ) {
	}

	public function credential_profiles(): array {
		return $this->secrets->credential_profiles( 'bb' );
	}

	public function credential_material( ?string $id = null ): ?array {
		return $this->secrets->credential_material( 'bb', $id );
	}

	public function has_webhook_profile(): bool {
		return array() !== $this->secrets->webhookMaterials( 'bb' );
	}
}
