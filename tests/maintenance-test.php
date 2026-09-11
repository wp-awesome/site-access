<?php
/**
 * Maintenance mode. Three properties are load-bearing and each has an assertion here:
 * 503 rather than 200, an administrator is exempt, and the payload needs neither theme nor database.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const WP_AWESOME_OPTION_MAINTENANCE          = 'test_maintenance';
const WP_AWESOME_MAINTENANCE_HEADING         = 'Down for a moment';
const WP_AWESOME_MAINTENANCE_BODY            = 'We are upgrading the site.';
const WP_AWESOME_MAINTENANCE_ILLUSTRATION    = __DIR__ . '/fixtures/illustration.svg';

require_once dirname(__DIR__) . '/src/load.php';

// --- the toggle -----------------------------------------------------------

wpaw_test_reset(['test_maintenance' => '1']);
wpaw_assert_true(wpaw_maintenance_enabled(), 'the toggle is on for the literal 1');
wpaw_assert_true(wpaw_maintenance_should_serve(), 'a visitor who is not an administrator receives the screen');

wpaw_test_set('can_manage', true);
wpaw_assert_false(wpaw_maintenance_should_serve(), 'a logged-in administrator keeps seeing the live site');

foreach (['0', '', 'yes', 'true', '01'] as $stored) {
	wpaw_test_reset(['test_maintenance' => $stored]);
	wpaw_assert_false(wpaw_maintenance_enabled(), sprintf('anything other than the literal 1 is off (%s)', var_export($stored, true)));
}

wpaw_test_reset([]);
wpaw_assert_false(wpaw_maintenance_enabled(), 'maintenance mode is off by default');

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
