<?php
/**
 * The host's own constant is wrong. The fallback of the fallback is `website`, never `disabled`.
 *
 * A dark site is loud and fixed in minutes; a silently ungated site leaks for as long as nobody
 * looks. For a security control, prefer the loud failure.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const WP_AWESOME_OPTION_SCOPE           = 'test_scope';
const WP_AWESOME_OPTION_ALLOWLIST       = 'test_allowlist';
const WP_AWESOME_OPTION_TRUSTED_PROXIES = 'test_proxies';
const WP_AWESOME_GATE_FALLBACK_SCOPE    = 'website-ish typo';

require_once dirname(__DIR__) . '/src/load.php';

wpaw_test_reset(['test_scope' => 'also-corrupt', 'test_allowlist' => '198.51.100.0/24']);
wpaw_assert_same('website', wpaw_fallback_scope(), 'an unusable host fallback constant resolves to website');
wpaw_assert_same('website', wpaw_scope(), 'a corrupt stored value plus a corrupt host constant still gates everything');
wpaw_assert_true(wpaw_gate_should_deny(), 'two layers of corruption cannot leave a site ungated');

// A valid stored value is still authoritative — a bad constant is not a reason to ignore the admin.
wpaw_test_reset(['test_scope' => 'disabled', 'test_allowlist' => '198.51.100.0/24']);
wpaw_assert_same('disabled', wpaw_scope(), 'a bad host constant does not override a valid stored choice');

wpaw_test_done('fallback-invalid-test');
