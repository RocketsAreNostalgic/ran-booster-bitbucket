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

	public function get_provider(): ProviderCode {
		return ProviderCode::parse( 'bb' );
	}

	public function normalize_credential( array $metadata, mixed $secret ): array {
		$label         = $this->required_string( $metadata['label'] ?? null, 'Credential label' );
		$kind          = $this->required_string( $metadata['kind'] ?? null, 'Credential kind' );
		$configuration = $metadata['configuration'] ?? array();
		$raw_secret    = $secret;
		$secret        = $this->required_string( $secret, 'Credential secret' );

		if ( ! is_array( $configuration ) ) {
			throw new RuntimeException( 'Credential configuration must be a record.' );
		}

		if ( array() !== array_diff( array_keys( $configuration ), array( 'workspace', 'email' ) ) ) {
			throw new RuntimeException( 'Bitbucket credential configuration contains unsupported fields.' );
		}

		if ( 'api-token' !== $kind ) {
			throw new RuntimeException( 'Bitbucket credentials must use an API token.' );
		}

		$raw_email = $configuration['email'] ?? null;
		$workspace = $this->required_string( $configuration['workspace'] ?? null, 'Bitbucket workspace' );
		$email     = $this->required_string( $raw_email, 'Bitbucket account email' );

		if ( ! $this->is_owner( $workspace )
			|| false === filter_var( $email, FILTER_VALIDATE_EMAIL )
			|| $this->has_email_delimiter_or_control( $raw_email )
			|| $this->has_ascii_control( $raw_secret )
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

	public function get_constant_names(): array {
		return array( self::WORKSPACE_CONSTANT, self::EMAIL_CONSTANT, self::TOKEN_CONSTANT );
	}

	public function credential_from_constants( array $constants ): ?array {
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

		return $this->normalize_credential(
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

	private function required_string( mixed $value, string $name ): string {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			throw new RuntimeException( $name . ' must be a non-empty string.' );
		}

		return trim( $value );
	}

	private function is_owner( string $owner ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9](?:[A-Za-z0-9_-]{0,62}[A-Za-z0-9])?$/', $owner );
	}

	private function has_email_delimiter_or_control( mixed $email ): bool {
		return is_string( $email ) && 1 === preg_match( '/[:\x00-\x1F\x7F]/', $email );
	}

	private function has_ascii_control( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/[\x00-\x1F\x7F]/', $value );
	}
}
