<?php

declare(strict_types=1);

namespace RAN\Booster\Bitbucket;

use InvalidArgumentException;
use JsonException;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader;
use RAN\RepositoryProvider\GitReferenceSyntax;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderDiagnosticResult;
use RAN\RepositoryProvider\ProviderWebhookProfileReader;
use RAN\RepositoryProvider\ProviderWebhookPolicy;
use RAN\RepositoryProvider\PushEvent;
use RAN\RepositoryProvider\WebhookEnvelope;
use RAN\RepositoryProvider\WebhookNormalizer;
use RAN\RepositoryProvider\WebhookRejected;
use RAN\RepositoryProvider\WebhookRequest;

final readonly class BitbucketWebhookNormalizer implements WebhookNormalizer {

	private const MAX_HEADER_BYTES = 128;
	private const MAX_UUID_BYTES   = 191;

	private BitbucketWebhookPolicy $policy;

	public function __construct(
		private ProviderWebhookProfileReader $webhook_profiles,
		private ?AuthenticatedWebhookDeliveryEvidenceReader $delivery_evidence = null
	) {
		$this->policy = new BitbucketWebhookPolicy();
	}

	public function getWebhookPolicy(): ProviderWebhookPolicy { // phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Certified Core WebhookNormalizer signature; migrate with Core #167.
		return $this->policy;
	}

	public function diagnoseWebhookReadiness(): ProviderDiagnosticResult { // phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Certified Core WebhookNormalizer signature; migrate with Core #167.
		try {
			if ( ! $this->webhook_profiles->hasWebhookProfile() ) {
				return new ProviderDiagnosticResult(
					ProviderDiagnosticResult::NOT_CONFIGURED,
					'bb.webhook.not_configured',
					'No Bitbucket webhook secret is configured.',
					'Save a Bitbucket webhook secret before sending a provider test delivery.'
				);
			}
		} catch ( \Throwable ) {
			return new ProviderDiagnosticResult(
				ProviderDiagnosticResult::FAILED,
				'bb.webhook.configuration_unavailable',
				'Bitbucket webhook configuration could not be read.',
				'Check the credential sidecar and save the Bitbucket webhook configuration again.'
			);
		}

		if ( null === $this->delivery_evidence ) {
			return $this->unverified_delivery_result();
		}

		try {
			$delivery = $this->delivery_evidence->latestAuthenticatedDelivery();
		} catch ( \Throwable ) {
			return new ProviderDiagnosticResult(
				ProviderDiagnosticResult::FAILED,
				'bb.webhook.delivery_evidence_unavailable',
				'Bitbucket authenticated delivery evidence could not be read.',
				'Check Booster Activity and send another Bitbucket test delivery.'
			);
		}

		if ( null === $delivery ) {
			return $this->unverified_delivery_result();
		}

		if ( ! $delivery->matchedManagedPackage ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Certified Core AuthenticatedWebhookDeliveryEvidence field; migrate with Core #167.
			return new ProviderDiagnosticResult(
				ProviderDiagnosticResult::WARNING,
				'bb.webhook.delivery_unmatched',
				'Booster authenticated a Bitbucket delivery, but it did not match a managed package.',
				'Confirm the repository identity and managed package configuration, then send another Bitbucket delivery.'
			);
		}

		return new ProviderDiagnosticResult(
			ProviderDiagnosticResult::PASSED,
			'bb.webhook.delivery_verified',
			'Booster authenticated a Bitbucket delivery that matched a managed package.',
			'No remediation is required.'
		);
	}

	public function normalizeWebhook( WebhookRequest $request ): WebhookEnvelope { // phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Certified Core WebhookNormalizer signature; migrate with Core #167.
		if ( ! $request->getProvider()->equals( ProviderCode::parse( 'bb' ) ) ) {
			throw new WebhookRejected( 400, 'Webhook provider does not match Bitbucket.' );
		}

		$body = $request->getBody();

		$event_key = $this->event_key( $request );
		if ( 'repo:push' !== $event_key ) {
			$request->requireVerification();
			return WebhookEnvelope::ignored();
		}

		$delivery_id  = $this->delivery_id( $request );
		$verification = $request->requireVerification();
		$payload      = $this->decode_push_payload( $body );
		$repository   = $this->push_repository( $payload );

		if ( ! $this->policy->authorizeWebhook( $verification, $repository['id'], $repository['coordinates']->get_full_name() ) ) {
			throw new WebhookRejected( 401, 'Webhook authentication failed.' );
		}

		$events = $this->push_events(
			$payload['push']['changes'],
			$repository['coordinates']->get_full_name(),
			$repository['id'],
			$delivery_id
		);

		return array() === $events
			? WebhookEnvelope::ignored()
			: WebhookEnvelope::events( ...$events );
	}

	private function unverified_delivery_result(): ProviderDiagnosticResult {
		return new ProviderDiagnosticResult(
			ProviderDiagnosticResult::WARNING,
			'bb.webhook.delivery_unverified',
			'A Bitbucket webhook secret is configured, but that does not prove the remote hook or a matching delivery.',
			'Send a Bitbucket test delivery, then compare Request History with the Provider request ID in Booster Activity.'
		);
	}

	private function event_key( WebhookRequest $request ): string {
		$event_key = $this->bounded_header( $request->getRawHeaderValues( 'x-event-key' ) );

		if ( null === $event_key ) {
			throw new WebhookRejected( 400, 'Bitbucket event key is required.' );
		}

		return $event_key;
	}

	private function delivery_id( WebhookRequest $request ): string {
		$delivery_id = $this->bounded_header( $request->getRawHeaderValues( 'x-request-uuid' ) );

		if ( null === $delivery_id ) {
			throw new WebhookRejected( 400, 'Bitbucket request identifier is required.' );
		}

		return $delivery_id;
	}

	/** @param list<string> $values */
	private function bounded_header( array $values ): ?string {
		if ( 1 !== count( $values ) ) {
			return null;
		}

		$value = trim( $values[0] );

		return 1 === preg_match( '/\A[^\x00-\x20\x7F]{1,' . self::MAX_HEADER_BYTES . '}\z/', $value )
			? $value
			: null;
	}

	/** @return array<string, mixed> */
	private function decode_push_payload( string $body ): array {
		try {
			$payload = json_decode( $body, true, 512, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			throw new WebhookRejected( 400, 'Bitbucket push payload is invalid.' );
		}

		if ( ! is_array( $payload ) ) {
			throw new WebhookRejected( 400, 'Bitbucket push payload is invalid.' );
		}

		return $payload;
	}

	/**
	 * @param array<string, mixed> $payload Decoded Bitbucket push payload.
	 * @return array{coordinates: BitbucketRepositoryCoordinates, id: string}
	 */
	private function push_repository( array $payload ): array {
		if ( ! is_array( $payload['repository'] ?? null )
			|| ! is_string( $payload['repository']['full_name'] ?? null )
			|| ! is_string( $payload['repository']['uuid'] ?? null )
			|| ! is_array( $payload['push'] ?? null )
			|| ! is_array( $payload['push']['changes'] ?? null )
			|| ! array_is_list( $payload['push']['changes'] )
			|| array() === $payload['push']['changes']
		) {
			throw new WebhookRejected( 400, 'Bitbucket push payload is invalid.' );
		}

		try {
			$coordinates = BitbucketRepositoryCoordinates::from_full_name( $payload['repository']['full_name'] );
		} catch ( InvalidArgumentException ) {
			throw new WebhookRejected( 400, 'Bitbucket push payload is invalid.' );
		}

		$repository_id = trim( $payload['repository']['uuid'] );
		if ( '' === $repository_id
			|| strlen( $repository_id ) > self::MAX_UUID_BYTES
			|| 1 === preg_match( '/[\x00-\x20\x7F]/', $repository_id )
		) {
			throw new WebhookRejected( 400, 'Bitbucket push payload is invalid.' );
		}

		return array(
			'coordinates' => $coordinates,
			'id'          => $repository_id,
		);
	}

	/**
	 * @param list<mixed> $changes Bitbucket reference changes.
	 * @return list<PushEvent>
	 */
	private function push_events(
		array $changes,
		string $repository,
		string $repository_id,
		string $delivery_id
	): array {
		if ( count( $changes ) > 32 ) {
			throw new WebhookRejected( 400, 'Bitbucket push payload contains too many changes.' );
		}

		$events = array();

		foreach ( $changes as $change ) {
			if ( ! is_array( $change )
				|| ( array_key_exists( 'closed', $change ) && ! is_bool( $change['closed'] ) )
				|| ! array_key_exists( 'new', $change )
			) {
				throw new WebhookRejected( 400, 'Bitbucket push payload is invalid.' );
			}

			if ( true === ( $change['closed'] ?? false ) || null === $change['new'] ) {
				continue;
			}

			if ( ! is_array( $change['new'] ) || ! is_string( $change['new']['type'] ?? null ) ) {
				throw new WebhookRejected( 400, 'Bitbucket push payload is invalid.' );
			}

			if ( 'branch' !== $change['new']['type'] ) {
				continue;
			}

			$branch = $change['new']['name'] ?? null;
			$target = $change['new']['target'] ?? null;
			$commit = is_array( $target ) ? $target['hash'] ?? null : null;

			if ( ! is_string( $branch )
				|| ! $this->is_branch( $branch )
				|| ! is_string( $commit )
				|| 1 !== preg_match( '/\A[a-f0-9]{40}\z/i', $commit )
			) {
				throw new WebhookRejected( 400, 'Bitbucket push payload is invalid.' );
			}

			if ( 1 === preg_match( '/\A0{40}\z/', $commit ) ) {
				continue;
			}

			$events[] = new PushEvent(
				ProviderCode::parse( 'bb' ),
				$repository,
				$repository_id,
				$branch,
				strtolower( $commit ),
				$delivery_id
			);
		}

		return $events;
	}

	private function is_branch( string $branch ): bool {
		return GitReferenceSyntax::isValidNamedReference( $branch );
	}
}
