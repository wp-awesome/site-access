<?php
/**
 * A configured log that cannot be read must say so.
 *
 * This is the assertion that catches a missing precondition OUTSIDE this code — a log the web server
 * writes into a volume that is not mounted where the interpreter runs, a permission change, a path
 * that moved. Every one of those produces an empty table, and an empty table reads as "nobody has
 * been denied". Two very different states must never render the same.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const WP_AWESOME_OPTION_SCOPE           = 'test_scope';
const WP_AWESOME_OPTION_ALLOWLIST       = 'test_allowlist';
const WP_AWESOME_OPTION_TRUSTED_PROXIES = 'test_proxies';
const WP_AWESOME_OPTION_MAINTENANCE     = 'test_maintenance';
const WP_AWESOME_GATE_ACCESS_LOG_PATH   = '/run/a-volume-that-is-not-mounted-here/access.log';

require_once dirname(__DIR__) . '/src/load.php';

wpaw_assert_same('unreadable', wpaw_access_log_state(), 'a configured log that is not there is unreadable, not empty');
wpaw_assert_same([], wpaw_recent_blocked_ips(), 'and reading it still yields no rows rather than an error');

wpaw_test_reset(['test_scope' => 'website', 'test_allowlist' => '203.0.113.0/24']);
wpaw_test_set('can_manage', true);

ob_start();
wpaw_settings_render_page();
$screen = (string) ob_get_clean();

wpaw_assert_contains('This report is unavailable', $screen, 'the screen states that the report could not be produced');
wpaw_assert_contains('/run/a-volume-that-is-not-mounted-here/access.log', $screen, 'and names the path it could not read, so the cause is findable');
wpaw_assert_not_contains('No requests were denied', $screen, 'an unavailable report never claims nobody was denied');
wpaw_assert_not_contains('<th>IP address</th>', $screen, 'and never renders an empty table that reads as a clean bill of health');

wpaw_test_done('unavailable-log-test');
