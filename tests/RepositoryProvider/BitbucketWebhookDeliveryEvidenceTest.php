<?php

declare(strict_types=1);

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Isolated PHPUnit namespace matches the test autoload contract.
namespace Tests\RepositoryProvider;

use PHPUnit\Framework\TestCase;
use RAN\Booster\Bitbucket\BitbucketWebhookNormalizer;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidence;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderDiagnosticResult;
use RAN\RepositoryProvider\ProviderWebhookProfileReader;

final class BitbucketWebhookDeliveryEvidenceTest extends TestCase {
	public function test_matched_authenticated_delivery_passes_readiness(): void {
		$result = $this->normalizer(
			new AuthenticatedWebhookDeliveryEvidence(
				ProviderCode::parse( 'bb' ),
				'2026-09-15 12:00:00',
				true
			)
		)->diagnose_webhook_readiness();

		self::assertSame( ProviderDiagnosticResult::PASSED, $result->status );
		self::assertSame( 'bb.webhook.delivery_verified', $result->code );
	}

	public function test_authenticated_delivery_without_managed_package_match_remains_warning(): void {
		$result = $this->normalizer(
			new AuthenticatedWebhookDeliveryEvidence(
				ProviderCode::parse( 'bb' ),
				'2026-09-15 12:00:00',
				false
			)
		)->diagnose_webhook_readiness();

		self::assertSame( ProviderDiagnosticResult::WARNING, $result->status );
		self::assertSame( 'bb.webhook.delivery_unmatched', $result->code );
	}

	public function test_unavailable_delivery_evidence_fails_closed_without_exception_text(): void {
		$profiles = new class() implements ProviderWebhookProfileReader {
			public function has_webhook_profile(): bool {
				return true;
			}
		};
		$evidence = new class() implements AuthenticatedWebhookDeliveryEvidenceReader {
			public function latest_authenticated_delivery(): ?AuthenticatedWebhookDeliveryEvidence {
				throw new \RuntimeException( 'private-delivery-evidence-canary' );
			}
		};
		$result   = ( new BitbucketWebhookNormalizer( $profiles, $evidence ) )->diagnose_webhook_readiness();
		$output   = implode( ' ', $result->to_array() );

		self::assertSame( ProviderDiagnosticResult::FAILED, $result->status );
		self::assertSame( 'bb.webhook.delivery_evidence_unavailable', $result->code );
		self::assertStringNotContainsString( 'private-delivery-evidence-canary', $output );
	}

	private function normalizer( ?AuthenticatedWebhookDeliveryEvidence $delivery ): BitbucketWebhookNormalizer {
		$profiles = new class() implements ProviderWebhookProfileReader {
			public function has_webhook_profile(): bool {
				return true;
			}
		};
		$evidence = new class( $delivery ) implements AuthenticatedWebhookDeliveryEvidenceReader {
			public function __construct( private ?AuthenticatedWebhookDeliveryEvidence $delivery ) {}

			public function latest_authenticated_delivery(): ?AuthenticatedWebhookDeliveryEvidence {
				return $this->delivery;
			}
		};

		return new BitbucketWebhookNormalizer( webhook_profiles: $profiles, delivery_evidence: $evidence );
	}
}
