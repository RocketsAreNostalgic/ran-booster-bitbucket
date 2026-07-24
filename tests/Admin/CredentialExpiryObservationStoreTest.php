<?php

declare(strict_types=1);

namespace Tests\Admin;

use PHPUnit\Framework\TestCase;
use RAN\RepositoryProvider\CredentialExpiryReport;
use RuntimeException;
use Tests\Support\InMemoryCredentialExpiryObservationStore;

final class CredentialExpiryObservationStoreTest extends TestCase {

	public function testStoresManualAndProviderObservationsByProviderAndProfile(): void {
		$store = new InMemoryCredentialExpiryObservationStore();

		$store->setManualExpiry( 'gh', 'github_primary', '2026-09-01' );
		$store->recordProviderExpiry(
			'gh',
			'github_primary',
			CredentialExpiryReport::known( '2026-08-23T12:30:00Z' ),
			'2026-07-23T12:30:00Z'
		);
		$store->setManualExpiry( 'bb', 'bitbucket_primary', '2026-10-01' );

		self::assertSame(
			array(
				'manual_expires_on'   => '2026-09-01',
				'provider_checked_at' => '2026-07-23T12:30:00Z',
				'provider_expires_at' => '2026-08-23T12:30:00Z',
			),
			$store->get( 'gh', 'github_primary' )
		);
		self::assertSame(
			array( 'manual_expires_on' => '2026-10-01' ),
			$store->get( 'bb', 'bitbucket_primary' )
		);
		self::assertFalse( $store->lastAutoload );
	}

	public function testUnknownProviderReportClearsOnlyProviderExpiry(): void {
		$store = new InMemoryCredentialExpiryObservationStore();
		$store->setManualExpiry( 'gh', 'github_primary', '2026-09-01' );
		$store->recordProviderExpiry(
			'gh',
			'github_primary',
			CredentialExpiryReport::known( '2026-08-23T12:30:00Z' ),
			'2026-07-23T12:30:00Z'
		);

		$store->recordProviderExpiry(
			'gh',
			'github_primary',
			CredentialExpiryReport::unknown(),
			'2026-07-24T12:30:00Z'
		);

		self::assertSame(
			array(
				'manual_expires_on'   => '2026-09-01',
				'provider_checked_at' => '2026-07-24T12:30:00Z',
			),
			$store->get( 'gh', 'github_primary' )
		);
	}

	public function testIgnoresMalformedStoredDataAndCanonicalisesWrites(): void {
		$store           = new InMemoryCredentialExpiryObservationStore();
		$store->document = array(
			'version'  => 1,
			'profiles' => array(
				'gh'     => array(
					'valid_profile' => array(
						'manual_expires_on'   => '2026-09-01',
						'provider_expires_at' => 'provider-canary',
					),
					'bad id'        => array( 'manual_expires_on' => '2026-10-01' ),
				),
				'GitHub' => array( 'other_profile' => array( 'manual_expires_on' => '2026-11-01' ) ),
			),
		);

		self::assertSame(
			array( 'manual_expires_on' => '2026-09-01' ),
			$store->get( 'gh', 'valid_profile' )
		);

		$store->setManualExpiry( 'gh', 'valid_profile', null );
		self::assertSame(
			array(
				'version'  => 1,
				'profiles' => array(),
			),
			$store->document
		);
	}

	public function testClearsOnlyTheSelectedObservation(): void {
		$store = new InMemoryCredentialExpiryObservationStore();
		$store->setManualExpiry( 'gh', 'shared_profile', '2026-09-01' );
		$store->setManualExpiry( 'bb', 'shared_profile', '2026-10-01' );

		$store->clear( 'gh', 'shared_profile' );

		self::assertSame( array(), $store->get( 'gh', 'shared_profile' ) );
		self::assertSame(
			array( 'manual_expires_on' => '2026-10-01' ),
			$store->get( 'bb', 'shared_profile' )
		);
	}

	public function testReportsUnverifiableWrites(): void {
		$store             = new InMemoryCredentialExpiryObservationStore();
		$store->failWrites = true;

		$this->expectException( RuntimeException::class );
		$store->setManualExpiry( 'gh', 'github_primary', '2026-09-01' );
	}
}
