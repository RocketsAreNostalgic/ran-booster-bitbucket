<?php

declare(strict_types=1);

namespace RAN\Booster\Bitbucket\Tests\RepositoryProvider;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Booster\Bitbucket\BitbucketRepositoryCoordinates;

final class BitbucketRepositoryCoordinatesTest extends TestCase {

	public function test_valid_coordinates_preserve_trimmed_case_and_allow_repository_dots(): void {
		$coordinates = BitbucketRepositoryCoordinates::from_full_name( full_name: '  RocketsAreNostalgic/RAN.Booster  ' );

		self::assertSame( 'RocketsAreNostalgic', $coordinates->get_workspace() );
		self::assertSame( 'RAN.Booster', $coordinates->get_repository_slug() );
		self::assertSame( 'RocketsAreNostalgic/RAN.Booster', $coordinates->get_full_name() );
	}

	public function test_validated_full_name_matching_is_case_insensitive(): void {
		$coordinates = BitbucketRepositoryCoordinates::from_full_name( 'RocketsAreNostalgic/RAN.Booster' );

		self::assertTrue( $coordinates->matches_full_name( full_name: 'rocketsarenostalgic/ran.booster' ) );
		self::assertTrue( $coordinates->matches_full_name( '  ROCKETSARENOSTALGIC/RAN.BOOSTER  ' ) );
		self::assertFalse( $coordinates->matches_full_name( 'RocketsAreNostalgic/other' ) );
		self::assertFalse( $coordinates->matches_full_name( 'RocketsAreNostalgic/RAN.Booster/extra' ) );
		self::assertFalse( $coordinates->matches_full_name( 'RocketsAreNostalgic/..' ) );
	}

	#[DataProvider( 'valid_full_name_provider' )]
	public function test_boundary_valid_coordinates_are_accepted( string $full_name ): void {
		$coordinates = BitbucketRepositoryCoordinates::from_full_name( $full_name );

		self::assertSame( trim( $full_name ), $coordinates->get_full_name() );
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function valid_full_name_provider(): iterable {
		yield 'single characters' => array( 'a/b' );
		yield 'workspace punctuation' => array( 'workspace_slug-1/repository' );
		yield 'repository punctuation' => array( 'workspace/repository.name_v1-final' );
		yield 'repository trailing dot' => array( 'workspace/repository.' );
		yield 'maximum lengths' => array( str_repeat( 'w', 64 ) . '/' . str_repeat( 'r', 100 ) );
	}

	#[DataProvider( 'invalid_full_name_provider' )]
	public function test_malformed_and_hostile_coordinates_are_rejected( string $full_name ): void {
		$this->expectException( InvalidArgumentException::class );

		BitbucketRepositoryCoordinates::from_full_name( $full_name );
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function invalid_full_name_provider(): iterable {
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
