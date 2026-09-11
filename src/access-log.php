<?php
/**
 * The recently-blocked-address report.
 *
 * Answers the only question an administrator actually has after switching the gate on: who did I
 * just shut out? Without it, the allowlist is edited blind.
 *
 * The host points `WP_AWESOME_GATE_ACCESS_LOG_PATH` at a single web-server access log written in
 * nginx's stock `combined` format, which is also Apache's:
 *
 *   $remote_addr - $remote_user [$time_local] "$request" $status $body_bytes_sent "$http_referer" "$http_user_agent"
 *
 * WHY A STANDARD FORMAT RATHER THAN A LOCAL ONE. This parser previously read a bespoke
 * field-per-token format, chosen because `key=value` looked easier to parse than counting columns.
 * The reasoning did not survive contact: every consumer of a bespoke format needs a bespoke parser,
 * while `combined` is read natively by goaccess, fail2ban, awstats and every log pipeline in
 * existence. More importantly, a locally invented format can DRIFT from the parser that reads it.
 * A sibling project's format omitted the leading bracket its own regex required, so its report could
 * never match a single line. A format fixed by convention removes the opportunity to invent, and so
 * removes the drift.
 *
 * WHY THE ADDRESS BEING FIRST IS LOAD-BEARING. In the old format `client=` was the LAST field, so a
 * line read from a file ended with a newline immediately after the address. `[^ ]+` captured that
 * newline — a newline is not a space — and `filter_var()` then rejected every real address, silently.
 * The live report was permanently empty and read as "nobody has been blocked". In `combined` the
 * address is the FIRST field with six fields behind it, so no line terminator can reach the capture.
 * The class of bug is gone by construction rather than by remembering to trim. Lines are still
 * trimmed and still tested with a trailing newline present, because defence in depth is cheap here.
 *
 * WHAT WAS GIVEN UP. `combined` carries no `$host`, which the previous format did. This report never
 * captured it, so the report loses nothing; multi-vhost diagnosis from the log does. That belongs in
 * a second log stream rather than deforming the one a security control parses.
 *
 * A consuming project should still pin its format string and this parser together in a deployment
 * test, so an edit to either fails loudly.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

/**
 * A single path, not a glob. Empty means this host has no log to read.
 */
function wpaw_access_log_path(): string {
	return wpaw_setting('WP_AWESOME_GATE_ACCESS_LOG_PATH', '');
}

/**
 * Whether the report can be produced at all, and if not, why.
 *
 * THIS DISTINCTION IS THE POINT, not bookkeeping. "Nobody has been denied" and "this report cannot
 * be produced" look identical on screen as an empty table, and an administrator reading the empty
 * one concludes the first. That is how a broken report survives: it never looks broken.
 *
 * A log path can be configured and still unreadable for reasons that have nothing to do with this
 * code — most commonly the web server writes it into a volume that is not mounted into the
 * container the interpreter runs in, so the file genuinely does not exist from here. The screen has
 * to say so.
 *
 * @return 'not-configured'|'unreadable'|'readable'
 */
function wpaw_access_log_state(): string {
	$path = wpaw_access_log_path();

	if ('' === $path) {
		return 'not-configured';
	}

	return is_readable($path) ? 'readable' : 'unreadable';
}

/**
 * Extracts unique recent client addresses that received a 403 response.
 *
 * Kept independent of file I/O so malformed, stale and non-403 lines can be regression-tested
 * without exposing real visitor addresses.
 *
 * @param iterable<string> $lines
 * @return array<string, array{count: int, last_seen: string}>
 */
function wpaw_blocked_ips_from_lines(iterable $lines, int $after): array {
	$blocked = [];

	foreach ($lines as $line) {
		$line = trim((string) $line);

		if (1 !== preg_match('/^(\S+) \S+ \S+ \[([^\]]+)\] "[^"]*" 403 /', $line, $matches)) {
			continue;
		}

		$occurred = DateTimeImmutable::createFromFormat('d/M/Y:H:i:s O', $matches[2]);
		$seen_at  = false === $occurred ? false : $occurred->getTimestamp();
		$ip       = $matches[1];

		if (false === $seen_at || $seen_at < $after || false === filter_var($ip, FILTER_VALIDATE_IP)) {
			continue;
		}

		if (! isset($blocked[$ip])) {
			$blocked[$ip] = ['count' => 0, 'last_seen' => 0];
		}

		$blocked[$ip]['count']++;
		$blocked[$ip]['last_seen'] = max($blocked[$ip]['last_seen'], $seen_at);
	}

	uksort($blocked, static function (string $left, string $right) use ($blocked): int {
		$recent_first = $blocked[$right]['last_seen'] <=> $blocked[$left]['last_seen'];

		return 0 !== $recent_first ? $recent_first : strnatcasecmp($left, $right);
	});

	$result = [];
	foreach ($blocked as $ip => $details) {
		$result[$ip] = [
			'count'     => $details['count'],
			'last_seen' => gmdate('Y-m-d\TH:i:s\Z', $details['last_seen']),
		];
	}

	return $result;
}

/**
 * Reads the access log without loading it into memory. A missing or unreadable log produces an
 * empty report, never an access-control failure.
 *
 * @return array<string, array{count: int, last_seen: string}>
 */
function wpaw_recent_blocked_ips(): array {
	$path = wpaw_access_log_path();

	if ('readable' !== wpaw_access_log_state()) {
		return [];
	}

	try {
		$lines = new SplFileObject($path);
	} catch (RuntimeException) {
		return [];
	}

	$window = defined('WEEK_IN_SECONDS') ? (int) WEEK_IN_SECONDS : 604800;

	return wpaw_blocked_ips_from_lines($lines, time() - $window);
}
