<?php
/**
 * Host-declared extra login paths.
 *
 * A plugin that relocates the login form registers its `login_url` filter as an ordinary plugin,
 * which loads AFTER mu-plugins — so at enforcement time `wp_login_url()` still returns the default.
 * Closing that with a filter would be circular. Instead the host, which knows its own configuration,
 * names a callable that reads it directly and returns extra paths.
 *
 * Every failure mode here must contribute NOTHING rather than throw: this runs before WordPress
 * exists, so a fatal takes the site down instead of denying one request.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const WP_AWESOME_OPTION_SCOPE           = 'test_scope';
const WP_AWESOME_OPTION_ALLOWLIST       = 'test_allowlist';
const WP_AWESOME_OPTION_TRUSTED_PROXIES = 'test_proxies';

/**
 * The host callable. Reads its own stored configuration with a raw option read — deliberately not
 * `apply_filters()`, which is unattached at this point and is the very reason this mechanism exists.
 *
 * @return list<string>
 */
function wpaw_test_declared_login_paths(): array {
	$declared = $GLOBALS['wpaw_test_login_paths'] ?? [];

	if (is_string($declared) && 'throw' === $declared) {
		throw new RuntimeException('a host callable that explodes must not take the site down');
	}

	return $declared;
}

const WP_AWESOME_GATE_LOGIN_PATHS = 'wpaw_test_declared_login_paths';

require_once dirname(__DIR__) . '/src/load.php';

/** @param 'frontend'|'admin'|'login-default'|'custom' $surface */
function wpaw_login_paths_denies(string $surface, string $uri = '/'): bool {
	wpaw_test_reset(['test_scope' => 'admin', 'test_allowlist' => '198.51.100.0/24']);
	$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
	$_SERVER['REQUEST_URI'] = $uri;

	if ('admin' === $surface) {
		wpaw_test_set('is_admin', true);
	}

	return wpaw_gate_should_deny();
}

// --- a host-declared custom path is treated as login surface ---

$GLOBALS['wpaw_test_login_paths'] = ['/secretbase'];

wpaw_assert_true(
	wpaw_login_paths_denies('custom', '/secretbase'),
	'a host-declared login path is denied in admin scope for a blocked address'
);

wpaw_assert_true(
	wpaw_login_paths_denies('custom', '/secretbase/'),
	'a declared path matches regardless of trailing slash'
);

wpaw_assert_true(
	wpaw_login_paths_denies('custom', '/secretbase?sgs-token=secretbase'),
	'a declared path matches with a query string, which normalisation strips'
);

wpaw_assert_true(
	wpaw_login_paths_denies('login-default', '/wp-login.php'),
	'declaring an extra path does not stop the derived default login path from being denied'
);

wpaw_assert_true(wpaw_login_paths_denies('admin'), 'the dashboard is still denied');

wpaw_assert_same(
	false,
	wpaw_login_paths_denies('frontend', '/about'),
	'the public frontend stays public in admin scope, which is the whole point of the scope'
);

wpaw_assert_same(
	false,
	wpaw_login_paths_denies('custom', '/secretbase-elsewhere'),
	'matching is exact, never a prefix — a declared path must not gate its neighbours'
);

// --- normalisation of what the host hands back ---

$GLOBALS['wpaw_test_login_paths'] = ['secretbase'];
wpaw_assert_true(
	wpaw_login_paths_denies('custom', '/secretbase'),
	'a declared path without a leading slash is normalised rather than ignored'
);

$GLOBALS['wpaw_test_login_paths'] = ['/one', '/two'];
wpaw_assert_true(wpaw_login_paths_denies('custom', '/one'), 'the first of several declared paths matches');
wpaw_assert_true(wpaw_login_paths_denies('custom', '/two'), 'the second of several declared paths matches');

// --- every malformed shape contributes NOTHING and never throws ---

foreach ([
	'not an array'                 => 'secretbase',
	'null'                         => null,
	'a list of non-strings'        => [42, false, null],
	'an empty string'              => [''],
	'whitespace only'              => ['   '],
	'the site root'                => ['/'],
	'a callable that throws'       => 'throw',
] as $label => $declared) {
	$GLOBALS['wpaw_test_login_paths'] = $declared;

	wpaw_assert_same(
		false,
		wpaw_login_paths_denies('frontend', '/about'),
		sprintf('%s contributes no extra paths and leaves the frontend public', $label)
	);

	wpaw_assert_true(
		wpaw_login_paths_denies('admin'),
		sprintf('%s leaves the dashboard denied — failing safe never means failing open', $label)
	);
}

// The site root is refused specifically because accepting it would turn the entire frontend into
// login surface, converting admin scope into website scope through a configuration typo.
$GLOBALS['wpaw_test_login_paths'] = ['/'];
wpaw_assert_same(
	false,
	wpaw_login_paths_denies('custom', '/'),
	'declaring the site root is refused rather than gating every page'
);

wpaw_test_done('login-paths-test');
