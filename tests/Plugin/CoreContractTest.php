<?php

declare(strict_types=1);

namespace Tests\Plugin;

use PHPUnit\Framework\TestCase;

final class CoreContractTest extends TestCase {
	private const COMPATIBLE_CORE_COMMIT = '96a2c93ae538bd107fadf2e7c1fb4eba4b726252';

	public function testConfiguredCoreCheckoutPublishesTheRequiredApiGeneration(): void {
		$coreRoot          = getenv( 'RAN_BOOSTER_CORE_PATH' );
		$coreRoot          = false === $coreRoot || '' === $coreRoot
			? dirname( __DIR__, 3 ) . '/ran-booster'
			: rtrim( $coreRoot, '/\\' );
		$entryFile         = $coreRoot . '/ran-booster.php';
		$documentationFile = $coreRoot . '/views/documentation.php';
		$core              = file_get_contents( $entryFile ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local contract fixture.
		$documentation     = file_get_contents( $documentationFile ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local contract fixture.

		self::assertIsString( $core, 'A RAN Booster entry file is required at ' . $entryFile );
		self::assertIsString( $documentation, 'A RAN Booster documentation view is required at ' . $documentationFile );
		self::assertMatchesRegularExpression(
			"/define\\(\\s*'RAN_BOOSTER_PROVIDER_API_VERSION',\\s*8\\s*\\)/",
			$core
		);
		self::assertMatchesRegularExpression(
			"/define\\(\\s*'RAN_BOOSTER_ADDON_API_VERSION',\\s*14\\s*\\)/",
			$core
		);
		self::assertMatchesRegularExpression(
			"/define\\(\\s*'RAN_BOOSTER_ADMIN_INTERACTION_API_VERSION',\\s*2\\s*\\)/",
			$core
		);
		self::assertStringNotContainsString( 'RAN_BOOSTER_LOGGING_API_VERSION', $core );
		self::assertStringContainsString( 'ran_booster_documentation_sections_after_provider_', $documentation );
	}

	public function testCoreWorkflowPinsRequireAnExactReleaseTag(): void {
		foreach ( array( 'quality.yml', 'release-please.yml' ) as $workflowName ) {
			$workflow = file_get_contents( dirname( __DIR__, 2 ) . '/.github/workflows/' . $workflowName ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local workflow contract.
			self::assertIsString( $workflow );
			self::assertStringContainsString( self::COMPATIBLE_CORE_COMMIT, $workflow );
			self::assertStringContainsString( 'fetch-depth: 0', $workflow );
			self::assertStringContainsString( 'git describe --tags --exact-match HEAD', $workflow );
			self::assertStringContainsString( '^v[0-9]+\\.[0-9]+\\.[0-9]+(-[0-9A-Za-z.-]+)?$', $workflow );
		}
	}

	public function testPackageReleaseIsBoundToTheManifestChangingCommit(): void {
		$workflow = file_get_contents( dirname( __DIR__, 2 ) . '/.github/workflows/release-please.yml' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local workflow contract.
		self::assertIsString( $workflow );

		self::assertStringContainsString( 'skip-github-release: true', $workflow );
		self::assertStringContainsString( 'fetch-depth: 0', $workflow );
		self::assertStringContainsString( 'git diff --quiet HEAD^ HEAD -- .release-please-manifest.json && manifest_changed=false', $workflow );
		self::assertStringContainsString( 'gh api --paginate --slurp "repos/${GITHUB_REPOSITORY}/releases?per_page=100"', $workflow );
		self::assertStringContainsString( 'select(.tag_name == $tag)', $workflow );
		self::assertStringContainsString( '"$manifest_changed" == false', $workflow );
		self::assertStringContainsString( 'git log -1 --format=%H -- .release-please-manifest.json', $workflow );
		self::assertStringContainsString( "'.target_commitish'", $workflow );
		self::assertStringContainsString( 'The published release is not immutable', $workflow );
		self::assertStringContainsString( 'git checkout --detach "${RAN_RELEASE_COMMIT}"', $workflow );
		self::assertStringContainsString( 'composer validate --strict --no-check-all --no-check-publish', $workflow );
		self::assertMatchesRegularExpression(
			'/name: Check out the exact release source\s+if: env\.RAN_RELEASE_PENDING == \'true\'\s+working-directory: ran-booster-bitbucket\s+run: git checkout --detach/s',
			$workflow
		);
		self::assertStringContainsString( '--target "${RAN_RELEASE_COMMIT}"', $workflow );
		self::assertStringContainsString( 'RAN_IMMUTABLE_RELEASES_ENABLED', $workflow );
		self::assertStringContainsString( "--jq '.immutable'", $workflow );
		self::assertStringContainsString( 'for delay in 0 2 2 2 2', $workflow );
	}

	public function testPackageReleaseCommandsDoNotDependOnTheGitWorkingDirectory(): void {
		$workflow = file_get_contents( dirname( __DIR__, 2 ) . '/.github/workflows/release-please.yml' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local workflow contract.
		self::assertIsString( $workflow );

		self::assertStringContainsString( 'gh release create "${RAN_RELEASE_TAG}" --draft --target "${RAN_RELEASE_COMMIT}" --title "${RAN_RELEASE_TAG}" --generate-notes "${prerelease[@]}" --repo "${GITHUB_REPOSITORY}"', $workflow );
		self::assertStringContainsString( 'gh release download "${RAN_RELEASE_TAG}" --dir "$remote" --pattern "ran-booster-bitbucket-${RAN_RELEASE_VERSION}.zip*" --repo "${GITHUB_REPOSITORY}"', $workflow );
		self::assertStringContainsString( 'gh release edit "${RAN_RELEASE_TAG}" --draft=false "${latest[@]}" --repo "${GITHUB_REPOSITORY}"', $workflow );
		self::assertSame( 4, substr_count( $workflow, '--repo "${GITHUB_REPOSITORY}"' ) );
	}
}
