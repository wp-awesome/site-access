<?php
/**
 * wp-awesome — one gate, one maintenance screen, one settings page.
 *
 * The entry point a host requires from its own must-use plugin. It is NOT itself a must-use plugin:
 * WordPress does not recurse into subdirectories of `mu-plugins`, so a directory placed there is
 * invisible to it and nothing in this file would ever run. See the README — that failure mode is
 * completely silent.
 *
 * The host defines its constants BEFORE requiring this file. Every one of them is optional; see
 * `src/config.php` for the list and the defaults.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

require_once __DIR__ . '/src/load.php';

/**
 * Migrates, enforces, and wires the admin surface — in that order, at mu-plugin load.
 *
 * Returns a report of what it did, and memoizes it, for two reasons. A host's stub failing to load
 * this file is SILENT — no error, no warning, just an ungated site — so a consuming project needs
 * something behavioural to assert in its own test suite. And memoizing makes a double `require`
 * harmless rather than a second set of hooks.
 *
 * @return array{scope: string, gate: string, maintenance: bool, settings: bool}
 */
function wpaw_boot(): array {
	static $report = null;

	if (null !== $report) {
		return $report;
	}

	// Before any enforcement decision: an install on an older schema must not be read with the new
	// meaning of its stored value.
	wpaw_migrate_scope();

	$report = [
		'scope'       => wpaw_scope(),
		// Ends the request when it denies. Everything after this line runs only for a request that
		// was allowed through.
		'gate'        => wpaw_gate_enforce(),
		'maintenance' => wpaw_maintenance_bootstrap(),
		'settings'    => is_admin() ? wpaw_settings_bootstrap() : false,
	];

	return $report;
}

wpaw_boot();
