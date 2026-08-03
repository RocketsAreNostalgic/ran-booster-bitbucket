<?php

declare(strict_types=1);

namespace RAN\Booster\Bitbucket;

use InvalidArgumentException;
use JsonException;
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

	public function __construct( private ProviderWebhookProfileReader $webhookProfiles ) {
		$this->policy = new BitbucketWebhookPolicy();
	}

	public function getWebhookPolicy(): ProviderWebhookPolicy {
		return $this->policy;
	}

	public function diagnoseWebhookReadiness(): ProviderDiagnosticResult {
		try {
			if ( ! $this->webhookProfiles->hasWebhookProfile() ) {
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

		return new ProviderDiagnosticResult(
			ProviderDiagnosticResult::WARNING,
			'bb.webhook.delivery_unverified',
			'A Bitbucket webhook secret is configured, but that does not prove the remote hook or a matching delivery.',
			'Send a Bitbucket test delivery, then compare Request History with the Provider request ID in Booster Activity.'
		);
	}

	public function normalizeWebhook( WebhookRequest $request ): WebhookEnvelope {
		if ( ! $request->getProvider()->equals( ProviderCode::parse( 'bb' ) ) ) {
			throw new WebhookRejected( 400, 'Webhook provider does not match Bitbucket.' );
		}

		$body = $request->getBody();

		$eventKey = $this->eventKey( $request );
		if ( 'repo:push' !== $eventKey ) {
			$request->requireVerification();
			return WebhookEnvelope::ignored();
		}

		$deliveryId   = $this->deliveryId( $request );
		$verification = $request->requireVerification();
		$payload      = $this->decodePushPayload( $body );
		$repository   = $this->pushRepository( $payload );

		if ( ! $this->policy->authorizeWebhook( $verification, $repository['id'], $repository['coordinates']->getFullName() ) ) {
			throw new WebhookRejected( 401, 'Webhook authentication failed.' );
		}

		$events = $this->pushEvents(
			$payload['push']['changes'],
			$repository['coordinates']->getFullName(),
			$repository['id'],
			$deliveryId
		);

		return array() === $events
			? WebhookEnvelope::ignored()
			: WebhookEnvelope::events( ...$events );
	}

	private function eventKey( WebhookRequest $request ): string {
		$eventKey = $this->boundedHeader( $request->getRawHeaderValues( 'x-event-key' ) );

		if ( null === $eventKey ) {
			throw new WebhookRejected( 400, 'Bitbucket event key is required.' );
		}

		return $eventKey;
	}

	private function deliveryId( WebhookRequest $request ): string {
		$deliveryId = $this->boundedHeader( $request->getRawHeaderValues( 'x-request-uuid' ) );

		if ( null === $deliveryId ) {
			throw new WebhookRejected( 400, 'Bitbucket request identifier is required.' );
		}

		return $deliveryId;
	}

	/** @param list<string> $values */
	private function boundedHeader( array $values ): ?string {
		if ( 1 !== count( $values ) ) {
			return null;
		}

		$value = trim( $values[0] );

		return 1 === preg_match( '/\A[^\x00-\x20\x7F]{1,' . self::MAX_HEADER_BYTES . '}\z/', $value )
			? $value
			: null;
	}

	/** @return array<string, mixed> */
	private function decodePushPayload( string $body ): array {
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
	private function pushRepository( array $payload ): array {
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
			$coordinates = BitbucketRepositoryCoordinates::fromFullName( $payload['repository']['full_name'] );
		} catch ( InvalidArgumentException ) {
			throw new WebhookRejected( 400, 'Bitbucket push payload is invalid.' );
		}

		$repositoryId = trim( $payload['repository']['uuid'] );
		if ( '' === $repositoryId
			|| strlen( $repositoryId ) > self::MAX_UUID_BYTES
			|| 1 === preg_match( '/[\x00-\x20\x7F]/', $repositoryId )
		) {
			throw new WebhookRejected( 400, 'Bitbucket push payload is invalid.' );
		}

		return array(
			'coordinates' => $coordinates,
			'id'          => $repositoryId,
		);
	}

	/**
	 * @param list<mixed> $changes Bitbucket reference changes.
	 * @return list<PushEvent>
	 */
	private function pushEvents(
		array $changes,
		string $repository,
		string $repositoryId,
		string $deliveryId
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
				|| ! $this->isBranch( $branch )
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
				$repositoryId,
				$branch,
				strtolower( $commit ),
				$deliveryId
			);
		}

		return $events;
	}

	private function isBranch( string $branch ): bool {
		return GitReferenceSyntax::isValidNamedReference( $branch );
	}
}
