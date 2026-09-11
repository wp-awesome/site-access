<?php
/**
 * The recently-blocked-IP report.
 *
 * The parser is kept independent of file I/O so malformed, stale and non-403 lines are regression
 * tested without exposing real visitor addresses. The FORMAT these fixtures are written in was
 * verified against real nginx output before the parser was written, not the other way around — a
 * hand-typed fixture that only tests itself is how a sibling project's log_format and its own
 * parser regex came to disagree without anyone noticing.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const WP_AWESOME_GATE_ACCESS_LOG_PATH = '/nowhere/does-not-exist.log';

require_once dirname(__DIR__) . '/src/load.php';

wpaw_assert_same(
	[
		'203.0.113.4' => ['count' => 2, 'last_seen' => '2026-08-02T09:00:00Z'],
		'2001:db8::4' => ['count' => 1, 'last_seen' => '2026-08-01T07:30:00Z'],
	],
	wpaw_blocked_ips_from_lines(
		[
			'203.0.113.4 - - [02/Aug/2026:08:00:00 +0000] "GET / HTTP/1.1" 403 153 "-" "Mozilla/5.0"',
			'203.0.113.4 - - [02/Aug/2026:09:00:00 +0000] "GET /wp-login.php HTTP/1.1" 403 153 "-" "Mozilla/5.0"',
			'2001:db8::4 - - [01/Aug/2026:07:30:00 +0000] "GET / HTTP/1.1" 403 153 "-" "Mozilla/5.0"',
			'198.51.100.1 - - [20/Jul/2026:09:00:00 +0000] "GET / HTTP/1.1" 403 153 "-" "Mozilla/5.0"',
			'203.0.113.9 - - [02/Aug/2026:09:00:00 +0000] "GET / HTTP/1.1" 200 153 "-" "Mozilla/5.0"',
			'not-an-ip - - [02/Aug/2026:09:00:00 +0000] "GET / HTTP/1.1" 403 153 "-" "Mozilla/5.0"',
			'a line from some other log entirely',
			'',
		],
		strtotime('2026-07-27T09:00:00+00:00')
	),
	'the report aggregates unique recent 403 clients by count and latest UTC time, newest first'
);

wpaw_assert_same([], wpaw_blocked_ips_from_lines([], time()), 'no lines produce no report');
wpaw_assert_same([], wpaw_recent_blocked_ips(), 'a missing access log produces an empty report, never an access-control failure');

// THE SHAPE A FILE ACTUALLY YIELDS. In the retired field-per-token format the address was the LAST
// field, so a real line ended with a newline straight after it and a capture that swallowed it made
// the whole report silently empty. `combined` puts the address FIRST, so that is now impossible by
// construction -- this stays as a regression guard, not because the risk remains.
wpaw_assert_same(
	['203.0.113.4' => ['count' => 1, 'last_seen' => '2026-08-02T08:00:00Z']],
	wpaw_blocked_ips_from_lines(
		["203.0.113.4 - - [02/Aug/2026:08:00:00 +0000] \"GET / HTTP/1.1\" 403 153 \"-\" \"Mozilla/5.0\"\n"],
		strtotime('2026-07-27T09:00:00+00:00')
	),
	'a line carrying its trailing newline, exactly as a file yields it, still parses'
);

// End to end over a real file, because the assertion above is only as good as the claim that this is
// the shape a file yields.
$log = tempnam(sys_get_temp_dir(), 'wpaw-log-');
file_put_contents($log, sprintf(
	"198.51.100.77 - - [%s] \"GET / HTTP/1.1\" 403 153 \"-\" \"Mozilla/5.0\"\n",
	gmdate('d/M/Y:H:i:s O', time() - 3600)
));

wpaw_assert_same(
	['198.51.100.77'],
	array_keys(wpaw_blocked_ips_from_lines(new SplFileObject($log), time() - 86400)),
	'reading a real file reports the address that was really blocked'
);

unlink($log);

wpaw_test_done('access-log-test');
