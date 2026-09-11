<?php
/**
 * Client-identity behaviour. SECURITY CRITICAL: every assertion here describes a way the allowlist
 * could be bypassed if the implementation drifted.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const WP_AWESOME_OPTION_SCOPE           = 'test_scope';
const WP_AWESOME_OPTION_ALLOWLIST       = 'test_allowlist';
const WP_AWESOME_OPTION_TRUSTED_PROXIES = 'test_proxies';

require_once dirname(__DIR__) . '/src/load.php';

// --- range matching -------------------------------------------------------

wpaw_assert_true(wpaw_ip_in_range('203.0.113.9', '203.0.113.0/24'), 'IPv4 CIDR match');
wpaw_assert_false(wpaw_ip_in_range('203.0.114.9', '203.0.113.0/24'), 'IPv4 CIDR mismatch');
wpaw_assert_true(wpaw_ip_in_range('2001:db8::9', '2001:db8::/32'), 'IPv6 CIDR match');
wpaw_assert_false(wpaw_ip_in_range('2001:db9::9', '2001:db8::/32'), 'IPv6 CIDR mismatch');
wpaw_assert_true(wpaw_ip_in_range('203.0.113.9', '203.0.113.9'), 'bare IPv4 equality');
wpaw_assert_false(wpaw_ip_in_range('192.168.1.1', '192.168.1.10/32'), 'a string prefix is not a subnet');
wpaw_assert_false(wpaw_ip_in_range('203.0.113.9', '2001:db8::/32'), 'an IPv4 address is never inside an IPv6 range');
wpaw_assert_false(wpaw_ip_in_range('203.0.113.9', '203.0.113.0/33'), 'an out-of-range prefix length never matches');

// --- list parsing ---------------------------------------------------------

wpaw_assert_same(
	['203.0.113.1', '2001:db8::/32'],
	wpaw_parse_list("203.0.113.1\\n# comment\n\n2001:db8::/32 # office"),
	'literal and real newlines, comments, and blanks are parsed safely'
);

wpaw_assert_true(wpaw_is_valid_entry('203.0.113.4'), 'a bare IPv4 address is a valid entry');
wpaw_assert_true(wpaw_is_valid_entry('2001:db8::/32'), 'an IPv6 CIDR is a valid entry');
wpaw_assert_false(wpaw_is_valid_entry('not-an-ip'), 'garbage is not a valid entry');
wpaw_assert_false(wpaw_is_valid_entry('203.0.113.0/64'), 'an IPv4 prefix longer than 32 bits is not a valid entry');

// --- client identity ------------------------------------------------------

wpaw_test_reset(['test_proxies' => '']);
$_SERVER = ['REMOTE_ADDR' => '198.51.100.9', 'HTTP_X_FORWARDED_FOR' => '203.0.113.8'];
wpaw_assert_same('198.51.100.9', wpaw_client_ip(), 'a forged X-Forwarded-For cannot move the client IP when no proxy is trusted');

wpaw_test_reset(['test_proxies' => "10.0.0.0/24\n"]);
$_SERVER = ['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '203.0.113.8, 10.0.0.1'];
wpaw_assert_same('203.0.113.8', wpaw_client_ip(), 'the rightmost non-proxy forwarded entry is selected');

wpaw_test_reset(['test_proxies' => "10.0.0.0/24\n"]);
$_SERVER = ['REMOTE_ADDR' => '198.51.100.9', 'HTTP_X_FORWARDED_FOR' => '203.0.113.8'];
wpaw_assert_same('198.51.100.9', wpaw_client_ip(), 'a forwarded header from a peer that is not a trusted proxy is ignored');

wpaw_test_reset(['test_proxies' => "10.0.0.0/24\n"]);
$_SERVER = ['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '10.0.0.7, 10.0.0.1'];
wpaw_assert_same('10.0.0.2', wpaw_client_ip(), 'a chain containing only trusted proxies falls back to the connecting address');

wpaw_test_reset([]);
$_SERVER = ['REMOTE_ADDR' => 'not-an-ip'];
wpaw_assert_same('', wpaw_client_ip(), 'an unparseable connecting address yields no client IP');

// The bypass this whole file exists to prevent, stated as one assertion.
wpaw_test_reset(['test_allowlist' => '203.0.113.0/24', 'test_proxies' => '', 'test_scope' => 'website']);
$_SERVER = ['REMOTE_ADDR' => '198.51.100.9', 'HTTP_X_FORWARDED_FOR' => '203.0.113.4'];
wpaw_assert_true(wpaw_gate_should_deny(), 'a forged X-Forwarded-For naming an allowlisted address does not open the gate');

wpaw_test_done('ip-test');
