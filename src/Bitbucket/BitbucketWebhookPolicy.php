<?php

declare(strict_types=1);

namespace RAN\Booster\Bitbucket;

use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderWebhookPolicy;
use RAN\RepositoryProvider\SignedWebhookVerification;
use RuntimeException;

final readonly class BitbucketWebhookPolicy implements ProviderWebhookPolicy {

	public function getProvider(): ProviderCode { // phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Certified Core ProviderWebhookPolicy signature; migrate with Core #167.
		return ProviderCode::parse( 'bb' );
	}

	public function getRetainedHeaders(): array { // phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Certified Core ProviderWebhookPolicy signature; migrate with Core #167.
		return array( 'x-event-key', 'x-request-uuid', 'x-hub-signature' );
	}

	public function getSignatureHeader(): string { // phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Certified Core ProviderWebhookPolicy signature; migrate with Core #167.
		return 'x-hub-signature';
	}

	public function normalizeWebhook( array $metadata, mixed $secret ): array { // phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Certified Core ProviderWebhookPolicy signature; migrate with Core #167.
		$label        = $this->required_string( $metadata['label'] ?? null, 'Webhook secret label' );
		$scope        = $this->required_string( $metadata['scope'] ?? null, 'Webhook secret scope' );
		$target       = isset( $metadata['target'] ) && is_string( $metadata['target'] )
			? trim( $metadata['target'], " \t\n\r\0\x0B/" )
			: '';
		$secret       = $this->required_secret( $secret );
		$authority_id = isset( $metadata['authority_id'] ) && is_string( $metadata['authority_id'] )
			? trim( $metadata['authority_id'] )
			: '';

		if ( ! in_array( $scope, array( 'owner', 'repository' ), true ) ) {
			throw new RuntimeException( 'Webhook secret scope is not supported by this provider.' );
		}

		if ( 'owner' === $scope && ! $this->is_workspace( $target ) ) {
			throw new RuntimeException( 'Workspace-scoped webhook secrets require a valid provider workspace.' );
		} elseif ( 'repository' === $scope && ! $this->is_repository( $target ) ) {
			throw new RuntimeException( 'Repository-scoped webhook secrets require a workspace/repository target.' );
		}
		if ( 'owner' === $scope ) {
			$authority_id = '';
		} elseif ( '' === $authority_id || strlen( $authority_id ) > 191 || 1 === preg_match( '/[\x00-\x1F\x7F]/', $authority_id ) ) {
			throw new RuntimeException( 'Repository-scoped webhook secrets require a stable repository identity.' );
		}

		return array(
			'label'        => $label,
			'scope'        => $scope,
			'target'       => $target,
			'authority_id' => $authority_id,
			'secret'       => $secret,
		);
	}

	public function getConstantNames(): array { // phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Certified Core ProviderWebhookPolicy signature; migrate with Core #167.
		return array();
	}

	public function webhookFromConstants( array $constants ): ?array { // phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Certified Core ProviderWebhookPolicy signature; migrate with Core #167.
		return null;
	}

	public function authorizeWebhook( // phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Certified Core ProviderWebhookPolicy signature; migrate with Core #167.
		SignedWebhookVerification $verification,
		string $repositoryAuthorityId, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Certified Core ProviderWebhookPolicy signature; migrate with Core #167.
		string $repository
	): bool {
		if ( '' === $repositoryAuthorityId || ! $verification->getProvider()->equals( $this->getProvider() ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Certified Core ProviderWebhookPolicy signature; migrate with Core #167.
			return false;
		}

		$repository = strtolower( trim( $repository, '/' ) );
		$workspace  = explode( '/', $repository, 2 )[0];
		foreach ( $verification->getProfiles() as $profile ) {
			$scope  = strtolower( trim( $profile['scope'] ) );
			$target = strtolower( trim( $profile['target'], " \t\n\r\0\x0B/" ) );
			if ( ( 'owner' === $scope && '' !== $target && $target === $workspace )
				|| ( 'repository' === $scope
					&& '' !== $profile['authority_id']
					&& hash_equals( $profile['authority_id'], $repositoryAuthorityId ) ) // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Certified Core ProviderWebhookPolicy signature; migrate with Core #167.
			) {
				return true;
			}
		}

		return false;
	}

	public function repositoryTargetMatches( string $target, string $repositoryLocator ): bool { // phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase, WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Certified Core ProviderWebhookPolicy signature; migrate with Core #167.
		return 0 === strcasecmp( trim( $target, '/' ), trim( $repositoryLocator, '/' ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Certified Core ProviderWebhookPolicy signature; migrate with Core #167.
	}

	private function assert_secret( string $secret ): void {
		if ( strlen( $secret ) < 32 || strlen( $secret ) > 512 || 1 === preg_match( '/[\x00-\x1F\x7F]/', $secret ) ) {
			throw new RuntimeException( 'Webhook secrets must contain 32 to 512 bytes without control characters.' );
		}
	}

	private function required_secret( mixed $secret ): string {
		if ( ! is_string( $secret ) ) {
			throw new RuntimeException( 'Webhook secret must be a string.' );
		}

		$this->assert_secret( $secret );

		return $secret;
	}

	private function required_string( mixed $value, string $name ): string {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Provider policy errors are mapped at the admin boundary.
			throw new RuntimeException( $name . ' must be a non-empty string.' );
		}

		return trim( $value );
	}

	private function is_workspace( string $workspace ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9](?:[A-Za-z0-9_-]{0,62}[A-Za-z0-9])?$/', $workspace );
	}

	private function is_repository( string $repository ): bool {
		if ( 1 !== substr_count( $repository, '/' ) ) {
			return false;
		}

		list($workspace, $name) = explode( '/', $repository, 2 );

		return $this->is_workspace( $workspace ) && 1 === preg_match( '/^[A-Za-z0-9_.-]{1,100}$/', $name );
	}
}
