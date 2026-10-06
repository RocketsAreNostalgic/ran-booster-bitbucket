<?php

declare(strict_types=1);

namespace RAN\Booster\Bitbucket\Tests\Plugin;

use PHPUnit\Framework\TestCase;

final class PluginCompatibilityTest extends TestCase {

	public function test_plugin_header_declares_booster_as_its_native_dependency(): void {
		$plugin = file_get_contents( dirname( __DIR__, 2 ) . '/ran-booster-bitbucket.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local release metadata contract.

		self::assertIsString( $plugin );
		self::assertStringContainsString( 'Requires Plugins: ran-booster', $plugin );
		self::assertStringContainsString( 'Update URI: https://github.com/RocketsAreNostalgic/ran-booster-bitbucket', $plugin );
	}

	public function test_extension_record_matches_the_plugin_identity_and_exact_api_tuple(): void {
		$root       = dirname( __DIR__, 2 );
		$composer   = json_decode(
			(string) file_get_contents( $root . '/composer.json' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local extension record contract.
			true,
			512,
			JSON_THROW_ON_ERROR
		);
		$entrypoint = file_get_contents( $root . '/ran-booster-bitbucket.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local extension record contract.
		$plugin     = file_get_contents( $root . '/src/Bitbucket/Plugin.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local extension record contract.

		self::assertIsArray( $composer );
		self::assertIsString( $entrypoint );
		self::assertIsString( $plugin );
		self::assertSame(
			array(
				'schema'             => 1,
				'id'                 => 'ran-booster-bitbucket',
				'name'               => 'RAN Booster Bitbucket Cloud',
				'plugin-basename'    => 'ran-booster-bitbucket/ran-booster-bitbucket.php',
				'repository'         => 'https://github.com/RocketsAreNostalgic/ran-booster-bitbucket',
				'update-uri'         => 'https://github.com/RocketsAreNostalgic/ran-booster-bitbucket',
				'availability'       => 'free',
				'requires-wordpress' => '7.0',
				'requires-php'       => '8.2',
				'booster-apis'       => array(
					'required' => array(
						'provider' => 14,
						'addon'    => 17,
					),
					'optional' => array(),
				),
				'maturity'           => 'beta',
				'documentation-uri'  => 'https://github.com/RocketsAreNostalgic/ran-booster-bitbucket#readme',
				'support-uri'        => 'https://github.com/RocketsAreNostalgic/ran-booster-bitbucket/issues',
				'security-uri'       => 'https://github.com/RocketsAreNostalgic/ran-booster-bitbucket/security/policy',
				'readiness'          => 'public-release-required',
			),
			$composer['extra']['ran-booster-extension'] ?? null
		);
		self::assertStringContainsString( 'Plugin Name: RAN Booster Bitbucket Cloud', $entrypoint );
		self::assertStringContainsString( 'Update URI: https://github.com/RocketsAreNostalgic/ran-booster-bitbucket', $entrypoint );
		self::assertStringContainsString( '14 === RAN_BOOSTER_PROVIDER_API_VERSION', $plugin );
		self::assertStringContainsString( '17 === RAN_BOOSTER_ADDON_API_VERSION', $plugin );
		$security = file_get_contents( $root . '/SECURITY.md' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local public-support contract.
		self::assertIsString( $security );
		self::assertStringContainsString( 'security/advisories/new', $security );
	}

	public function test_entrypoint_boots_one_final_stateless_composition_root(): void {
		$root       = dirname( __DIR__, 2 );
		$entrypoint = file_get_contents( $root . '/ran-booster-bitbucket.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local architecture contract.
		require_once $root . '/autoload.php';
		$plugin = new \ReflectionClass( \RAN\Booster\Bitbucket\Plugin::class );

		self::assertIsString( $entrypoint );
		self::assertStringContainsString( '\\RAN\\Booster\\Bitbucket\\Plugin::boot();', $entrypoint );
		self::assertTrue( $plugin->isFinal() );
		self::assertSame( array(), $plugin->getProperties() );
	}

	public function test_release_please_owns_every_plugin_version_source(): void {
		$root     = dirname( __DIR__, 2 );
		$plugin   = file_get_contents( $root . '/ran-booster-bitbucket.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local release metadata contract.
		$readme   = file_get_contents( $root . '/readme.txt' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local release metadata contract.
		$composer = json_decode(
			(string) file_get_contents( $root . '/composer.json' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local release metadata contract.
			true,
			512,
			JSON_THROW_ON_ERROR
		);
		$manifest = json_decode(
			(string) file_get_contents( $root . '/.release-please-manifest.json' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local release metadata contract.
			true,
			512,
			JSON_THROW_ON_ERROR
		);

		self::assertIsString( $plugin );
		self::assertIsString( $readme );
		self::assertIsArray( $composer );
		self::assertIsArray( $manifest );
		self::assertMatchesRegularExpression(
			'/x-release-please-start-version\\R \\* Version: ([^\\s]+)\\R \\* x-release-please-end/',
			$plugin
		);
		self::assertMatchesRegularExpression(
			'/<!-- x-release-please-start-version -->\\RStable tag: ([^\\s]+)\\R<!-- x-release-please-end -->/',
			$readme
		);

		preg_match( '/\\* Version: ([^\\s]+)/', $plugin, $plugin_version );
		preg_match( '/Stable tag: ([^\\s]+)/', $readme, $readme_version );

		self::assertSame( $manifest['.'], $plugin_version[1] ?? null );
		self::assertSame( $manifest['.'], $readme_version[1] ?? null );
		self::assertArrayNotHasKey( 'version', $composer );
	}

	public function test_it_fails_closed_without_booster(): void {
		$result = $this->run_fixture( 'absent' );

		self::assertSame( 1, $result['provider_callbacks'] );
		self::assertSame( 1, $result['documentation_filters'] );
		self::assertSame( 0, $result['documentation_sections'] );
		self::assertSame( '', $result['documentation'] );
		self::assertFalse( $result['provider_loaded'] );
		self::assertFalse( $result['registered'] );
		self::assertStringContainsString( 'requires a compatible RAN Booster installation', $result['compatibility_notice'] );
		self::assertSame( 0, $result['remote_calls'] );
	}

	public function test_compatibility_notice_requires_plugin_activation_capability(): void {
		$result = $this->run_fixture( 'absent-unprivileged' );

		self::assertSame( '', $result['compatibility_notice'] );
		self::assertFalse( $result['registered'] );
		self::assertSame( 0, $result['remote_calls'] );
	}

	public function test_it_fails_closed_with_provider_api_thirteen_and_current_add_on_api(): void {
		$this->assert_tuple_fails_closed_in_both_load_orders( 'provider-thirteen-addon-seventeen' );
	}

	public function test_it_fails_closed_with_provider_api_twelve_and_current_add_on_api(): void {
		$this->assert_tuple_fails_closed_in_both_load_orders( 'provider-twelve-addon-seventeen' );
	}

	public function test_it_fails_closed_with_current_provider_and_immediate_old_add_on_api(): void {
		$this->assert_tuple_fails_closed_in_both_load_orders( 'provider-fourteen-addon-sixteen' );
	}

	public function test_it_fails_closed_with_the_immediate_old_provider_and_add_on_tuple(): void {
		$this->assert_tuple_fails_closed_in_both_load_orders( 'provider-eleven-addon-fifteen' );
	}

	public function test_it_fails_closed_with_newer_provider_api(): void {
		$this->assert_tuple_fails_closed_in_both_load_orders( 'provider-fifteen-addon-seventeen' );
	}

	public function test_it_fails_closed_with_newer_add_on_api(): void {
		$this->assert_tuple_fails_closed_in_both_load_orders( 'provider-fourteen-addon-eighteen' );
	}

	public function test_it_registers_only_against_provider_api_fourteen_without_claiming_optional_capabilities(): void {
		$result = $this->run_fixture( 'compatible-core-first' );

		self::assertSame( 1, $result['provider_callbacks'] );
		self::assertSame( 1, $result['documentation_sections'] );
		self::assertSame( 3, $result['admin_interaction_api_version'] );
		self::assertSame( 0, $result['admin_interaction_callbacks'] );
		self::assertTrue( $result['markers_defined_when_loaded'] );
		self::assertTrue( $result['registered'] );
		self::assertTrue( $result['provider_loaded'] );
		self::assertGreaterThan( 0, $result['implementation_load_attempts'] );
		self::assertSame( 'bb', $result['provider_code'] );
		self::assertTrue( $result['credential_store_was_scoped'] );
		self::assertTrue( $result['delivery_evidence_was_scoped'] );
		self::assertTrue( $result['owner_requires_managed_target'] );
		self::assertSame( 200, $result['navigation_slot'] );
		self::assertSame( 0, $result['credential_store_reads'] );
		self::assertSame( array_fill( 0, 5, false ), $result['implements_release_capabilities'] );
		self::assertFalse( $result['implements_webhook_fitness'] );
		self::assertFalse( $result['implements_webhook_management'] );
		self::assertSame( 1, $result['remote_calls'] );
		self::assertSame( 'example/reference-plugin', $result['operation_locator'] );
		self::assertSame( '', $result['compatibility_notice'] );
	}

	public function test_it_registers_when_the_add_on_loads_before_compatible_core_markers(): void {
		$result = $this->run_fixture( 'compatible-addon-first' );

		self::assertSame( 1, $result['provider_callbacks'] );
		self::assertSame( 1, $result['documentation_sections'] );
		self::assertSame( 3, $result['admin_interaction_api_version'] );
		self::assertSame( 0, $result['admin_interaction_callbacks'] );
		self::assertFalse( $result['markers_defined_when_loaded'] );
		self::assertTrue( $result['registered'] );
		self::assertTrue( $result['provider_loaded'] );
		self::assertGreaterThan( 0, $result['implementation_load_attempts'] );
		self::assertSame( 'bb', $result['provider_code'] );
		self::assertTrue( $result['credential_store_was_scoped'] );
		self::assertTrue( $result['delivery_evidence_was_scoped'] );
		self::assertTrue( $result['owner_requires_managed_target'] );
		self::assertSame( 200, $result['navigation_slot'] );
		self::assertSame( 0, $result['credential_store_reads'] );
		self::assertSame( array_fill( 0, 5, false ), $result['implements_release_capabilities'] );
		self::assertFalse( $result['implements_webhook_fitness'] );
		self::assertFalse( $result['implements_webhook_management'] );
		self::assertSame( 1, $result['remote_calls'] );
		self::assertSame( 'example/reference-plugin', $result['operation_locator'] );
		self::assertSame( '', $result['compatibility_notice'] );
	}

	public function test_unsupported_multisite_callbacks_stay_inert_despite_compatible_apis(): void {
		$result = $this->run_fixture( 'unsupported-multisite' );

		self::assertSame( 1, $result['provider_callbacks'] );
		self::assertSame( 0, $result['documentation_sections'] );
		self::assertSame( '', $result['documentation'] );
		self::assertFalse( $result['provider_loaded'] );
		self::assertFalse( $result['registered'] );
		self::assertFalse( $result['credential_store_was_scoped'] );
		self::assertSame( 0, $result['remote_calls'] );
		self::assertStringContainsString( 'requires a compatible RAN Booster installation', $result['compatibility_notice'] );
	}

	public function test_compatible_generation_renders_one_complete_non_interactive_guide(): void {
		$result = $this->run_fixture( 'compatible' );
		$guide  = $result['documentation'];

		self::assertIsString( $guide );
		self::assertStringNotContainsString( '<details', $guide );
		self::assertStringContainsString( 'Repositories: Read (read:repository:bitbucket)', $guide );
		self::assertStringContainsString( 'trusted credential-bearing provider code', $guide );
		self::assertStringContainsString( 'may request individual Bitbucket credentials from that namespace more than once', $guide );
		self::assertStringContainsString( 'cannot enumerate or read another provider’s credentials', $guide );
		self::assertStringContainsString( 'not confidentiality from hostile PHP', $guide );
		self::assertStringContainsString( 'Connect a package', $guide );
		self::assertStringContainsString( 'Set up Push-to-Deploy manually', $guide );
		self::assertStringContainsString( 'Move or recover a package with Transporter', $guide );
		self::assertStringContainsString( 'import the copied material, use a saved Bitbucket credential on that site, or leave its packages unchanged', $guide );
		self::assertStringContainsString( 'There is no credential or anonymous fallback', $guide );
		self::assertStringContainsString( 'does not assess token permissions', $guide );
		self::assertStringContainsString( 'does not remove, revoke or rotate the source API token', $guide );
		self::assertStringContainsString( 'Deactivation, deletion and provider cleanup', $guide );
		self::assertStringContainsString( 'Releases and support', $guide );
		self::assertStringNotContainsString( '<form', $guide );
		self::assertStringNotContainsString( '<input', $guide );
		self::assertStringNotContainsString( 'wp_nonce', $guide );
		self::assertStringNotContainsString( 'admin_post_', $guide );
	}

	public function test_deactivated_add_on_does_not_contribute_a_provider_hook(): void {
		$result = $this->run_fixture( 'inactive' );

		self::assertSame( 0, $result['provider_callbacks'] );
		self::assertSame( 0, $result['documentation_filters'] );
		self::assertSame( 0, $result['documentation_sections'] );
		self::assertSame( '', $result['documentation'] );
		self::assertFalse( $result['registered'] );
	}

	private function assert_tuple_fails_closed_in_both_load_orders( string $mode ): void {
		foreach (
			array(
				$mode                  => true,
				$mode . '-addon-first' => false,
			) as $fixture_mode => $markers_defined_when_loaded
		) {
			$result = $this->run_fixture( $fixture_mode );

			self::assertSame( 1, $result['provider_callbacks'], $fixture_mode );
			self::assertSame( 0, $result['documentation_sections'], $fixture_mode );
			self::assertSame( '', $result['documentation'], $fixture_mode );
			self::assertSame( $markers_defined_when_loaded, $result['markers_defined_when_loaded'], $fixture_mode );
			self::assertFalse( $result['provider_loaded'], $fixture_mode );
			self::assertSame( 0, $result['implementation_load_attempts'], $fixture_mode );
			self::assertFalse( $result['credential_store_was_scoped'], $fixture_mode );
			self::assertFalse( $result['delivery_evidence_was_scoped'], $fixture_mode );
			self::assertFalse( $result['registered'], $fixture_mode );
			self::assertStringContainsString( 'requires a compatible RAN Booster installation', $result['compatibility_notice'], $fixture_mode );
			self::assertSame( 0, $result['credential_store_reads'], $fixture_mode );
			self::assertSame( 0, $result['remote_calls'], $fixture_mode );
		}
	}

	/** @return array<string, bool|int|string|null|list<bool>> */
	private function run_fixture( string $mode ): array {
		$fixture = dirname( __DIR__ ) . '/fixtures/plugin-lifecycle.php';
		$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $fixture ) . ' ' . escapeshellarg( $mode );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec -- Isolated CLI contract executes repository tooling in a disposable subprocess, outside WordPress runtime.
		$output = shell_exec( $command );

		self::assertIsString( $output );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_decode_json_decode -- Fixture output is local, structured test data.
		$result = json_decode( $output, true, 512, JSON_THROW_ON_ERROR );
		self::assertIsArray( $result );

		return $result;
	}
}
