<?php
/**
 * Where and when enforcement runs, and what the boot actually wires up.
 *
 * The `exit()` path itself is proved out-of-process by `fixtures/deny.php`, because a test that
 * called it in-process would end the test run.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const WP_AWESOME_OPTION_SCOPE           = 'test_scope';
const WP_AWESOME_OPTION_ALLOWLIST       = 'test_allowlist';
const WP_AWESOME_OPTION_TRUSTED_PROXIES = 'test_proxies';
const WP_AWESOME_OPTION_MAINTENANCE     = 'test_maintenance';
const WP_AWESOME_OPTION_LEGACY_ENABLED  = 'test_legacy';

wpaw_test_reset(['test_scope' => '0', 'test_allowlist' => '198.51.100.0/24']);
wpaw_test_set('is_admin', true);

require_once dirname(__DIR__) . '/site-access.php';

// --- the entry point wires every feature ----------------------------------

$report = wpaw_boot();

wpaw_assert_same('website', wpaw_test_get('options')['test_scope'], 'requiring the entry point migrated the stored option');
wpaw_assert_same('website', $report['scope'], 'the boot report names the scope in force');
wpaw_assert_same('exempt-runtime', $report['gate'], 'the gate ran and exempted this CLI process rather than being skipped entirely');
wpaw_assert_true($report['maintenance'], 'the boot wired maintenance mode');
wpaw_assert_true($report['settings'], 'the boot wired the settings screen');

$hooks = array_column(wpaw_test_get('actions'), 'hook');
wpaw_assert_true(in_array('template_redirect', $hooks, true), 'maintenance enforcement is hung on template_redirect');
wpaw_assert_true(in_array('admin_menu', $hooks, true), 'the settings page is registered');
wpaw_assert_true(in_array('admin_init', $hooks, true), 'the settings are registered');

foreach (wpaw_test_get('actions') as $action) {
	if ('template_redirect' === $action['hook']) {
		wpaw_assert_same(1, $action['priority'], 'maintenance runs ahead of anything else on template_redirect');
	}
}

wpaw_assert_same($report, wpaw_boot(), 'booting twice reports the same thing and does not wire anything twice');
wpaw_assert_same(count($hooks), count(wpaw_test_get('actions')), 'booting twice registers no additional hooks');

// --- a host with no access log at all -------------------------------------

wpaw_assert_same('not-configured', wpaw_access_log_state(), 'a host that points at no log gets no report section rather than a broken one');

// --- the runtime exemption ------------------------------------------------

wpaw_assert_same('cli-sapi', wpaw_gate_runtime_exemption(), 'a CLI process is never gated');

// --- the environment does NOT decide anything; scope is the sole authority ---
//
// There was once a WP_AWESOME_GATE_PRODUCTION_NOOP constant making the gate inert on production.
// It was removed deliberately. A settings page offering three modes must mean three modes: an
// administrator choosing `website` and silently getting no gating is the product lying to its
// operator, and no notice can fix that because the operator cannot change a constant. Cross-
// environment drift belongs in a deploy check, which fails loudly at deploy, rather than in a
// runtime override that disables the control forever and invisibly.

foreach (['production', 'staging', 'development', 'local'] as $environment) {
	wpaw_test_reset(['test_scope' => 'website', 'test_allowlist' => '198.51.100.0/24']);
	wpaw_test_set('environment', $environment);
	$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
	$_SERVER['REQUEST_URI'] = '/';

	wpaw_assert_true(
		wpaw_gate_should_deny(),
		sprintf('website scope gates a blocked address on %s, because scope alone decides', $environment)
	);

	wpaw_test_reset(['test_scope' => 'disabled', 'test_allowlist' => '198.51.100.0/24']);
	wpaw_test_set('environment', $environment);
	$_SERVER['REMOTE_ADDR'] = '203.0.113.9';

	wpaw_assert_same(
		false,
		wpaw_gate_should_deny(),
		sprintf('disabled scope gates nothing on %s, which is how an operator turns it off', $environment)
	);
}

// --- the denial itself ----------------------------------------------------

$headers = wpaw_gate_deny_headers();
wpaw_assert_same('HTTP/1.1 403 Forbidden', $headers[0], 'a denied request receives 403');
wpaw_assert_contains('Cache-Control: no-store', implode("\n", $headers), 'a denial is never cached');

$output = [];
$status = 0;
exec(sprintf('%s %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg(__DIR__ . '/fixtures/deny.php')), $output, $status);

wpaw_assert_same(0, $status, 'the denial exits cleanly rather than fataling');
wpaw_assert_same(['403 Forbidden'], $output, 'the denial emits a bare plain-text body and nothing else');

wpaw_test_done('enforcement-test');
