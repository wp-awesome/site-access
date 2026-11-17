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
 * Resolves one of the seven option keys the host may rename.
 *
 * Keys are injectable because an existing installation's keys hold live settings — the allowlist
 * that gates a public site among them. Renaming a key means writing yet another migration and
 * getting it right on a security control, for no functional gain. The wire format is not the API.
 *
 * `legacy_enabled` defaults to empty, which means "this host has no legacy option to migrate from".
 *
 * `public_ajax_enabled` and `public_ajax_actions` are stored settings rather than constants because
 * they enumerate the site's own endpoints: the set changes when the theme or a plugin adds a public
 * fetch, which is a content-level operation, not a redeploy. They remain fail-closed — the section
 * is disabled by default, and empty means no exemption.
 */
function wpaw_option_key(string $name): string {
	$constants = [
		'scope'                 => ['WP_AWESOME_OPTION_SCOPE', 'wp_awesome_scope'],
		'allowlist'             => ['WP_AWESOME_OPTION_ALLOWLIST', 'wp_awesome_allowlist'],
		'trusted_proxies'       => ['WP_AWESOME_OPTION_TRUSTED_PROXIES', 'wp_awesome_trusted_proxies'],
		'maintenance'           => ['WP_AWESOME_OPTION_MAINTENANCE', 'wp_awesome_maintenance'],
		'public_ajax_enabled'   => ['WP_AWESOME_OPTION_PUBLIC_AJAX_ENABLED', 'wp_awesome_public_ajax_enabled'],
		'public_ajax_actions'   => ['WP_AWESOME_OPTION_PUBLIC_AJAX_ACTIONS', 'wp_awesome_public_ajax_actions'],
		'legacy_enabled'        => ['WP_AWESOME_OPTION_LEGACY_ENABLED', ''],
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
