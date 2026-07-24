<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider;

use PHPUnit\Framework\TestCase;
use RAN\RepositoryProvider\Admin\CredentialFieldMetadata;
use RAN\RepositoryProvider\Admin\CredentialKindMetadata;
use RAN\RepositoryProvider\Admin\ProviderAdminMetadata;
use RAN\RepositoryProvider\Admin\ProviderSetupMetadata;
use RAN\RepositoryProvider\Admin\WebhookScopeMetadata;
use RAN\RepositoryProvider\ArchiveRequest;
use RAN\RepositoryProvider\AuthenticatedPreparedArchive;
use RAN\RepositoryProvider\Bitbucket\BitbucketCredential;
use RAN\RepositoryProvider\CredentialExpiryReport;
use RAN\RepositoryProvider\CredentialValidationResult;
use RAN\RepositoryProvider\ProviderDiagnosticRequest;
use RAN\RepositoryProvider\ProviderDiagnosticResult;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\ProviderSecretPolicyCatalog;
use RAN\RepositoryProvider\PublicRepositoryBrowseMetadata;
use RAN\RepositoryProvider\PushEvent;
use RAN\RepositoryProvider\RepositoryBrowseRequest;
use RAN\RepositoryProvider\RepositoryBrowseResult;
use RAN\RepositoryProvider\RepositoryDescriptor;
use RAN\RepositoryProvider\RepositoryLookupRequest;
use RAN\RepositoryProvider\RepositoryReference;
use RAN\RepositoryProvider\WebhookRequest;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

final class ProviderApiReadonlyValuesTest extends TestCase {

	/**
	 * @return array<class-string, list<string>>
	 */
	private static function values(): array {
		return array(
			ProviderMetadata::class               => array( 'code', 'label', 'repositoryUrlBase', 'ownerLabel', 'admin' ),
			PublicRepositoryBrowseMetadata::class => array( 'supportsProviderDefaultProfile' ),
			RepositoryDescriptor::class           => array( 'provider', 'locator', 'packageSlug', 'providerRepositoryId', 'private', 'defaultBranch', 'credentialId' ),
			RepositoryReference::class            => array( 'locator', 'providerRepositoryId', 'private', 'credentialId' ),
			RepositoryLookupRequest::class        => array( 'locator', 'credentialId', 'publicOnly' ),
			ArchiveRequest::class                 => array( 'repository', 'ref', 'expectedBranch' ),
			RepositoryBrowseResult::class         => array( 'repositories', 'partialReason' ),
			CredentialExpiryReport::class         => array( 'expiresAt' ),
			CredentialValidationResult::class     => array( 'reason', 'expiry' ),
			ProviderDiagnosticResult::class       => array( 'status', 'code', 'message', 'remediation' ),
			PushEvent::class                      => array( 'provider', 'repository', 'providerRepositoryId', 'branch', 'commit', 'deliveryId' ),
			CredentialFieldMetadata::class        => array( 'key', 'label', 'type', 'required', 'placeholder', 'description' ),
			CredentialKindMetadata::class         => array( 'code', 'label', 'secretLabel', 'secretPlaceholder', 'fields' ),
			ProviderSetupMetadata::class          => array( 'credentialSummary', 'credentialLinks', 'webhookLocation', 'webhookEvent', 'webhookDocumentationUrl', 'deliveryDocumentationUrl' ),
			WebhookScopeMetadata::class           => array( 'code', 'label', 'requiresTarget', 'targetLabel', 'targetPlaceholder', 'description' ),
			ProviderAdminMetadata::class          => array( 'credentialKinds', 'webhookScopes', 'setup' ),
		);
	}

	public function testProviderApiFourHasTheExactReadonlyValueSurface(): void {
		self::assertCount( 16, self::values() );

		foreach ( self::values() as $class => $expectedProperties ) {
			$reflection = new ReflectionClass( $class );
			self::assertTrue( $reflection->isFinal(), $class );
			self::assertTrue( $reflection->isReadOnly(), $class );

			$publicProperties = array_values(
				array_map(
					static fn( ReflectionProperty $property ): string => $property->getName(),
					$reflection->getProperties( ReflectionProperty::IS_PUBLIC )
				)
			);
			sort( $expectedProperties );
			sort( $publicProperties );
			self::assertSame( $expectedProperties, $publicProperties, $class );

			foreach ( $reflection->getProperties( ReflectionProperty::IS_PUBLIC ) as $property ) {
				self::assertTrue( $property->isReadOnly(), $class . '::$' . $property->getName() );
			}
		}
	}

	public function testProviderApiFourRemovesTrivialAccessorsAndRetainsSemanticMethods(): void {
		$semanticMethods = array(
			ProviderMetadata::class               => array( 'toArray' ),
			PublicRepositoryBrowseMetadata::class => array(),
			RepositoryDescriptor::class           => array( 'toArray' ),
			RepositoryReference::class            => array( 'fromDescriptor' ),
			RepositoryLookupRequest::class        => array(),
			ArchiveRequest::class                 => array(),
			RepositoryBrowseResult::class         => array( 'isPartial' ),
			CredentialExpiryReport::class         => array( 'known', 'unknown', 'isKnown' ),
			CredentialValidationResult::class     => array( 'valid', 'invalid', 'unavailable', 'rateLimited', 'invalidResponse', 'isValid', 'getDisplayMessage' ),
			ProviderDiagnosticResult::class       => array( 'toArray' ),
			PushEvent::class                      => array( 'toArray' ),
			CredentialFieldMetadata::class        => array(),
			CredentialKindMetadata::class         => array(),
			ProviderSetupMetadata::class          => array(),
			WebhookScopeMetadata::class           => array(),
			ProviderAdminMetadata::class          => array( 'getCredentialKind', 'getWebhookScope' ),
		);

		foreach ( $semanticMethods as $class => $expectedMethods ) {
			$reflection = new ReflectionClass( $class );
			$methods    = array_values(
				array_map(
					static fn( ReflectionMethod $method ): string => $method->getName(),
					array_filter(
						$reflection->getMethods( ReflectionMethod::IS_PUBLIC ),
						static fn( ReflectionMethod $method ): bool => '__construct' !== $method->getName()
					)
				)
			);
			sort( $expectedMethods );
			sort( $methods );
			self::assertSame( $expectedMethods, $methods, $class );
		}
	}

	public function testExcludedMutableLifecycleAndSecurityTypesDoNotExposePublicState(): void {
		$excluded = array(
			ProviderDiagnosticRequest::class    => array( 'claimRemoteCall', 'remainingSeconds' ),
			RepositoryBrowseRequest::class      => array( 'claimRemoteCall', 'acceptResponseBody', 'hasCapacity' ),
			WebhookRequest::class               => array( 'withVerification', 'requireVerification' ),
			AuthenticatedPreparedArchive::class => array( 'verifyCurrentHead', 'cleanup' ),
			ProviderRegistry::class             => array( 'register', 'seal' ),
			ProviderSecretPolicyCatalog::class  => array( 'register' ),
			BitbucketCredential::class          => array( 'getWorkspace', 'authorize' ),
		);

		foreach ( $excluded as $class => $retainedMethods ) {
			$reflection = new ReflectionClass( $class );
			self::assertSame( array(), $reflection->getProperties( ReflectionProperty::IS_PUBLIC ), $class );

			foreach ( $retainedMethods as $method ) {
				self::assertTrue( $reflection->hasMethod( $method ), $class . '::' . $method );
			}
		}

		self::assertFalse( ( new ReflectionClass( RepositoryBrowseRequest::class ) )->hasMethod( 'getProvider' ) );
	}
}
