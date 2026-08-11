<?php

declare(strict_types=1);

namespace Tests\Plugin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class ReleaseVerifierTest extends TestCase {
	private string $temporary = '';

	protected function tearDown(): void {
		if ( '' === $this->temporary || ! is_dir( $this->temporary ) ) {
			return;
		}

		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $this->temporary, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $files as $file ) {
			$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}
		rmdir( $this->temporary );
	}

	public function testExactCommitArchivePassesTheHostileVerifier(): void {
		$fixture = $this->archiveFixture();

		self::assertSame( 0, $this->verify( $fixture['archive'], $fixture['commit'] ) );
	}

	/** @return iterable<string, array{callable(string): void}> */
	public static function hostileArchiveCases(): iterable {
		yield 'unknown member' => array(
			static function ( string $archive ): void {
				self::mutateZip( $archive, static fn( ZipArchive $zip ): bool => $zip->addFromString( 'ran-booster-bitbucket/unknown.php', '<?php' ) );
			},
		);
		yield 'unsafe path' => array(
			static function ( string $archive ): void {
				self::mutateZip( $archive, static fn( ZipArchive $zip ): bool => $zip->addFromString( 'ran-booster-bitbucket/../escape.php', '<?php' ) );
			},
		);
		yield 'normalized collision' => array(
			static function ( string $archive ): void {
				self::mutateZip( $archive, static fn( ZipArchive $zip ): bool => $zip->addFromString( 'ran-booster-bitbucket/src//Bitbucket/Plugin.php', '<?php' ) );
			},
		);
		yield 'symlink mode' => array(
			static function ( string $archive ): void {
				self::mutateZip(
					$archive,
					static function ( ZipArchive $zip ): bool {
						$name = 'ran-booster-bitbucket/link';

						return $zip->addFromString( $name, 'README.md' )
							&& $zip->setExternalAttributesName( $name, ZipArchive::OPSYS_UNIX, 0120777 << 16 );
					}
				);
			},
		);
		yield 'executable mode' => array(
			static function ( string $archive ): void {
				self::mutateZip(
					$archive,
					static function ( ZipArchive $zip ): bool {
						$name = 'ran-booster-bitbucket/executable.php';

						return $zip->addFromString( $name, '<?php' )
							&& $zip->setExternalAttributesName( $name, ZipArchive::OPSYS_UNIX, 0100755 << 16 );
					}
				);
			},
		);
		yield 'compression ratio' => array(
			static function ( string $archive ): void {
				self::mutateZip( $archive, static fn( ZipArchive $zip ): bool => $zip->addFromString( 'ran-booster-bitbucket/ratio.txt', str_repeat( '0', 1_000_000 ) ) );
			},
		);
		yield 'missing member' => array(
			static function ( string $archive ): void {
				self::mutateZip( $archive, static fn( ZipArchive $zip ): bool => $zip->deleteName( 'ran-booster-bitbucket/README.md' ) );
			},
		);
		yield 'allowed member byte tampering' => array(
			static function ( string $archive ): void {
				self::mutateZip(
					$archive,
					static function ( ZipArchive $zip ): bool {
						return $zip->deleteName( 'ran-booster-bitbucket/README.md' )
							&& $zip->addFromString( 'ran-booster-bitbucket/README.md', 'tampered' );
					}
				);
			},
		);
	}

	#[DataProvider( 'hostileArchiveCases' )]
	public function testHostileArchivesAreRejectedBeforeUse( callable $mutate ): void {
		$fixture = $this->archiveFixture();
		$mutate( $fixture['archive'] );
		$this->writeChecksum( $fixture['archive'] );

		self::assertSame( 1, $this->verify( $fixture['archive'], $fixture['commit'] ) );
	}

	public function testDuplicateRawMemberIsRejected(): void {
		$fixture = $this->archiveFixture();
		$python  = <<<'PYTHON'
import sys
import warnings
import zipfile
warnings.simplefilter("ignore", UserWarning)
with zipfile.ZipFile(sys.argv[1], "a") as archive:
    name = "ran-booster-bitbucket/README.md"
    archive.writestr(name, archive.read(name))
PYTHON;
		$status  = $this->executeCommand( array( 'python3', '-c', $python, $fixture['archive'] ) );
		self::assertSame( 0, $status );
		$this->writeChecksum( $fixture['archive'] );

		self::assertSame( 1, $this->verify( $fixture['archive'], $fixture['commit'] ) );
	}

	public function testChecksumAndSourceProvenanceDisagreementAreRejected(): void {
		$fixture = $this->archiveFixture();
		file_put_contents( $fixture['archive'] . '.sha256', str_repeat( '0', 64 ) . '  ' . basename( $fixture['archive'] ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local hostile fixture.
		self::assertSame( 1, $this->verify( $fixture['archive'], $fixture['commit'] ) );

		$this->writeChecksum( $fixture['archive'] );
		$otherCommit = trim( (string) shell_exec( 'git -C ' . escapeshellarg( dirname( __DIR__, 2 ) ) . ' rev-list --max-parents=0 --max-count=1 HEAD' ) );
		self::assertMatchesRegularExpression( '/^[0-9a-f]{40}$/', $otherCommit );
		self::assertNotSame( $fixture['commit'], $otherCommit );
		self::assertSame( 1, $this->verify( $fixture['archive'], $otherCommit ) );
	}

	/** @return array{archive: string, commit: string} */
	private function archiveFixture(): array {
		$root            = dirname( __DIR__, 2 );
		$commit          = trim( (string) shell_exec( 'git -C ' . escapeshellarg( $root ) . ' rev-parse HEAD' ) );
		$version         = trim( (string) shell_exec( 'git -C ' . escapeshellarg( $root ) . ' show ' . escapeshellarg( $commit . ':ran-booster-bitbucket.php' ) . " | sed -n 's/^[[:space:]]*\\*[[:space:]]*Version:[[:space:]]*\\([^[:space:]]*\\)[[:space:]]*$/\\1/p'" ) );
		$sourceArchive   = $root . '/build/ran-booster-bitbucket-' . $version . '.zip';
		$sourceChecksum  = $sourceArchive . '.sha256';
		self::assertMatchesRegularExpression( '/^[0-9a-f]{40}$/', $commit );
		self::assertSame( 0, $this->executeCommand( array( 'bash', $root . '/scripts/build-release.sh', $commit ) ) );

		$this->temporary = sys_get_temp_dir() . '/ran-booster-bitbucket-test-' . bin2hex( random_bytes( 8 ) );
		mkdir( $this->temporary, 0700, true );
		$archive = $this->temporary . '/' . basename( $sourceArchive );
		copy( $sourceArchive, $archive );
		copy( $sourceChecksum, $archive . '.sha256' );

		return array( 'archive' => $archive, 'commit' => $commit );
	}

	private static function mutateZip( string $archive, callable $mutate ): void {
		$zip = new ZipArchive();
		self::assertTrue( $zip->open( $archive ) );
		self::assertTrue( $mutate( $zip ) );
		self::assertTrue( $zip->close() );
	}

	private function writeChecksum( string $archive ): void {
		file_put_contents( $archive . '.sha256', hash_file( 'sha256', $archive ) . '  ' . basename( $archive ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local hostile fixture.
	}

	/** @param list<string> $command */
	private function executeCommand( array $command ): int {
		$process = proc_open( $command, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		self::assertIsResource( $process );
		stream_get_contents( $pipes[1] );
		stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );

		return proc_close( $process );
	}

	private function verify( string $archive, string $commit ): int {
		return $this->executeCommand( array( 'bash', dirname( __DIR__, 2 ) . '/scripts/verify-release.sh', $archive, $commit ) );
	}
}
