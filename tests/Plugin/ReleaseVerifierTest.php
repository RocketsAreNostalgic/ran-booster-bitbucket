<?php

declare(strict_types=1);

namespace Tests\Plugin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class ReleaseVerifierTest extends TestCase {
	private string $temporary = '';

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Preserve the PHPUnit lifecycle override signature.
	protected function tearDown(): void {
		if ( '' === $this->temporary || ! is_dir( $this->temporary ) ) {
			return;
		}

		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $this->temporary, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $files as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only the disposable local CLI fixture directory without loading WordPress. Remove only the disposable local CLI fixture file without loading WordPress.
			$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only the disposable local CLI fixture directory without loading WordPress.
		rmdir( $this->temporary );
	}

	public function test_exact_commit_archive_passes_the_hostile_verifier(): void {
		$fixture = $this->archive_fixture();

		self::assertSame( 0, $this->verify( $fixture['archive'], $fixture['commit'] ) );
	}

	/** @return iterable<string, array{callable(string): void}> */
	public static function hostile_archive_cases(): iterable {
		yield 'unknown member' => array(
			static function ( string $archive ): void {
				self::mutate_zip( $archive, static fn( ZipArchive $zip ): bool => $zip->addFromString( 'ran-booster-bitbucket/unknown.php', '<?php' ) );
			},
		);
		yield 'unsafe path' => array(
			static function ( string $archive ): void {
				self::mutate_zip( $archive, static fn( ZipArchive $zip ): bool => $zip->addFromString( 'ran-booster-bitbucket/../escape.php', '<?php' ) );
			},
		);
		yield 'normalized collision' => array(
			static function ( string $archive ): void {
				self::mutate_zip( $archive, static fn( ZipArchive $zip ): bool => $zip->addFromString( 'ran-booster-bitbucket/src//Bitbucket/Plugin.php', '<?php' ) );
			},
		);
		yield 'symlink mode' => array(
			static function ( string $archive ): void {
				self::mutate_zip(
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
				self::mutate_zip(
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
				self::mutate_zip( $archive, static fn( ZipArchive $zip ): bool => $zip->addFromString( 'ran-booster-bitbucket/ratio.txt', str_repeat( '0', 1_000_000 ) ) );
			},
		);
		yield 'missing member' => array(
			static function ( string $archive ): void {
				self::mutate_zip( $archive, static fn( ZipArchive $zip ): bool => $zip->deleteName( 'ran-booster-bitbucket/README.md' ) );
			},
		);
		yield 'allowed member byte tampering' => array(
			static function ( string $archive ): void {
				self::mutate_zip(
					$archive,
					static function ( ZipArchive $zip ): bool {
						return $zip->deleteName( 'ran-booster-bitbucket/README.md' )
							&& $zip->addFromString( 'ran-booster-bitbucket/README.md', 'tampered' );
					}
				);
			},
		);
	}

	#[DataProvider( 'hostile_archive_cases' )]
	public function test_hostile_archives_are_rejected_before_use( callable $mutate ): void {
		$fixture = $this->archive_fixture();
		$mutate( $fixture['archive'] );
		$this->write_checksum( $fixture['archive'] );

		self::assertSame( 1, $this->verify( $fixture['archive'], $fixture['commit'] ) );
	}

	public function test_duplicate_raw_member_is_rejected(): void {
		$fixture = $this->archive_fixture();
		$python  = <<<'PYTHON'
import sys
import warnings
import zipfile
warnings.simplefilter("ignore", UserWarning)
with zipfile.ZipFile(sys.argv[1], "a") as archive:
    name = "ran-booster-bitbucket/README.md"
    archive.writestr(name, archive.read(name))
PYTHON;
		$status  = $this->execute_command( array( 'python3', '-c', $python, $fixture['archive'] ) );
		self::assertSame( 0, $status );
		$this->write_checksum( $fixture['archive'] );

		self::assertSame( 1, $this->verify( $fixture['archive'], $fixture['commit'] ) );
	}

	public function test_checksum_and_source_provenance_disagreement_are_rejected(): void {
		$fixture = $this->archive_fixture();
		file_put_contents( $fixture['archive'] . '.sha256', str_repeat( '0', 64 ) . '  ' . basename( $fixture['archive'] ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local hostile fixture.
		self::assertSame( 1, $this->verify( $fixture['archive'], $fixture['commit'] ) );

		$this->write_checksum( $fixture['archive'] );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec -- Isolated CLI contract executes repository tooling in a disposable subprocess, outside WordPress runtime.
		$other_commit = trim( (string) shell_exec( 'git -C ' . escapeshellarg( dirname( __DIR__, 2 ) ) . ' rev-list --max-parents=0 --max-count=1 HEAD' ) );
		self::assertMatchesRegularExpression( '/^[0-9a-f]{40}$/', $other_commit );
		self::assertNotSame( $fixture['commit'], $other_commit );
		self::assertSame( 1, $this->verify( $fixture['archive'], $other_commit ) );
	}

	/** @return array{archive: string, commit: string} */
	private function archive_fixture(): array {
		$root = dirname( __DIR__, 2 );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec -- Isolated CLI contract executes repository tooling in a disposable subprocess, outside WordPress runtime.
		$commit = trim( (string) shell_exec( 'git -C ' . escapeshellarg( $root ) . ' rev-parse HEAD' ) );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec -- Isolated CLI contract executes repository tooling in a disposable subprocess, outside WordPress runtime.
		$version         = trim( (string) shell_exec( 'git -C ' . escapeshellarg( $root ) . ' show ' . escapeshellarg( $commit . ':ran-booster-bitbucket.php' ) . " | sed -n 's/^[[:space:]]*\\*[[:space:]]*Version:[[:space:]]*\\([^[:space:]]*\\)[[:space:]]*$/\\1/p'" ) );
		$source_archive  = $root . '/build/ran-booster-bitbucket-' . $version . '.zip';
		$source_checksum = $source_archive . '.sha256';
		self::assertMatchesRegularExpression( '/^[0-9a-f]{40}$/', $commit );
		self::assertSame( 0, $this->execute_command( array( 'bash', $root . '/scripts/build-release.sh', $commit ) ) );

		$this->temporary = sys_get_temp_dir() . '/ran-booster-bitbucket-test-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create a disposable local CLI fixture directory without loading WordPress.
		mkdir( $this->temporary, 0700, true );
		$archive = $this->temporary . '/' . basename( $source_archive );
		copy( $source_archive, $archive );
		copy( $source_checksum, $archive . '.sha256' );

		return array(
			'archive' => $archive,
			'commit'  => $commit,
		);
	}

	private static function mutate_zip( string $archive, callable $mutate ): void {
		$zip = new ZipArchive();
		self::assertTrue( $zip->open( $archive ) );
		self::assertTrue( $mutate( $zip ) );
		self::assertTrue( $zip->close() );
	}

	private function write_checksum( string $archive ): void {
		file_put_contents( $archive . '.sha256', hash_file( 'sha256', $archive ) . '  ' . basename( $archive ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local hostile fixture.
	}

	/** @param list<string> $command */
	private function execute_command( array $command ): int {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- CLI contract controls a disposable subprocess and inspects its exact exit status.
		$process = proc_open(
			$command,
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);
		self::assertIsResource( $process );
		stream_get_contents( $pipes[1] );
		stream_get_contents( $pipes[2] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the CLI subprocess pipe after collecting its output.
		fclose( $pipes[1] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the CLI subprocess pipe after collecting its output.
		fclose( $pipes[2] );

		return proc_close( $process );
	}

	private function verify( string $archive, string $commit ): int {
		return $this->execute_command( array( 'bash', dirname( __DIR__, 2 ) . '/scripts/verify-release.sh', $archive, $commit ) );
	}
}
