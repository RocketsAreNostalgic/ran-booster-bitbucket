<?php

declare(strict_types=1);

namespace Tests\Admin;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\InMemoryPublicRepositoryLookupProfileStore;

final class PublicRepositoryLookupProfileStoreTest extends TestCase {

	public function testStoresReplacesAndClearsOnlyProviderScopedProfileIds(): void {
		$store = new InMemoryPublicRepositoryLookupProfileStore();

		$store->set( 'gh', 'github_public' );
		$store->set( 'bb', 'bitbucket_workspace' );
		$store->set( 'gh', 'github_replacement' );

		self::assertSame( 'github_replacement', $store->get( 'gh' ) );
		self::assertSame( 'bitbucket_workspace', $store->get( 'bb' ) );
		self::assertSame(
			array(
				'bb' => 'bitbucket_workspace',
				'gh' => 'github_replacement',
			),
			$store->profiles
		);

		$store->set( 'gh', null );

		self::assertNull( $store->get( 'gh' ) );
		self::assertSame( array( 'bb' => 'bitbucket_workspace' ), $store->profiles );
	}

	public function testIgnoresMalformedStoredValuesAndRejectsMalformedWrites(): void {
		$store           = new InMemoryPublicRepositoryLookupProfileStore();
		$store->profiles = array(
			'gh'             => 'valid_profile',
			'GitHub'         => 'wrong_provider_case',
			'bb'             => array( 'not-a-string' ),
			'invalid<script' => 'another_profile',
		);

		self::assertSame( 'valid_profile', $store->get( 'gh' ) );
		self::assertNull( $store->get( 'bb' ) );

		$this->expectException( RuntimeException::class );
		$store->set( 'gh', ' invalid ' );
	}

	public function testReportsAWriteThatCannotBeVerified(): void {
		$store             = new InMemoryPublicRepositoryLookupProfileStore();
		$store->failWrites = true;

		$this->expectException( RuntimeException::class );
		$store->set( 'gh', 'github_public' );
	}
}
