<?php
/**
 * Address parsing, range matching and client identity.
 *
 * SECURITY CRITICAL, all of it. Every function here answers "is this request from an address the
 * site owner listed", and every shortcut in it is a bypass.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Normalizes literal newline escapes and sorts non-empty allowlist lines by address.
 *
 * Comment-only lines stay immediately above the next entry they annotate; inline comments remain
 * attached to their entry. Trailing comments stay at the end rather than being discarded.
 *
 * @return string[]
 */
function wpaw_normalize_lines(string $raw): array {
	$raw      = str_replace(['\\r\\n', '\\n', '\\r'], "\n", $raw);
	$groups   = [];
	$comments = [];

	foreach (preg_split('/\R/', $raw) ?: [] as $line) {
		$line = trim($line);

		if ('' === $line) {
			continue;
		}

		if ('#' === $line[0]) {
			$comments[] = $line;
			continue;
		}

		$groups[] = [
			'ip'    => trim((string) preg_replace('/#.*$/', '', $line)),
			'lines' => [...$comments, $line],
		];
		$comments = [];
	}

	usort($groups, static fn(array $left, array $right): int => strnatcasecmp($left['ip'], $right['ip']));

	$lines = [];
	foreach ($groups as $group) {
		array_push($lines, ...$group['lines']);
	}

	return [...$lines, ...$comments];
}

/**
 * Parses a newline-separated list into entries, dropping blanks and # comments.
 *
 * @return string[]
 */
function wpaw_parse_list(string $raw): array {
	$entries = array_map(
		static fn(string $line): string => trim((string) preg_replace('/#.*$/', '', $line)),
		wpaw_normalize_lines($raw)
	);

	return array_values(array_filter($entries));
}

/**
 * Whether $ip falls inside $range, which may be a bare address or CIDR.
 *
 * Compares packed binary with a bit mask rather than string prefixes: "192.168.1.1" is not inside
 * "192.168.1.10/32" even though one is a string prefix of the other, and IPv6 has several textual
 * spellings of one address that only converge once packed.
 */
function wpaw_ip_in_range(string $ip, string $range): bool {
	$packed_ip = @inet_pton($ip);

	if (false === $packed_ip) {
		return false;
	}

	if (false === strpos($range, '/')) {
		$packed_range = @inet_pton($range);

		return false !== $packed_range && hash_equals($packed_range, $packed_ip);
	}

	[$subnet, $bits] = explode('/', $range, 2);

	$packed_subnet = @inet_pton(trim($subnet));

	if (false === $packed_subnet || ! is_numeric(trim($bits))) {
		return false;
	}

	// An IPv4 address can never sit inside an IPv6 range, or vice versa; their packed forms differ
	// in length (4 vs 16 bytes).
	if (strlen($packed_subnet) !== strlen($packed_ip)) {
		return false;
	}

	$bits     = (int) trim($bits);
	$max_bits = strlen($packed_ip) * 8;

	if ($bits < 0 || $bits > $max_bits) {
		return false;
	}

	$whole_bytes = intdiv($bits, 8);
	$rest_bits   = $bits % 8;

	if ($whole_bytes > 0 && ! hash_equals(substr($packed_subnet, 0, $whole_bytes), substr($packed_ip, 0, $whole_bytes))) {
		return false;
	}

	if (0 === $rest_bits) {
		return true;
	}

	$mask = chr((0xFF << (8 - $rest_bits)) & 0xFF);

	return (ord($packed_ip[$whole_bytes]) & ord($mask)) === (ord($packed_subnet[$whole_bytes]) & ord($mask));
}

/**
 * Validates a single list entry (bare address or CIDR).
 */
function wpaw_is_valid_entry(string $entry): bool {
	if (false === strpos($entry, '/')) {
		return false !== filter_var($entry, FILTER_VALIDATE_IP);
	}

	[$subnet, $bits] = explode('/', $entry, 2);

	$packed = @inet_pton(trim($subnet));

	if (false === $packed || ! ctype_digit(trim($bits))) {
		return false;
	}

	return (int) trim($bits) <= strlen($packed) * 8;
}

/**
 * Resolves the client address.
 *
 * By default this trusts REMOTE_ADDR and nothing else, because reading a forwarded header
 * unconditionally is a one-line bypass: anyone could send `X-Forwarded-For: <allowed>`.
 *
 * The trusted-proxy list exists only for stacks where REMOTE_ADDR really is a proxy. When
 * REMOTE_ADDR matches one of those, and only then, X-Forwarded-For is consulted — taking the
 * RIGHTMOST entry that is not itself a trusted proxy. The rightmost entries were appended by
 * infrastructure the site owner controls; the leftmost are whatever the client sent.
 */
function wpaw_client_ip(): string {
	$remote = isset($_SERVER['REMOTE_ADDR']) ? trim((string) $_SERVER['REMOTE_ADDR']) : '';

	if ('' === $remote || false === filter_var($remote, FILTER_VALIDATE_IP)) {
		return '';
	}

	$proxies = wpaw_parse_list((string) get_option(wpaw_option_key('trusted_proxies'), ''));

	if (empty($proxies)) {
		return $remote;
	}

	$remote_is_proxy = false;

	foreach ($proxies as $proxy) {
		if (wpaw_ip_in_range($remote, $proxy)) {
			$remote_is_proxy = true;
			break;
		}
	}

	if (! $remote_is_proxy || empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
		return $remote;
	}

	$forwarded = array_map('trim', explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']));

	// Walk right-to-left and take the first entry that is not itself a proxy.
	foreach (array_reverse($forwarded) as $candidate) {
		if (false === filter_var($candidate, FILTER_VALIDATE_IP)) {
			continue;
		}

		foreach ($proxies as $proxy) {
			if (wpaw_ip_in_range($candidate, $proxy)) {
				continue 2;
			}
		}

		return $candidate;
	}

	return $remote;
}
