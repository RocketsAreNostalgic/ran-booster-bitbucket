<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider;

use RAN\RepositoryProvider\ProviderCode;
use RAN\Secrets\SecretsFile;

final class BitbucketRepositoryBrowserSecretsStub extends SecretsFile {

	/** @var list<array{0: string, 1: string|null}> */
	public array $materialLookups = array();

	/** @var list<string> */
	public array $profileLookups = array();

	/** @param array<string, array<string, mixed>> $materials */
	public function __construct( private array $materials ) {
		parent::__construct( null, array() );
	}

	/** @return array<string, array<string, mixed>> */
	public function credentialProfiles( ProviderCode|string $provider ): array {
		$provider               = $provider instanceof ProviderCode ? $provider->value : $provider;
		$this->profileLookups[] = $provider;

		if ( ProviderCode::parse( 'bb' )->value !== $provider ) {
			return array();
		}

		$profiles = array();

		foreach ( $this->materials as $id => $material ) {
			$profiles[ $id ] = array(
				'id'            => $id,
				'provider'      => $material['provider'] ?? null,
				'label'         => $material['label'] ?? $id,
				'kind'          => $material['kind'] ?? null,
				'configuration' => $material['configuration'] ?? array(),
				'source'        => $material['source'] ?? 'file',
				'immutable'     => $material['immutable'] ?? false,
				'configured'    => $material['configured'] ?? true,
			);
		}

		return $profiles;
	}

	/** @return array<string, mixed>|null */
	public function credentialMaterial( ProviderCode|string $provider, ?string $id = null ): ?array {
		$provider                = $provider instanceof ProviderCode ? $provider->value : $provider;
		$this->materialLookups[] = array( $provider, $id );

		if ( ProviderCode::parse( 'bb' )->value !== $provider || null === $id ) {
			return null;
		}

		return $this->materials[ $id ] ?? null;
	}
}
