<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider;

use RAN\RepositoryProvider\ProviderCredentialStore;
use RAN\Secrets\SecretsFile;

/** Test-only adapter that gives legacy sidecar fixtures the public bb boundary. */
final readonly class BitbucketProviderCredentialStore implements ProviderCredentialStore {

	public function __construct( private SecretsFile $secrets ) {
	}

	public function credentialProfiles(): array {
		return $this->secrets->credentialProfiles( 'bb' );
	}

	public function credentialMaterial( ?string $id = null ): ?array {
		return $this->secrets->credentialMaterial( 'bb', $id );
	}

	public function hasWebhookProfile(): bool {
		return array() !== $this->secrets->webhookMaterials( 'bb' );
	}
}
