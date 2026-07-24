<?php

declare(strict_types=1);

namespace RAN\Booster\Bitbucket;

use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderCredentialPolicy;
use RuntimeException;

final readonly class BitbucketCredentialPolicy implements ProviderCredentialPolicy {

	private const WORKSPACE_CONSTANT = 'RAN_BOOSTER_BITBUCKET_WORKSPACE';
	private const EMAIL_CONSTANT     = 'RAN_BOOSTER_BITBUCKET_EMAIL';
	private const TOKEN_CONSTANT     = 'RAN_BOOSTER_BITBUCKET_TOKEN';

	public function getProvider(): ProviderCode {
		return ProviderCode::parse( 'bb' );
	}

	public function normalizeCredential( array $metadata, mixed $secret ): array {
		$label         = $this->requiredString( $metadata['label'] ?? null, 'Credential label' );
		$kind          = $this->requiredString( $metadata['kind'] ?? null, 'Credential kind' );
		$configuration = $metadata['configuration'] ?? array();
		$rawSecret     = $secret;
		$secret        = $this->requiredString( $secret, 'Credential secret' );

		if ( ! is_array( $configuration ) ) {
			throw new RuntimeException( 'Credential configuration must be a record.' );
		}

		if ( array() !== array_diff( array_keys( $configuration ), array( 'workspace', 'email' ) ) ) {
			throw new RuntimeException( 'Bitbucket credential configuration contains unsupported fields.' );
		}

		if ( 'api-token' !== $kind ) {
			throw new RuntimeException( 'Bitbucket credentials must use an API token.' );
		}

		$rawEmail  = $configuration['email'] ?? null;
		$workspace = $this->requiredString( $configuration['workspace'] ?? null, 'Bitbucket workspace' );
		$email     = $this->requiredString( $rawEmail, 'Bitbucket account email' );

		if ( ! $this->isOwner( $workspace )
			|| false === filter_var( $email, FILTER_VALIDATE_EMAIL )
			|| $this->hasEmailDelimiterOrControl( $rawEmail )
			|| $this->hasAsciiControl( $rawSecret )
		) {
			throw new RuntimeException( 'Bitbucket credentials require a valid workspace and account email.' );
		}

		return array(
			'label'         => $label,
			'kind'          => $kind,
			'configuration' => array(
				'workspace' => $workspace,
				'email'     => $email,
			),
			'secret'        => $secret,
		);
	}

	public function getConstantNames(): array {
		return array( self::WORKSPACE_CONSTANT, self::EMAIL_CONSTANT, self::TOKEN_CONSTANT );
	}

	public function credentialFromConstants( array $constants ): ?array {
		$workspace = $constants[ self::WORKSPACE_CONSTANT ] ?? '';
		$email     = $constants[ self::EMAIL_CONSTANT ] ?? '';
		$token     = $constants[ self::TOKEN_CONSTANT ] ?? '';
		$values    = array_filter(
			array( $workspace, $email, $token ),
			static fn ( mixed $value ): bool => is_string( $value ) && '' !== trim( $value )
		);

		if ( array() === $values ) {
			return null;
		}

		if ( 3 !== count( $values ) ) {
			throw new RuntimeException( 'Bitbucket deployment constants require workspace, email, and API token together.' );
		}

		return $this->normalizeCredential(
			array(
				'label'         => 'Deployment configuration',
				'kind'          => 'api-token',
				'configuration' => array(
					'workspace' => $workspace,
					'email'     => $email,
				),
			),
			$token
		);
	}

	private function requiredString( mixed $value, string $name ): string {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Provider policy errors are mapped at the admin boundary.
			throw new RuntimeException( $name . ' must be a non-empty string.' );
		}

		return trim( $value );
	}

	private function isOwner( string $owner ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9](?:[A-Za-z0-9_-]{0,62}[A-Za-z0-9])?$/', $owner );
	}

	private function hasEmailDelimiterOrControl( mixed $email ): bool {
		return is_string( $email ) && 1 === preg_match( '/[:\x00-\x1F\x7F]/', $email );
	}

	private function hasAsciiControl( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/[\x00-\x1F\x7F]/', $value );
	}
}
