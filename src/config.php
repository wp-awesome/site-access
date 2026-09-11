<?php
/**
 * Host configuration.
 *
 * Everything site-specific enters through a constant the HOST defines before requiring the entry
 * point. Nothing here reads a database row to decide how the package is configured — configuration
 * is code, so it is reviewed, versioned and impossible to change from the admin screen.
 *
 * Every constant is optional and every default is neutral, so a host that defines nothing still
 * gets a working, safe plugin under its own generic option keys.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Resolves one of the five option keys the host may rename.
 *
 * Keys are injectable because an existing installation's keys hold live settings — the allowlist
 * that gates a public site among them. Renaming a key means writing yet another migration and
 * getting it right on a security control, for no functional gain. The wire format is not the API.
 *
 * `legacy_enabled` defaults to empty, which means "this host has no legacy option to migrate from".
 */
function wpaw_option_key(string $name): string {
	$constants = [
		'scope'           => ['WP_AWESOME_OPTION_SCOPE', 'wp_awesome_scope'],
		'allowlist'       => ['WP_AWESOME_OPTION_ALLOWLIST', 'wp_awesome_allowlist'],
		'trusted_proxies' => ['WP_AWESOME_OPTION_TRUSTED_PROXIES', 'wp_awesome_trusted_proxies'],
		'maintenance'     => ['WP_AWESOME_OPTION_MAINTENANCE', 'wp_awesome_maintenance'],
		'legacy_enabled'  => ['WP_AWESOME_OPTION_LEGACY_ENABLED', ''],
	];

	if (! isset($constants[$name])) {
		return '';
	}

	[$constant, $default] = $constants[$name];

	return trim(defined($constant) ? (string) constant($constant) : $default);
}

/**
 * Reads a host string constant, falling back to a neutral default.
 */
function wpaw_setting(string $constant, string $default = ''): string {
	return defined($constant) ? (string) constant($constant) : $default;
}

/**
 * Reads a host boolean constant, falling back to a neutral default.
 */
function wpaw_flag(string $constant, bool $default): bool {
	return defined($constant) ? (bool) constant($constant) : $default;
}
