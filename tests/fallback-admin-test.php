<?php
/**
 * The same corruption, a different host, a different safe direction.
 *
 * A public production site declares `admin`: an unreadable stored value must lock the dashboard,
 * not the homepage. Separate process from `scope-test.php` because the fallback is a constant.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const WP_AWESOME_OPTION_SCOPE           = 'test_scope';
const WP_AWESOME_OPTION_ALLOWLIST       = 'test_allowlist';
const WP_AWESOME_OPTION_TRUSTED_PROXIES = 'test_proxies';
const WP_AWESOME_GATE_FALLBACK_SCOPE    = 'admin';

require_once dirname(__DIR__) . '/src/load.php';

wpaw_test_reset(['test_scope' => 'nonsense-from-a-restore', 'test_allowlist' => '198.51.100.0/24']);
wpaw_assert_same('admin', wpaw_scope(), 'an unrecognised stored scope resolves to this host\'s admin fallback');
wpaw_assert_false(wpaw_gate_should_deny(), 'the public frontend stays up when the stored scope is corrupt on an admin-fallback host');

wpaw_test_set('is_admin', true);
wpaw_assert_true(wpaw_gate_should_deny(), 'the dashboard is still gated when the stored scope is corrupt on an admin-fallback host');

wpaw_test_reset(['test_allowlist' => '198.51.100.0/24']);
wpaw_assert_same('admin', wpaw_scope(), 'an absent stored scope resolves to this host\'s admin fallback');

// The invariant that holds on EVERY host, whatever the fallback is.
wpaw_test_reset(['test_scope' => 'disabled ', 'test_allowlist' => '198.51.100.0/24']);
wpaw_assert_false('disabled' === wpaw_scope(), 'a value that merely looks like "disabled" never ungates a site');

wpaw_test_done('fallback-admin-test');
