<?php

declare(strict_types=1);

namespace RAN\Booster\Bitbucket;

use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderCredentialStore;
use RuntimeException;

final readonly class BitbucketCredentialLoader {

	public function __construct( private ProviderCredentialStore $credentials ) {
	}

	public function load( string $credential_id ): BitbucketCredential {
		$credential_id = trim( $credential_id );

		if ( '' === $credential_id ) {
			throw BitbucketCredentialException::unavailable();
		}

		try {
			$material = $this->credentials->credential_material( $credential_id );
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

		return BitbucketCredential::from_material(
			$material['configuration']['workspace'] ?? null,
			$material['configuration']['email'] ?? null,
			$material['secret'] ?? null
		);
	}
}
