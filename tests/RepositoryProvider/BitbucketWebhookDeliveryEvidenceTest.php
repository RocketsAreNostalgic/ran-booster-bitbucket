<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider;

use PHPUnit\Framework\TestCase;
use RAN\Booster\Bitbucket\BitbucketWebhookNormalizer;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidence;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderDiagnosticResult;
use RAN\RepositoryProvider\ProviderWebhookProfileReader;

final class BitbucketWebhookDeliveryEvidenceTest extends TestCase {
	public function testMatchedAuthenticatedDeliveryPassesReadiness(): void {
		$result = $this->normalizer(
			new AuthenticatedWebhookDeliveryEvidence(
				ProviderCode::parse( 'bb' ),
				'2026-09-15 12:00:00',
				true
			)
		)->diagnoseWebhookReadiness();

		self::assertSame( ProviderDiagnosticResult::PASSED, $result->status );
		self::assertSame( 'bb.webhook.delivery_verified', $result->code );
	}

	public function testAuthenticatedDeliveryWithoutManagedPackageMatchRemainsWarning(): void {
		$result = $this->normalizer(
			new AuthenticatedWebhookDeliveryEvidence(
				ProviderCode::parse( 'bb' ),
				'2026-09-15 12:00:00',
				false
			)
		)->diagnoseWebhookReadiness();

		self::assertSame( ProviderDiagnosticResult::WARNING, $result->status );
		self::assertSame( 'bb.webhook.delivery_unmatched', $result->code );
	}

	public function testUnavailableDeliveryEvidenceFailsClosedWithoutExceptionText(): void {
		$profiles = new class() implements ProviderWebhookProfileReader {
			public function hasWebhookProfile(): bool {
				return true;
			}
		};
		$evidence = new class() implements AuthenticatedWebhookDeliveryEvidenceReader {
			public function latestAuthenticatedDelivery(): ?AuthenticatedWebhookDeliveryEvidence {
				throw new \RuntimeException( 'private-delivery-evidence-canary' );
			}
		};
		$result = ( new BitbucketWebhookNormalizer( $profiles, $evidence ) )->diagnoseWebhookReadiness();
		$output = implode( ' ', $result->toArray() );

		self::assertSame( ProviderDiagnosticResult::FAILED, $result->status );
		self::assertSame( 'bb.webhook.delivery_evidence_unavailable', $result->code );
		self::assertStringNotContainsString( 'private-delivery-evidence-canary', $output );
	}

	private function normalizer( ?AuthenticatedWebhookDeliveryEvidence $delivery ): BitbucketWebhookNormalizer {
		$profiles = new class() implements ProviderWebhookProfileReader {
			public function hasWebhookProfile(): bool {
				return true;
			}
		};
		$evidence = new class( $delivery ) implements AuthenticatedWebhookDeliveryEvidenceReader {
			public function __construct( private ?AuthenticatedWebhookDeliveryEvidence $delivery ) {}

			public function latestAuthenticatedDelivery(): ?AuthenticatedWebhookDeliveryEvidence {
				return $this->delivery;
			}
		};

		return new BitbucketWebhookNormalizer( $profiles, $evidence );
	}
}
