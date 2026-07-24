<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider;

use RAN\RepositoryProvider\ProviderCode;
use RAN\Secrets\SecretsFile;

final class BitbucketCredentialValidationSecretsStub extends SecretsFile {

	/** @var list<array{0: string, 1: string|null}> */
	public array $lookups = array();

	/** @param array<string, array<string, mixed>> $credentials */
	public function __construct( private array $credentials ) {
		parent::__construct( null, array() );
	}

	/** @return array<string, mixed>|null */
	public function credentialMaterial( ProviderCode|string $provider, ?string $id = null ): ?array {
		$provider        = $provider instanceof ProviderCode ? $provider->value : $provider;
		$this->lookups[] = array( $provider, $id );

		return null === $id ? null : ( $this->credentials[ $id ] ?? null );
	}
}
