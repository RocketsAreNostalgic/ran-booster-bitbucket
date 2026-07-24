<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Booster\Bitbucket\BitbucketRepositoryCoordinates;

final class BitbucketRepositoryCoordinatesTest extends TestCase {

	public function testValidCoordinatesPreserveTrimmedCaseAndAllowRepositoryDots(): void {
		$coordinates = BitbucketRepositoryCoordinates::fromFullName( '  RocketsAreNostalgic/RAN.Booster  ' );

		self::assertSame( 'RocketsAreNostalgic', $coordinates->getWorkspace() );
		self::assertSame( 'RAN.Booster', $coordinates->getRepositorySlug() );
		self::assertSame( 'RocketsAreNostalgic/RAN.Booster', $coordinates->getFullName() );
	}

	public function testValidatedFullNameMatchingIsCaseInsensitive(): void {
		$coordinates = BitbucketRepositoryCoordinates::fromFullName( 'RocketsAreNostalgic/RAN.Booster' );

		self::assertTrue( $coordinates->matchesFullName( 'rocketsarenostalgic/ran.booster' ) );
		self::assertTrue( $coordinates->matchesFullName( '  ROCKETSARENOSTALGIC/RAN.BOOSTER  ' ) );
		self::assertFalse( $coordinates->matchesFullName( 'RocketsAreNostalgic/other' ) );
		self::assertFalse( $coordinates->matchesFullName( 'RocketsAreNostalgic/RAN.Booster/extra' ) );
		self::assertFalse( $coordinates->matchesFullName( 'RocketsAreNostalgic/..' ) );
	}

	#[DataProvider( 'validFullNameProvider' )]
	public function testBoundaryValidCoordinatesAreAccepted( string $fullName ): void {
		$coordinates = BitbucketRepositoryCoordinates::fromFullName( $fullName );

		self::assertSame( trim( $fullName ), $coordinates->getFullName() );
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function validFullNameProvider(): iterable {
		yield 'single characters' => array( 'a/b' );
		yield 'workspace punctuation' => array( 'workspace_slug-1/repository' );
		yield 'repository punctuation' => array( 'workspace/repository.name_v1-final' );
		yield 'repository trailing dot' => array( 'workspace/repository.' );
		yield 'maximum lengths' => array( str_repeat( 'w', 64 ) . '/' . str_repeat( 'r', 100 ) );
	}

	#[DataProvider( 'invalidFullNameProvider' )]
	public function testMalformedAndHostileCoordinatesAreRejected( string $fullName ): void {
		$this->expectException( InvalidArgumentException::class );

		BitbucketRepositoryCoordinates::fromFullName( $fullName );
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function invalidFullNameProvider(): iterable {
		yield 'empty' => array( '' );
		yield 'whitespace' => array( " \t\n" );
		yield 'workspace only' => array( 'workspace' );
		yield 'missing workspace' => array( '/repository' );
		yield 'missing repository' => array( 'workspace/' );
		yield 'extra component' => array( 'workspace/repository/extra' );
		yield 'workspace dot' => array( 'work.space/repository' );
		yield 'workspace leading punctuation' => array( '-workspace/repository' );
		yield 'workspace trailing punctuation' => array( 'workspace-/repository' );
		yield 'repository current directory' => array( 'workspace/.' );
		yield 'repository parent directory' => array( 'workspace/..' );
		yield 'repository leading dot' => array( 'workspace/.repository' );
		yield 'repository traversal' => array( 'workspace/../repository' );
		yield 'encoded separator' => array( 'workspace/repository%2Fother' );
		yield 'backslash separator' => array( 'workspace\\repository' );
		yield 'embedded space' => array( 'work space/repository' );
		yield 'control byte' => array( "workspace/repo\x00sitory" );
		yield 'unicode workspace' => array( 'wörkspace/repository' );
		yield 'workspace too long' => array( str_repeat( 'w', 65 ) . '/repository' );
		yield 'repository too long' => array( 'workspace/' . str_repeat( 'r', 101 ) );
	}
}
