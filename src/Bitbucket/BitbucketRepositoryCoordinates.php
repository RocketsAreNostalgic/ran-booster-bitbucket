<?php

declare(strict_types=1);

namespace RAN\Booster\Bitbucket;

use InvalidArgumentException;

final readonly class BitbucketRepositoryCoordinates {

	private function __construct(
		private string $workspace,
		private string $repository_slug
	) {
	}

	public static function from_full_name( string $full_name ): self {
		$full_name = trim( $full_name );
		$parts     = explode( '/', $full_name );

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

	public function get_workspace(): string {
		return $this->workspace;
	}

	public function get_repository_slug(): string {
		return $this->repository_slug;
	}

	public function get_full_name(): string {
		return $this->workspace . '/' . $this->repository_slug;
	}

	public function matches_full_name( string $full_name ): bool {
		try {
			$other = self::from_full_name( $full_name );
		} catch ( InvalidArgumentException ) {
			return false;
		}

		return 0 === strcasecmp( $this->get_full_name(), $other->get_full_name() );
	}
}
