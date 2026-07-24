<?php

declare(strict_types=1);

namespace RAN\Booster\Bitbucket;

use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderCredentialStore;
use RuntimeException;

final readonly class BitbucketCredentialLoader {

	public function __construct( private ProviderCredentialStore $credentials ) {
	}

	public function load( string $credentialId ): BitbucketCredential {
		$credentialId = trim( $credentialId );

		if ( '' === $credentialId ) {
			throw BitbucketCredentialException::unavailable();
		}

		try {
			$material = $this->credentials->credentialMaterial( $credentialId );
		} catch ( RuntimeException ) {
			throw BitbucketCredentialException::unavailable();
		}

		if ( ! is_array( $material )
			|| ProviderCode::parse( 'bb' )->value !== ( $material['provider'] ?? null )
			|| 'api-token' !== ( $material['kind'] ?? null )
			|| ! is_array( $material['configuration'] ?? null )
		) {
			throw BitbucketCredentialException::unavailable();
		}

		return BitbucketCredential::fromMaterial(
			$material['configuration']['workspace'] ?? null,
			$material['configuration']['email'] ?? null,
			$material['secret'] ?? null
		);
	}
}
