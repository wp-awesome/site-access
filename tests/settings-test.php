<?php
/**
 * The single settings screen: what it saves, what it refuses to save, and what it renders.
 *
 * The refusals matter more than the saves. Every one of them is a way an administrator could lock
 * themselves out of a machine they have no console access to.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const WP_AWESOME_OPTION_SCOPE           = 'test_scope';
const WP_AWESOME_OPTION_ALLOWLIST       = 'test_allowlist';
const WP_AWESOME_OPTION_TRUSTED_PROXIES = 'test_proxies';
const WP_AWESOME_OPTION_MAINTENANCE     = 'test_maintenance';

$log = tempnam(sys_get_temp_dir(), 'wpaw-log-');
file_put_contents($log, sprintf(
	"198.51.100.77 - - [%s] \"GET / HTTP/1.1\" 403 153 \"-\" \"Mozilla/5.0\"\n",
	gmdate('d/M/Y:H:i:s O', time() - 3600)
));
define('WP_AWESOME_GATE_ACCESS_LOG_PATH', $log);

require_once dirname(__DIR__) . '/src/load.php';

// --- registration ---------------------------------------------------------

wpaw_test_reset([]);
wpaw_settings_register();

$registered = wpaw_test_get('settings')['options'];
wpaw_assert_same(
	['test_scope', 'test_allowlist', 'test_proxies', 'test_maintenance'],
	array_keys($registered),
	'all four controls are registered, under the host\'s own option keys'
);
wpaw_assert_same(
	1,
	count(array_unique(array_column($registered, 'group'))),
	'every control belongs to the same settings group, so one Save button saves the screen'
);

wpaw_settings_menu();
wpaw_assert_same('manage_options', wpaw_test_get('settings')['page']['capability'], 'the screen requires manage_options');

// --- the scope control ----------------------------------------------------

foreach (['disabled', 'website', 'admin'] as $scope) {
	wpaw_test_reset(['test_scope' => 'website']);
	wpaw_assert_same($scope, wpaw_sanitize_scope($scope), sprintf('%s is an accepted scope', $scope));
}

wpaw_test_reset(['test_scope' => 'website']);
wpaw_assert_same('website', wpaw_sanitize_scope('nonsense'), 'a value outside the three is refused and the stored scope is kept');
wpaw_assert_same(1, count(wpaw_test_get('settings_errors')), 'and the refusal is reported to the administrator');

wpaw_test_reset(['test_scope' => 'corrupt']);
wpaw_assert_same('website', wpaw_sanitize_scope('nonsense'), 'refusing a save on an already-corrupt option keeps the resolved fallback, not the corruption');

// --- the maintenance control ----------------------------------------------

wpaw_test_reset([]);
wpaw_assert_same('1', wpaw_sanitize_toggle('1'), 'the maintenance checkbox stores 1 when ticked');
wpaw_assert_same('0', wpaw_sanitize_toggle('on'), 'anything else stores 0');
wpaw_assert_same('0', wpaw_sanitize_toggle(''), 'an unticked checkbox stores 0');

// --- the allowlist --------------------------------------------------------

wpaw_test_reset(['test_allowlist' => '203.0.113.9/32']);
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$_POST                  = ['test_scope' => 'website'];
wpaw_assert_same('203.0.113.9/32', wpaw_sanitize_allowlist('198.51.100.0/24'), 'a save that would lock the administrator out is refused');
wpaw_assert_same('lockout', wpaw_test_get('settings_errors')[0]['code'], 'and the refusal names the reason');

wpaw_test_reset(['test_allowlist' => '203.0.113.9/32']);
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$_POST                  = ['test_scope' => 'admin'];
wpaw_assert_same('203.0.113.9/32', wpaw_sanitize_allowlist('198.51.100.0/24'), 'the lockout guard applies in admin scope too — losing the dashboard is the same lockout');

wpaw_test_reset(['test_allowlist' => '203.0.113.9/32']);
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$_POST                  = ['test_scope' => 'website', 'wp_awesome_force_save' => '1'];
wpaw_assert_same('198.51.100.0/24', wpaw_sanitize_allowlist('198.51.100.0/24'), 'the override saves a list the administrator is not on, deliberately');

wpaw_test_reset(['test_allowlist' => '203.0.113.9/32']);
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$_POST                  = ['test_scope' => 'disabled'];
wpaw_assert_same('198.51.100.0/24', wpaw_sanitize_allowlist('198.51.100.0/24'), 'no lockout is possible while the scope is disabled, so the guard does not fire');

wpaw_test_reset(['test_allowlist' => '203.0.113.9/32']);
$_POST = ['test_scope' => 'website'];
wpaw_assert_same('203.0.113.9/32', wpaw_sanitize_allowlist("203.0.113.9/32\nnot-an-ip"), 'one invalid entry refuses the whole save');
wpaw_assert_same('bad_entry', wpaw_test_get('settings_errors')[0]['code'], 'and says which entry');

wpaw_test_reset([]);
$_POST = ['test_scope' => 'disabled'];
wpaw_assert_same(
	"# Office\n203.0.113.3/32 # office inline\n# Client\n203.0.113.20 # client inline\n# Trailing note",
	wpaw_sanitize_allowlist("# Client\\n203.0.113.20 # client inline\n# Office\n203.0.113.3/32 # office inline\n\n# Trailing note"),
	'a save normalises literal newlines, sorts entries, and keeps attached and inline comments'
);

// --- trusted proxies ------------------------------------------------------

wpaw_test_reset(['test_proxies' => '10.0.0.0/24']);
wpaw_assert_same('10.0.0.0/24', wpaw_sanitize_proxies('nonsense'), 'an invalid trusted proxy refuses the save');
wpaw_assert_same('bad_proxy', wpaw_test_get('settings_errors')[0]['code'], 'and says so');

wpaw_test_reset(['test_proxies' => '']);
wpaw_assert_same('10.0.0.0/24', wpaw_sanitize_proxies('10.0.0.0/24'), 'a valid trusted proxy saves');

// --- the screen -----------------------------------------------------------

wpaw_test_reset(['test_scope' => 'website', 'test_allowlist' => '203.0.113.0/24']);

ob_start();
wpaw_settings_render_page();
wpaw_assert_same('', (string) ob_get_clean(), 'a user without manage_options renders nothing at all');

wpaw_test_set('can_manage', true);

ob_start();
wpaw_settings_render_page();
$screen = (string) ob_get_clean();

wpaw_assert_contains('name="test_scope"', $screen, 'the scope control posts under the host\'s option key');
wpaw_assert_contains('value="disabled"', $screen, 'the scope control offers disabled');
wpaw_assert_contains('value="website"', $screen, 'the scope control offers website');
wpaw_assert_contains('value="admin"', $screen, 'the scope control offers admin');
wpaw_assert_contains('name="test_maintenance"', $screen, 'the maintenance checkbox is on the same screen');
wpaw_assert_contains('name="test_allowlist"', $screen, 'the allowlist textarea is on the same screen');
wpaw_assert_contains('name="test_proxies"', $screen, 'the trusted-proxy textarea is on the same screen');
wpaw_assert_contains('name="wp_awesome_force_save"', $screen, 'the self-lockout override is on the same screen');
wpaw_assert_contains('198.51.100.77', $screen, 'a recently blocked address is listed');
wpaw_assert_contains('wpawAddAddress(\'198.51.100.77\')', $screen, 'and can be added to the allowlist with one click');
wpaw_assert_not_contains('Allow public access', $screen, 'the old public-access checkbox is gone, replaced by the scope control');

wpaw_test_reset(['test_scope' => 'corrupt-value', 'test_allowlist' => '203.0.113.0/24']);
wpaw_test_set('can_manage', true);
ob_start();
wpaw_settings_render_page();
$screen = (string) ob_get_clean();
wpaw_assert_contains('corrupt-value', $screen, 'an unrecognised stored scope is surfaced rather than hidden behind the fallback');

unlink($log);

wpaw_test_done('settings-test');
