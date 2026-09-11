<?php
/**
 * Scheduled work runs as an HTTP request on many hosts. Gating it would silently break every
 * scheduled job the moment an allowlist was populated.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const WP_AWESOME_OPTION_SCOPE     = 'test_scope';
const WP_AWESOME_OPTION_ALLOWLIST = 'test_allowlist';

define('DOING_CRON', true);

require_once dirname(__DIR__) . '/src/load.php';

wpaw_test_reset(['test_scope' => 'website', 'test_allowlist' => '198.51.100.0/24']);

wpaw_assert_same('cron', wpaw_gate_runtime_exemption(), 'cron is exempt in its own right');
wpaw_assert_same('exempt-runtime', wpaw_gate_enforce(), 'a cron run is never denied');

wpaw_test_done('cron-test');
