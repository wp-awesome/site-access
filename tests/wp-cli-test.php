<?php
/**
 * WP-CLI is the documented recovery path from a bad allowlist. Gating it would make a lockout
 * unrecoverable without database access, so it is exempted BEFORE anything else is considered.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const WP_AWESOME_OPTION_SCOPE     = 'test_scope';
const WP_AWESOME_OPTION_ALLOWLIST = 'test_allowlist';

define('WP_CLI', true);

require_once dirname(__DIR__) . '/src/load.php';

wpaw_test_reset(['test_scope' => 'website', 'test_allowlist' => '198.51.100.0/24']);

wpaw_assert_same('wp-cli', wpaw_gate_runtime_exemption(), 'WP-CLI is exempt in its own right, ahead of the SAPI check');
wpaw_assert_same('exempt-runtime', wpaw_gate_enforce(), 'a WP-CLI invocation is never denied, whatever the allowlist says');
wpaw_assert_true(wpaw_gate_should_deny(), 'and the decision function still reports that this client would be denied over HTTP');

wpaw_test_done('wp-cli-test');
