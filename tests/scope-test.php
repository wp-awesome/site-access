<?php
/**
 * The three-value blocking scope, under the default host fallback (`website`).
 *
 * Each surface is exercised as a REQUEST SHAPE, never as a literal path: `is_admin()` for the
 * dashboard and a path derived from `wp_login_url()` for the login form.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const WP_AWESOME_OPTION_SCOPE           = 'test_scope';
const WP_AWESOME_OPTION_ALLOWLIST       = 'test_allowlist';
const WP_AWESOME_OPTION_TRUSTED_PROXIES = 'test_proxies';

require_once dirname(__DIR__) . '/src/load.php';

/**
 * @param 'frontend'|'admin'|'login'|'ajax' $surface
 */
function wpaw_test_request(string $scope, string $surface, string $allowlist = '198.51.100.0/24', string $remote = '203.0.113.9'): bool {
	wpaw_test_reset(['test_scope' => $scope, 'test_allowlist' => $allowlist, 'test_proxies' => '']);

	$_SERVER['REMOTE_ADDR'] = $remote;
	$_SERVER['REQUEST_URI'] = '/about-us/';

	if ('admin' === $surface || 'ajax' === $surface) {
		wpaw_test_set('is_admin', true);
		$_SERVER['REQUEST_URI'] = '/wp-admin/index.php';
	}

	if ('ajax' === $surface) {
		wpaw_test_set('doing_ajax', true);
		$_SERVER['REQUEST_URI'] = '/wp-admin/admin-ajax.php';
	}

	if ('login' === $surface) {
		$_SERVER['REQUEST_URI'] = '/wp-login.php?redirect_to=%2Fwp-admin%2F';
	}

	return wpaw_gate_should_deny();
}

// --- website: the whole HTTP surface --------------------------------------

wpaw_assert_true(wpaw_test_request('website', 'frontend'), 'website scope denies the frontend');
wpaw_assert_true(wpaw_test_request('website', 'admin'), 'website scope denies the dashboard');
wpaw_assert_true(wpaw_test_request('website', 'login'), 'website scope denies the login form');
wpaw_assert_true(wpaw_test_request('website', 'ajax'), 'website scope has no AJAX hole and must never grow one');

// --- admin: dashboard and login only --------------------------------------

wpaw_assert_false(wpaw_test_request('admin', 'frontend'), 'admin scope leaves the public frontend public');
wpaw_assert_true(wpaw_test_request('admin', 'admin'), 'admin scope denies the dashboard');
wpaw_assert_true(wpaw_test_request('admin', 'login'), 'admin scope denies the login form');
wpaw_assert_false(wpaw_test_request('admin', 'ajax'), 'admin scope exempts AJAX, which the public frontend shares');

// --- disabled: nothing at all ---------------------------------------------

wpaw_assert_false(wpaw_test_request('disabled', 'frontend'), 'disabled scope denies nothing on the frontend');
wpaw_assert_false(wpaw_test_request('disabled', 'admin'), 'disabled scope denies nothing in the dashboard');
wpaw_assert_false(wpaw_test_request('disabled', 'login'), 'disabled scope denies nothing at login');

// --- an allowlisted client passes everywhere ------------------------------

foreach (['website', 'admin', 'disabled'] as $scope) {
	foreach (['frontend', 'admin', 'login', 'ajax'] as $surface) {
		wpaw_assert_false(
			wpaw_test_request($scope, $surface, '203.0.113.0/24'),
			sprintf('an allowlisted client passes %s scope on the %s surface', $scope, $surface)
		);
	}
}

// --- the fail-safe --------------------------------------------------------

foreach (['website', 'admin'] as $scope) {
	foreach (['frontend', 'admin', 'login'] as $surface) {
		wpaw_assert_false(
			wpaw_test_request($scope, $surface, ''),
			sprintf('an empty allowlist restricts nothing in %s scope on the %s surface', $scope, $surface)
		);
		wpaw_assert_false(
			wpaw_test_request($scope, $surface, "# only a comment\n\n"),
			sprintf('an allowlist of comments and blanks restricts nothing in %s scope on the %s surface', $scope, $surface)
		);
	}
}

// --- the login path is derived, never hardcoded ---------------------------

wpaw_test_reset(['test_scope' => 'admin', 'test_allowlist' => '198.51.100.0/24']);
wpaw_test_set('login_url', 'https://example.test/not-the-usual-login/');
$_SERVER['REQUEST_URI'] = '/not-the-usual-login/';
wpaw_assert_true(wpaw_gate_should_deny(), 'a relocated login form is denied because the path comes from wp_login_url()');

$_SERVER['REQUEST_URI'] = '/wp-login.php';
wpaw_assert_false(wpaw_gate_should_deny(), 'a path that is not the site login form is treated as frontend');

wpaw_test_reset(['test_scope' => 'admin', 'test_allowlist' => '198.51.100.0/24']);
wpaw_test_set('login_url', 'https://example.test/wordpress/wp-login.php');
$_SERVER['REQUEST_URI'] = '/wordpress/wp-login.php';
wpaw_assert_true(wpaw_gate_should_deny(), 'a subdirectory install\'s login path is matched with its prefix');

// --- an unrecognised or absent stored value resolves to the host fallback ---

wpaw_test_reset(['test_scope' => 'nonsense-from-a-restore', 'test_allowlist' => '198.51.100.0/24']);
wpaw_assert_same('website', wpaw_scope(), 'an unrecognised stored scope resolves to the host fallback, never to disabled');
wpaw_assert_true(wpaw_gate_should_deny(), 'an unrecognised stored scope still gates the frontend under a website fallback');

wpaw_test_reset(['test_allowlist' => '198.51.100.0/24']);
wpaw_assert_same('website', wpaw_scope(), 'an absent stored scope resolves to the host fallback');

wpaw_test_reset(['test_scope' => '', 'test_allowlist' => '198.51.100.0/24']);
wpaw_assert_same('website', wpaw_scope(), 'an empty stored scope resolves to the host fallback');

wpaw_test_reset(['test_scope' => 'WEBSITE', 'test_allowlist' => '198.51.100.0/24']);
wpaw_assert_same('website', wpaw_scope(), 'scope values are compared exactly; a near-miss is treated as corruption');

wpaw_test_done('scope-test');
