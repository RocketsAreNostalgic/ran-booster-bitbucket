<?php

/** Foreign WP-CLI command used only by installed-site evidence helpers. */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Analysis-only stand-in preserves the foreign WP-CLI class used by installed-site helpers.
class WP_CLI {
	public static function success( string $message ): void {}
}
