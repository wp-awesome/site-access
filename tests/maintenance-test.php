<?php
/**
 * Maintenance mode. Three properties are load-bearing and each has an assertion here:
 * 503 rather than 200, every frontend request is intercepted including an administrator's, and the
 * payload needs neither theme nor database.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const WP_AWESOME_OPTION_MAINTENANCE          = 'test_maintenance';
const WP_AWESOME_MAINTENANCE_HEADING         = 'Down for a moment';
const WP_AWESOME_MAINTENANCE_BODY            = 'We are upgrading the site.';
const WP_AWESOME_MAINTENANCE_ILLUSTRATION    = __DIR__ . '/fixtures/illustration.svg';

/**
 * This host declares one relocated login path, so the single exemption the screen allows is exercised
 * the way a real host supplies it rather than through a special case invented for the test.
 *
 * @return list<string>
 */
function wpaw_test_maintenance_login_paths(): array {
	$GLOBALS['wpaw_test_maint_login_path_calls'] = ($GLOBALS['wpaw_test_maint_login_path_calls'] ?? 0) + 1;

	return $GLOBALS['wpaw_test_maint_login_paths'] ?? [];
}

const WP_AWESOME_GATE_LOGIN_PATHS = 'wpaw_test_maintenance_login_paths';

$GLOBALS['wpaw_test_maint_login_paths'] = ['/secretbase'];

require_once dirname(__DIR__) . '/src/load.php';

// --- the toggle -----------------------------------------------------------

wpaw_test_reset(['test_maintenance' => '1']);
$_SERVER['REQUEST_URI'] = '/about';
wpaw_assert_true(wpaw_maintenance_enabled(), 'the toggle is on for the literal 1');
wpaw_assert_true(wpaw_maintenance_should_serve(), 'a visitor receives the screen on a frontend page');

wpaw_test_set('can_manage', true);
wpaw_assert_true(
	wpaw_maintenance_should_serve(),
	'a logged-in administrator receives the same screen, so the site is closed for whoever closed it'
);

foreach (['0', '', 'yes', 'true', '01'] as $stored) {
	wpaw_test_reset(['test_maintenance' => $stored]);
	wpaw_assert_false(wpaw_maintenance_enabled(), sprintf('anything other than the literal 1 is off (%s)', var_export($stored, true)));
}

wpaw_test_reset([]);
$_SERVER['REQUEST_URI'] = '/about';
wpaw_test_set('can_manage', true);
wpaw_assert_false(wpaw_maintenance_enabled(), 'maintenance mode is off by default');
wpaw_assert_false(wpaw_maintenance_should_serve(), 'with the toggle off nothing is intercepted, administrators included');

// An open site must behave exactly as it did before this policy existed, which includes not asking the
// host anything: the login surface is consulted only after the toggle says maintenance is on.
$GLOBALS['wpaw_test_maint_login_path_calls'] = 0;
wpaw_maintenance_should_serve();
wpaw_assert_same(
	0,
	$GLOBALS['wpaw_test_maint_login_path_calls'],
	'with maintenance off the login surface is not consulted at all, so a slow or broken host callable cannot affect an open site'
);

// --- the login surface is the only exemption ------------------------------
//
// The dashboard and the default login form never reach `template_redirect`, so a standard host needs
// no exemption. A host whose login form has MOVED to a frontend path does: replacing it with the
// screen leaves nobody able to sign in and switch maintenance off. The package already has one
// narrow way to declare that surface, and the screen reuses it rather than growing a URI heuristic.

wpaw_test_reset(['test_maintenance' => '1']);
$_SERVER['REQUEST_URI'] = '/wp-login.php';
wpaw_assert_false(wpaw_maintenance_should_serve(), 'the default login form is never replaced by the screen, so signing in stays possible');

wpaw_test_set('can_manage', true);
wpaw_assert_false(wpaw_maintenance_should_serve(), 'and it is not replaced for an administrator either — the way back in is the same for everyone');

$_SERVER['REQUEST_URI'] = '/secretbase?redirect_to=%2Fwp-admin%2F';
wpaw_assert_false(wpaw_maintenance_should_serve(), 'a host-declared login path is never replaced by the screen, query string included');

$_SERVER['REQUEST_URI'] = '/secretbase-elsewhere';
wpaw_assert_true(wpaw_maintenance_should_serve(), 'the login exemption is exact, never a prefix — a neighbouring page still receives the screen');

// The root is refused by the declared-path mechanism itself, which is what stops a configuration typo
// from exempting the entire frontend from maintenance mode.
$GLOBALS['wpaw_test_maint_login_paths'] = ['/'];
$_SERVER['REQUEST_URI'] = '/';
wpaw_assert_true(wpaw_maintenance_should_serve(), 'declaring the site root is refused, so the whole frontend can never be exempted');

$GLOBALS['wpaw_test_maint_login_paths'] = [];

// --- 503, never 200 -------------------------------------------------------

$headers = wpaw_maintenance_headers();
wpaw_assert_same('HTTP/1.1 503 Service Unavailable', $headers[0], 'the status line is 503, so a crawler never treats the screen as the real page');
wpaw_assert_contains('Retry-After: ', implode("\n", $headers), 'Retry-After tells a crawler this is temporary');
wpaw_assert_contains('Cache-Control: no-store', implode("\n", $headers), 'the screen is never cached');
wpaw_assert_not_contains('200', implode("\n", $headers), 'no 200 anywhere in the response headers');

// --- the payload needs no theme and no database ---------------------------

wpaw_test_reset(['test_maintenance' => '1']);
wpaw_test_set('option_guard', true);

$payload = wpaw_maintenance_render();

wpaw_test_set('option_guard', false);

wpaw_assert_contains('<!DOCTYPE html>', $payload, 'the payload is a complete document');
wpaw_assert_contains('Down for a moment', $payload, 'the host supplies the heading');
wpaw_assert_contains('We are upgrading the site.', $payload, 'the host supplies the body copy');
wpaw_assert_contains('<svg', $payload, 'the host illustration is inlined from the path it supplied');
wpaw_assert_contains('noindex', $payload, 'the payload asks not to be indexed as well as returning 503');
wpaw_assert_contains('<style>', $payload, 'every rule the screen needs travels inside the payload');
wpaw_assert_not_contains('wp-content', $payload, 'the payload references no theme asset');
wpaw_assert_not_contains('<link', $payload, 'the payload enqueues no stylesheet');

// The harness defines no theme functions at all, so reaching for one would already have been fatal.
// This states the remaining half: it did not read the database either.
wpaw_test_set('option_guard', true);
wpaw_maintenance_render();
wpaw_test_set('option_guard', false);

// --- a missing illustration degrades, never fatals -------------------------

wpaw_assert_same('', wpaw_maintenance_illustration('/nowhere/illustration.svg'), 'an unreadable illustration degrades to nothing');

// --- neutral defaults ------------------------------------------------------

wpaw_assert_not_contains('YKPS', $payload, 'the shared package carries no host branding of its own');

wpaw_test_done('maintenance-test');
