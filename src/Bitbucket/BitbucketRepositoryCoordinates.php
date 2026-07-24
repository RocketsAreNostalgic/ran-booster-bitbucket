<?php

declare(strict_types=1);

namespace RAN\Booster\Bitbucket;

use InvalidArgumentException;

final readonly class BitbucketRepositoryCoordinates {

	private function __construct(
		private string $workspace,
		private string $repositorySlug
	) {
	}

	public static function fromFullName( string $fullName ): self {
		$fullName = trim( $fullName );
		$parts    = explode( '/', $fullName );

		if ( 2 !== count( $parts )
			|| 1 !== preg_match( '/^[A-Za-z0-9](?:[A-Za-z0-9_-]{0,62}[A-Za-z0-9])?$/', $parts[0] )
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/', $parts[1] )
			|| '.' === $parts[1]
			|| '..' === $parts[1]
		) {
			throw new InvalidArgumentException( 'Bitbucket repository coordinates are invalid.' );
		}

		return new self( $parts[0], $parts[1] );
	}

	public function getWorkspace(): string {
		return $this->workspace;
	}

	public function getRepositorySlug(): string {
		return $this->repositorySlug;
	}

	public function getFullName(): string {
		return $this->workspace . '/' . $this->repositorySlug;
	}

	public function matchesFullName( string $fullName ): bool {
		try {
			$other = self::fromFullName( $fullName );
		} catch ( InvalidArgumentException ) {
			return false;
		}

		return 0 === strcasecmp( $this->getFullName(), $other->getFullName() );
	}
}
